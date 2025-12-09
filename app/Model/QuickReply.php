<?php

declare(strict_types=1);

namespace App\Model;

/**
 * ============================================================================
 * 快捷回复模型 - 对应数据库 quick_reply 表
 * ============================================================================
 *
 * 【作用说明】
 * 快捷回复是客服工作时常用的"话术模板"。
 * 比如常见问题的标准回答，客服可以一键选择，不用重复打字。
 *
 * 【使用场景】
 * 客服在聊天界面点击"快捷回复"按钮，会弹出一个下拉菜单，
 * 显示所有可用的快捷回复，点击后自动填充到输入框。
 *
 * 【管理方式】
 * 本系统采用"全局共享"模式：
 * - 所有客服使用同一套快捷回复模板
 * - 由管理员统一管理（增删改）
 *
 * 【数据库字段说明】
 * @property int $id           主键ID
 * @property string $title     标题/简称，在下拉菜单中显示
 * @property string $content   完整内容，点击后填充到输入框
 * @property int $sort_order   排序序号，数字越小越靠前
 * @property int $is_active    是否启用：1=启用, 0=禁用
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * 【示例数据】
 * | title        | content                                    |
 * |--------------|--------------------------------------------|
 * | 欢迎语       | 您好，欢迎咨询！请问有什么可以帮您？       |
 * | 稍等         | 请稍等，正在为您查询...                    |
 * | 退款流程     | 退款申请路径：我的订单 → 申请退款 → ...    |
 */
class QuickReply extends Model
{
    /**
     * 指定对应的数据库表名
     */
    protected ?string $table = 'quick_reply';

    /**
     * 允许批量赋值的字段
     */
    protected array $fillable = [
        'title',
        'content',
        'sort_order',
        'is_active',
    ];

    /**
     * 字段类型转换
     */
    protected array $casts = [
        'id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'integer',
    ];

    /**
     * 获取所有启用的快捷回复
     *
     * 【使用场景】
     * 客服点击"快捷回复"按钮时，调用此方法获取可用的快捷回复列表。
     *
     * 【排序规则】
     * 1. 首先按 sort_order 升序（数字小的排前面）
     * 2. sort_order 相同时，按 id 升序（先创建的排前面）
     *
     * @return array 快捷回复数组
     *
     * @example
     * $replies = QuickReply::getActive();
     * // 返回: [['id' => 1, 'title' => '欢迎语', 'content' => '...'], ...]
     */
    public static function getActive(): array
    {
        return static::query()
            ->where('is_active', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
    }
}

