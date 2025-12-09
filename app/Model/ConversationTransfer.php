<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * ============================================================================
 * 会话转移记录模型 - 对应数据库 conversation_transfer 表
 * ============================================================================
 *
 * 【作用说明】
 * 这个模型用来记录会话转移的历史。当一个会话从A客服转移到B客服时，
 * 就会在这个表里插入一条记录，方便后续追溯和统计。
 *
 * 【什么时候会发生转移？】
 * 1. 手动转移：管理员或客服主动将会话转给其他客服
 * 2. 超时转移：客服长时间（如5分钟）没有回复，系统自动转给其他客服
 *
 * 【记录的作用】
 * - 追溯：查看会话经过了哪些客服的手
 * - 统计：分析转移原因，优化客服分配策略
 * - 考核：统计客服的超时转移次数
 *
 * 【数据库字段说明】
 * @property int $id                 主键ID
 * @property int $conversation_id    被转移的会话ID
 * @property int $from_agent_id      原客服ID（从谁手里转出）
 * @property int $to_agent_id        目标客服ID（转给谁）
 * @property int $transfer_type      转移类型：1=手动, 2=超时自动
 * @property int|null $operator_id   操作人ID（手动转移时记录是谁操作的）
 * @property string $reason          转移原因
 * @property string $created_at      转移时间
 * @property-read Conversation $conversation 被转移的会话
 * @property-read Agent $fromAgent   原客服
 * @property-read Agent $toAgent     目标客服
 * @property-read Agent|null $operator 操作人
 */
class ConversationTransfer extends Model
{
    /**
     * 指定对应的数据库表名
     */
    protected ?string $table = 'conversation_transfer';

    /**
     * 禁用 updated_at 字段
     * 转移记录一旦创建就不应该被修改
     */
    public const UPDATED_AT = null;

    /**
     * 转移类型：手动转移
     * 管理员或客服主动发起的转移
     */
    public const TYPE_MANUAL = 1;

    /**
     * 转移类型：超时自动转移
     * 系统定时任务检测到客服超时未回复，自动触发的转移
     */
    public const TYPE_AUTO = 2;

    /**
     * 允许批量赋值的字段
     */
    protected array $fillable = [
        'conversation_id',
        'from_agent_id',
        'to_agent_id',
        'transfer_type',
        'operator_id',
        'reason',
    ];

    /**
     * 字段类型转换
     */
    protected array $casts = [
        'id' => 'integer',
        'conversation_id' => 'integer',
        'from_agent_id' => 'integer',
        'to_agent_id' => 'integer',
        'transfer_type' => 'integer',
        'operator_id' => 'integer',
    ];

    /**
     * 定义与会话的关联关系
     *
     * @return BelongsTo 关联对象
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'id');
    }

    /**
     * 定义与原客服的关联关系
     *
     * 【说明】
     * 获取会话转移前的客服信息
     *
     * @return BelongsTo 关联对象
     */
    public function fromAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'from_agent_id', 'id');
    }

    /**
     * 定义与目标客服的关联关系
     *
     * 【说明】
     * 获取会话转移后的客服信息
     *
     * @return BelongsTo 关联对象
     */
    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id', 'id');
    }

    /**
     * 定义与操作人的关联关系
     *
     * 【说明】
     * - 手动转移：记录是哪个客服/管理员发起的转移
     * - 自动转移：operator_id 为 null
     *
     * @return BelongsTo 关联对象
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'operator_id', 'id');
    }

    /**
     * 获取转移类型的中文名称
     *
     * @return string "手动转移" / "超时自动转移"
     */
    public function getTypeLabel(): string
    {
        return match($this->transfer_type) {
            self::TYPE_MANUAL => '手动转移',
            self::TYPE_AUTO => '超时自动转移',
            default => '未知',
        };
    }
}

