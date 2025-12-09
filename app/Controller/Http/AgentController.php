<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Enums\AgentStatus;
use App\Model\Agent;
use App\Service\AgentService;
use App\Service\WebSocketService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 客服控制器 - 处理客服相关的HTTP接口
 * ============================================================================
 *
 * 【接口列表】
 * - GET /agent/info：获取当前客服信息
 * - POST /agent/status：更新客服状态
 * - GET /agent/list：获取客服列表（管理员）
 * - GET /agent/detail/{id}：获取客服详情（管理员）
 * - POST /agent/create：创建客服（管理员）
 * - POST /agent/update/{id}：更新客服（管理员）
 * - POST /agent/delete/{id}：删除客服（管理员）
 *
 * 【认证要求】
 * 所有接口都需要通过 AuthMiddleware 认证。
 * 管理接口需要管理员权限。
 */
class AgentController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected AgentService $agentService,
        protected WebSocketService $webSocketService
    ) {
    }

    /**
     * 获取当前客服信息
     *
     * 【接口】GET /agent/info
     *
     * 【返回数据】
     * - id：客服ID
     * - username：用户名
     * - nickname：昵称
     * - avatar：头像
     * - max_sessions：最大会话数
     * - status：当前状态值
     * - status_label：状态文字
     *
     * @return array
     */
    public function info(): array
    {
        // 从Context获取当前登录的客服ID（由AuthMiddleware设置）
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        if (!$agent) {
            return json_error('客服不存在');
        }

        // 从Redis获取实时状态
        $status = $this->agentService->getStatus($agentId);

        return json_success([
            'id' => $agent->id,
            'username' => $agent->username,
            'nickname' => $agent->nickname,
            'avatar' => $agent->avatar,
            'max_sessions' => $agent->max_sessions,
            'status' => $status->value,
            'status_label' => $status->label(),
            'is_admin' => $agent->is_admin,  // 是否超级管理员
        ]);
    }

    /**
     * 更新客服状态
     *
     * 【接口】POST /agent/status
     *
     * 【请求参数】
     * - status：状态值（1=在线, 2=离线, 3=忙碌）
     *
     * 【特殊逻辑】
     * 如果状态改为"在线"，会自动分配等待中的会话。
     *
     * @param RequestInterface $request
     * @return array
     */
    public function updateStatus(RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $status = $request->input('status');

        // 参数验证
        if ($status === null) {
            return json_error('状态不能为空');
        }

        $statusValue = (int) $status;
        if (!in_array($statusValue, [AgentStatus::ONLINE, AgentStatus::OFFLINE, AgentStatus::BUSY])) {
            return json_error('无效的状态值');
        }

        $agentStatus = AgentStatus::from($statusValue);

        // 如果客服重新上线，分配等待中的会话
        if ($statusValue == AgentStatus::ONLINE) {
            $this->webSocketService->handleAgentOnline($agentId);
        }

        // 更新状态
        $this->agentService->setStatus($agentId, $agentStatus);

        return json_success([
            'status' => $agentStatus->value,
            'status_label' => $agentStatus->label(),
        ], '状态更新成功');
    }

    // ==================== 管理员专用接口 ====================

    /**
     * 检查当前用户是否为管理员
     */
    private function checkAdmin(): bool
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);
        return $agent && $agent->is_admin === 1;
    }

    /**
     * 获取客服列表
     *
     * 【接口】GET /agent/list
     *
     * @return array
     */
    public function list(): array
    {
        if (!$this->checkAdmin()) {
            return json_error('无权限访问', 403);
        }

        $agents = Agent::query()
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($agent) {
                return [
                    'id' => $agent->id,
                    'username' => $agent->username,
                    'nickname' => $agent->nickname,
                    'is_admin' => $agent->is_admin,
                    'status' => $this->agentService->getStatus($agent->id)->value,
                    'max_sessions' => $agent->max_sessions,
                    'current_sessions' => $this->agentService->getActiveSessionCount($agent->id),
                    'created_at' => $agent->created_at?->toDateTimeString(),
                ];
            });

        return json_success(['list' => $agents]);
    }

    /**
     * 获取客服详情
     *
     * 【接口】GET /agent/detail/{id}
     *
     * @param int $id
     * @return array
     */
    public function detail(int $id): array
    {
        if (!$this->checkAdmin()) {
            return json_error('无权限访问', 403);
        }

        $agent = Agent::find($id);
        if (!$agent) {
            return json_error('客服不存在');
        }

        return json_success([
            'id' => $agent->id,
            'username' => $agent->username,
            'nickname' => $agent->nickname,
            'is_admin' => $agent->is_admin,
            'max_sessions' => $agent->max_sessions,
            'avatar' => $agent->avatar,
        ]);
    }

    /**
     * 创建客服
     *
     * 【接口】POST /agent/create
     *
     * @param RequestInterface $request
     * @return array
     */
    public function create(RequestInterface $request): array
    {
        if (!$this->checkAdmin()) {
            return json_error('无权限访问', 403);
        }

        $username = $request->input('username', '');
        $password = $request->input('password', '');
        $nickname = $request->input('nickname', '');
        $maxSessions = (int) $request->input('max_sessions', 10);
        $isAdmin = (int) $request->input('is_admin', 0);

        if (!$username || !$password) {
            return json_error('用户名和密码不能为空');
        }

        // 验证密码强度
        $passwordError = $this->validatePassword($password);
        if ($passwordError) {
            return json_error($passwordError);
        }

        // 检查用户名是否已存在
        if (Agent::where('username', $username)->exists()) {
            return json_error('用户名已存在');
        }

        $agent = Agent::create([
            'username' => $username,
            'password' => $password,  // 模型的 setPasswordAttribute 会自动加密
            'nickname' => $nickname ?: $username,
            'max_sessions' => max(1, min(100, $maxSessions)),
            'is_admin' => $isAdmin ? 1 : 0,
        ]);

        return json_success(['id' => $agent->id], '创建成功');
    }

    /**
     * 更新客服
     *
     * 【接口】POST /agent/update/{id}
     *
     * @param int $id
     * @param RequestInterface $request
     * @return array
     */
    public function update(int $id, RequestInterface $request): array
    {
        if (!$this->checkAdmin()) {
            return json_error('无权限访问', 403);
        }

        $agent = Agent::find($id);
        if (!$agent) {
            return json_error('客服不存在');
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $nickname = $request->input('nickname');
        $maxSessions = $request->input('max_sessions');
        $isAdmin = $request->input('is_admin');

        // 检查用户名是否与其他客服重复
        if ($username && $username !== $agent->username) {
            if (Agent::where('username', $username)->where('id', '!=', $id)->exists()) {
                return json_error('用户名已存在');
            }
            $agent->username = $username;
        }

        if ($password) {
            // 验证密码强度
            $passwordError = $this->validatePassword($password);
            if ($passwordError) {
                return json_error($passwordError);
            }
            $agent->password = $password;  // 模型的 setPasswordAttribute 会自动加密
        }

        if ($nickname !== null) {
            $agent->nickname = $nickname;
        }

        if ($maxSessions !== null) {
            $agent->max_sessions = max(1, min(100, (int) $maxSessions));
        }

        if ($isAdmin !== null) {
            $agent->is_admin = (int) $isAdmin ? 1 : 0;
        }

        $agent->save();

        return json_success([], '更新成功');
    }

    /**
     * 删除客服
     *
     * 【接口】POST /agent/delete/{id}
     *
     * @param int $id
     * @return array
     */
    public function delete(int $id): array
    {
        if (!$this->checkAdmin()) {
            return json_error('无权限访问', 403);
        }

        $currentAgentId = Context::get('agent_id');

        // 不能删除自己
        if ($id === $currentAgentId) {
            return json_error('不能删除自己');
        }

        $agent = Agent::find($id);
        if (!$agent) {
            return json_error('客服不存在');
        }

        // 删除客服（可以考虑软删除，这里用硬删除）
        $agent->delete();

        return json_success([], '删除成功');
    }

    /**
     * 验证密码强度
     *
     * 【密码要求】
     * - 长度至少8位
     * - 必须包含字母和数字
     *
     * @param string $password 密码
     * @return string|null 错误消息，验证通过返回 null
     */
    private function validatePassword(string $password): ?string
    {
        // 检查长度
        if (strlen($password) < 8) {
            return '密码长度至少8位';
        }

        // 检查是否包含字母
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return '密码必须包含字母';
        }

        // 检查是否包含数字
        if (!preg_match('/[0-9]/', $password)) {
            return '密码必须包含数字';
        }

        return null;
    }
}

