<?php

declare(strict_types=1);

namespace App\Task;

use App\Enums\AgentStatus;
use App\Enums\ConversationStatus;
use App\Model\Agent;
use App\Model\Conversation;
use App\Service\AgentService;
use App\Service\ConversationService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * ============================================================================
 * 客服心跳检测定时任务 - 检查并清理离线客服
 * ============================================================================
 *
 * 【功能说明】
 * 当客服浏览器意外关闭（断电、崩溃等）时，WebSocket的onClose事件可能不会触发，
 * 导致Redis中客服仍显示在线。此任务定期检测客服的活跃标记，
 * 如果标记已过期，则认为客服离线，自动清理并重新分配其会话。
 *
 * 【执行频率】
 * 每30秒执行一次。
 *
 * 【检测逻辑】
 * 1. 获取所有数据库中状态为"在线"的客服
 * 2. 检查每个客服的Redis活跃标记是否存在
 * 3. 如果不存在，说明客服已离线超过60秒
 * 4. 清理Redis连接信息，将数据库状态改为离线
 * 5. 将该客服的进行中会话重新分配给其他在线客服
 */
class AgentHeartbeatTask
{
    /**
     * 执行心跳检测
     */
    public function execute(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $agentService = $container->get(AgentService::class);
        $conversationService = $container->get(ConversationService::class);

        // 获取所有数据库中显示在线的客服
        $onlineAgents = Agent::where('status', AgentStatus::ONLINE)->get();

        if ($onlineAgents->isEmpty()) {
            return;
        }

        $offlineCount = 0;
        $reassignedCount = 0;

        foreach ($onlineAgents as $agent) {
            // 检查活跃标记是否存在
            if (!$agentService->isAlive($agent->id)) {
                $logger->info('[AgentHeartbeat] Agent offline detected', [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->nickname,
                ]);

                // 清理连接信息
                $agentService->removeConnection($agent->id);

                // 更新状态为离线（会自动从负载均衡中移除）
                $agentService->setStatus($agent->id, AgentStatus::OFFLINE());

                $offlineCount++;

                // 重新分配该客服的进行中会话
                $activeConversations = Conversation::where('agent_id', $agent->id)
                    ->where('status', ConversationStatus::ACTIVE)
                    ->get();

                foreach ($activeConversations as $conversation) {
                    // 尝试分配给其他在线客服
                    $newAgentId = $agentService->getAvailableAgent();
                    if ($newAgentId) {
                        // 执行转移（transferType=3 表示系统自动转移）
                        $conversationService->transfer(
                            $conversation->id,
                            $newAgentId,
                            3,
                            null,
                            '客服离线，系统自动转移'
                        );
                        $reassignedCount++;

                        $logger->info('[AgentHeartbeat] Conversation reassigned', [
                            'conversation_id' => $conversation->id,
                            'from_agent' => $agent->id,
                            'to_agent' => $newAgentId,
                        ]);
                    } else {
                        // 没有可用客服，将会话改为等待状态
                        $conversation->status = ConversationStatus::WAITING;
                        $conversation->agent_id = null;
                        $conversation->save();

                        $logger->warning('[AgentHeartbeat] No available agent, conversation set to waiting', [
                            'conversation_id' => $conversation->id,
                        ]);
                    }
                }
            }
        }

        // 只在有变动时记录
        if ($offlineCount > 0) {
            $logger->info('[AgentHeartbeat] Task completed', [
                'offline_agents' => $offlineCount,
                'reassigned_conversations' => $reassignedCount,
            ]);
        }
    }
}

