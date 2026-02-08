<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Model\Agent;
use App\Model\Conversation;
use App\Model\Customer;
use App\Service\CustomerService;
use App\Service\MessageService;
use App\Service\WebSocketService;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 客户控制器 - 处理客户端的HTTP接口
 * ============================================================================
 *
 * 【接口列表】
 * - POST /customer/init：客户初始化
 * - GET /customer/history：获取历史消息
 *
 * 【说明】
 * 这些接口供客户端（SDK）调用，不需要认证。
 * 客户通过UUID标识，UUID由客户端生成并持久化。
 */
class CustomerController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected CustomerService $customerService,
        protected MessageService $messageService,
        protected WebSocketService $webSocketService
    ) {
    }

    /**
     * 客户初始化
     *
     * 【接口】POST /customer/init
     *
     * 【请求参数】
     * - uuid：客户唯一标识（必填）
     * - source_url：来源页面URL
     * - referrer：引荐来源
     *
     * 【返回数据】
     * - customer_id：客户ID
     * - uuid：客户UUID
     * - conversation_id：当前会话ID（如果有）
     * - conversation_status：会话状态
     *
     * 【处理逻辑】
     * 1. 创建或获取客户记录
     * 2. 自动检测设备类型、浏览器、操作系统
     * 3. 返回当前进行中的会话（如果有）
     *
     * @param RequestInterface $request
     * @return array
     */
    public function init(RequestInterface $request): array
    {
        $uuid = $request->input('uuid', '');

        if (!$uuid) {
            return json_error('uuid不能为空');
        }

        // 获取客户IP（优先使用代理头）
        $ip = $request->getHeaderLine('X-Real-IP')
            ?: $request->getHeaderLine('X-Forwarded-For')
            ?: $request->server('remote_addr', '');
        $userAgent = $request->getHeaderLine('User-Agent');

        // 收集客户设备信息
        $extraInfo = [
            'source_url' => $request->input('source_url', ''),
            'referrer' => $request->input('referrer', ''),
            'device_type' => $this->detectDeviceType($userAgent),
            'browser' => $this->detectBrowser($userAgent),
            'os' => $this->detectOS($userAgent),
            'timezone' => $request->input('timezone', ''),
        ];

        // 创建或获取客户记录
        $customer = $this->customerService->getOrCreate($uuid, $ip, $userAgent, $extraInfo);

        // 获取当前进行中的会话
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                ConversationStatus::WAITING,
                ConversationStatus::ACTIVE,
            ])
            ->orderBy('id', 'desc')
            ->first();

        return json_success([
            'customer_id' => $customer->id,
            'uuid' => $customer->uuid,
            'conversation_id' => $conversation?->id,
            'conversation_status' => $conversation?->status,
        ]);
    }

    /**
     * 检测设备类型
     *
     * 【返回值】
     * - mobile：手机
     * - tablet：平板
     * - pc：电脑
     *
     * @param string $ua User-Agent字符串
     * @return string
     */
    private function detectDeviceType(string $ua): string
    {
        $ua = strtolower($ua);
        // 手机特征
        if (preg_match('/mobile|android.*mobile|iphone|ipod|blackberry|windows phone/i', $ua)) {
            return 'mobile';
        }
        // 平板特征
        if (preg_match('/ipad|android(?!.*mobile)|tablet/i', $ua)) {
            return 'tablet';
        }
        return 'pc';
    }

    /**
     * 检测浏览器
     *
     * @param string $ua User-Agent字符串
     * @return string 浏览器名称
     */
    private function detectBrowser(string $ua): string
    {
        // 注意：Edge必须在Chrome之前检测，因为Edge的UA也包含Chrome
        if (preg_match('/edg/i', $ua)) return 'Edge';
        if (preg_match('/chrome/i', $ua)) return 'Chrome';
        if (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) return 'Safari';
        if (preg_match('/firefox/i', $ua)) return 'Firefox';
        if (preg_match('/msie|trident/i', $ua)) return 'IE';
        if (preg_match('/opera|opr/i', $ua)) return 'Opera';
        return 'Unknown';
    }

    /**
     * 检测操作系统
     *
     * @param string $ua User-Agent字符串
     * @return string 操作系统名称
     */
    private function detectOS(string $ua): string
    {
        // Windows版本检测
        if (preg_match('/windows nt 10/i', $ua)) return 'Windows 10';
        if (preg_match('/windows nt 6.3/i', $ua)) return 'Windows 8.1';
        if (preg_match('/windows nt 6.2/i', $ua)) return 'Windows 8';
        if (preg_match('/windows nt 6.1/i', $ua)) return 'Windows 7';
        if (preg_match('/windows/i', $ua)) return 'Windows';
        // 其他系统
        if (preg_match('/mac os x/i', $ua)) return 'macOS';
        if (preg_match('/linux/i', $ua)) return 'Linux';
        if (preg_match('/iphone/i', $ua)) return 'iOS';
        if (preg_match('/ipad/i', $ua)) return 'iPadOS';
        if (preg_match('/android/i', $ua)) return 'Android';
        return 'Unknown';
    }

    /**
     * 获取客户历史消息
     *
     * 【接口】GET /customer/history
     *
     * 【请求参数】
     * - uuid：客户UUID（必填）
     * - limit：消息数量限制（默认50，最大100）
     *
     * 【返回数据】
     * - list：消息列表
     * - total：消息总数
     * - conversation_id：会话ID
     *
     * 【说明】
     * 返回最近一个会话的消息，过滤掉系统转移消息。
     *
     * @param RequestInterface $request
     * @return array
     */
    public function history(RequestInterface $request): array
    {
        $uuid = $request->input('uuid', '');

        if (!$uuid) {
            return json_error('uuid不能为空');
        }

        // 查找客户
        $customer = Customer::where('uuid', $uuid)->first();

        if (!$customer) {
            return json_success(['list' => [], 'total' => 0]);
        }

        // 获取最近的会话
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$conversation) {
            return json_success(['list' => [], 'total' => 0]);
        }

        $limit = (int) $request->input('limit', 50);
        // forCustomer = true: 过滤掉转移相关的系统消息
        $messages = $this->messageService->getHistory($conversation->id, min($limit, 100), null, true);

        // 标记客服发送的消息为已读，并通知客服
        $count = $this->messageService->markAsRead($conversation->id, SenderType::CUSTOMER());
        if ($count > 0 && $conversation->agent_id) {
            $this->webSocketService->sendToAgent($conversation->agent_id, [
                'type' => 'messages_read',
                'data' => [
                    'conversation_id' => $conversation->id,
                    'reader' => 'customer',
                ],
            ]);
        }

        return json_success([
            'list' => $messages,
            'total' => count($messages),
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * 更新客户信息（邮箱）
     *
     * 【接口】PUT /customer/{id}
     *
     * 【请求参数】
     * - email：客户邮箱（可选）
     *
     * 【权限说明】
     * 需要客服认证，只有负责该客户的客服或管理员可以更新
     *
     * @param int $id 客户ID
     * @param RequestInterface $request
     * @return array
     */
    public function update(int $id, RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        if (!$agent) {
            return json_error('无权操作');
        }

        // 查找客户
        $customer = Customer::find($id);
        if (!$customer) {
            return json_error('客户不存在');
        }

        // 权限检查：管理员可以修改任何客户，普通客服只能修改自己服务过的客户
        if (!$agent->isAdmin()) {
            $hasConversation = Conversation::query()
                ->where('customer_id', $id)
                ->where('agent_id', $agentId)
                ->exists();
            if (!$hasConversation) {
                return json_error('无权修改此客户信息');
            }
        }

        // 更新邮箱
        $email = $request->input('email');
        if ($email !== null) {
            // 简单的邮箱格式验证
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return json_error('邮箱格式不正确');
            }
            $customer->email = $email;
        }

        $customer->save();

        return json_success([
            'id' => $customer->id,
            'email' => $customer->email,
        ], '更新成功');
    }

    /**
     * 获取客户的历史会话列表
     *
     * 【接口】GET /customer/{id}/conversations
     *
     * 【请求参数】
     * - page：页码（默认1）
     * - page_size：每页数量（默认10，最大20）
     *
     * 【返回数据】
     * - list：会话列表（包含客服信息、消息数、创建时间等）
     * - total：总数
     * - page：当前页码
     * - page_size：每页数量
     *
     * 【权限说明】
     * 需要客服认证，只有服务过该客户的客服或管理员可以查看
     *
     * @param int $id 客户ID
     * @param RequestInterface $request
     * @return array
     */
    public function conversations(int $id, RequestInterface $request): array
    {
        $agentId = Context::get('agent_id');
        $agent = Agent::find($agentId);

        if (!$agent) {
            return json_error('无权操作');
        }

        // 查找客户
        $customer = Customer::find($id);
        if (!$customer) {
            return json_error('客户不存在');
        }

        // 权限检查：管理员可以查看任何客户，普通客服只能查看自己服务过的客户
        if (!$agent->isAdmin()) {
            $hasConversation = Conversation::query()
                ->where('customer_id', $id)
                ->where('agent_id', $agentId)
                ->exists();
            if (!$hasConversation) {
                return json_error('无权查看此客户的历史会话');
            }
        }

        // 分页参数
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(20, max(1, (int) $request->input('page_size', 10)));

        // 查询该客户的所有会话
        $query = Conversation::query()
            ->with(['agent:id,username,nickname'])
            ->where('customer_id', $id)
            ->orderBy('id', 'desc');

        $total = $query->count();

        $list = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->map(function ($conv) {
                // 获取每个会话的消息数
                $messageCount = \App\Model\Message::where('conversation_id', $conv->id)->count();

                // 获取最后一条消息
                $lastMessage = \App\Model\Message::where('conversation_id', $conv->id)
                    ->orderBy('id', 'desc')
                    ->first();

                return [
                    'id' => $conv->id,
                    'status' => $conv->status,
                    'status_text' => $this->getStatusText($conv->status),
                    'agent' => $conv->agent ? [
                        'id' => $conv->agent->id,
                        'nickname' => $conv->agent->nickname ?: $conv->agent->username,
                    ] : null,
                    'message_count' => $messageCount,
                    'last_message' => $lastMessage ? [
                        'content' => mb_substr($lastMessage->content, 0, 50),
                        'sender_type' => $lastMessage->sender_type,
                        'created_at' => $lastMessage->created_at,
                    ] : null,
                    'created_at' => $conv->created_at,
                    'closed_at' => $conv->closed_at,
                ];
            })
            ->toArray();

        return json_success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'customer' => [
                'id' => $customer->id,
                'uuid' => $customer->uuid,
            ],
        ]);
    }

    /**
     * 获取会话状态文本
     */
    private function getStatusText(int $status): string
    {
        return match ($status) {
            ConversationStatus::WAITING => '待分配',
            ConversationStatus::ACTIVE => '进行中',
            ConversationStatus::CLOSED => '已关闭',
            default => '未知',
        };
    }

    /**
     * 保存欢迎语消息
     *
     * 【接口】POST /customer/save-welcome
     *
     * 【请求参数】
     * - uuid：客户UUID（必填）
     * - content：欢迎语内容（必填）
     * - temp_id：前端临时ID（必填，用于前端更新）
     *
     * 【返回数据】
     * - message_id：真实消息ID
     * - temp_id：前端临时ID
     * - created_at：创建时间
     *
     * 【说明】
     * 客户发送第一条消息前，先调用此接口保存欢迎语。
     * 欢迎语以客服身份保存（sender_type=2, sender_id=0 表示系统客服）。
     *
     * @param RequestInterface $request
     * @return array
     */
    public function saveWelcome(RequestInterface $request): array
    {
        $uuid = $request->input('uuid', '');
        $content = $request->input('content', '');
        $tempId = $request->input('temp_id', '');

        if (!$uuid || !$content || !$tempId) {
            return json_error('参数不完整');
        }

        // 查找客户
        $customer = Customer::where('uuid', $uuid)->first();
        if (!$customer) {
            return json_error('客户不存在');
        }

        // 获取或创建会话
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                ConversationStatus::WAITING,
                ConversationStatus::ACTIVE,
            ])
            ->orderBy('id', 'desc')
            ->first();

        if (!$conversation) {
            // 创建新会话（等待分配状态）
            $conversation = Conversation::create([
                'customer_id' => $customer->id,
                'status' => ConversationStatus::WAITING,
            ]);
        }

        // 创建欢迎语消息（以客服身份，sender_id=0 表示系统客服）
        $message = $this->messageService->create(
            $conversation->id,
            SenderType::AGENT(),
            0,  // sender_id=0 表示系统客服
            $content
        );

        return json_success([
            'message_id' => $message->id,
            'temp_id' => $tempId,
            'conversation_id' => $conversation->id,
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }
}

