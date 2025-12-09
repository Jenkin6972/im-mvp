<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Model\Agent;
use App\Model\Conversation;
use App\Service\MessageService;
use App\Service\WebSocketService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 消息控制器 - 处理消息相关的HTTP接口
 * ============================================================================
 *
 * 【接口列表】
 * - GET /message/history/{conversation_id}：获取会话消息历史
 *
 * 【认证要求】
 * 需要通过 AuthMiddleware 认证。
 */
class MessageController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected MessageService $messageService,
        protected WebSocketService $webSocketService
    ) {
    }

    /**
     * 获取会话消息历史
     *
     * 【接口】GET /message/history/{conversation_id}
     *
     * 【请求参数】
     * - limit：消息数量限制（默认50，最大100）
     * - before_id：获取此ID之前的消息（用于分页加载更多）
     * - readonly：只读模式（超管上帝视角使用，不标记已读）
     *
     * 【返回数据】
     * - list：消息列表
     * - total：返回的消息数量
     *
     * 【权限说明】
     * - 普通客服：只能查看自己的会话，或者正在服务的客户的历史会话
     * - 超级管理员：可以查看所有会话（上帝视角模式）
     *
     * 【特殊逻辑】
     * 获取消息后会自动标记为已读（只读模式除外）。
     *
     * @param int $conversation_id 会话ID
     * @param RequestInterface $request
     * @return array
     */
    public function history(int $conversation_id, RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        $conversation = Conversation::find($conversation_id);

        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查：
        // 1. 超级管理员可以查看所有会话
        // 2. 普通客服可以查看自己的会话
        // 3. 普通客服正在服务某客户时，可以查看该客户的所有历史会话
        $isAdmin = $agent && $agent->isAdmin();
        $isOwner = $conversation->agent_id === $agentId;

        // 检查该客服是否正在服务这个客户（有进行中的会话）
        $isServingCustomer = false;
        if (!$isAdmin && !$isOwner) {
            $isServingCustomer = Conversation::query()
                ->where('customer_id', $conversation->customer_id)
                ->where('agent_id', $agentId)
                ->where('status', ConversationStatus::ACTIVE)
                ->exists();
        }

        if (!$isAdmin && !$isOwner && !$isServingCustomer) {
            return json_error('无权查看此会话');
        }

        // 解析分页参数
        $limit = (int) $request->input('limit', 50);
        $beforeId = $request->input('before_id');
        $readonly = $request->input('readonly', false);

        // 获取消息历史
        $messages = $this->messageService->getHistory(
            $conversation_id,
            min($limit, 100),
            $beforeId ? (int) $beforeId : null
        );

        // 标记消息为已读（只读模式或非会话所有者不标记）
        if (!$readonly && $isOwner) {
            $this->messageService->markAsRead($conversation_id, SenderType::AGENT());

            // 通知客户消息已被客服读取（显示已读状态）
            $conversationWithCustomer = Conversation::with('customer')->find($conversation_id);
            if ($conversationWithCustomer && $conversationWithCustomer->customer) {
                $this->webSocketService->sendToCustomer($conversationWithCustomer->customer->uuid, [
                    'type' => 'messages_read',
                    'data' => [
                        'conversation_id' => $conversation_id,
                        'reader' => 'agent',
                    ],
                ]);
            }
        }

        return json_success([
            'list' => $messages,
            'total' => count($messages),
        ]);
    }
}

