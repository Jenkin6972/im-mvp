<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuthService;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ============================================================================
 * 认证中间件 - 验证客服登录状态
 * ============================================================================
 *
 * 【作用】
 * 拦截需要认证的HTTP请求，验证JWT Token是否有效。
 *
 * 【工作流程】
 * 1. 从请求头或URL参数获取Token
 * 2. 验证Token有效性
 * 3. 将客服ID存入Context供后续使用
 *
 * 【Token获取方式】
 * - 请求头：Authorization: Bearer {token}
 * - URL参数：?token={token}
 *
 * 【使用方式】
 * 在路由配置中添加中间件：
 * Router::addGroup('/api', function () {
 *     // 需要认证的路由
 * }, ['middleware' => [AuthMiddleware::class]]);
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected AuthService $authService
    ) {
    }

    /**
     * 处理请求
     *
     * 【处理流程】
     * 1. 获取Token
     * 2. 验证Token
     * 3. 存储客服ID到Context
     * 4. 继续处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 请求处理器
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->getToken($request);

        // 没有Token
        if (!$token) {
            return $this->unauthorized('缺少认证Token');
        }

        // 验证Token
        $agentId = $this->authService->verifyToken($token);

        if (!$agentId) {
            return $this->unauthorized('Token无效或已过期');
        }

        // 存储到上下文，供Controller使用
        Context::set('agent_id', $agentId);
        Context::set('token', $token);

        // 继续处理请求
        return $handler->handle($request);
    }

    /**
     * 从请求中获取Token
     *
     * 【获取顺序】
     * 1. 优先从Authorization头获取
     * 2. 其次从URL参数获取
     *
     * @param ServerRequestInterface $request
     * @return string|null
     */
    protected function getToken(ServerRequestInterface $request): ?string
    {
        // 从Authorization头获取
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);  // 去掉 "Bearer " 前缀
        }

        // 从URL参数获取（用于WebSocket连接等场景）
        $params = $request->getQueryParams();
        return $params['token'] ?? null;
    }

    /**
     * 返回401未授权响应
     *
     * @param string $message 错误信息
     * @return ResponseInterface
     */
    protected function unauthorized(string $message): ResponseInterface
    {
        $response = \Hyperf\Context\Context::get(ResponseInterface::class);
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(
                json_encode(['code' => 401, 'message' => $message], JSON_UNESCAPED_UNICODE)
            ));
    }
}

