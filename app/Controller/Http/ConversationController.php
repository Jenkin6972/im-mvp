<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Model\Agent;
use App\Model\Conversation;
use App\Model\ConversationTransfer;
use App\Service\AgentService;
use App\Service\ConversationService;
use App\Service\WebSocketService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 会话控制器 - 处理会话相关的HTTP接口
 * ============================================================================
 *
 * 【接口列表】
 * - GET /conversation/list：获取会话列表
 * - POST /conversation/close/{id}：关闭会话
 * - POST /conversation/read/{id}：标记已读
 * - POST /conversation/transfer/{id}：转移会话
 * - GET /conversation/agents/{id}：获取可转移的客服列表
 * - GET /conversation/transfer-history/{id}：获取转移记录
 * - GET /conversation/customer/{id}：获取客户详情
 * - GET /conversation/history：历史会话查询
 *
 * 【权限说明】
 * - 普通客服：只能操作自己的会话
 * - 管理员：可以操作所有会话
 */
class ConversationController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected ConversationService $conversationService,
        protected AgentService $agentService,
        protected WebSocketService $webSocketService
    ) {
    }

    /**
     * 获取会话列表
     *
     * 【接口】GET /conversation/list
     *
     * 【请求参数】
     * - status：会话状态（可选，0=待分配, 1=进行中, 2=已关闭）
     *
     * @param RequestInterface $request
     * @return array
     */
    public function list(RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $status = $request->input('status');

        // 处理状态参数
        $statusValue = null;
        if ($status !== null && $status !== '') {
            $statusValue = (int) $status;
        }

        $conversations = $this->conversationService->getListForAgent($agentId, $statusValue);

        return json_success([
            'list' => $conversations,
            'total' => count($conversations),
        ]);
    }

    /**
     * 获取所有会话列表（超级管理员上帝视角）
     *
     * 【接口】GET /conversation/all
     *
     * 【请求参数】
     * - status：会话状态筛选（可选，0=待分配, 1=进行中, 2=已关闭）
     * - agent_id：客服ID筛选（可选）
     *
     * 【权限说明】
     * - 仅超级管理员可访问
     * - 返回所有会话，只读模式（前端禁用回复功能）
     *
     * @param RequestInterface $request
     * @return array
     */
    public function all(RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        // 权限检查：仅超级管理员可访问
        if (!$agent || !$agent->isAdmin()) {
            return json_error('无权访问，仅超级管理员可查看所有会话');
        }

        $status = $request->input('status');
        $filterAgentId = $request->input('agent_id');

        // 处理状态参数
        $statusValue = null;
        if ($status !== null && $status !== '') {
            $statusValue = (int) $status;
        }

        // 处理客服筛选参数
        $filterAgentIdValue = null;
        if ($filterAgentId !== null && $filterAgentId !== '') {
            $filterAgentIdValue = (int) $filterAgentId;
        }

        $conversations = $this->conversationService->getAllConversations($statusValue, $filterAgentIdValue);

        // 获取所有客服列表（用于前端筛选下拉框）
        $agents = Agent::query()
            ->where('status', 1)
            ->select(['id', 'username', 'nickname'])
            ->get()
            ->toArray();

        return json_success([
            'list' => $conversations,
            'total' => count($conversations),
            'agents' => $agents,
        ]);
    }

    /**
     * 关闭会话
     *
     * 【接口】POST /conversation/close/{id}
     *
     * @param int $id 会话ID
     * @return array
     */
    public function close(int $id): array
    {
        $agentId = Context::get('agent_id');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查：只能关闭自己的会话
        if ($conversation->agent_id !== $agentId) {
            return json_error('无权操作此会话');
        }

        if ($conversation->isClosed()) {
            return json_error('会话已关闭');
        }

        $this->conversationService->close($conversation);

        return json_success(null, '会话已关闭');
    }

    /**
     * 标记会话消息为已读
     *
     * 【接口】POST /conversation/read/{id}
     *
     * 【处理逻辑】
     * 1. 标记消息为已读
     * 2. 通过WebSocket通知客户消息已读（显示已读状态）
     *
     * @param int $id 会话ID
     * @return array
     */
    public function read(int $id): array
    {
        $agentId = Context::get('agent_id');

        $count = $this->conversationService->markMessagesAsRead($id, $agentId);

        // 如果有消息被标记为已读，通知客户
        if ($count > 0) {
            $conversation = Conversation::with('customer')->find($id);
            if ($conversation && $conversation->customer) {
                $this->webSocketService->sendToCustomer($conversation->customer->uuid, [
                    'type' => 'messages_read',
                    'data' => [
                        'conversation_id' => $id,
                        'reader' => 'agent',
                    ],
                ]);
            }
        }

        return json_success(['marked_count' => $count]);
    }

    /**
     * 转移会话
     *
     * 【接口】POST /conversation/transfer/{id}
     *
     * 【请求参数】
     * - to_agent_id：目标客服ID
     * - reason：转移原因（可选）
     *
     * 【权限说明】
     * - 管理员可以转移任何会话
     * - 普通客服只能转移自己的会话
     *
     * @param int $id 会话ID
     * @param RequestInterface $request
     * @return array
     */
    public function transfer(int $id, RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $toAgentId = (int) $request->input('to_agent_id');
        $reason = $request->input('reason', '');

        if (!$toAgentId) {
            return json_error('请选择目标客服');
        }

        $conversation = Conversation::find($id);
        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查
        $agent = Agent::find($agentId);
        if (!$agent->isAdmin() && $conversation->agent_id !== $agentId) {
            return json_error('无权转移此会话');
        }

        // 执行转移
        $result = $this->conversationService->transfer(
            $id,
            $toAgentId,
            ConversationTransfer::TYPE_MANUAL,
            $agentId,
            $reason
        );

        if ($result['success']) {
            return json_success(null, $result['message']);
        }

        return json_error($result['message']);
    }

    /**
     * 获取可转移的客服列表
     *
     * 【接口】GET /conversation/agents/{id}
     *
     * 【返回数据】
     * 所有在线客服列表（排除当前会话的客服）
     *
     * @param int $id 会话ID
     * @return array
     */
    public function agents(int $id): array
    {
        $agentId = Context::get('agent_id');

        $conversation = Conversation::find($id);
        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查
        $agent = Agent::find($agentId);
        if (!$agent->isAdmin() && $conversation->agent_id !== $agentId) {
            return json_error('无权操作此会话');
        }

        // 获取所有在线客服（排除当前会话的客服和管理员）
        $onlineAgents = $this->agentService->getOnlineAgents();
        $agents = array_filter($onlineAgents, function($a) use ($conversation) {
            // 排除当前会话的客服
            if ($a['id'] === $conversation->agent_id) {
                return false;
            }
            // 排除管理员账号
            $agentModel = Agent::find($a['id']);
            if ($agentModel && $agentModel->isAdmin()) {
                return false;
            }
            return true;
        });

        return json_success([
            'list' => array_values($agents),
        ]);
    }

    /**
     * 获取会话转移记录
     *
     * 【接口】GET /conversation/transfer-history/{id}
     *
     * @param int $id 会话ID
     * @return array
     */
    public function transferHistory(int $id): array
    {
        $agentId = Context::get('agent_id');

        $conversation = Conversation::find($id);
        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查
        $agent = Agent::find($agentId);
        if (!$agent->isAdmin() && $conversation->agent_id !== $agentId) {
            return json_error('无权查看此会话');
        }

        $history = $this->conversationService->getTransferHistory($id);

        return json_success([
            'list' => $history,
        ]);
    }

    /**
     * 获取会话的客户详细信息
     *
     * 【接口】GET /conversation/customer/{id}
     *
     * 【返回数据】
     * - 客户基本信息（IP、设备、浏览器等）
     * - 历史会话数
     * - 总消息数
     *
     * @param int $id 会话ID
     * @return array
     */
    public function customer(int $id): array
    {
        $agentId = Context::get('agent_id');

        $conversation = Conversation::with('customer')->find($id);
        if (!$conversation) {
            return json_error('会话不存在');
        }

        // 权限检查
        $agent = Agent::find($agentId);
        if (!$agent->isAdmin() && $conversation->agent_id !== $agentId) {
            return json_error('无权查看此会话');
        }

        $customer = $conversation->customer;
        if (!$customer) {
            return json_error('客户不存在');
        }

        // 统计该客户的历史会话数
        $historyConversations = Conversation::where('customer_id', $customer->id)
            ->count();

        // 统计该客户的总消息数（使用子查询）
        $totalMessages = \App\Model\Message::query()
            ->whereIn('conversation_id', function ($query) use ($customer) {
                $query->select('id')
                    ->from('conversation')
                    ->where('customer_id', $customer->id);
            })
            ->count();

        return json_success([
            'id' => $customer->id,
            'uuid' => $customer->uuid,
            'ip' => $customer->ip,
            'source_url' => $customer->source_url,
            'referrer' => $customer->referrer,
            'device_type' => $customer->device_type,
            'browser' => $customer->browser,
            'os' => $customer->os,
            'city' => $customer->city,
            'email' => $customer->email ?? '',
            'timezone' => $customer->timezone ?? '',
            'created_at' => $customer->created_at,
            'last_active_at' => $customer->last_active_at,
            'history_conversations' => $historyConversations,
            'total_messages' => $totalMessages,
        ]);
    }

    /**
     * 历史会话查询
     *
     * 【接口】GET /conversation/history
     *
     * 【请求参数】
     * - page：页码（默认1）
     * - page_size：每页数量（默认20，最大50）
     * - start_date：开始日期（格式：YYYY-MM-DD）
     * - end_date：结束日期（格式：YYYY-MM-DD）
     * - customer_id：客户ID
     * - agent_id：客服ID（仅管理员可用）
     * - keyword：关键词（搜索客户UUID或消息内容）
     *
     * 【权限说明】
     * - 普通客服：只能查看自己的历史会话
     * - 管理员：可以查看所有客服的历史会话
     *
     * @param RequestInterface $request
     * @return array
     */
    public function history(RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        // 解析查询参数
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(50, max(1, (int) $request->input('page_size', 20)));
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $customerId = $request->input('customer_id');
        $filterAgentId = $request->input('agent_id');
        $keyword = $request->input('keyword');

        // 构建查询：只查询已关闭的会话
        $query = Conversation::with(['customer', 'agent'])
            ->where('status', \App\Enums\ConversationStatus::CLOSED);

        // 权限过滤：非管理员只能查看自己的历史会话
        if (!$agent->isAdmin()) {
            $query->where('agent_id', $agentId);
        } elseif ($filterAgentId) {
            $query->where('agent_id', (int) $filterAgentId);
        }

        // 时间范围过滤
        if ($startDate) {
            $query->where('created_at', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate . ' 23:59:59');
        }

        // 客户ID过滤
        if ($customerId) {
            $query->where('customer_id', (int) $customerId);
        }

        // 关键词搜索（搜索客户UUID或消息内容）
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('customer', function ($cq) use ($keyword) {
                    $cq->where('uuid', 'like', "%{$keyword}%");
                })->orWhereHas('messages', function ($mq) use ($keyword) {
                    $mq->where('content', 'like', "%{$keyword}%");
                });
            });
        }

        // 分页查询
        $total = $query->count();
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();

        return json_success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ]);
    }
}

