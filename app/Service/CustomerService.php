<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\RedisKey;
use App\Model\Customer;
use Hyperf\Redis\Redis;

/**
 * ============================================================================
 * 客户服务类 - 管理客户的连接、会话等
 * ============================================================================
 *
 * 【什么是客户？】
 * 客户是使用聊天SDK的网站访客。他们不需要登录，通过UUID来识别身份。
 *
 * 【客户 vs 客服】
 * - 客户：网站访客，发起咨询的人
 * - 客服：公司员工，回答问题的人
 *
 * 【本服务的职责】
 * 1. 客户身份管理：根据UUID获取或创建客户
 * 2. 连接管理：保存和查询客户的WebSocket连接
 * 3. 会话关联：记录客户当前正在进行的会话
 * 4. 心跳管理：记录客户的最后活跃时间
 */
class CustomerService
{
    /**
     * 构造函数 - 依赖注入Redis客户端
     */
    public function __construct(
        protected Redis $redis
    ) {
    }

    /**
     * 根据UUID获取或创建客户
     *
     * 【UUID的作用】
     * UUID是客户端SDK生成的唯一标识，存储在浏览器localStorage中。
     * 同一个浏览器的访客，UUID相同，可以关联历史会话。
     *
     * @param string $uuid 客户唯一标识
     * @param string $ip 客户IP地址
     * @param string $userAgent 浏览器信息
     * @param array $extraInfo 额外信息
     * @return Customer 客户对象
     */
    public function getOrCreate(string $uuid, string $ip = '', string $userAgent = '', array $extraInfo = []): Customer
    {
        return Customer::findOrCreateByUuid($uuid, $ip, $userAgent, $extraInfo);
    }

    /**
     * 保存客户的WebSocket连接信息
     *
     * 【存储两个映射】
     * 1. UUID → FD：用于给指定客户发消息
     * 2. FD → 用户信息：用于连接断开时识别是谁
     *
     * @param string $uuid 客户UUID
     * @param int $fd WebSocket连接ID
     */
    public function saveConnection(string $uuid, int $fd): void
    {
        // UUID -> FD 的映射
        $this->redis->hSet(RedisKey::CUSTOMER_CONNECTIONS, $uuid, (string) $fd);
        // FD -> 用户信息 的映射
        $this->redis->hSet(RedisKey::FD_USER_MAP, (string) $fd, json_encode([
            'type' => 'customer',
            'uuid' => $uuid,
        ]));
    }

    /**
     * 获取客户的WebSocket连接FD
     *
     * @param string $uuid 客户UUID
     * @return int|null 连接ID，不在线返回null
     */
    public function getConnection(string $uuid): ?int
    {
        $fd = $this->redis->hGet(RedisKey::CUSTOMER_CONNECTIONS, $uuid);
        return $fd ? (int) $fd : null;
    }

    /**
     * 移除客户的连接信息
     *
     * 【调用时机】
     * 客户断开WebSocket连接时调用，清理Redis中的映射数据
     *
     * @param string $uuid 客户UUID
     */
    public function removeConnection(string $uuid): void
    {
        $fd = $this->getConnection($uuid);
        if ($fd) {
            $this->redis->hDel(RedisKey::FD_USER_MAP, (string) $fd);
        }
        $this->redis->hDel(RedisKey::CUSTOMER_CONNECTIONS, $uuid);
    }

    /**
     * 更新客户心跳时间
     *
     * @param string $uuid 客户UUID
     */
    public function updateHeartbeat(string $uuid): void
    {
        $this->redis->hSet(RedisKey::CUSTOMER_HEARTBEAT, $uuid, (string) time());
    }

    /**
     * 保存客户当前会话ID
     *
     * 【作用】
     * 记录客户正在进行的会话，方便快速查找。
     * 客户发消息时，可以直接获取当前会话ID，不用查数据库。
     *
     * @param string $uuid 客户UUID
     * @param int $conversationId 会话ID
     */
    public function setCurrentConversation(string $uuid, int $conversationId): void
    {
        $this->redis->hSet(RedisKey::CUSTOMER_CONVERSATION, $uuid, (string) $conversationId);
    }

    /**
     * 获取客户当前会话ID
     *
     * @param string $uuid 客户UUID
     * @return int|null 会话ID，没有则返回null
     */
    public function getCurrentConversation(string $uuid): ?int
    {
        $id = $this->redis->hGet(RedisKey::CUSTOMER_CONVERSATION, $uuid);
        return $id ? (int) $id : null;
    }

    /**
     * 根据FD获取客户信息
     *
     * 【使用场景】
     * WebSocket消息到达时，只知道FD，需要查询是哪个客户发的。
     *
     * @param int $fd 连接ID
     * @return array|null 客户信息 ['type' => 'customer', 'uuid' => '...']
     */
    public function getCustomerByFd(int $fd): ?array
    {
        $data = $this->redis->hGet(RedisKey::FD_USER_MAP, (string) $fd);
        if (!$data) {
            return null;
        }
        $info = json_decode($data, true);
        if ($info['type'] !== 'customer') {
            return null;
        }
        return $info;
    }
}

