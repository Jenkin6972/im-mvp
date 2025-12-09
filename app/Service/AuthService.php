<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\RedisKey;
use App\Model\Agent;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\Redis\Redis;

/**
 * ============================================================================
 * 认证服务类 - 处理客服的登录、登出、Token验证
 * ============================================================================
 *
 * 【什么是认证(Authentication)？】
 * 认证就是验证"你是谁"。用户输入用户名密码，系统验证后确认身份。
 *
 * 【什么是JWT？】
 * JWT(JSON Web Token)是一种安全的身份令牌。
 * 登录成功后，服务器生成一个JWT返回给客户端。
 * 之后客户端每次请求都带上这个JWT，服务器就知道是谁在请求。
 *
 * 【JWT的结构】
 * JWT由三部分组成，用点号分隔：
 * - Header（头部）：算法类型
 * - Payload（载荷）：用户信息、过期时间等
 * - Signature（签名）：防止篡改
 *
 * 【为什么还要存Redis？】
 * JWT本身可以验证，但存Redis有额外好处：
 * - 可以主动让Token失效（登出时删除）
 * - 可以实现单点登录（踢掉旧Token）
 */
class AuthService
{
    /**
     * 构造函数 - 依赖注入Redis客户端
     */
    public function __construct(
        protected Redis $redis
    ) {
    }

    // 登录失败限制配置
    private const MAX_LOGIN_ATTEMPTS = 5;      // 最大失败次数
    private const FAIL_WINDOW_SECONDS = 900;   // 失败计数窗口（15分钟）
    private const LOCK_SECONDS = 900;          // 锁定时长（15分钟）

    /**
     * 客服登录
     *
     * 【登录流程】
     * 1. 检查账号是否被锁定
     * 2. 根据用户名查找客服
     * 3. 验证密码是否正确
     * 4. 检查账号是否启用
     * 5. 生成JWT Token
     * 6. 返回Token和客服信息
     *
     * @param string $username 用户名
     * @param string $password 密码（明文）
     * @return array 返回 ['success' => bool, 'data' => [...], 'error' => '...']
     */
    public function login(string $username, string $password): array
    {
        // 检查是否被锁定
        $lockInfo = $this->checkLoginLock($username);
        if ($lockInfo['locked']) {
            return [
                'success' => false,
                'error' => "登录失败次数过多，请 {$lockInfo['remaining_minutes']} 分钟后再试",
                'data' => null,
            ];
        }

        // 根据用户名查找客服
        $agent = Agent::query()->where('username', $username)->first();

        // 验证密码（使用模型中的verifyPassword方法）
        if (!$agent || !$agent->verifyPassword($password)) {
            // 记录失败次数
            $failInfo = $this->recordLoginFailure($username);
            $remainingAttempts = self::MAX_LOGIN_ATTEMPTS - $failInfo['attempts'];

            if ($failInfo['locked']) {
                return [
                    'success' => false,
                    'error' => "登录失败次数过多，账号已锁定 " . (self::LOCK_SECONDS / 60) . " 分钟",
                    'data' => null,
                ];
            }

            return [
                'success' => false,
                'error' => "用户名或密码错误，还剩 {$remainingAttempts} 次尝试机会",
                'data' => null,
            ];
        }

        // 检查账号是否启用
        if (!$agent->isEnabled()) {
            return [
                'success' => false,
                'error' => '账号已被禁用',
                'data' => null,
            ];
        }

        // 登录成功，清除失败记录
        $this->clearLoginFailure($username);

        // 生成Token
        $token = $this->generateToken($agent);

        // 返回登录结果
        return [
            'success' => true,
            'error' => null,
            'data' => [
                'token' => $token,
                'agent' => [
                    'id' => $agent->id,
                    'username' => $agent->username,
                    'nickname' => $agent->nickname,
                    'avatar' => $agent->avatar,
                    'is_admin' => $agent->is_admin,
                ],
            ],
        ];
    }

