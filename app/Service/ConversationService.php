<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ConversationStatus;
use App\Model\Conversation;
use App\Model\Customer;
use App\Model\Message;

/**
 * ============================================================================
 * 会话服务类 - 管理客户与客服之间的会话
 * ============================================================================
 *
 * 【什么是会话(Conversation)？】
 * 会话是客户和客服之间的一次完整对话。
 * 从客户发起咨询开始，到问题解决关闭为止。
 *
 * 【会话的生命周期】
 * 1. 客户发起咨询 → 创建会话（状态：等待中）
 * 2. 系统分配客服 → 会话激活（状态：进行中）
 * 3. 双方聊天 → 消息存储在message表
 * 4. 问题解决 → 关闭会话（状态：已关闭）
 *
 * 【本服务的职责】
 * 1. 会话创建：客户发起咨询时创建会话
 * 2. 客服分配：自动选择负载最小的客服
 * 3. 会话转移：将会话从一个客服转给另一个
 * 4. 会话关闭：结束会话并释放客服负载
 * 5. 会话查询：获取客服的会话列表
 */
class ConversationService
{
    /**
     * 构造函数 - 依赖注入其他服务
     */
    public function __construct(
        protected AgentService $agentService,
        protected CustomerService $customerService
    ) {
    }

    /**
     * 获取或创建客户的活跃会话
     *
     * 【核心方法 - 客户发起咨询】
     * 客户打开聊天窗口时调用此方法。
     * 如果有未关闭的会话，直接返回；否则创建新会话。
     *
     * @param Customer $customer 客户对象
     * @return Conversation 会话对象
     */
    public function getOrCreateForCustomer(Customer $customer): Conversation
    {
        // 查找现有的未关闭会话（等待中或进行中）
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                ConversationStatus::WAITING,
                ConversationStatus::ACTIVE,
            ])
            ->orderBy('id', 'desc')
            ->first();

        // 如果有未关闭的会话，直接返回
        if ($conversation) {
            return $conversation;
        }

        // 创建新会话，初始状态为"等待中"
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'status' => ConversationStatus::WAITING,
        ]);

        // 尝试分配客服（如果有在线客服）
        $this->assignAgent($conversation);

        // 在Redis中记录客户当前会话，方便快速查找
        $this->customerService->setCurrentConversation($customer->uuid, $conversation->id);

        return $conversation;
    }

    /**
     * 自动分配客服
     *
     * 【分配逻辑】
     * 调用AgentService获取负载最小的在线客服，然后分配给会话。
     *
     * @param Conversation $conversation 会话对象
     * @return bool 是否分配成功
     */
    public function assignAgent(Conversation $conversation): bool
    {
        // 如果已经分配了客服，直接返回
        if ($conversation->agent_id) {
            return true;
        }

        // 获取负载最小的可用客服
        $agentId = $this->agentService->getAvailableAgent();

        if (!$agentId) {
            return false; // 没有可用客服
        }

        return $this->assignAgentTo($conversation, $agentId);
    }

    /**
     * 分配指定客服到会话
     *
     * @param Conversation $conversation 会话对象
     * @param int $agentId 客服ID
     * @return bool 是否成功
     */
    public function assignAgentTo(Conversation $conversation, int $agentId): bool
    {
        // 如果已经分配了客服，不能重复分配
        if ($conversation->agent_id) {
            return false;
        }

        // 更新会话：设置客服ID，状态改为"进行中"
        $conversation->update([
            'agent_id' => $agentId,
            'status' => ConversationStatus::ACTIVE,
        ]);

        // 重新计算客服负载（因为多了一个会话）
        $this->agentService->calculateLoad($agentId);

        return true;
    }

    /**
     * 关闭会话
     *
     * 【调用时机】
     * - 客服手动关闭会话
     * - 客户长时间不活跃，系统自动关闭
     *
     * @param Conversation $conversation 会话对象
     * @return bool 是否成功
     */
    public function close(Conversation $conversation): bool
    {
        $agentId = $conversation->agent_id;

        // 更新会话状态为"已关闭"，记录关闭时间
        $result = $conversation->update([
            'status' => ConversationStatus::CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
        ]);

        // 重新计算客服负载（因为少了一个会话）
        if ($agentId) {
            $this->agentService->calculateLoad($agentId);

            // 尝试分配待分配会话给该客服（因为现在有空余容量了）
            $this->tryAssignWaitingConversations($agentId);
        }

        return $result;
    }

    /**
     * 尝试分配待分配会话给指定客服
     *
     * 【调用时机】
     * - 客服关闭一个会话后（有空余容量了）
     * - 定时任务巡检时
     *
     * @param int $agentId 客服ID
     * @return int 成功分配的会话数
     */
    public function tryAssignWaitingConversations(int $agentId): int
    {
        // 检查客服是否在线
        if (!$this->agentService->isAgentOnline($agentId)) {
            return 0;
        }

        // 获取客服信息
        $agent = \App\Model\Agent::find($agentId);
        if (!$agent) {
            return 0;
        }

        // 管理员不参与接待
        if ($agent->is_admin === 1) {
            return 0;
        }

        // 计算可分配数量
        $currentSessionCount = $this->agentService->getActiveSessionCount($agentId);
        $availableSlots = $agent->max_sessions - $currentSessionCount;

        if ($availableSlots <= 0) {
            return 0;
        }

        // 查找待分配会话
        $waitingConversations = Conversation::query()
            ->with(['customer'])
            ->where('status', ConversationStatus::WAITING)
            ->whereNull('agent_id')
            ->orderBy('created_at', 'asc')
            ->limit($availableSlots)
            ->get();

        $assignedCount = 0;

        foreach ($waitingConversations as $conversation) {
            // 再次检查容量
            $currentCount = $this->agentService->getActiveSessionCount($agentId);
            if ($currentCount >= $agent->max_sessions) {
                break;
            }

            // 分配会话
            if ($this->assignAgentTo($conversation, $agentId)) {
                $assignedCount++;

                // 通过容器获取 WebSocketService 发送通知（避免循环依赖）
                try {
                    $webSocketService = \Hyperf\Context\ApplicationContext::getContainer()->get(WebSocketService::class);

                    // 通知客服有新会话
                    $webSocketService->sendToAgent($agentId, [
                        'type' => 'conversation_assigned',
                        'data' => [
                            'conversation_id' => $conversation->id,
                            'customer' => [
                                'id' => $conversation->customer->id ?? 0,
                                'uuid' => $conversation->customer->uuid ?? '',
                                'ip' => $conversation->customer->ip ?? '',
                            ],
                        ],
                    ]);

                    // 通知客户已分配客服
                    if ($conversation->customer) {
                        $webSocketService->sendToCustomer($conversation->customer->uuid, [
                            'type' => 'agent_assigned',
                            'data' => [
                                'message' => \App\Model\SystemConfig::getText('msg_agent_assigned'),
                                'conversation_id' => $conversation->id,
                            ],
                        ]);
                    }
                } catch (\Throwable $e) {
                    // 记录错误但不中断流程
                    logger()->error('Failed to send websocket notification', [
                        'error' => $e->getMessage(),
                        'conversation_id' => $conversation->id,
                    ]);
                }

                logger()->info('Waiting conversation assigned after close', [
                    'conversation_id' => $conversation->id,
                    'agent_id' => $agentId,
                ]);
            }
        }

        return $assignedCount;
    }

    /**
     * 获取客服的会话列表
     *
     * 【返回数据】
     * 每个会话包含：
     * - 会话基本信息
     * - 客户信息
     * - 最后一条消息
     * - 未读消息数
     *
     * @param int $agentId 客服ID
     * @param int|null $status 筛选状态（可选）
     * @return array 会话列表
     */
    public function getListForAgent(int $agentId, ?int $status = null): array
    {
        // 构建查询
        $query = Conversation::query()
            ->with(['customer'])  // 预加载客户信息
            ->where('agent_id', $agentId);

        // 按状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        } else {
            // 默认只显示未关闭的会话
            $query->whereIn('status', [
                ConversationStatus::WAITING,
                ConversationStatus::ACTIVE,
            ]);
        }

        // 按最后消息时间倒序（最新的在前面）
        $conversations = $query->orderBy('last_message_at', 'desc')->get();

        // 获取会话ID列表
        $conversationIds = $conversations->pluck('id')->toArray();

        if (empty($conversationIds)) {
            return [];
        }

        // 批量获取每个会话的最后一条消息
        $lastMessages = $this->getLastMessages($conversationIds);

        // 批量获取每个会话的未读消息数
        $unreadCounts = $this->getUnreadCounts($conversationIds);

        // 组装返回数据
        $result = [];
        foreach ($conversations as $conversation) {
            $convArray = $conversation->toArray();
            $convId = $conversation->id;

            // 添加最后一条消息
            $convArray['last_message'] = $lastMessages[$convId] ?? null;

            // 添加未读数量
            $convArray['unread_count'] = $unreadCounts[$convId] ?? 0;

            $result[] = $convArray;
        }

        return $result;
    }

    /**
     * 获取多个会话的最后一条消息
     *
     * 【批量查询优化】
     * 一次查询获取所有会话的消息，然后在内存中分组。
     * 比循环查询每个会话效率高很多。
     *
     * @param array $conversationIds 会话ID数组
     * @return array [会话ID => 最后消息]
     */
    private function getLastMessages(array $conversationIds): array
    {
        // 查询所有消息，按ID倒序
        $messages = \App\Model\Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('conversation_id');  // 按会话ID分组

        $result = [];
        foreach ($messages as $convId => $convMessages) {
            // 每组的第一条就是最后一条消息（因为按ID倒序）
            $lastMsg = $convMessages->first();
            if ($lastMsg) {
                $result[$convId] = [
                    'id' => $lastMsg->id,
                    'content' => $lastMsg->content,
                    'sender_type' => $lastMsg->sender_type,
                    'created_at' => $lastMsg->created_at,
                ];
            }
        }

        return $result;
    }

    /**
     * 获取多个会话的未读消息数
     *
     * 【只统计客户消息】
     * 客服端显示的未读数，是客户发送的未读消息数。
     * 客服自己发的消息不算未读。
     *
     * @param array $conversationIds 会话ID数组
     * @return array [会话ID => 未读数]
     */
    private function getUnreadCounts(array $conversationIds): array
    {
        $counts = \App\Model\Message::query()
            ->selectRaw('conversation_id, COUNT(*) as count')
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_type', \App\Enums\SenderType::CUSTOMER) // 只统计客户消息
            ->where('is_read', 0) // 未读
            ->groupBy('conversation_id')
            ->pluck('count', 'conversation_id')
            ->toArray();

        return $counts;
    }

    /**
     * 更新会话的最后消息时间
     *
     * 【作用】
     * 用于会话列表排序，最新有消息的会话排在前面。
     *
     * @param int $conversationId 会话ID
     */
    public function updateLastMessageTime(int $conversationId): void
    {
        Conversation::where('id', $conversationId)->update([
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 标记会话中客户发送的消息为已读
     *
     * 【调用时机】
     * 客服点击会话时，标记该会话的客户消息为已读。
     *
     * @param int $conversationId 会话ID
     * @param int $agentId 客服ID（用于验证权限）
     * @return int 标记的消息数量
     */
    public function markMessagesAsRead(int $conversationId, int $agentId): int
    {
        // 验证会话属于该客服
        $conversation = Conversation::find($conversationId);
        if (!$conversation || $conversation->agent_id !== $agentId) {
            return 0;
        }

        // 标记该会话中客户发送的未读消息为已读
        return \App\Model\Message::query()
            ->where('conversation_id', $conversationId)
            ->where('sender_type', \App\Enums\SenderType::CUSTOMER)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);
    }

    /**
     * 转移会话到其他客服
     *
     * 【核心方法 - 会话转移】
     * 将会话从当前客服转移给另一个客服。
     *
     * 【转移类型】
     * - 手动转移(1)：客服主动转移
     * - 自动转移(2)：系统检测到超时，自动转移
     *
     * 【转移流程】
     * 1. 验证会话和目标客服
     * 2. 更新会话的客服ID
     * 3. 记录转移历史
     * 4. 标记消息为未读（让新客服看到红点）
     * 5. 插入系统消息
     * 6. 发送WebSocket通知
     * 7. 重新计算双方客服负载
     *
     * @param int $conversationId 会话ID
     * @param int $toAgentId 目标客服ID
     * @param int $transferType 转移类型: 1手动 2自动
     * @param int|null $operatorId 操作人ID（手动转移时）
     * @param string $reason 转移原因
     * @return array ['success' => bool, 'message' => string]
     */
    public function transfer(
        int $conversationId,
        int $toAgentId,
        int $transferType = 1,
        ?int $operatorId = null,
        string $reason = ''
    ): array {
        // 查找会话
        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return ['success' => false, 'message' => '会话不存在'];
        }

        if ($conversation->isClosed()) {
            return ['success' => false, 'message' => '会话已关闭，无法转移'];
        }

        $fromAgentId = $conversation->agent_id;

        if (!$fromAgentId) {
            return ['success' => false, 'message' => '会话未分配客服，无需转移'];
        }

        if ($fromAgentId === $toAgentId) {
            return ['success' => false, 'message' => '不能转移给当前客服'];
        }

        // 验证目标客服是否存在且启用
        $toAgent = \App\Model\Agent::find($toAgentId);
        if (!$toAgent || !$toAgent->isEnabled()) {
            return ['success' => false, 'message' => '目标客服不存在或已禁用'];
        }

        // 检查目标客服是否在线
        // 检查目标客服是否在线
        if (!$this->agentService->isAgentOnline($toAgentId)) {
            return ['success' => false, 'message' => '目标客服不在线'];
        }

        // 检查目标客服是否还有空位
        if (!$this->agentService->hasCapacity($toAgentId)) {
            return ['success' => false, 'message' => '目标客服会话数已满'];
        }

        // 获取原客服名称（用于系统消息）
        $fromAgent = \App\Model\Agent::find($fromAgentId);
        $fromAgentName = $fromAgent ? ($fromAgent->nickname ?: $fromAgent->username) : '未知客服';
        $toAgentName = $toAgent->nickname ?: $toAgent->username;

        // 更新会话的客服ID
        $conversation->update(['agent_id' => $toAgentId]);

        // 记录转移历史（用于追溯和统计）
        \App\Model\ConversationTransfer::create([
            'conversation_id' => $conversationId,
            'from_agent_id' => $fromAgentId,
            'to_agent_id' => $toAgentId,
            'transfer_type' => $transferType,
            'operator_id' => $operatorId,
            'reason' => $reason,
        ]);

        // 将该会话的所有消息标记为未读，让新客服看到红点
        Message::where('conversation_id', $conversationId)
            ->update(['is_read' => 0]);

        // 插入系统消息，提示会话已转移（只在客服端显示）
        $transferTypeText = $transferType === 1 ? '手动转移' : '自动转移';
        $systemMessage = Message::create([
            'conversation_id' => $conversationId,
            'sender_type' => \App\Enums\SenderType::SYSTEM,
            'sender_id' => 0,
            'content' => "【系统消息】会话已从客服「{$fromAgentName}」{$transferTypeText}至客服「{$toAgentName}」",
            'content_type' => \App\Enums\ContentType::TEXT,
            'is_read' => 0,
        ]);

        // 重新计算双方客服负载
        $this->agentService->calculateLoad($fromAgentId);
        $this->agentService->calculateLoad($toAgentId);

        // 发送 WebSocket 通知给相关方
        $this->notifyTransfer($conversation, $fromAgentId, $toAgentId, $toAgent, $transferType, $reason, $systemMessage);

        return ['success' => true, 'message' => '转移成功'];
    }

    /**
     * 发送转移通知
     *
     * 【通知对象】
     * 1. 原客服：会话已转出，前端移除该会话
     * 2. 新客服：会话已转入，前端添加该会话
     * 3. 客户：客服已更换（可选提示）
     *
     * @param Conversation $conversation 会话对象
     * @param int $fromAgentId 原客服ID
     * @param int $toAgentId 新客服ID
     * @param \App\Model\Agent $toAgent 新客服对象
     * @param int $transferType 转移类型
     * @param string $reason 转移原因
     * @param Message|null $systemMessage 系统消息
     */
    protected function notifyTransfer(
        Conversation $conversation,
        int $fromAgentId,
        int $toAgentId,
        \App\Model\Agent $toAgent,
        int $transferType,
        string $reason,
        ?Message $systemMessage = null
    ): void {
        // 获取WebSocket服务实例
        $webSocketService = make(WebSocketService::class);

        // 获取会话历史消息（包含刚插入的系统消息）
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'content' => $m->content,
                'sender_type' => $m->sender_type,
                'created_at' => $m->created_at->format('Y-m-d H:i:s'),
            ])
            ->toArray();

        // 计算未读消息数（新客服看到的未读数）
        $unreadCount = Message::where('conversation_id', $conversation->id)
            ->where('is_read', 0)
            ->count();

        // 通知原客服：会话已转出
        $webSocketService->sendToAgent($fromAgentId, [
            'type' => 'conversation_transferred_out',
            'data' => [
                'conversation_id' => $conversation->id,
                'to_agent_id' => $toAgentId,
                'to_agent_name' => $toAgent->nickname ?: $toAgent->username,
                'transfer_type' => $transferType,
                'reason' => $reason,
            ],
        ]);

        // 通知新客服：会话已转入
        $webSocketService->sendToAgent($toAgentId, [
            'type' => 'conversation_assigned',
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'status' => $conversation->status,
                    'unread_count' => $unreadCount,
                    'customer' => $conversation->customer ? [
                        'id' => $conversation->customer->id,
                        'uuid' => $conversation->customer->uuid,
                    ] : null,
                ],
                'messages' => $messages,
                'is_transfer' => true,
                'from_agent_id' => $fromAgentId,
                'system_message' => $systemMessage ? [
                    'id' => $systemMessage->id,
                    'content' => $systemMessage->content,
                    'sender_type' => $systemMessage->sender_type,
                    'created_at' => $systemMessage->created_at->format('Y-m-d H:i:s'),
                ] : null,
            ],
        ]);

        // 通知客户：客服已更换
        if ($conversation->customer) {
            $webSocketService->sendToCustomer($conversation->customer->uuid, [
                'type' => 'agent_changed',
                'data' => [
                    'message' => '您的会话已转接给其他客服，将继续为您服务。',
                ],
            ]);
        }
    }

    /**
     * 获取超时未回复的会话
     *
     * 【超时判断逻辑】
     * 1. 会话状态为"进行中"
     * 2. 已分配客服
     * 3. 客户发过消息
     * 4. 客户最后消息时间超过超时时间
     * 5. 客服没有回复，或回复时间早于客户最后消息
     *
     * @param int $timeoutMinutes 超时分钟数，默认10分钟
     * @return array 超时会话列表
     */
    public function getTimeoutConversations(int $timeoutMinutes = 10): array
    {
        // 计算超时时间点
        $timeoutTime = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

        return Conversation::query()
            ->where('status', ConversationStatus::ACTIVE)
            ->whereNotNull('agent_id')
            ->whereNotNull('last_customer_msg_at')
            // 客户最后消息时间超过超时时间
            ->where('last_customer_msg_at', '<', $timeoutTime)
            ->where(function ($query) {
                // 客服从未回复，或回复时间早于客户最后消息
                $query->whereNull('last_agent_reply_at')
                    ->orWhereRaw('last_agent_reply_at < last_customer_msg_at');
            })
            ->get()
            ->toArray();
    }

    /**
     * 自动转移超时会话
     *
     * 【定时任务调用】
     * 由 AutoTransferTask 定时任务调用，检查并转移超时会话。
     *
     * 【转移流程】
     * 1. 查找所有超时未回复的会话
     * 2. 为每个会话找一个可用客服（排除当前客服）
     * 3. 执行转移
     *
     * @param int $timeoutMinutes 超时分钟数
     * @return array ['transferred' => 成功数, 'failed' => 失败数]
     */
    public function autoTransferTimeoutConversations(int $timeoutMinutes = 10): array
    {
        $result = ['transferred' => 0, 'failed' => 0];

        $timeoutTime = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

        // 查找超时未回复的会话
        $conversations = Conversation::query()
            ->where('status', ConversationStatus::ACTIVE)
            ->whereNotNull('agent_id')
            ->whereNotNull('last_customer_msg_at')
            ->where(function ($query) {
                $query->whereNull('last_agent_reply_at')
                    ->orWhereRaw('last_agent_reply_at < last_customer_msg_at');
            })
            ->where('last_customer_msg_at', '<', $timeoutTime)
            ->get();

        // 遍历每个超时会话
        foreach ($conversations as $conversation) {
            // 查找其他可用客服（排除当前客服）
            $availableAgentId = $this->agentService->getAvailableAgentExcept($conversation->agent_id);

            if (!$availableAgentId) {
                // 没有可用客服，转移失败
                $result['failed']++;
                continue;
            }

            // 执行转移
            $transferResult = $this->transfer(
                $conversation->id,
                $availableAgentId,
                \App\Model\ConversationTransfer::TYPE_AUTO,
                null,
                "客户消息超过{$timeoutMinutes}分钟未回复，系统自动转移"
            );

            if ($transferResult['success']) {
                $result['transferred']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 更新客服最后回复时间
     *
     * 【调用时机】
     * 客服发送消息时调用，用于超时检测。
     *
     * @param int $conversationId 会话ID
     */
    public function updateAgentReplyTime(int $conversationId): void
    {
        Conversation::where('id', $conversationId)->update([
            'last_agent_reply_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 更新客户最后消息时间
     *
     * 【调用时机】
     * 客户发送消息时调用，用于超时检测。
     *
     * @param int $conversationId 会话ID
     */
    public function updateCustomerMessageTime(int $conversationId): void
    {
        Conversation::where('id', $conversationId)->update([
            'last_customer_msg_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 获取会话的转移历史记录
     *
     * @param int $conversationId 会话ID
     * @return array 转移记录列表
     */
    public function getTransferHistory(int $conversationId): array
    {
        return \App\Model\ConversationTransfer::query()
            ->with(['fromAgent', 'toAgent', 'operator'])
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * 获取所有启用的客服列表
     *
     * 【使用场景】
     * 会话转移时，显示可选的目标客服列表。
     *
     * @return array 客服列表
     */
    public function getAgentList(): array
    {
        return \App\Model\Agent::query()
            ->where('status', 1)
            ->select(['id', 'username', 'nickname'])
            ->get()
            ->toArray();
    }

    /**
     * 获取所有会话列表（超级管理员上帝视角）
     *
     * 【使用场景】
     * 超级管理员查看系统中所有会话，用于监控和管理。
     *
     * @param int|null $status 筛选状态（可选）
     * @param int|null $agentId 筛选客服ID（可选）
     * @return array 会话列表
     */
    public function getAllConversations(?int $status = null, ?int $agentId = null): array
    {
        // 构建查询
        $query = Conversation::query()
            ->with(['customer', 'agent']);  // 预加载客户和客服信息

        // 按状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        // 按客服筛选
        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        }

        // 按最后消息时间倒序
        $conversations = $query->orderBy('last_message_at', 'desc')->get();

        // 获取会话ID列表
        $conversationIds = $conversations->pluck('id')->toArray();

        if (empty($conversationIds)) {
            return [];
        }

        // 批量获取每个会话的最后一条消息
        $lastMessages = $this->getLastMessages($conversationIds);

        // 批量获取每个会话的未读消息数
        $unreadCounts = $this->getUnreadCounts($conversationIds);

        // 组装返回数据
        $result = [];
        foreach ($conversations as $conversation) {
            $convArray = $conversation->toArray();
            $convId = $conversation->id;

            // 添加最后一条消息
            $convArray['last_message'] = $lastMessages[$convId] ?? null;

            // 添加未读数量
            $convArray['unread_count'] = $unreadCounts[$convId] ?? 0;

            $result[] = $convArray;
        }

        return $result;
    }
}

