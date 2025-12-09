<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Model\Agent;
use App\Model\Conversation;
use App\Model\Customer;
use App\Model\SystemConfig;
use Hyperf\Redis\Redis;
use Hyperf\WebSocketServer\Sender;

/**
 * ============================================================================
 * WebSocket服务类 - 处理实时消息的核心服务
 * ============================================================================
 *
 * 【什么是WebSocket？】
 * WebSocket是一种网络通信协议，允许服务器主动向客户端推送消息。
 * 传统HTTP只能客户端请求、服务器响应，而WebSocket可以双向通信。
 *
 * 【本服务的职责】
 * 1. 消息路由：将消息发送给正确的接收者
 * 2. 消息处理：处理客户和客服发送的消息
 * 3. 状态同步：同步打字状态、已读状态等
 * 4. 离线消息：客户重新上线时推送离线消息
 *
 * 【消息类型】
 * - new_message：新消息
 * - message_sent：消息发送确认
 * - typing：打字状态
 * - messages_read：消息已读
 * - conversation_assigned：会话分配
 * - agent_assigned：客服接入通知
 * - queue_notice：排队通知
 * - offline_messages：离线消息
 *
 * 【FD是什么？】
 * FD(File Descriptor)是文件描述符，在WebSocket中代表一个连接。
 * 每个客户端连接都有一个唯一的FD，通过FD可以向该客户端发送消息。
 */
class WebSocketService
{
    /**
     * 构造函数 - 依赖注入
     *
     * @param Sender $sender Hyperf的WebSocket发送器
     * @param Redis $redis Redis客户端
     * @param AgentService $agentService 客服服务
     * @param CustomerService $customerService 客户服务
     * @param ConversationService $conversationService 会话服务
     * @param MessageService $messageService 消息服务
     */
    public function __construct(
        protected Sender $sender,
        protected Redis $redis,
        protected AgentService $agentService,
        protected CustomerService $customerService,
        protected ConversationService $conversationService,
        protected MessageService $messageService
    ) {
    }

    /**
     * 客服上线后处理等待中的会话
     *
     * 【调用时机】
     * 客服WebSocket连接成功后调用。
     *
     * 【处理逻辑】
     * 1. 查找所有等待中的会话
     * 2. 尝试分配给当前客服
     * 3. 通知客服和客户
     *
     * @param int $agentId 客服ID
     */
    public function handleAgentOnline(int $agentId): void
    {
        // 先检查客服是否有空余容量
        $agent = Agent::find($agentId);
        if (!$agent) {
            return;
        }

        // 管理员不参与接待
        if ($agent->is_admin === 1) {
            return;
        }

        // 获取当前进行中的会话数
        $currentSessionCount = $this->agentService->getActiveSessionCount($agentId);
        $availableSlots = $agent->max_sessions - $currentSessionCount;

        if ($availableSlots <= 0) {
            // 客服已满载，不分配新会话
            return;
        }

        // 查找等待中的会话（按创建时间排序，先来先服务）
        $waitingConversations = Conversation::query()
            ->with(['customer', 'messages' => function ($q) {
                $q->orderBy('id', 'desc')->limit(5);  // 只加载最近5条消息
            }])
            ->where('status', ConversationStatus::WAITING)
            ->whereNull('agent_id')
            ->orderBy('created_at', 'asc')
            ->limit($availableSlots)  // 只取可分配的数量
            ->get();

        // 遍历等待中的会话
        foreach ($waitingConversations as $conversation) {
            // 再次检查容量（防止循环中超额分配）
            $currentCount = $this->agentService->getActiveSessionCount($agentId);
            if ($currentCount >= $agent->max_sessions) {
                break;
            }

            // 尝试分配给当前客服
            if ($this->conversationService->assignAgentTo($conversation, $agentId)) {
                // 通知客服有新会话
                $this->sendToAgent($agentId, [
                    'type' => 'conversation_assigned',
                    'data' => [
                        'conversation_id' => $conversation->id,
                        'customer' => [
                            'id' => $conversation->customer->id ?? 0,
                            'uuid' => $conversation->customer->uuid ?? '',
                            'ip' => $conversation->customer->ip ?? '',
                        ],
                        'messages' => $conversation->messages->map(function ($msg) {
                            return [
                                'id' => $msg->id,
                                'sender_type' => $msg->sender_type,
                                'content' => $msg->content,
                                'created_at' => $msg->created_at,
                            ];
                        })->toArray(),
                    ],
                ]);

                // 通知客户已分配客服
                if ($conversation->customer) {
                    $this->sendToCustomer($conversation->customer->uuid, [
                        'type' => 'agent_assigned',
                        'data' => [
                            'message' => SystemConfig::getText('msg_agent_assigned'),
                            'conversation_id' => $conversation->id,
                        ],
                    ]);
                }

                logger()->info("Waiting conversation assigned to agent", [
                    'conversation_id' => $conversation->id,
                    'agent_id' => $agentId,
                ]);
            }
        }
    }

