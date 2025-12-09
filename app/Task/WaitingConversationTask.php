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
 * 待分配会话巡检定时任务 - 自动分配等待中的会话
 * ============================================================================
 *
 * 【功能说明】
 * 当有待分配会话时，尝试分配给有空余容量的在线客服。
 * 作为"关闭会话时触发分配"的兜底机制，确保不会有遗漏。
 *
 * 【执行频率】
 * 每分钟执行一次。
 *
 * 【分配逻辑】
 * 1. 查找所有待分配会话（status=0, agent_id=null）
 * 2. 获取所有在线客服，按负载从低到高排序
 * 3. 遍历客服，如果有空余容量，分配会话
 * 4. 直到所有待分配会话都被分配或没有可用客服
 */
class WaitingConversationTask
{
    /**
     * 执行待分配会话巡检
     */
    public function execute(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $agentService = $container->get(AgentService::class);
        $conversationService = $container->get(ConversationService::class);

        // 查找待分配会话
        $waitingCount = Conversation::where('status', ConversationStatus::WAITING)
            ->whereNull('agent_id')
            ->count();

        if ($waitingCount === 0) {
            // 没有待分配会话，跳过
            return;
        }

        $logger->info('[WaitingConversation] Found waiting conversations', [
            'count' => $waitingCount,
        ]);

        // 获取所有在线客服（非管理员）
        $onlineAgents = Agent::where('status', 1)  // 账号启用
            ->where('is_admin', 0)  // 非管理员
            ->get();

        $totalAssigned = 0;

        foreach ($onlineAgents as $agent) {
            // 检查客服是否真正在线（Redis状态）
            if (!$agentService->isAgentOnline($agent->id)) {
                continue;
            }

            // 检查是否有活跃标记（心跳）
            if (!$agentService->isAlive($agent->id)) {
                continue;
            }

            // 尝试分配待分配会话给该客服
            $assigned = $conversationService->tryAssignWaitingConversations($agent->id);
            $totalAssigned += $assigned;

            if ($assigned > 0) {
                $logger->info('[WaitingConversation] Assigned conversations to agent', [
                    'agent_id' => $agent->id,
                    'assigned' => $assigned,
                ]);
            }

            // 重新检查是否还有待分配会话
            $remainingCount = Conversation::where('status', ConversationStatus::WAITING)
                ->whereNull('agent_id')
                ->count();

            if ($remainingCount === 0) {
                break;
            }
        }

        if ($totalAssigned > 0) {
            $logger->info('[WaitingConversation] Task completed', [
                'total_assigned' => $totalAssigned,
            ]);
        }
    }
}

