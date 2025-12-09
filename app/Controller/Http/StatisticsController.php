<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Service\StatisticsService;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 统计控制器 - 客服KPI数据统计
 * ============================================================================
 *
 * 【接口列表】
 * - GET /statistics/my：获取当前客服的统计数据
 * - GET /statistics/agent/{id}：获取指定客服的统计数据（管理员）
 * - GET /statistics/global：获取全局统计数据（管理员）
 *
 * 【时间范围】
 * 支持以下方式指定时间范围：
 * - range=today：今日
 * - range=week：最近7天
 * - range=month：最近30天
 * - start_date + end_date：自定义时间段
 */
class StatisticsController
{
    /**
     * 构造函数 - 依赖注入
     */
    public function __construct(
        protected StatisticsService $statisticsService
    ) {
    }

    /**
     * 获取当前客服的统计数据
     *
     * 【接口】GET /statistics/my
     *
     * 【请求参数】
     * - range：时间范围（today/week/month）
     * - start_date：开始日期（格式：YYYY-MM-DD）
     * - end_date：结束日期（格式：YYYY-MM-DD）
     *
     * 【返回数据】
     * - total_conversations：会话总数
     * - total_messages：消息总数
     * - avg_response_time：平均响应时间
     * - avg_response_time_formatted：格式化的响应时间
     *
     * @param RequestInterface $request
     * @return array
     */
    public function my(RequestInterface $request): array
    {
        $agentId = $request->getAttribute('agent_id');
        [$startDate, $endDate] = $this->parseDateRange($request);

        $stats = $this->statisticsService->getAgentStats($agentId, $startDate, $endDate);

        return json_success($stats);
    }

    /**
     * 获取指定客服的统计数据（管理员）
     *
     * 【接口】GET /statistics/agent/{id}
     *
     * @param int $id 客服ID
     * @param RequestInterface $request
     * @return array
     */
    public function agent(int $id, RequestInterface $request): array
    {
        [$startDate, $endDate] = $this->parseDateRange($request);

        $stats = $this->statisticsService->getAgentStats($id, $startDate, $endDate);

        return json_success($stats);
    }

    /**
     * 获取全局统计数据（管理员）
     *
     * 【接口】GET /statistics/global
     *
     * 【返回数据】
     * - 全局统计数据
     * - 各客服排行榜
     *
     * @param RequestInterface $request
     * @return array
     */
    public function global(RequestInterface $request): array
    {
        [$startDate, $endDate] = $this->parseDateRange($request);

        $stats = $this->statisticsService->getGlobalStats($startDate, $endDate);

        return json_success($stats);
    }

    /**
     * 解析日期范围
     *
     * 【优先级】
     * 1. 如果同时传入 start_date 和 end_date，使用自定义范围
     * 2. 否则根据 range 参数计算
     *
     * @param RequestInterface $request
     * @return array [开始日期, 结束日期]
     */
    protected function parseDateRange(RequestInterface $request): array
    {
        $range = $request->input('range', 'today');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // 自定义时间段优先
        if ($startDate && $endDate) {
            return [$startDate, $endDate];
        }

        $today = date('Y-m-d');

        // 根据预设范围计算
        switch ($range) {
            case 'today':
                return [$today, $today];
            case 'week':
                return [date('Y-m-d', strtotime('-6 days')), $today];
            case 'month':
                return [date('Y-m-d', strtotime('-29 days')), $today];
            default:
                return [$today, $today];
        }
    }
}

