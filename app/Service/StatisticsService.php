<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ConversationStatus;
use App\Model\Conversation;
use App\Model\Message;
use Hyperf\DbConnection\Db;

/**
 * ============================================================================
 * 统计服务类 - 提供客服KPI数据统计
 * ============================================================================
 *
 * 【什么是KPI？】
 * KPI(Key Performance Indicator)是关键绩效指标。
 * 用来衡量客服的工作表现，比如接待量、响应速度等。
 *
 * 【本服务的职责】
 * 1. 客服个人统计：单个客服的工作数据
 * 2. 全局统计：整个客服团队的数据
 * 3. 响应时间计算：客服首次回复的平均时间
 *
 * 【统计维度】
 * - 今日：当天的数据
 * - 本周：本周一到今天的数据
 * - 本月：本月1号到今天的数据
 * - 自定义：任意时间段的数据
 */
class StatisticsService
{
    /**
     * 获取客服个人统计数据
     *
     * 【统计指标】
     * - 接待会话数：分配给该客服的会话总数
     * - 已完成会话数：已关闭的会话数
     * - 发送消息数：客服发送的消息数
     * - 接收消息数：客户发送的消息数
     * - 平均响应时间：客服首次回复的平均时间
     *
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期 (Y-m-d)
     * @param string $endDate 结束日期 (Y-m-d)
     * @return array 统计数据
     */
    public function getAgentStats(int $agentId, string $startDate, string $endDate): array
    {
        // 转换为完整的时间范围
        $startTime = $startDate . ' 00:00:00';
        $endTime = $endDate . ' 23:59:59';

        // 接待会话数
        $totalConversations = Conversation::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 已完成会话数
        $closedConversations = Conversation::query()
            ->where('agent_id', $agentId)
            ->where('status', ConversationStatus::CLOSED)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 发送消息数（客服发送的）
        $sentMessages = Message::query()
            ->where('sender_type', 2) // AGENT
            ->where('sender_id', $agentId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 接收消息数（客户发送的，在该客服的会话中）
        $receivedMessages = Db::table('message')
            ->join('conversation', 'message.conversation_id', '=', 'conversation.id')
            ->where('conversation.agent_id', $agentId)
            ->where('message.sender_type', 1) // CUSTOMER
            ->whereBetween('message.created_at', [$startTime, $endTime])
            ->count();

        // 平均响应时间（秒）
        $avgResponseTime = $this->calculateAvgResponseTime($agentId, $startTime, $endTime);

        return [
            'total_conversations' => $totalConversations,
            'closed_conversations' => $closedConversations,
            'sent_messages' => $sentMessages,
            'received_messages' => $receivedMessages,
            'avg_response_time' => $avgResponseTime,
            'avg_response_time_formatted' => $this->formatSeconds($avgResponseTime),
        ];
    }

    /**
     * 获取全局统计数据
     *
     * 【统计指标】
     * - 总会话数：所有会话数量
     * - 已完成会话数：已关闭的会话数
     * - 等待中会话数：当前等待分配的会话数
     * - 进行中会话数：当前正在进行的会话数
     * - 总消息数：所有消息数量
     * - 按客服统计：每个客服的详细KPI数据
     *
     * @param string $startDate 开始日期 (Y-m-d)
     * @param string $endDate 结束日期 (Y-m-d)
     * @return array 统计数据
     */
    public function getGlobalStats(string $startDate, string $endDate): array
    {
        $startTime = $startDate . ' 00:00:00';
        $endTime = $endDate . ' 23:59:59';

        // 总会话数
        $totalConversations = Conversation::query()
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 已完成会话数
        $closedConversations = Conversation::query()
            ->where('status', ConversationStatus::CLOSED)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 等待中会话数（实时数据，不限时间段）
        $waitingConversations = Conversation::query()
            ->where('status', ConversationStatus::WAITING)
            ->count();

        // 进行中会话数（实时数据，不限时间段）
        $activeConversations = Conversation::query()
            ->where('status', ConversationStatus::ACTIVE)
            ->count();

        // 总消息数
        $totalMessages = Message::query()
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();

        // 获取所有客服的详细KPI数据
        $agentDetailStats = $this->getAgentDetailStats($startDate, $endDate);

        return [
            'total_conversations' => $totalConversations,
            'closed_conversations' => $closedConversations,
            'waiting_conversations' => $waitingConversations,
            'active_conversations' => $activeConversations,
            'total_messages' => $totalMessages,
            'agent_detail_stats' => $agentDetailStats,
        ];
    }

    /**
     * 获取所有客服的详细KPI数据
     *
     * 【KPI指标】
     * - 接待会话数
     * - 已完成会话数
     * - 发送消息数
     * - 接收消息数
     * - 平均响应时间
     * - 当前活跃会话数（实时）
     *
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array 客服KPI数据列表
     */
    protected function getAgentDetailStats(string $startDate, string $endDate): array
    {
        // 获取所有启用的客服
        $agents = \App\Model\Agent::query()
            ->where('status', 1)
            ->get();

        $result = [];
        foreach ($agents as $agent) {
            $stats = $this->getAgentStats($agent->id, $startDate, $endDate);

            // 获取当前活跃会话数（实时数据）
            $currentActiveConversations = Conversation::query()
                ->where('agent_id', $agent->id)
                ->where('status', ConversationStatus::ACTIVE)
                ->count();

            $result[] = [
                'agent_id' => $agent->id,
                'username' => $agent->username,
                'nickname' => $agent->nickname,
                'is_admin' => $agent->is_admin,
                'total_conversations' => $stats['total_conversations'],
                'closed_conversations' => $stats['closed_conversations'],
                'sent_messages' => $stats['sent_messages'],
                'received_messages' => $stats['received_messages'],
                'avg_response_time' => $stats['avg_response_time'],
                'avg_response_time_formatted' => $stats['avg_response_time_formatted'],
                'current_active_conversations' => $currentActiveConversations,
            ];
        }

        // 按接待会话数排序（降序）
        usort($result, fn($a, $b) => $b['total_conversations'] - $a['total_conversations']);

        return $result;
    }

    /**
     * 计算平均响应时间
     *
     * 【计算方法】
     * 1. 获取客服处理的所有会话
     * 2. 对每个会话，找到客户第一条消息和客服第一条回复
     * 3. 计算时间差
     * 4. 求平均值
     *
     * @param int $agentId 客服ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @return int 平均响应时间（秒）
     */
    protected function calculateAvgResponseTime(int $agentId, string $startTime, string $endTime): int
    {
        // 获取客服在时间段内处理的会话ID列表
        $conversations = Conversation::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->pluck('id');

        if ($conversations->isEmpty()) {
            return 0;
        }

        $totalTime = 0;  // 总响应时间
        $count = 0;      // 有效会话数

        foreach ($conversations as $convId) {
            // 获取客户第一条消息
            $firstCustomerMsg = Message::query()
                ->where('conversation_id', $convId)
                ->where('sender_type', 1)  // CUSTOMER
                ->orderBy('id', 'asc')
                ->first();

            if (!$firstCustomerMsg) continue;

            // 获取客服第一条回复（必须在客户消息之后）
            $firstAgentMsg = Message::query()
                ->where('conversation_id', $convId)
                ->where('sender_type', 2)  // AGENT
                ->where('id', '>', $firstCustomerMsg->id)
                ->orderBy('id', 'asc')
                ->first();

            if (!$firstAgentMsg) continue;

            // 计算响应时间（使用 Carbon 的 timestamp 属性或转换为字符串）
            $agentTime = $firstAgentMsg->created_at instanceof \Carbon\Carbon
                ? $firstAgentMsg->created_at->timestamp
                : strtotime((string) $firstAgentMsg->created_at);
            $customerTime = $firstCustomerMsg->created_at instanceof \Carbon\Carbon
                ? $firstCustomerMsg->created_at->timestamp
                : strtotime((string) $firstCustomerMsg->created_at);

            $responseTime = $agentTime - $customerTime;
            if ($responseTime > 0) {
                $totalTime += $responseTime;
                $count++;
            }
        }

        // 返回平均值
        return $count > 0 ? (int) ($totalTime / $count) : 0;
    }

    /**
     * 格式化秒数为可读时间
     *
     * 【示例】
     * - 30 → "30秒"
     * - 90 → "1分30秒"
     * - 3700 → "1小时1分"
     *
     * @param int $seconds 秒数
     * @return string 格式化后的时间字符串
     */
    protected function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . '秒';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . '分' . ($secs > 0 ? $secs . '秒' : '');
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . '小时' . ($minutes > 0 ? $minutes . '分' : '');
        }
    }
}

