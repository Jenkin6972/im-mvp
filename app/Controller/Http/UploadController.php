<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Service\OssService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

/**
 * ============================================================================
 * 上传控制器 - 处理文件上传请求
 * ============================================================================
 *
 * 【功能说明】
 * 提供图片上传接口，支持客户和客服上传聊天图片
 */
class UploadController
{
    public function __construct(
        protected ContainerInterface $container,
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected OssService $ossService
    ) {
    }

    /**
     * 上传图片
     *
     * 【请求方式】POST /upload/image
     * 【请求参数】file - 图片文件（multipart/form-data）
     * 【返回数据】{ code: 0, data: { url: "https://..." } }
     */
    public function image()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->response->json([
                'code' => 1,
                'message' => '请选择要上传的图片',
            ]);
        }

        try {
            $result = $this->ossService->uploadImage($file);
            return $this->response->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 1,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

