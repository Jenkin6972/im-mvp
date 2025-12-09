<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enums\AgentStatus;
use App\Service\AgentService;
use App\Service\AuthService;
use App\Service\ConversationService;
use App\Service\CustomerService;
use App\Service\WebSocketService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\WebSocketServer\Context;
use Hyperf\WebSocketServer\Sender;

/**
 * ============================================================================
 * WebSocket控制器 - 处理WebSocket连接的入口
 * ============================================================================
 *
 * 【什么是WebSocket控制器？】
 * 这是WebSocket连接的入口点，类似于HTTP的路由控制器。
 * 当客户端建立WebSocket连接时，会触发这里的方法。
 *
 * 【三个核心方法】
 * 1. onOpen：连接建立时触发
 * 2. onMessage：收到消息时触发
 * 3. onClose：连接关闭时触发
 *
 * 【连接类型】
 * - agent：客服连接，需要token认证
 * - customer：客户连接，需要uuid标识
 *
 * 【消息类型】
 * - ping：心跳检测
 * - message：聊天消息
 * - typing：打字状态
 * - read：标记已读
 * - status：状态变更（客服）
 * - close_conversation：关闭会话（客服）
 */
class WebSocketController implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected WebSocketService $webSocketService,
        protected AgentService $agentService,
        protected CustomerService $customerService,
        protected ConversationService $conversationService,
        protected AuthService $authService,
        protected Sender $sender
    ) {
    }

    /**
     * 连接建立时触发
     *
     * 【触发时机】
     * 客户端调用 new WebSocket(url) 时触发。
     *
     * 【处理逻辑】
     * 1. 解析URL参数，判断是客服还是客户
     * 2. 分别调用对应的处理方法
     *
     * @param mixed $server Swoole服务器对象
     * @param mixed $request 请求对象，包含fd和参数
     */
    public function onOpen($server, $request): void
    {
        $fd = $request->fd;  // 连接标识
        $params = $request->get ?? [];  // URL参数

        // 解析连接类型（默认为客户）
        $type = $params['type'] ?? 'customer';

        if ($type === 'agent') {
            $this->handleAgentOpen($server, $fd, $params);
        } else {
            $this->handleCustomerOpen($fd, $params, $request);
        }
    }

    /**
     * 收到消息时触发
     *
     * 【触发时机】
     * 客户端调用 ws.send(data) 时触发。
     *
     * 【处理逻辑】
     * 1. 解析JSON消息
     * 2. 处理心跳
     * 3. 根据用户类型分发到对应处理方法
     *
     * @param mixed $server Swoole服务器对象
     * @param mixed $frame 消息帧，包含fd和data
     */
    public function onMessage($server, $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);

        if (!$data) {
            return;
        }

        $msgType = $data['type'] ?? '';

        // 心跳处理（保持连接活跃）
        if ($msgType === 'ping') {
            $this->webSocketService->sendToFd($fd, ['type' => 'pong']);
            $this->handleHeartbeat($fd);
            return;
        }

        // 获取用户信息（在onOpen时保存的）
        $userInfo = Context::get('user_info');
        if (!$userInfo) {
            return;
        }

        // 根据用户类型分发消息
        if ($userInfo['type'] === 'agent') {
            $this->handleAgentMessage($userInfo['id'], $msgType, $data);
        } else {
            $this->handleCustomerMessage($userInfo['uuid'], $msgType, $data);
        }
    }

    /**
     * 连接关闭时触发
     *
     * 【触发时机】
     * 客户端关闭连接或网络断开时触发。
     *
     * 【处理逻辑】
     * 1. 清理Redis中的连接信息
     * 2. 更新客服状态为离线
     *
     * @param mixed $server Swoole服务器对象
     * @param int $fd 连接标识
     * @param int $reactorId 反应器ID
     */
    public function onClose($server, int $fd, int $reactorId): void
    {
        $userInfo = Context::get('user_info');
        if (!$userInfo) {
            return;
        }

        if ($userInfo['type'] === 'agent') {
            // 客服断开：清理连接，设置离线
            $this->agentService->removeConnection($userInfo['id']);
            $this->agentService->setStatus($userInfo['id'], AgentStatus::OFFLINE());
            logger()->info("Agent disconnected", ['agent_id' => $userInfo['id'], 'fd' => $fd]);
        } else {
            // 客户断开：清理连接
            $this->customerService->removeConnection($userInfo['uuid']);
            logger()->info("Customer disconnected", ['uuid' => $userInfo['uuid'], 'fd' => $fd]);
        }
    }

    /**
     * 处理客服连接
     *
     * 【认证流程】
     * 1. 验证token
     * 2. 检查是否有旧连接（多端登录限制）
     * 3. 保存连接信息
     * 4. 分配等待中的会话
     *
     * @param mixed $server Swoole服务器对象
     * @param int $fd 连接标识
     * @param array $params URL参数
     */
    protected function handleAgentOpen($server, int $fd, array $params): void
    {
        $token = $params['token'] ?? '';
        $agentId = $this->authService->verifyToken($token);

        // 认证失败
        if (!$agentId) {
            $this->webSocketService->sendToFd($fd, [
                'type' => 'error',
                'message' => '认证失败',
            ]);
            return;
        }

        // 多端登录限制：检查是否已有连接
        $oldFd = $this->agentService->getConnection($agentId);
        if ($oldFd && $oldFd !== $fd) {
            // 先检查旧连接是否仍然有效（服务重启后旧FD可能已无效）
            // 使用 Swoole Server 的 isEstablished 方法检测 WebSocket 连接是否有效
            $swooleServer = $server instanceof \Swoole\WebSocket\Server ? $server : null;
            $isOldConnectionValid = $swooleServer && $swooleServer->isEstablished($oldFd);

            if ($isOldConnectionValid) {
                // 旧连接仍然有效，通知被踢下线
                $this->webSocketService->sendToFd($oldFd, [
                    'type' => 'kicked',
                    'message' => '您的账号在其他设备登录，当前连接已断开',
                ]);
                // 关闭旧连接
                try {
                    $this->sender->disconnect($oldFd);
                } catch (\Throwable) {
                    // 忽略关闭失败
                }
                logger()->info("Agent kicked from old connection", ['agent_id' => $agentId, 'old_fd' => $oldFd, 'new_fd' => $fd]);
            } else {
                // 旧连接已无效（可能是服务重启），只需清理Redis中的映射
                logger()->info("Agent old connection invalid, cleaning up", ['agent_id' => $agentId, 'old_fd' => $oldFd, 'new_fd' => $fd]);
            }
        }

        // 保存连接信息到Context和Redis
        Context::set('user_info', ['type' => 'agent', 'id' => $agentId]);
        $this->agentService->saveConnection($agentId, $fd);
        $this->agentService->setStatus($agentId, AgentStatus::ONLINE());
        $this->agentService->updateHeartbeat($agentId);

        // 发送连接成功消息
        $this->webSocketService->sendToFd($fd, [
            'type' => 'connected',
            'data' => [
                'agent_id' => $agentId,
                'status' => AgentStatus::ONLINE,
            ],
        ]);

        logger()->info("Agent connected", ['agent_id' => $agentId, 'fd' => $fd]);

        // 处理等待中的会话（分配给新上线的客服）
        $this->webSocketService->handleAgentOnline($agentId);
    }

    /**
     * 处理客户连接
     *
     * 【连接流程】
     * 1. 验证uuid参数
     * 2. 获取客户IP和UA
     * 3. 创建或获取客户记录
     * 4. 保存连接信息
     * 5. 推送离线消息
     *
     * @param int $fd 连接标识
     * @param array $params URL参数
     * @param mixed $request 请求对象
     */
    protected function handleCustomerOpen(int $fd, array $params, $request): void
    {
        $uuid = $params['uuid'] ?? '';
        if (!$uuid) {
            $this->webSocketService->sendToFd($fd, [
                'type' => 'error',
                'message' => '缺少uuid参数',
            ]);
            return;
        }

        // 获取客户IP（优先使用代理头）
        $ip = $request->header['x-real-ip']
            ?? $request->header['x-forwarded-for']
            ?? $request->server['remote_addr']
            ?? '';
        $userAgent = $request->header['user-agent'] ?? '';

        // 创建或获取客户记录
        $customer = $this->customerService->getOrCreate($uuid, $ip, $userAgent);

        // 保存连接信息
        Context::set('user_info', ['type' => 'customer', 'uuid' => $uuid, 'id' => $customer->id]);
        $this->customerService->saveConnection($uuid, $fd);
        $this->customerService->updateHeartbeat($uuid);

        // 发送连接成功消息
        $this->webSocketService->sendToFd($fd, [
            'type' => 'connected',
            'data' => ['customer_id' => $customer->id, 'uuid' => $uuid],
        ]);

        logger()->info("Customer connected", ['uuid' => $uuid, 'fd' => $fd, 'ip' => $ip]);

        // 推送离线期间的消息
        $this->webSocketService->pushOfflineMessages($customer);
    }

    /**
     * 处理心跳
     *
     * 【心跳机制】
     * 客户端定期发送ping，服务端回复pong。
     * 同时更新Redis中的心跳时间，用于检测连接是否存活。
     *
     * @param int $fd 连接标识（未使用，但保留参数以备扩展）
     */
    protected function handleHeartbeat(int $fd): void
    {
        $userInfo = Context::get('user_info');
        if (!$userInfo) {
            return;
        }

        // 更新心跳时间
        if ($userInfo['type'] === 'agent') {
            $this->agentService->updateHeartbeat($userInfo['id']);
        } else {
            $this->customerService->updateHeartbeat($userInfo['uuid']);
        }
    }

    /**
     * 处理客服消息
     *
     * 【消息类型】
     * - message：发送聊天消息
     * - close_conversation：关闭会话
     * - status：更改在线状态
     * - typing：打字状态
     * - read：标记已读
     *
     * @param int $agentId 客服ID
     * @param string $msgType 消息类型
     * @param array $data 消息数据
     */
    protected function handleAgentMessage(int $agentId, string $msgType, array $data): void
    {
        switch ($msgType) {
            case 'message':
                $this->webSocketService->handleAgentMessage($agentId, $data['data'] ?? []);
                break;
            case 'close_conversation':
                $this->handleCloseConversation($agentId, $data['data'] ?? []);
                break;
            case 'status':
                $this->handleStatusChange($agentId, $data['data'] ?? []);
                break;
            case 'typing':
                $this->webSocketService->handleTyping($agentId, $data['data'] ?? []);
                break;
            case 'read':
                $this->webSocketService->handleAgentRead($agentId, $data['data'] ?? []);
                break;
        }
    }

    /**
     * 处理客户消息
     *
     * 【消息类型】
     * - message：发送聊天消息
     * - typing：打字状态
     * - read：标记已读
     *
     * @param string $uuid 客户UUID
     * @param string $msgType 消息类型
     * @param array $data 消息数据
     */
    protected function handleCustomerMessage(string $uuid, string $msgType, array $data): void
    {
        switch ($msgType) {
            case 'message':
                $this->webSocketService->handleCustomerMessage($uuid, $data['data'] ?? []);
                break;
            case 'typing':
                $this->webSocketService->handleCustomerTyping($uuid, $data['data'] ?? []);
                break;
            case 'read':
                $this->webSocketService->handleCustomerRead($uuid, $data['data'] ?? []);
                break;
        }
    }

    /**
     * 处理关闭会话
     *
     * 【关闭流程】
     * 1. 验证会话归属
     * 2. 更新会话状态为已关闭
     * 3. 通知客户和客服
     *
     * @param int $agentId 客服ID
     * @param array $data 包含 conversation_id
     */
    protected function handleCloseConversation(int $agentId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? 0;
        if (!$conversationId) {
            return;
        }

        // 验证会话归属
        $conversation = \App\Model\Conversation::find($conversationId);
        if (!$conversation || $conversation->agent_id !== $agentId) {
            return;
        }

        // 关闭会话
        $this->conversationService->close($conversation);

        // 通知客户
        if ($conversation->customer) {
            $this->webSocketService->sendToCustomer($conversation->customer->uuid, [
                'type' => 'conversation_closed',
                'data' => ['conversation_id' => $conversationId],
            ]);
        }

        // 通知客服
        $this->webSocketService->sendToAgent($agentId, [
            'type' => 'conversation_closed',
            'data' => ['conversation_id' => $conversationId],
        ]);
    }

    /**
     * 处理状态变更
     *
     * 【状态值】
     * - 1：在线
     * - 2：离线
     * - 3：忙碌
     *
     * @param int $agentId 客服ID
     * @param array $data 包含 status
     */
    protected function handleStatusChange(int $agentId, array $data): void
    {
        $status = $data['status'] ?? null;
        if ($status === null) {
            return;
        }

        // 验证状态值
        $statusValue = (int) $status;
        if (!in_array($statusValue, [AgentStatus::ONLINE, AgentStatus::OFFLINE, AgentStatus::BUSY])) {
            return;
        }

        // 更新状态
        $agentStatus = AgentStatus::from($statusValue);
        $this->agentService->setStatus($agentId, $agentStatus);

        // 通知客服状态已更新
        $this->webSocketService->sendToAgent($agentId, [
            'type' => 'status_changed',
            'data' => ['status' => $agentStatus->value],
        ]);
    }
}

