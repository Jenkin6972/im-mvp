<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ============================================================================
 * 消息发送者类型枚举类
 * ============================================================================
 *
 * 【作用说明】
 * 这个类用来标识每条消息是谁发送的。在聊天界面中，我们需要知道每条消息
 * 是客户发的、还是客服发的、还是系统自动发的，这样才能正确显示消息样式。
 *
 * 【类型说明】
 * - CUSTOMER (客户)：访问网站的用户发送的消息
 * - AGENT (客服)：客服人员发送的消息
 * - SYSTEM (系统)：系统自动生成的消息，比如"会话已转移"
 *
 * 【前端显示区别】
 * - 客户消息：显示在聊天框左边
 * - 客服消息：显示在聊天框右边
 * - 系统消息：显示在聊天框中间，灰色小字
 *
 * 【为什么需要系统消息？】
 * 有些操作需要通知用户，但不是任何人发的，比如：
 * - "会话已从客服A转移至客服B"
 * - "客服正在输入中..."
 * - "会话已关闭"
 */
class SenderType
{
    /**
     * 客户发送的消息
     *
     * 【说明】
     * - sender_id 字段存储的是 customer 表的 id
     * - 前端显示在聊天框左边，带客户头像
     */
    public const CUSTOMER = 1;

    /**
     * 客服发送的消息
     *
     * 【说明】
     * - sender_id 字段存储的是 agent 表的 id
     * - 前端显示在聊天框右边，带客服头像
     */
    public const AGENT = 2;

    /**
     * 系统自动发送的消息
     *
     * 【说明】
     * - sender_id 字段通常为 0
     * - 前端显示在聊天框中间，灰色小字，无头像
     * - 不会在客户端显示（只在客服端显示）
     *
     * 【使用场景】
     * - 会话转移通知
     * - 超时提醒
     * - 其他系统通知
     */
    public const SYSTEM = 3;

    /**
     * 当前类型的数值
     * @var int
     */
    public int $value;

    /**
     * 构造函数 - 创建一个发送者类型对象
     *
     * @param int $value 类型数值(1=客户, 2=客服, 3=系统)
     */
    public function __construct(int $value)
    {
        $this->value = $value;
    }

    /**
     * 从数值创建类型对象
     *
     * @param int $value 类型数值
     * @return self 类型对象
     */
    public static function from(int $value): self
    {
        return new self($value);
    }

    /**
     * 创建"客户"类型对象的快捷方法
     *
     * @return self 客户类型对象
     */
    public static function CUSTOMER(): self
    {
        return new self(self::CUSTOMER);
    }

    /**
     * 创建"客服"类型对象的快捷方法
     *
     * @return self 客服类型对象
     */
    public static function AGENT(): self
    {
        return new self(self::AGENT);
    }

    /**
     * 创建"系统"类型对象的快捷方法
     *
     * @return self 系统类型对象
     */
    public static function SYSTEM(): self
    {
        return new self(self::SYSTEM);
    }

    /**
     * 获取类型的中文名称
     *
     * @return string 类型的中文名称
     */
    public function label(): string
    {
        return match($this->value) {
            self::CUSTOMER => '客户',
            self::AGENT => '客服',
            self::SYSTEM => '系统',
            default => '未知',
        };
    }
}

