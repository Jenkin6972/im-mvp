<?php

declare(strict_types=1);

namespace App\Model;

use App\Enums\ConversationStatus;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * ============================================================================
 * 会话模型 - 对应数据库 conversation 表
 * ============================================================================
 *
 * 【什么是会话？】
 * 会话(Conversation)就是客户和客服之间的一次完整对话。
 * 类似于微信里的"聊天窗口"，从客户发起咨询开始，到客服关闭会话结束。
 *
 * 【会话的生命周期】
 * 1. 客户发送第一条消息 → 创建会话，状态为"待分配"
 * 2. 系统分配给在线客服 → 状态变为"进行中"
 * 3. 客服点击关闭按钮 → 状态变为"已完成"
 *
 * 【表之间的关系】
 * - 一个会话属于一个客户 (belongsTo Customer)
 * - 一个会话属于一个客服 (belongsTo Agent)
 * - 一个会话有多条消息 (hasMany Message)
 *
 * 【数据库字段说明】
 * @property int $id                    主键ID
 * @property int $customer_id           客户ID，关联 customer 表
 * @property int|null $agent_id         客服ID，关联 agent 表（待分配时为null）
 * @property int $status                状态：0=待分配, 1=进行中, 2=已完成
 * @property string|null $last_message_at       最后一条消息时间
 * @property string|null $last_agent_reply_at   客服最后回复时间（用于判断超时）
 * @property string|null $last_customer_msg_at  客户最后发消息时间
 * @property string $created_at         会话创建时间
 * @property string|null $closed_at     会话关闭时间
 * @property-read Customer $customer    关联的客户对象
 * @property-read Agent|null $agent     关联的客服对象
 * @property-read Message[] $messages   会话中的所有消息
 */
class Conversation extends Model
{
    /**
     * 指定对应的数据库表名
     */
    protected ?string $table = 'conversation';

    /**
     * 禁用 updated_at 字段
     */
    public const UPDATED_AT = null;

    /**
     * 允许批量赋值的字段
     */
    protected array $fillable = [
        'customer_id',
        'agent_id',
        'status',
        'last_message_at',
        'last_agent_reply_at',
        'last_customer_msg_at',
        'closed_at',
    ];

    /**
     * 字段类型转换
     */
    protected array $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'agent_id' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 定义与客户的关联关系
     *
     * 【关系类型】BelongsTo（属于）
     * 一个会话属于一个客户，通过 customer_id 字段关联。
     *
     * 【使用方式】
     * $conversation = Conversation::find(1);
     * $customer = $conversation->customer;  // 获取这个会话的客户信息
     *
     * @return BelongsTo 关联对象
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    /**
     * 定义与客服的关联关系
     *
     * 【关系类型】BelongsTo（属于）
     * 一个会话属于一个客服，通过 agent_id 字段关联。
     * 注意：待分配状态的会话没有客服，agent_id 为 null。
     *
     * @return BelongsTo 关联对象
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    /**
     * 定义与消息的关联关系
     *
     * 【关系类型】HasMany（有多个）
     * 一个会话有多条消息。
     *
     * 【使用方式】
     * $conversation = Conversation::find(1);
     * $messages = $conversation->messages;  // 获取这个会话的所有消息
     *
     * @return HasMany 关联对象
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id', 'id');
    }

    /**
     * 判断会话是否处于待分配状态
     *
     * @return bool true=待分配
     */
    public function isWaiting(): bool
    {
        return $this->status === ConversationStatus::WAITING->value;
    }

    /**
     * 判断会话是否处于进行中状态
     *
     * @return bool true=进行中
     */
    public function isActive(): bool
    {
        return $this->status === ConversationStatus::ACTIVE->value;
    }

    /**
     * 判断会话是否已关闭
     *
     * @return bool true=已关闭
     */
    public function isClosed(): bool
    {
        return $this->status === ConversationStatus::CLOSED->value;
    }

    /**
     * 获取状态的中文名称
     *
     * @return string 状态名称
     */
    public function getStatusLabel(): string
    {
        return ConversationStatus::from($this->status)->label();
    }
}

