<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Service\AuthService;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 认证控制器 - 处理客服登录登出
 * ============================================================================
 *
 * 【接口列表】
 * - POST /auth/login：客服登录
 * - POST /auth/logout：客服登出
 *
 * 【认证方式】
 * 使用JWT Token认证，登录成功后返回token，
 * 后续请求需要在Header中携带：Authorization: Bearer {token}
 */
class AuthController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected AuthService $authService
    ) {
    }

    /**
     * 客服登录
     *
     * 【接口】POST /auth/login
     *
     * 【请求参数】
     * - username：用户名
     * - password：密码
     *
     * 【返回数据】
     * - token：JWT令牌
     * - agent：客服信息
     *
     * 【安全机制】
     * - 同一用户名连续5次登录失败后，锁定15分钟
     * - 登录成功后清除失败记录
     *
     * @param RequestInterface $request
     * @return array
     */
    public function login(RequestInterface $request): array
    {
        $username = $request->input('username', '');
        $password = $request->input('password', '');

        // 参数验证
        if (!$username || !$password) {
            return json_error('用户名和密码不能为空');
        }

        // 调用认证服务
        $result = $this->authService->login($username, $password);

        if (!$result['success']) {
            return json_error($result['error']);
        }

        return json_success($result['data'], '登录成功');
    }

    /**
     * 客服登出
     *
     * 【接口】POST /auth/logout
     *
     * 【请求头】
     * Authorization: Bearer {token}
     *
     * 【处理逻辑】
     * 从Redis中删除token，使其失效。
     *
     * @param RequestInterface $request
     * @return array
     */
    public function logout(RequestInterface $request): array
    {
        // 从Header获取token
        $token = $request->getHeaderLine('Authorization');
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);  // 去掉 "Bearer " 前缀
        }

        // 使token失效
        if ($token) {
            $this->authService->logout($token);
        }

        return json_success(null, '登出成功');
    }
}