    /**
     * 发送消息到指定FD
     *
     * 【底层方法】
     * 这是最底层的发送方法，其他发送方法都调用它。
     *
     * @param int $fd 文件描述符（连接标识）
     * @param array $data 要发送的数据
     * @return bool 是否发送成功
     */
    public function sendToFd(int $fd, array $data): bool
    {
        try {
            // 将数组转为JSON字符串发送
            $this->sender->push($fd, json_encode($data, JSON_UNESCAPED_UNICODE));
            return true;
        } catch (\Throwable $e) {
            logger()->error("Send to fd failed: {$e->getMessage()}", ['fd' => $fd]);
            return false;
        }
    }

    /**
     * 发送消息到客服
     *
     * @param int $agentId 客服ID
     * @param array $data 要发送的数据
     * @return bool 是否发送成功
     */
    public function sendToAgent(int $agentId, array $data): bool
    {
        // 从Redis获取客服的FD
        $fd = $this->agentService->getConnection($agentId);
        if (!$fd) {
            return false;  // 客服不在线
        }
        return $this->sendToFd($fd, $data);
    }

    /**
     * 发送消息到客户
     *
     * @param string $uuid 客户UUID
     * @param array $data 要发送的数据
     * @return bool 是否发送成功
     */
    public function sendToCustomer(string $uuid, array $data): bool
    {
        // 从Redis获取客户的FD
        $fd = $this->customerService->getConnection($uuid);
        if (!$fd) {
            return false;  // 客户不在线
        }
        return $this->sendToFd($fd, $data);
    }

