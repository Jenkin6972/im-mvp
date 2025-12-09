<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model as BaseModel;

/**
 * ============================================================================
 * 基础模型类
 * ============================================================================
 *
 * 【什么是模型(Model)？】
 * 模型是用来操作数据库表的类。每个模型对应数据库中的一张表。
 * 比如：Agent 模型对应 agent 表，Customer 模型对应 customer 表。
 *
 * 【为什么需要模型？】
 * - 不用写原始SQL语句，用面向对象的方式操作数据库
 * - 自动处理数据类型转换
 * - 自动管理创建时间、更新时间
 * - 可以定义表与表之间的关联关系
 *
 * 【使用示例】
 * // 查询所有客服
 * $agents = Agent::all();
 *
 * // 根据ID查询
 * $agent = Agent::find(1);
 *
 * // 创建新记录
 * Agent::create(['username' => 'test', 'password' => '123456']);
 *
 * // 更新记录
 * $agent->update(['nickname' => '新昵称']);
 *
 * // 删除记录
 * $agent->delete();
 *
 * 【继承关系】
 * 所有模型都继承这个基础模型类，这个类又继承 Hyperf 框架的 Model 类。
 * 这样所有模型都能使用框架提供的数据库操作功能。
 */
abstract class Model extends BaseModel
{
    /**
     * 默认时间戳格式
     *
     * 【说明】
     * 数据库中的时间字段（如 created_at）会按照这个格式存储。
     * 'Y-m-d H:i:s' 的意思是：年-月-日 时:分:秒，例如 2024-01-15 14:30:00
     */
    protected ?string $dateFormat = 'Y-m-d H:i:s';
}