    /**
     * 检查账号是否被锁定
     *
     * @param string $username 用户名
     * @return array ['locked' => bool, 'remaining_minutes' => int]
     */
    private function checkLoginLock(string $username): array
    {
        $lockKey = RedisKey::LOGIN_LOCK_PREFIX . $username;
        $ttl = $this->redis->ttl($lockKey);

        if ($ttl > 0) {
            return [
                'locked' => true,
                'remaining_minutes' => (int) ceil($ttl / 60),
            ];
        }

        return ['locked' => false, 'remaining_minutes' => 0];
    }

    /**
     * 记录登录失败
     *
     * @param string $username 用户名
     * @return array ['attempts' => int, 'locked' => bool]
     */
    private function recordLoginFailure(string $username): array
    {
        $failKey = RedisKey::LOGIN_FAIL_PREFIX . $username;
        $lockKey = RedisKey::LOGIN_LOCK_PREFIX . $username;

        // 增加失败次数
        $attempts = $this->redis->incr($failKey);

        // 首次失败时设置过期时间
        if ($attempts === 1) {
            $this->redis->expire($failKey, self::FAIL_WINDOW_SECONDS);
        }

        // 达到最大次数，锁定账号
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->redis->setex($lockKey, self::LOCK_SECONDS, time());
            $this->redis->del($failKey); // 清除失败计数
            return ['attempts' => $attempts, 'locked' => true];
        }

        return ['attempts' => $attempts, 'locked' => false];
    }

    /**
     * 清除登录失败记录
     *
     * @param string $username 用户名
     */
    private function clearLoginFailure(string $username): void
    {
        $failKey = RedisKey::LOGIN_FAIL_PREFIX . $username;
        $this->redis->del($failKey);
    }

    /**
     * 生成JWT Token
     *
     * 【Token内容】
     * - iss: 签发者（im-mvp）
     * - sub: 主题（客服ID）
     * - iat: 签发时间
     * - exp: 过期时间
     * - data: 自定义数据（客服ID、用户名）
     *
     * @param Agent $agent 客服对象
     * @return string JWT Token字符串
     */
    public function generateToken(Agent $agent): string
    {
        // 从环境变量获取密钥和有效期
        $key = env('JWT_SECRET', 'im-mvp-secret');
        $ttl = (int) env('JWT_TTL', 86400);  // 默认24小时

        // 构建Token载荷
        $payload = [
            'iss' => 'im-mvp',           // 签发者
            'sub' => $agent->id,          // 主题（客服ID）
            'iat' => time(),              // 签发时间
            'exp' => time() + $ttl,       // 过期时间
            'data' => [                   // 自定义数据
                'agent_id' => $agent->id,
                'username' => $agent->username,
            ],
        ];

        // 使用HS256算法生成Token
        $token = JWT::encode($payload, $key, 'HS256');

        // 同时存储到Redis，用于主动失效和验证
        $this->redis->setex(
            RedisKey::TOKEN_PREFIX . $token,
            $ttl,
            (string) $agent->id
        );

        return $token;
    }

    /**
     * 验证Token
     *
     * 【验证流程】
     * 1. 解码JWT，验证签名和过期时间
     * 2. 检查Redis中是否存在（可能已被主动删除）
     * 3. 返回客服ID
     *
     * @param string $token JWT Token
     * @return int|null 验证成功返回客服ID，失败返回null
     */
    public function verifyToken(string $token): ?int
    {
        try {
            $key = env('JWT_SECRET', 'im-mvp-secret');

            // 解码并验证JWT（会自动检查签名和过期时间）
            $decoded = JWT::decode($token, new Key($key, 'HS256'));

            // 检查Redis中是否存在（登出后会被删除）
            $agentId = $this->redis->get(RedisKey::TOKEN_PREFIX . $token);
            if (!$agentId) {
                return null;
            }

            return (int) $decoded->sub;
        } catch (\Exception $e) {
            // JWT解码失败（签名错误、已过期等）
            return null;
        }
    }

    /**
     * 登出
     *
     * 【实现方式】
     * 从Redis中删除Token，使其立即失效。
     * 即使JWT本身还没过期，也无法通过验证。
     *
     * @param string $token JWT Token
     * @return bool 是否成功
     */
    public function logout(string $token): bool
    {
        return (bool) $this->redis->del(RedisKey::TOKEN_PREFIX . $token);
    }
}