    /**
     * 推送离线消息给客户
     *
     * 【调用时机】
     * 客户WebSocket连接成功后调用。
     *
     * 【离线消息】
     * 客户离线期间，客服发送的消息会保存在数据库。
     * 客户重新上线后，推送这些未读消息。
     *
     * @param Customer $customer 客户对象
     */
    public function pushOfflineMessages(Customer $customer): void
    {
        // 查找客户的活跃会话
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ConversationStatus::WAITING, ConversationStatus::ACTIVE])
            ->first();

        if (!$conversation) {
            return;
        }

        // 获取未读消息（客服发送的，客户未读的）
        $unreadMessages = $this->messageService->getUnreadMessages($conversation->id, SenderType::CUSTOMER());

        if (empty($unreadMessages)) {
            return;
        }

        // 推送离线消息
        $this->sendToCustomer($customer->uuid, [
            'type' => 'offline_messages',
            'data' => [
                'conversation_id' => $conversation->id,
                'messages' => $unreadMessages,
            ],
        ]);

        logger()->info("Pushed offline messages to customer", [
            'uuid' => $customer->uuid,
            'count' => count($unreadMessages),
        ]);
    }

    /**
     * 处理客户发送的消息
     *
     * 【消息流程】
     * 1. 获取或创建会话
     * 2. 保存消息到数据库
     * 3. 推送给客服（如果有）
     * 4. 回复确认给客户
     *
     * @param string $uuid 客户UUID
     * @param array $data 消息数据，包含 content 字段
     */
    public function handleCustomerMessage(string $uuid, array $data): void
    {
        // 根据UUID查找客户
        $customer = Customer::where('uuid', $uuid)->first();
        if (!$customer) {
            return;
        }

        // 获取或创建会话
        $conversation = $this->conversationService->getOrCreateForCustomer($customer);
        $isNewConversation = $conversation->wasRecentlyCreated;

        // 保存消息到数据库
        $message = $this->messageService->create(
            $conversation->id,
            SenderType::CUSTOMER(),
            $customer->id,
            $data['content'] ?? ''
        );

        // 构建消息数据（用于推送）
        $msgData = [
            'type' => 'new_message',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $conversation->id,
                'sender_type' => SenderType::CUSTOMER,
                'sender_id' => $customer->id,
                'content' => $message->content,
                'content_type' => $message->content_type,
                'created_at' => $message->created_at,
            ],
        ];

        // 推送给客服
        if ($conversation->agent_id) {
            $this->sendToAgent($conversation->agent_id, $msgData);

            // 如果是新会话，通知客服
            if ($isNewConversation) {
                $this->sendToAgent($conversation->agent_id, [
                    'type' => 'conversation_assigned',
                    'data' => [
                        'conversation_id' => $conversation->id,
                        'customer' => [
                            'id' => $customer->id,
                            'uuid' => $customer->uuid,
                            'ip' => $customer->ip,
                        ],
                    ],
                ]);
            }
        } else {
            // 没有客服在线，通知客户排队等待
            $this->sendToCustomer($uuid, [
                'type' => 'queue_notice',
                'data' => [
                    'message' => SystemConfig::getText('msg_queue_waiting'),
                    'conversation_id' => $conversation->id,
                ],
            ]);
        }

        // 回复确认给客户（让客户知道消息已发送成功）
        $this->sendToCustomer($uuid, [
            'type' => 'message_sent',
            'data' => $msgData['data'],
        ]);

        // 更新客户最后消息时间（用于超时自动转移判断）
        $this->conversationService->updateCustomerMessageTime($conversation->id);
    }

    /**
     * 处理客服发送的消息
     *
     * 【消息流程】
     * 1. 验证会话归属
     * 2. 保存消息到数据库
     * 3. 推送给客户
     * 4. 回复确认给客服
     *
     * @param int $agentId 客服ID
     * @param array $data 消息数据，包含 conversation_id 和 content 字段
     */
    public function handleAgentMessage(int $agentId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? 0;
        $content = $data['content'] ?? '';

        // 参数验证
        if (!$conversationId || !$content) {
            return;
        }

        // 验证会话归属（只能给自己的会话发消息）
        $conversation = Conversation::with('customer')->find($conversationId);
        if (!$conversation || $conversation->agent_id !== $agentId) {
            return;
        }

        // 保存消息到数据库
        $message = $this->messageService->create(
            $conversationId,
            SenderType::AGENT(),
            $agentId,
            $content
        );

        // 构建消息数据
        $msgData = [
            'type' => 'new_message',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $conversationId,
                'sender_type' => SenderType::AGENT,
                'sender_id' => $agentId,
                'content' => $message->content,
                'content_type' => $message->content_type,
                'created_at' => $message->created_at,
            ],
        ];

        // 推送给客户
        if ($conversation->customer) {
            $this->sendToCustomer($conversation->customer->uuid, $msgData);
        }

        // 回复确认给客服
        $this->sendToAgent($agentId, [
            'type' => 'message_sent',
            'data' => $msgData['data'],
        ]);

        // 更新客服最后回复时间（用于超时自动转移判断）
        $this->conversationService->updateAgentReplyTime($conversationId);
    }

    /**
     * 处理客服打字状态
     *
     * 【功能说明】
     * 当客服在输入框打字时，通知客户"客服正在输入..."
     *
     * @param int $agentId 客服ID
     * @param array $data 包含 conversation_id 和 is_typing 字段
     */
    public function handleTyping(int $agentId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? 0;
        if (!$conversationId) {
            return;
        }

        // 验证会话归属
        $conversation = Conversation::with('customer')->find($conversationId);
        if (!$conversation || $conversation->agent_id !== $agentId) {
            return;
        }

        // 推送给客户
        if ($conversation->customer) {
            $this->sendToCustomer($conversation->customer->uuid, [
                'type' => 'typing',
                'data' => [
                    'conversation_id' => $conversationId,
                    'is_typing' => $data['is_typing'] ?? true,
                ],
            ]);
        }
    }

    /**
     * 处理客户打字状态
     *
     * 【功能说明】
     * 当客户在输入框打字时，通知客服"客户正在输入..."
     *
     * @param string $uuid 客户UUID
     * @param array $data 包含 is_typing 字段
     */
    public function handleCustomerTyping(string $uuid, array $data): void
    {
        $customer = Customer::where('uuid', $uuid)->first();
        if (!$customer) {
            return;
        }

        // 查找活跃的会话
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ConversationStatus::WAITING, ConversationStatus::ACTIVE])
            ->first();

        if (!$conversation || !$conversation->agent_id) {
            return;
        }

        // 推送给客服
        $this->sendToAgent($conversation->agent_id, [
            'type' => 'typing',
            'data' => [
                'conversation_id' => $conversation->id,
                'is_typing' => $data['is_typing'] ?? true,
            ],
        ]);
    }

    /**
     * 处理客服标记已读
     *
     * 【功能说明】
     * 客服点击会话时，标记客户消息为已读，并通知客户。
     * 客户端会显示 ✓✓ 表示消息已读。
     *
     * @param int $agentId 客服ID
     * @param array $data 包含 conversation_id 字段
     */
    public function handleAgentRead(int $agentId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? 0;
        if (!$conversationId) {
            return;
        }

        // 验证会话归属
        $conversation = Conversation::with('customer')->find($conversationId);
        if (!$conversation || $conversation->agent_id !== $agentId) {
            return;
        }

        // 标记消息为已读
        $this->messageService->markAsRead($conversationId, SenderType::AGENT());

        // 通知客户消息已读
        if ($conversation->customer) {
            $this->sendToCustomer($conversation->customer->uuid, [
                'type' => 'messages_read',
                'data' => [
                    'conversation_id' => $conversationId,
                    'reader' => 'agent',
                ],
            ]);
        }
    }

    /**
     * 处理客户标记已读
     *
     * 【功能说明】
     * 客户打开聊天窗口时，标记客服消息为已读，并通知客服。
     * 客服端会显示 ✓✓ 表示消息已读。
     *
     * @param string $uuid 客户UUID
     * @param array $data 包含 conversation_id 字段
     */
    public function handleCustomerRead(string $uuid, array $data): void
    {
        $customer = Customer::where('uuid', $uuid)->first();
        if (!$customer) {
            return;
        }

        $conversationId = $data['conversation_id'] ?? 0;
        if (!$conversationId) {
            return;
        }

        // 验证会话归属
        $conversation = Conversation::find($conversationId);
        if (!$conversation || $conversation->customer_id !== $customer->id) {
            return;
        }

        // 标记消息为已读
        $this->messageService->markAsRead($conversationId, SenderType::CUSTOMER());

        // 通知客服消息已读
        if ($conversation->agent_id) {
            $this->sendToAgent($conversation->agent_id, [
                'type' => 'messages_read',
                'data' => [
                    'conversation_id' => $conversationId,
                    'reader' => 'customer',
                ],
            ]);
        }
    }
}

