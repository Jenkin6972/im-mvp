<?php

declare(strict_types=1);

use Hyperf\Crontab\Crontab;

return [
    'enable' => true,
    'crontab' => [
        // 每分钟检查超时未回复的会话并自动转移
        (new Crontab())
            ->setName('auto-transfer-timeout-conversations')
            ->setRule('* * * * *')
            ->setCallback([App\Task\AutoTransferTask::class, 'execute'])
            ->setMemo('自动转移超时未回复的会话'),

        // 每分钟检查客服心跳状态，清理离线客服
        (new Crontab())
            ->setName('agent-heartbeat-check')
            ->setRule('* * * * *')
            ->setCallback([App\Task\AgentHeartbeatTask::class, 'execute'])
            ->setMemo('检查客服心跳，清理离线客服并重新分配会话'),

        // 每分钟巡检待分配会话，尝试分配给有空余容量的在线客服
        (new Crontab())
            ->setName('waiting-conversation-check')
            ->setRule('* * * * *')
            ->setCallback([App\Task\WaitingConversationTask::class, 'execute'])
            ->setMemo('巡检待分配会话，分配给有空余容量的在线客服'),
    ],
];

