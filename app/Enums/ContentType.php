<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ============================================================================
 * 消息内容类型枚举类
 * ============================================================================
 *
 * 【作用说明】
 * 这个类用来定义消息的内容类型。就像微信可以发文字、图片、语音一样，
 * 我们的IM系统也支持不同类型的消息内容。
 *
 * 【类型说明】
 * - TEXT (文本)：普通的文字消息，比如"你好"
 * - IMAGE (图片)：图片消息，存储的是图片的URL地址
 *
 * 【为什么需要区分消息类型？】
 * 不同类型的消息在前端的展示方式不同：
 * - 文本消息：直接显示文字
 * - 图片消息：需要用 <img> 标签来显示图片
 *
 * 【扩展说明】
 * 未来可以添加更多类型，比如：
 * - VIDEO = 3 (视频)
 * - FILE = 4 (文件)
 * - VOICE = 5 (语音)
 */
class ContentType
{
    /**
     * 文本消息 - 最常用的消息类型
     * 内容字段(content)存储的就是文字本身
     */
    public const TEXT = 1;

    /**
     * 图片消息 - 发送图片
     * 内容字段(content)存储的是图片的URL地址
     * 前端收到后会用 <img src="xxx"> 来显示
     */
    public const IMAGE = 2;

    /**
     * 当前类型的数值
     * @var int
     */
    public int $value;

    /**
     * 构造函数 - 创建一个内容类型对象
     *
     * @param int $value 类型数值(1=文本, 2=图片)
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
     * 创建"文本"类型对象的快捷方法
     *
     * @return self 文本类型对象
     */
    public static function TEXT(): self
    {
        return new self(self::TEXT);
    }

    /**
     * 创建"图片"类型对象的快捷方法
     *
     * @return self 图片类型对象
     */
    public static function IMAGE(): self
    {
        return new self(self::IMAGE);
    }

    /**
     * 获取类型的中文名称
     *
     * @return string 类型的中文名称
     */
    public function label(): string
    {
        return match($this->value) {
            self::TEXT => '文本',
            self::IMAGE => '图片',
            default => '未知',
        };
    }
}

