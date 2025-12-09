<?php

declare(strict_types=1);

namespace App\Model;

use App\Enums\ContentType;
use App\Enums\SenderType;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * ============================================================================
 * 消息模型 - 对应数据库 message 表
 * ============================================================================
 *
 * 【作用说明】
 * 消息模型用来存储聊天中的每一条消息。
 * 无论是客户发的、客服发的、还是系统自动生成的，都存在这张表里。
 *
 * 【消息的显示】
 * 前端根据 sender_type 来决定消息的显示位置：
 * - 客户消息：显示在左边
 * - 客服消息：显示在右边
 * - 系统消息：显示在中间，灰色小字
 *
 * 【数据库字段说明】
 * @property int $id                 主键ID
 * @property int $conversation_id    所属会话ID
 * @property int $sender_type        发送者类型：1=客户, 2=客服, 3=系统
 * @property int $sender_id          发送者ID（客户ID或客服ID，系统消息为0）
 * @property int $content_type       内容类型：1=文本, 2=图片
 * @property string $content         消息内容（文本或图片URL）
 * @property int $is_read            是否已读：0=未读, 1=已读
 * @property string $created_at      发送时间
 * @property-read Conversation $conversation 所属会话
 */
class Message extends Model
{
    /**
     * 指定对应的数据库表名
     */
    protected ?string $table = 'message';

    /**
     * 禁用 updated_at 字段
     * 消息一旦发送就不应该被修改
     */
    public const UPDATED_AT = null;

    /**
     * 允许批量赋值的字段
     */
    protected array $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'content_type',
        'content',
        'is_read',
    ];

    /**
     * 字段类型转换
     */
    protected array $casts = [
        'id' => 'integer',
        'conversation_id' => 'integer',
        'sender_type' => 'integer',
        'sender_id' => 'integer',
        'content_type' => 'integer',
        'is_read' => 'integer',
    ];

    /**
     * 定义与会话的关联关系
     *
     * 【关系类型】BelongsTo（属于）
     * 一条消息属于一个会话。
     *
     * @return BelongsTo 关联对象
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'id');
    }

    /**
     * 判断消息是否已读
     *
     * 【已读的含义】
     * - 客户发的消息：客服已阅读
     * - 客服发的消息：客户已阅读
     *
     * @return bool true=已读
     */
    public function isRead(): bool
    {
        return $this->is_read === 1;
    }

    /**
     * 标记消息为已读
     *
     * @return bool 是否成功
     */
    public function markAsRead(): bool
    {
        return $this->update(['is_read' => 1]);
    }

    /**
     * 获取发送者类型的中文名称
     *
     * @return string "客户" / "客服" / "系统"
     */
    public function getSenderTypeLabel(): string
    {
        return SenderType::from($this->sender_type)->label();
    }

    /**
     * 获取内容类型的中文名称
     *
     * @return string "文本" / "图片"
     */
    public function getContentTypeLabel(): string
    {
        return ContentType::from($this->content_type)->label();
    }

    /**
     * 判断消息是否来自客户
     *
     * @return bool true=客户发送的消息
     */
    public function isFromCustomer(): bool
    {
        return $this->sender_type === SenderType::CUSTOMER->value;
    }

    /**
     * 判断消息是否来自客服
     *
     * @return bool true=客服发送的消息
     */
    public function isFromAgent(): bool
    {
        return $this->sender_type === SenderType::AGENT->value;
    }
}

