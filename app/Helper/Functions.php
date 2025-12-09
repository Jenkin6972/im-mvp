<?php

/**
 * ============================================================================
 * 全局辅助函数 - 提供常用的快捷方法
 * ============================================================================
 *
 * 【说明】
 * 这些函数可以在项目的任何地方直接调用，无需引入。
 * 在 composer.json 的 autoload.files 中配置自动加载。
 */

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;

if (!function_exists('container')) {
    /**
     * 获取容器实例
     *
     * 【什么是容器？】
     * 容器是Hyperf的依赖注入容器，用于管理类的实例。
     * 通过容器可以获取任何已注册的服务。
     *
     * 【使用示例】
     * $redis = container()->get(\Hyperf\Redis\Redis::class);
     *
     * @return \Psr\Container\ContainerInterface
     */
    function container(): \Psr\Container\ContainerInterface
    {
        return ApplicationContext::getContainer();
    }
}

if (!function_exists('redis')) {
    /**
     * 获取Redis实例
     *
     * 【使用示例】
     * redis()->set('key', 'value');
     * $value = redis()->get('key');
     *
     * @return \Hyperf\Redis\Redis
     */
    function redis(): \Hyperf\Redis\Redis
    {
        return container()->get(\Hyperf\Redis\Redis::class);
    }
}

if (!function_exists('logger')) {
    /**
     * 获取日志实例
     *
     * 【使用示例】
     * logger()->info('这是一条日志');
     * logger()->error('发生错误', ['error' => $e->getMessage()]);
     *
     * @param string $name 日志通道名称
     * @return \Psr\Log\LoggerInterface
     */
    function logger(string $name = 'default'): \Psr\Log\LoggerInterface
    {
        return container()->get(\Hyperf\Logger\LoggerFactory::class)->get($name);
    }
}

if (!function_exists('json_success')) {
    /**
     * 成功响应
     *
     * 【返回格式】
     * {
     *     "code": 0,
     *     "message": "success",
     *     "data": {...}
     * }
     *
     * 【使用示例】
     * return json_success(['id' => 1], '创建成功');
     *
     * @param mixed $data 返回的数据
     * @param string $message 提示信息
     * @return array
     */
    function json_success(mixed $data = null, string $message = 'success'): array
    {
        return [
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ];
    }
}

if (!function_exists('json_error')) {
    /**
     * 错误响应
     *
     * 【返回格式】
     * {
     *     "code": -1,
     *     "message": "error",
     *     "data": null
     * }
     *
     * 【使用示例】
     * return json_error('参数错误');
     * return json_error('用户不存在', 404);
     *
     * @param string $message 错误信息
     * @param int $code 错误码（默认-1）
     * @param mixed $data 附加数据
     * @return array
     */
    function json_error(string $message = 'error', int $code = -1, mixed $data = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }
}

