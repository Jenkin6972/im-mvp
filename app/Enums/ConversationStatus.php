<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ============================================================================
 * 会话状态枚举类
 * ============================================================================
 *
 * 【作用说明】
 * 这个类用来定义客服会话的状态。一个会话就是客户和客服之间的一次完整对话，
 * 从客户发起咨询开始，到客服关闭会话结束。
 *
 * 【会话生命周期】
 *
 *   客户发起咨询
 *        ↓
 *   ┌─────────────┐
 *   │  WAITING    │  等待分配 - 刚创建，还没有客服接待
 *   │  (待分配)   │
 *   └─────┬───────┘
 *         ↓ 系统自动分配给在线客服
 *   ┌─────────────┐
 *   │  ACTIVE     │  进行中 - 客服正在与客户沟通
 *   │  (进行中)   │
 *   └─────┬───────┘
 *         ↓ 客服点击"关闭会话"按钮
 *   ┌─────────────┐
 *   │  CLOSED     │  已完成 - 会话结束，进入历史记录
 *   │  (已完成)   │
 *   └─────────────┘
 *
 * 【特殊情况】
 * - 会话转移：会话从一个客服转给另一个客服，状态不变(还是 ACTIVE)
 * - 超时转移：如果客服长时间不回复，系统会自动转给其他客服
 */
class ConversationStatus
{
    /**
     * 待分配状态 - 会话刚创建，等待客服接待
     *
     * 【触发条件】
     * - 客户首次发送消息时，系统创建会话，初始状态就是 WAITING
     *
     * 【后续操作】
     * - 系统会自动查找在线客服并分配会话
     * - 如果没有在线客服，会话会一直保持 WAITING 状态
     */
    public const WAITING = 0;

    /**
     * 进行中状态 - 客服正在接待客户
     *
     * 【触发条件】
     * - 会话被分配给客服后，状态变为 ACTIVE
     *
     * 【特点】
     * - 客户和客服可以正常收发消息
     * - 客服可以在这个状态下转移会话给其他客服
     */
    public const ACTIVE = 1;

    /**
     * 已完成状态 - 会话已关闭
     *
     * 【触发条件】
     * - 客服点击"关闭会话"按钮
     *
     * 【特点】
     * - 会话进入历史记录，可以在"历史会话"中查看
     * - 客户如果再次发消息，会创建新的会话
     */
    public const CLOSED = 2;

    /**
     * 当前状态的数值
     * @var int
     */
    public int $value;

    /**
     * 构造函数 - 创建一个状态对象
     *
     * @param int $value 状态数值(0=待分配, 1=进行中, 2=已完成)
     */
    public function __construct(int $value)
    {
        $this->value = $value;
    }

    /**
     * 从数值创建状态对象
     *
     * @param int $value 状态数值
     * @return self 状态对象
     */
    public static function from(int $value): self
    {
        return new self($value);
    }

    /**
     * 创建"待分配"状态对象的快捷方法
     *
     * @return self 待分配状态对象
     */
    public static function WAITING(): self
    {
        return new self(self::WAITING);
    }

    /**
     * 创建"进行中"状态对象的快捷方法
     *
     * @return self 进行中状态对象
     */
    public static function ACTIVE(): self
    {
        return new self(self::ACTIVE);
    }

    /**
     * 创建"已完成"状态对象的快捷方法
     *
     * @return self 已完成状态对象
     */
    public static function CLOSED(): self
    {
        return new self(self::CLOSED);
    }

    /**
     * 获取状态的中文名称
     *
     * @return string 状态的中文名称
     */
    public function label(): string
    {
        return match($this->value) {
            self::WAITING => '待分配',
            self::ACTIVE => '进行中',
            self::CLOSED => '已完成',
            default => '未知',
        };
    }
}

