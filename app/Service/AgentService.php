<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\RedisKey;
use App\Enums\AgentStatus;
use App\Enums\ConversationStatus;
use App\Model\Agent;
use App\Model\Conversation;
use Hyperf\Redis\Redis;

/**
 * ============================================================================
 * 客服服务类 - 管理客服的在线状态、连接、负载等
 * ============================================================================
 *
 * 【什么是服务(Service)？】
 * 服务是业务逻辑的封装。把复杂的业务逻辑放在服务类中，控制器只负责接收请求和返回响应。
 * 这样代码更清晰，也更容易复用和测试。
 *
 * 【本服务的职责】
 * 1. 客服状态管理：在线、离线、忙碌状态的切换
 * 2. 连接管理：保存和查询客服的WebSocket连接
 * 3. 负载均衡：计算客服负载，用于智能分配会话
 * 4. 心跳管理：记录客服的最后活跃时间
 *
 * 【Redis的使用】
 * 客服的状态、连接等实时数据存储在Redis中，而不是数据库：
 * - 速度快：Redis是内存数据库，读写速度比MySQL快100倍
 * - 自动过期：可以设置数据的过期时间
 * - 适合实时数据：状态、连接这类数据不需要持久化
 */
class AgentService
{
    /**
     * 构造函数 - 依赖注入Redis客户端
     *
     * 【依赖注入是什么？】
     * 不是在类内部 new Redis()，而是由框架自动传入。
     * 这样更灵活，测试时可以传入模拟对象。
     */
    public function __construct(
        protected Redis $redis
    ) {
    }

    /**
     * 设置客服在线状态
     *
     * 【调用时机】
     * - 客服登录/连接WebSocket：设置为 ONLINE
     * - 客服退出/断开连接：设置为 OFFLINE
     * - 客服手动设置忙碌：设置为 BUSY
     *
     * 【负载更新】
     * - 上线时：计算负载，加入分配队列
     * - 离线/忙碌时：从分配队列移除，不再接收新会话
     *
     * @param int $agentId 客服ID
     * @param AgentStatus $status 新状态
     */
    public function setStatus(int $agentId, AgentStatus $status): void
    {
        // 将状态存入Redis Hash表
        // Key: agent:status, Field: 客服ID, Value: 状态值
        $this->redis->hSet(RedisKey::AGENT_STATUS, (string) $agentId, (string) $status->value);

        // 如果上线，计算负载
        if ($status->value === AgentStatus::ONLINE) {
            $this->calculateLoad($agentId);
        } else {
            // 离线或忙碌，从负载排序集合中移除
            $this->redis->zRem(RedisKey::AGENT_LOAD, (string) $agentId);
        }
    }

    /**
     * 获取客服在线状态
     *
     * @param int $agentId 客服ID
     * @return AgentStatus 状态对象
     */
    public function getStatus(int $agentId): AgentStatus
    {
        $status = $this->redis->hGet(RedisKey::AGENT_STATUS, (string) $agentId);
        return $status ? AgentStatus::from((int) $status) : AgentStatus::OFFLINE();
    }

    /**
     * 获取客服当前活跃会话数
     *
     * @param int $agentId 客服ID
     * @return int 活跃会话数
     */
    public function getActiveSessionCount(int $agentId): int
    {
        return Conversation::query()
            ->where('agent_id', $agentId)
            ->whereIn('status', [ConversationStatus::WAITING, ConversationStatus::ACTIVE])
            ->count();
    }

    /**
     * 计算客服负载分数
     *
     * 【负载均衡算法】
     * 负载分数 = 进行中会话数 × 1.0 + 待处理会话数 × 1.5
     *
     * 待处理会话权重更高(1.5)是因为：
     * - 待处理的会话客户在等待中，体验差
     * - 应该优先让客服处理完待处理的会话
     *
     * 【存储方式】
     * 使用Redis的有序集合(Sorted Set)存储：
     * - Member: 客服ID
     * - Score: 负载分数
     * 自动按分数排序，方便获取负载最小的客服
     *
     * @param int $agentId 客服ID
     * @return float 负载分数
     */
    public function calculateLoad(int $agentId): float
    {
        // 获取进行中的会话数
        $activeCount = Conversation::query()
            ->where('agent_id', $agentId)
            ->where('status', ConversationStatus::ACTIVE)
            ->count();

        // 获取待处理的会话数（已分配但还没回复）
        $waitingCount = Conversation::query()
            ->where('agent_id', $agentId)
            ->where('status', ConversationStatus::WAITING)
            ->count();

        // 负载 = 进行中 * 1 + 待处理 * 1.5
        $load = $activeCount * 1.0 + $waitingCount * 1.5;

        // 更新到Redis有序集合
        $this->redis->zAdd(RedisKey::AGENT_LOAD, $load, (string) $agentId);

        return $load;
    }

