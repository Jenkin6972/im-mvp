<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Model\QuickReply;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * ============================================================================
 * 快捷回复控制器 - 管理客服常用语模板
 * ============================================================================
 *
 * 【接口列表】
 * - GET /quick-reply/list：获取启用的快捷回复（客服使用）
 * - GET /quick-reply/all：获取所有快捷回复（管理后台）
 * - POST /quick-reply/create：创建快捷回复
 * - PUT /quick-reply/update/{id}：更新快捷回复
 * - DELETE /quick-reply/delete/{id}：删除快捷回复
 *
 * 【说明】
 * 快捷回复是全局共享的，所有客服使用同一套模板。
 * 管理员可以创建、编辑、删除快捷回复。
 */
class QuickReplyController
{
    /**
     * 获取快捷回复列表（客服使用）
     *
     * 【接口】GET /quick-reply/list
     *
     * 【返回数据】
     * 只返回启用状态的快捷回复，按排序值排序。
     *
     * @return array
     */
    public function list(): array
    {
        $list = QuickReply::getActive();
        return json_success(['list' => $list]);
    }

    /**
     * 获取所有快捷回复（管理后台）
     *
     * 【接口】GET /quick-reply/all
     *
     * 【返回数据】
     * 返回所有快捷回复，包括禁用的。
     *
     * @return array
     */
    public function all(): array
    {
        $list = QuickReply::query()
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
        return json_success(['list' => $list]);
    }

    /**
     * 创建快捷回复
     *
     * 【接口】POST /quick-reply/create
     *
     * 【请求参数】
     * - title：标题（必填）
     * - content：内容（必填）
     * - sort_order：排序值（可选，默认0）
     *
     * @param RequestInterface $request
     * @return array
     */
    public function create(RequestInterface $request): array
    {
        $title = $request->input('title', '');
        $content = $request->input('content', '');
        $sortOrder = (int) $request->input('sort_order', 0);

        // 参数验证
        if (!$title || !$content) {
            return json_error('标题和内容不能为空');
        }

        // 创建记录
        $quickReply = QuickReply::create([
            'title' => $title,
            'content' => $content,
            'sort_order' => $sortOrder,
            'is_active' => 1,  // 默认启用
        ]);

        return json_success($quickReply->toArray());
    }

    /**
     * 更新快捷回复
     *
     * 【接口】PUT /quick-reply/update/{id}
     *
     * 【请求参数】
     * - title：标题（可选）
     * - content：内容（可选）
     * - sort_order：排序值（可选）
     * - is_active：是否启用（可选，1=启用, 0=禁用）
     *
     * @param int $id 快捷回复ID
     * @param RequestInterface $request
     * @return array
     */
    public function update(int $id, RequestInterface $request): array
    {
        $quickReply = QuickReply::find($id);
        if (!$quickReply) {
            return json_error('快捷回复不存在');
        }

        // 只更新传入的字段
        $title = $request->input('title');
        $content = $request->input('content');
        $sortOrder = $request->input('sort_order');
        $isActive = $request->input('is_active');

        if ($title !== null) {
            $quickReply->title = $title;
        }
        if ($content !== null) {
            $quickReply->content = $content;
        }
        if ($sortOrder !== null) {
            $quickReply->sort_order = (int) $sortOrder;
        }
        if ($isActive !== null) {
            $quickReply->is_active = (int) $isActive;
        }

        $quickReply->save();

        return json_success($quickReply->toArray());
    }

    /**
     * 删除快捷回复
     *
     * 【接口】DELETE /quick-reply/delete/{id}
     *
     * @param int $id 快捷回复ID
     * @return array
     */
    public function delete(int $id): array
    {
        $quickReply = QuickReply::find($id);
        if (!$quickReply) {
            return json_error('快捷回复不存在');
        }

        $quickReply->delete();

        return json_success(['id' => $id]);
    }
}

