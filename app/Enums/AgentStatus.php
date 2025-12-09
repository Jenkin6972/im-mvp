<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ============================================================================
 * 客服在线状态枚举类
 * ============================================================================
 *
 * 【作用说明】
 * 这个类用来定义客服人员的在线状态。就像微信里的"在线"、"离开"状态一样，
 * 客服也有不同的工作状态，系统会根据状态来决定是否给客服分配新的咨询会话。
 *
 * 【状态说明】
 * - ONLINE (在线)：客服正在工作，可以接待客户
 * - OFFLINE (离线)：客服不在线，无法接待客户
 * - BUSY (忙碌)：客服正忙，暂时不接待新客户
 *
 * 【为什么用数字而不是文字？】
 * 数据库存储数字比文字更节省空间，查询速度也更快。
 * 比如存储 1 只需要 1 字节，而存储 "ONLINE" 需要 6 字节。
 *
 * 【PHP 8.0 兼容说明】
 * PHP 8.1 开始支持原生枚举(enum)，但为了兼容 PHP 8.0，
 * 这里使用类(class)来模拟枚举的功能。
 */
class AgentStatus
{
    /**
     * 在线状态 - 客服可以正常接待客户
     * 当客服登录系统后，默认就是这个状态
     */
    public const ONLINE = 1;

    /**
     * 离线状态 - 客服不在线
     * 当客服退出系统或者断开连接后，自动变成这个状态
     */
    public const OFFLINE = 2;

    /**
     * 忙碌状态 - 客服暂时无法接待新客户
     * 客服可以手动设置这个状态，比如去开会、吃饭等
     * 设置后系统不会给该客服分配新的会话
     */
    public const BUSY = 3;

    /**
     * 当前状态的数值
     * @var int
     */
    public int $value;

    /**
     * 构造函数 - 创建一个状态对象
     *
     * @param int $value 状态数值(1=在线, 2=离线, 3=忙碌)
     */
    public function __construct(int $value)
    {
        $this->value = $value;
    }

    /**
     * 从数值创建状态对象
     *
     * 【使用场景】
     * 当从数据库读取到状态数值(比如 1)时，用这个方法转换成状态对象
     *
     * @param int $value 状态数值
     * @return self 状态对象
     *
     * @example
     * $status = AgentStatus::from(1); // 创建一个"在线"状态对象
     */
    public static function from(int $value): self
    {
        return new self($value);
    }

    /**
     * 创建"在线"状态对象的快捷方法
     *
     * @return self 在线状态对象
     *
     * @example
     * $status = AgentStatus::ONLINE(); // 创建一个"在线"状态对象
     */
    public static function ONLINE(): self
    {
        return new self(self::ONLINE);
    }

    /**
     * 创建"离线"状态对象的快捷方法
     *
     * @return self 离线状态对象
     */
    public static function OFFLINE(): self
    {
        return new self(self::OFFLINE);
    }

    /**
     * 创建"忙碌"状态对象的快捷方法
     *
     * @return self 忙碌状态对象
     */
    public static function BUSY(): self
    {
        return new self(self::BUSY);
    }

    /**
     * 获取状态的中文名称
     *
     * 【使用场景】
     * 在前端页面显示时，需要把数字转换成用户能理解的文字
     *
     * @return string 状态的中文名称
     *
     * @example
     * $status = AgentStatus::ONLINE();
     * echo $status->label(); // 输出: 在线
     */
    public function label(): string
    {
        return match($this->value) {
            self::ONLINE => '在线',
            self::OFFLINE => '离线',
            self::BUSY => '忙碌',
            default => '未知',
        };
    }
}

