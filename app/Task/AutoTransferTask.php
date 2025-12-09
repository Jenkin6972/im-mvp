<?php

declare(strict_types=1);

namespace App\Task;

use App\Service\ConversationService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * ============================================================================
 * 自动转移定时任务 - 处理超时未响应的会话
 * ============================================================================
 *
 * 【功能说明】
 * 当客服长时间未回复客户消息时，自动将会话转移给其他在线客服。
 *
 * 【执行频率】
 * 每分钟执行一次（在 config/autoload/crontab.php 中配置）。
 *
 * 【超时规则】
 * 默认2分钟未响应即视为超时。
 *
 * 【转移逻辑】
 * 1. 查找所有超时的会话
 * 2. 为每个会话寻找负载最低的在线客服
 * 3. 执行转移并发送系统消息
 */
class AutoTransferTask
{
    /**
     * 超时时间（分钟）
     *
     * 客服超过这个时间未回复，会话将被自动转移。
     */
    protected int $timeoutMinutes = 2;

    /**
     * 执行自动转移
     *
     * 【执行流程】
     * 1. 获取服务实例
     * 2. 调用自动转移方法
     * 3. 记录执行结果
     */
    public function execute(): void
    {
        // 获取容器中的服务实例
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $conversationService = $container->get(ConversationService::class);

        $logger->info('[AutoTransfer] Starting auto transfer task');

        // 执行自动转移
        $result = $conversationService->autoTransferTimeoutConversations($this->timeoutMinutes);

        // 记录结果（只在有转移时记录）
        if ($result['transferred'] > 0 || $result['failed'] > 0) {
            $logger->info('[AutoTransfer] Task completed', [
                'transferred' => $result['transferred'],  // 成功转移数
                'failed' => $result['failed'],            // 失败数
            ]);
        }
    }
}