    /**
     * 获取负载最小的在线客服
     *
     * 【核心方法 - 智能分配】
     * 当有新客户咨询时，调用此方法找到最合适的客服。
     *
     * 【分配规则】
     * 1. 从负载最小的客服开始检查
     * 2. 确保客服在线
     * 3. 确保客服没有超过最大会话数
     * 4. 返回第一个满足条件的客服
     *
     * @return int|null 客服ID，没有可用客服返回null
     */
    public function getAvailableAgent(): ?int
    {
        // 从Redis有序集合获取所有客服的负载，按分数升序（负载小的在前面）
        $agents = $this->redis->zRange(RedisKey::AGENT_LOAD, 0, -1, true);

        if (empty($agents)) {
            return null;
        }

        foreach ($agents as $agentId => $_load) {
            $agentId = (int) $agentId;

            // 检查是否在线
            if ($this->getStatus($agentId)->value !== AgentStatus::ONLINE) {
                continue;
            }

            // 检查是否超过最大会话数
            $agent = Agent::find($agentId);
            if (!$agent) {
                continue;
            }

            // 排除管理员（管理员只能查看，不能接待客户）
            if ($agent->is_admin === 1) {
                continue;
            }

            // 使用实时数据库查询来检查会话数，而不是依赖 Redis 缓存的 load 值
            $currentSessionCount = $this->getActiveSessionCount($agentId);
            if ($currentSessionCount < $agent->max_sessions) {
                return $agentId;
            }
        }

        return null;
    }

    /**
     * 客服活跃标记过期时间（秒）
     */
    public const ALIVE_TTL = 60;

    /**
     * 保存客服的WebSocket连接信息
     *
     * 【FD是什么？】
     * FD(File Descriptor)是Swoole给每个WebSocket连接分配的唯一编号。
     * 通过FD可以向指定的连接发送消息。
     *
     * 【存储两个映射】
     * 1. 客服ID → FD：用于给指定客服发消息
     * 2. FD → 用户信息：用于连接断开时识别是谁
     * 3. 活跃标记：带过期时间，用于检测客服是否真正在线
     *
     * @param int $agentId 客服ID
     * @param int $fd WebSocket连接ID
     */
    public function saveConnection(int $agentId, int $fd): void
    {
        // 客服ID -> FD 的映射
        $this->redis->hSet(RedisKey::AGENT_CONNECTIONS, (string) $agentId, (string) $fd);
        // FD -> 用户信息 的映射
        $this->redis->hSet(RedisKey::FD_USER_MAP, (string) $fd, json_encode([
            'type' => 'agent',
            'id' => $agentId,
        ]));
        // 设置活跃标记（带过期时间）
        $this->redis->setex(RedisKey::AGENT_ALIVE_PREFIX . $agentId, self::ALIVE_TTL, '1');
    }

    /**
     * 获取客服的WebSocket连接FD
     *
     * @param int $agentId 客服ID
     * @return int|null 连接ID，不在线返回null
     */
    public function getConnection(int $agentId): ?int
    {
        $fd = $this->redis->hGet(RedisKey::AGENT_CONNECTIONS, (string) $agentId);
        return $fd ? (int) $fd : null;
    }

    /**
     * 移除客服的连接信息
     *
     * 【调用时机】
     * 客服断开WebSocket连接时调用，清理Redis中的映射数据
     *
     * @param int $agentId 客服ID
     */
    public function removeConnection(int $agentId): void
    {
        $fd = $this->getConnection($agentId);
        if ($fd) {
            $this->redis->hDel(RedisKey::FD_USER_MAP, (string) $fd);
        }
        $this->redis->hDel(RedisKey::AGENT_CONNECTIONS, (string) $agentId);
        // 清理活跃标记
        $this->clearAlive($agentId);
    }

    /**
     * 更新客服心跳时间
     *
     * 【心跳机制】
     * 客服端定期发送心跳消息，服务端记录最后心跳时间。
     * 同时刷新活跃标记的过期时间。
     * 如果客服断开连接，活跃标记会自动过期。
     *
     * @param int $agentId 客服ID
     */
    public function updateHeartbeat(int $agentId): void
    {
        $this->redis->hSet(RedisKey::AGENT_HEARTBEAT, (string) $agentId, (string) time());
        // 刷新活跃标记过期时间
        $this->redis->setex(RedisKey::AGENT_ALIVE_PREFIX . $agentId, self::ALIVE_TTL, '1');
    }

