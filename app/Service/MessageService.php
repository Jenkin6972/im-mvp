<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ContentType;
use App\Enums\SenderType;
use App\Model\Message;

/**
 * ============================================================================
 * 消息服务类 - 处理聊天消息的创建、查询、已读状态等
 * ============================================================================
 *
 * 【本服务的职责】
 * 1. 消息创建：保存聊天消息到数据库
 * 2. 历史查询：获取会话的历史消息
 * 3. 已读管理：标记消息为已读，统计未读数
 * 4. 系统消息：创建系统通知消息
 *
 * 【消息类型】
 * - 客户消息：客户发送的消息
 * - 客服消息：客服发送的消息
 * - 系统消息：系统自动生成的消息（如转移通知）
 */
class MessageService
{
    /**
     * 构造函数 - 依赖注入会话服务
     */
    public function __construct(
        protected ConversationService $conversationService
    ) {
    }

    /**
     * 创建消息
     *
     * 【核心方法 - 保存消息】
     * 无论是客户发的、客服发的、还是系统生成的，都通过此方法保存。
     *
     * @param int $conversationId 会话ID
     * @param SenderType $senderType 发送者类型
     * @param int $senderId 发送者ID（系统消息为0）
     * @param string $content 消息内容
     * @param ContentType|null $contentType 内容类型，默认文本
     * @return Message 创建的消息对象
     */
    public function create(
        int $conversationId,
        SenderType $senderType,
        int $senderId,
        string $content,
        ?ContentType $contentType = null
    ): Message {
        // 默认为文本消息
        if ($contentType === null) {
            $contentType = ContentType::TEXT();
        }

        // 创建消息记录
        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_type' => $senderType->value,
            'sender_id' => $senderId,
            'content_type' => $contentType->value,
            'content' => $content,
            'is_read' => 0,  // 新消息默认未读
        ]);

        // 更新会话的最后消息时间（用于排序）
        $this->conversationService->updateLastMessageTime($conversationId);

        return $message;
    }

    /**
     * 获取会话历史消息
     *
     * 【分页加载】
     * 使用 beforeId 实现向上滚动加载更多历史消息。
     *
     * 【客户端过滤】
     * 客户端不显示转移相关的系统消息，避免客户困惑。
     *
     * @param int $conversationId 会话ID
     * @param int $limit 获取条数，默认50
     * @param int|null $beforeId 获取此ID之前的消息（用于分页）
     * @param bool $forCustomer 是否为客户端获取
     * @return array 消息列表
     */
    public function getHistory(int $conversationId, int $limit = 50, ?int $beforeId = null, bool $forCustomer = false): array
    {
        $query = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'desc')  // 按ID倒序
            ->limit($limit);

        // 分页：获取指定ID之前的消息
        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        // 客户端不显示转移相关的系统消息
        if ($forCustomer) {
            $query->where(function ($q) {
                $q->where('sender_type', '!=', SenderType::SYSTEM)
                  ->orWhere('content', 'not like', '%转移%');
            });
        }

        // 反转顺序，让旧消息在前面，并格式化时间为 ISO 格式
        return $query->get()->reverse()->values()->map(function ($msg) {
            $arr = $msg->toArray();
            $arr['created_at'] = $msg->created_at->toIso8601String();
            return $arr;
        })->toArray();
    }

    /**
     * 获取未读消息列表
     *
     * 【已读逻辑】
     * - 客服阅读：获取客户发的未读消息
     * - 客户阅读：获取客服发的未读消息
     *
     * @param int $conversationId 会话ID
     * @param SenderType $readerType 阅读者类型
     * @return array 未读消息列表
     */
    public function getUnreadMessages(int $conversationId, SenderType $readerType): array
    {
        // 获取对方发送的未读消息
        $senderType = $readerType->value === SenderType::AGENT
            ? SenderType::CUSTOMER  // 客服阅读 → 获取客户消息
            : SenderType::AGENT;    // 客户阅读 → 获取客服消息

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('sender_type', $senderType)
            ->where('is_read', 0)
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * 标记消息为已读
     *
     * 【调用时机】
     * - 客服点击会话时，标记客户消息为已读
     * - 客户打开聊天窗口时，标记客服消息为已读
     *
     * @param int $conversationId 会话ID
     * @param SenderType $readerType 阅读者类型
     * @return int 标记的消息数量
     */
    public function markAsRead(int $conversationId, SenderType $readerType): int
    {
        // 标记对方发送的消息为已读
        $senderType = $readerType->value === SenderType::AGENT
            ? SenderType::CUSTOMER
            : SenderType::AGENT;

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('sender_type', $senderType)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);
    }

    /**
     * 获取未读消息数
     *
     * @param int $conversationId 会话ID
     * @param SenderType $readerType 阅读者类型
     * @return int 未读消息数量
     */
    public function getUnreadCount(int $conversationId, SenderType $readerType): int
    {
        $senderType = $readerType->value === SenderType::AGENT
            ? SenderType::CUSTOMER
            : SenderType::AGENT;

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('sender_type', $senderType)
            ->where('is_read', 0)
            ->count();
    }

    /**
     * 创建系统消息
     *
     * 【使用场景】
     * - 会话转移通知
     * - 客服上下线通知
     * - 其他系统提示
     *
     * @param int $conversationId 会话ID
     * @param string $content 消息内容
     * @return Message 创建的消息对象
     */
    public function createSystemMessage(int $conversationId, string $content): Message
    {
        return $this->create(
            $conversationId,
            SenderType::SYSTEM(),
            0,  // 系统消息没有发送者ID
            $content
        );
    }
}

