<?php

/**
 * ============================================================================
 * 路由配置 - 定义所有HTTP和WebSocket路由
 * ============================================================================
 *
 * 【路由分类】
 * 1. 静态资源路由：页面、JS、音频文件
 * 2. 认证路由：登录、登出
 * 3. 客服路由：需要认证
 * 4. 会话路由：需要认证
 * 5. 消息路由：需要认证
 * 6. 客户路由：无需认证（SDK使用）
 * 7. 快捷回复路由：需要认证
 * 8. 统计路由：需要认证
 * 9. WebSocket路由
 *
 * 【认证说明】
 * 带有 AuthMiddleware 的路由需要在请求头携带Token：
 * Authorization: Bearer {token}
 */

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use Psr\Http\Message\ResponseInterface;

// ==================== 基础路由 ====================

// 健康检查接口
Router::get('/', function () {
    return ['code' => 0, 'message' => 'IM-MVP Server Running'];
});

// Favicon（防止浏览器请求404）
Router::get('/favicon.ico', function (): ResponseInterface {
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withStatus(204);  // 返回空内容
});

// ==================== 静态页面路由 ====================

// 客户端演示页面
Router::get('/demo', function (): ResponseInterface {
    $file = BASE_PATH . '/public/demo.html';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 客服工作台页面（使用 /workbench 路径避免与 /agent API 冲突）
Router::get('/workbench', function (): ResponseInterface {
    $file = BASE_PATH . '/public/agent/index.html';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

Router::get('/workbench/', function (): ResponseInterface {
    $file = BASE_PATH . '/public/agent/index.html';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 客服工作台JS文件
Router::get('/workbench/agent.js', function (): ResponseInterface {
    $file = BASE_PATH . '/public/agent/agent.js';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 客户端SDK文件
Router::get('/sdk/im-sdk.js', function (): ResponseInterface {
    $file = BASE_PATH . '/public/sdk/im-sdk.js';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 消息提示音
Router::get('/dingding.mp3', function (): ResponseInterface {
    $file = BASE_PATH . '/public/dingding.mp3';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'audio/mpeg')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 图片上传按钮
Router::get('/upload.png', function (): ResponseInterface {
    $file = BASE_PATH . '/public/upload.png';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'image/png')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// 客服头像
Router::get('/avatar.jpg', function (): ResponseInterface {
    $file = BASE_PATH . '/public/avatar.jpg';
    $response = \Hyperf\Context\Context::get(ResponseInterface::class);
    return $response->withHeader('Content-Type', 'image/jpeg')
        ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(file_get_contents($file)));
});

// ==================== 认证路由（无需Token） ====================

Router::post('/auth/login', [App\Controller\Http\AuthController::class, 'login']);
Router::post('/auth/logout', [App\Controller\Http\AuthController::class, 'logout']);

// ==================== 客服路由（需要认证） ====================

Router::addGroup('/agent', function () {
    Router::get('/info', [App\Controller\Http\AgentController::class, 'info']);           // 获取客服信息
    Router::put('/status', [App\Controller\Http\AgentController::class, 'updateStatus']); // 更新状态
    // 管理员专用接口
    Router::get('/list', [App\Controller\Http\AgentController::class, 'list']);           // 客服列表
    Router::get('/detail/{id:\d+}', [App\Controller\Http\AgentController::class, 'detail']); // 客服详情
    Router::post('/create', [App\Controller\Http\AgentController::class, 'create']);      // 创建客服
    Router::post('/update/{id:\d+}', [App\Controller\Http\AgentController::class, 'update']); // 更新客服
    Router::post('/delete/{id:\d+}', [App\Controller\Http\AgentController::class, 'delete']); // 删除客服
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 会话路由（需要认证） ====================

Router::addGroup('/conversation', function () {
    Router::get('/list', [App\Controller\Http\ConversationController::class, 'list']);                        // 会话列表
    Router::get('/all', [App\Controller\Http\ConversationController::class, 'all']);                          // 所有会话（超管上帝视角）
    Router::get('/history', [App\Controller\Http\ConversationController::class, 'history']);                  // 历史会话查询
    Router::post('/close/{id:\d+}', [App\Controller\Http\ConversationController::class, 'close']);            // 关闭会话
    Router::post('/read/{id:\d+}', [App\Controller\Http\ConversationController::class, 'read']);              // 标记已读
    Router::post('/transfer/{id:\d+}', [App\Controller\Http\ConversationController::class, 'transfer']);      // 转移会话
    Router::get('/agents/{id:\d+}', [App\Controller\Http\ConversationController::class, 'agents']);           // 可转移客服列表
    Router::get('/transfer-history/{id:\d+}', [App\Controller\Http\ConversationController::class, 'transferHistory']); // 转移记录
    Router::get('/customer/{id:\d+}', [App\Controller\Http\ConversationController::class, 'customer']);       // 客户详情
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 消息路由（需要认证） ====================

Router::addGroup('/message', function () {
    Router::get('/history/{conversation_id:\d+}', [App\Controller\Http\MessageController::class, 'history']); // 消息历史
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 客户路由（无需认证，SDK使用） ====================

Router::addGroup('/customer', function () {
    Router::post('/init', [App\Controller\Http\CustomerController::class, 'init']);           // 客户初始化
    Router::get('/history', [App\Controller\Http\CustomerController::class, 'history']);      // 历史消息
    Router::post('/save-welcome', [App\Controller\Http\CustomerController::class, 'saveWelcome']); // 保存欢迎语
});

// ==================== 上传路由 ====================

// 客户上传（无需认证）
Router::post('/upload/image', [App\Controller\Http\UploadController::class, 'image']);

// 客服上传（需要认证）
Router::post('/agent/upload/image', [App\Controller\Http\UploadController::class, 'image'], ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// 客户相关（需要认证，客服使用）
Router::put('/customer/{id:\d+}', [App\Controller\Http\CustomerController::class, 'update'], ['middleware' => [App\Middleware\AuthMiddleware::class]]);
Router::get('/customer/{id:\d+}/conversations', [App\Controller\Http\CustomerController::class, 'conversations'], ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 快捷回复路由（需要认证） ====================

Router::addGroup('/quick-reply', function () {
    Router::get('/list', [App\Controller\Http\QuickReplyController::class, 'list']);              // 获取启用的快捷回复
    Router::get('/all', [App\Controller\Http\QuickReplyController::class, 'all']);                // 获取所有快捷回复
    Router::post('/create', [App\Controller\Http\QuickReplyController::class, 'create']);         // 创建
    Router::put('/update/{id:\d+}', [App\Controller\Http\QuickReplyController::class, 'update']); // 更新
    Router::delete('/delete/{id:\d+}', [App\Controller\Http\QuickReplyController::class, 'delete']); // 删除
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 统计路由（需要认证） ====================

Router::addGroup('/statistics', function () {
    Router::get('/my', [App\Controller\Http\StatisticsController::class, 'my']);              // 当前客服统计
    Router::get('/agent/{id:\d+}', [App\Controller\Http\StatisticsController::class, 'agent']); // 指定客服统计
    Router::get('/global', [App\Controller\Http\StatisticsController::class, 'global']);      // 全局统计
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== 系统配置路由 ====================

// SDK文案配置（公开接口，无需认证）
Router::get('/config/sdk-texts', [App\Controller\Http\SystemConfigController::class, 'sdkTexts']);

// 后台配置管理（需要管理员认证）
Router::addGroup('/admin/config', function () {
    Router::get('', [App\Controller\Http\SystemConfigController::class, 'index']);                // 获取所有配置
    Router::put('/batch', [App\Controller\Http\SystemConfigController::class, 'batchUpdate']);    // 批量更新（静态路由需在变量路由之前）
    Router::put('/language', [App\Controller\Http\SystemConfigController::class, 'setLanguage']); // 切换语言
    Router::put('/{key}', [App\Controller\Http\SystemConfigController::class, 'update']);         // 更新单个配置（变量路由放最后）
}, ['middleware' => [App\Middleware\AuthMiddleware::class]]);

// ==================== WebSocket路由 ====================

// WebSocket服务器路由（端口9502）
Router::addServer('ws', function () {
    Router::get('/', App\Controller\WebSocketController::class);
});