    /**
     * 检查客服是否真正在线（活跃标记存在）
     *
     * @param int $agentId 客服ID
     * @return bool 是否在线
     */
    public function isAlive(int $agentId): bool
    {
        return (bool) $this->redis->exists(RedisKey::AGENT_ALIVE_PREFIX . $agentId);
    }

    /**
     * 清理客服活跃标记
     *
     * @param int $agentId 客服ID
     */
    public function clearAlive(int $agentId): void
    {
        $this->redis->del(RedisKey::AGENT_ALIVE_PREFIX . $agentId);
    }

    /**
     * 根据FD获取客服信息
     *
     * 【使用场景】
     * WebSocket消息到达时，只知道FD，需要查询是哪个客服发的。
     *
     * @param int $fd 连接ID
     * @return array|null 客服信息 ['type' => 'agent', 'id' => 1]
     */
    public function getAgentByFd(int $fd): ?array
    {
        $data = $this->redis->hGet(RedisKey::FD_USER_MAP, (string) $fd);
        if (!$data) {
            return null;
        }
        $info = json_decode($data, true);
        if ($info['type'] !== 'agent') {
            return null;
        }
        return $info;
    }

    /**
     * 检查客服是否在线
     *
     * @param int $agentId 客服ID
     * @return bool true=在线
     */
    public function isAgentOnline(int $agentId): bool
    {
        return $this->getStatus($agentId)->value === AgentStatus::ONLINE;
    }

    /**
     * 检查客服是否还有空位接收新会话
     *
     * 【使用场景】
     * 分配会话前检查客服是否已满载
     *
     * @param int $agentId 客服ID
     * @return bool true=有空位
     */
    public function hasCapacity(int $agentId): bool
    {
        $agent = Agent::find($agentId);
        if (!$agent) {
            return false;
        }

        // 使用实时数据库查询，而非 Redis 缓存的负载值
        // 这样可以确保在高并发场景下的准确性
        $activeCount = Conversation::where('agent_id', $agentId)
            ->where('status', ConversationStatus::ACTIVE)
            ->count();

        return $activeCount < $agent->max_sessions;
    }

    /**
     * 获取负载最小的在线客服（排除指定客服）
     *
     * 【使用场景】
     * 会话转移时，需要找一个可用客服，但要排除当前客服
     *
     * @param int $excludeAgentId 要排除的客服ID
     * @return int|null 可用客服ID，没有则返回null
     */
    public function getAvailableAgentExcept(int $excludeAgentId): ?int
    {
        // 获取所有在线客服的负载，按分数升序
        $agents = $this->redis->zRange(RedisKey::AGENT_LOAD, 0, -1, true);

        if (empty($agents)) {
            return null;
        }

        foreach ($agents as $agentId => $_load) {
            $agentId = (int) $agentId;

            // 排除指定客服
            if ($agentId === $excludeAgentId) {
                continue;
            }

            // 检查是否在线
            if (!$this->isAgentOnline($agentId)) {
                continue;
            }

            // 检查是否超过最大会话数
            $agent = Agent::find($agentId);
            if (!$agent) {
                continue;
            }

            // 排除管理员（管理员只能查看，不能接待客户）
            if ($agent->is_admin === 1) {
                continue;
            }

            // 使用实时数据库查询来检查会话数
            $currentSessionCount = $this->getActiveSessionCount($agentId);
            if ($currentSessionCount < $agent->max_sessions) {
                return $agentId;
            }
        }

        return null;
    }

    /**
     * 获取所有在线客服列表
     *
     * 【使用场景】
     * - 会话转移时，显示可转移的客服列表
     * - 管理后台显示在线客服
     *
     * @return array 在线客服列表 [['id' => 1, 'nickname' => '客服A'], ...]
     */
    public function getOnlineAgents(): array
    {
        // 从Redis获取所有客服的状态
        $statuses = $this->redis->hGetAll(RedisKey::AGENT_STATUS);
        $onlineAgentIds = [];

        // 筛选出在线状态的客服
        foreach ($statuses as $agentId => $status) {
            if ((int) $status === AgentStatus::ONLINE) {
                $onlineAgentIds[] = (int) $agentId;
            }
        }

        if (empty($onlineAgentIds)) {
            return [];
        }

        // 从数据库获取这些客服的详细信息
        return Agent::query()
            ->whereIn('id', $onlineAgentIds)
            ->where('status', 1)  // 账号状态也要是启用的
            ->select(['id', 'username', 'nickname'])
            ->get()
            ->toArray();
    }
}

