<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * ============================================================================
 * Redis键名常量 - 统一管理所有Redis键
 * ============================================================================
 *
 * 【为什么需要这个类？】
 * 1. 避免在代码中硬编码Redis键名
 * 2. 方便统一修改键名前缀
 * 3. 便于查看系统使用了哪些Redis键
 *
 * 【键名规范】
 * 所有键名以 "im:" 开头，表示这是IM系统的数据。
 *
 * 【数据结构说明】
 * - Hash：哈希表，类似PHP的关联数组
 * - ZSet：有序集合，按分数排序
 * - String：字符串
 */
class RedisKey
{
    // ==================== 客服相关 ====================

    /**
     * 客服WebSocket连接
     *
     * 【数据结构】Hash
     * 【键值对】agent_id => fd
     * 【说明】存储每个客服的WebSocket连接标识
     */
    public const AGENT_CONNECTIONS = 'im:agent:connections';

    /**
     * 客服在线状态
     *
     * 【数据结构】Hash
     * 【键值对】agent_id => status
     * 【状态值】1=在线, 2=离线, 3=忙碌
     */
    public const AGENT_STATUS = 'im:agent:status';

    /**
     * 客服心跳时间
     *
     * 【数据结构】Hash
     * 【键值对】agent_id => timestamp
     * 【说明】用于检测客服是否在线
     */
    public const AGENT_HEARTBEAT = 'im:agent:heartbeat';

    /**
     * 客服活跃标记（带过期时间）
     *
     * 【数据结构】String
     * 【完整键名】im:agent:alive:{agent_id}
     * 【值】1
     * 【过期时间】60秒
     * 【说明】用于快速判断客服是否在线，自动过期
     */
    public const AGENT_ALIVE_PREFIX = 'im:agent:alive:';

    /**
     * 客服负载分数
     *
     * 【数据结构】ZSet（有序集合）
     * 【成员】agent_id
     * 【分数】load_score（负载分数）
     * 【说明】用于负载均衡，分数越低优先分配
     */
    public const AGENT_LOAD = 'im:agent:load';

    // ==================== 客户相关 ====================

    /**
     * 客户WebSocket连接
     *
     * 【数据结构】Hash
     * 【键值对】customer_uuid => fd
     */
    public const CUSTOMER_CONNECTIONS = 'im:customer:connections';

    /**
     * 客户当前会话
     *
     * 【数据结构】Hash
     * 【键值对】customer_uuid => conversation_id
     * 【说明】记录客户当前正在进行的会话
     */
    public const CUSTOMER_CONVERSATION = 'im:customer:conversation';

    /**
     * 客户心跳时间
     *
     * 【数据结构】Hash
     * 【键值对】customer_uuid => timestamp
     */
    public const CUSTOMER_HEARTBEAT = 'im:customer:heartbeat';

    // ==================== 连接映射 ====================

    /**
     * FD到用户映射
     *
     * 【数据结构】Hash
     * 【键值对】fd => json{type, id}
     * 【说明】根据FD反查用户信息
     * 【示例】"123" => '{"type":"agent","id":1}'
     */
    public const FD_USER_MAP = 'im:fd:user:map';

    // ==================== JWT Token ====================

    /**
     * Token前缀
     *
     * 【数据结构】String
     * 【完整键名】im:token:{token}
     * 【值】agent_id
     * 【说明】用于验证Token有效性
     */
    public const TOKEN_PREFIX = 'im:token:';

    // ==================== 登录安全 ====================

    /**
     * 登录失败次数前缀
     *
     * 【数据结构】String
     * 【完整键名】im:login:fail:{username}
     * 【值】失败次数
     * 【过期时间】15分钟
     * 【说明】记录某用户名的登录失败次数
     */
    public const LOGIN_FAIL_PREFIX = 'im:login:fail:';

    /**
     * 登录锁定前缀
     *
     * 【数据结构】String
     * 【完整键名】im:login:lock:{username}
     * 【值】锁定时间戳
     * 【过期时间】锁定时长
     * 【说明】标记某用户名已被锁定
     */
    public const LOGIN_LOCK_PREFIX = 'im:login:lock:';

    /**
     * 生成带参数的Key
     *
     * 【使用示例】
     * RedisKey::make('im:user:%d:info', 123)
     * 结果：'im:user:123:info'
     *
     * @param string $key 键名模板
     * @param mixed ...$args 参数
     * @return string 完整键名
     */
    public static function make(string $key, mixed ...$args): string
    {
        return sprintf($key, ...$args);
    }
}

