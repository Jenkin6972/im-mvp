<?php

declare(strict_types=1);

namespace App\Service;

use OSS\Core\OssException;
use OSS\OssClient;

/**
 * ============================================================================
 * 阿里云 OSS 上传服务
 * ============================================================================
 *
 * 【功能说明】
 * 提供文件上传到阿里云 OSS 对象存储的功能
 *
 * 【使用场景】
 * - 聊天图片上传
 * - 头像上传
 * - 其他文件上传
 */
class OssService
{
    protected string $bucket;
    protected string $accessKeyId;
    protected string $accessKeySecret;
    protected string $endPoint;
    protected string $internalEndPoint;
    protected string $cdn;
    protected string $ossUrl;
    protected ?OssClient $ossClient = null;

    public function __construct()
    {
        $config = config('oss');
        $this->bucket = $config['bucket'] ?? '';
        $this->accessKeyId = $config['accessKeyId'] ?? '';
        $this->accessKeySecret = $config['accessKeySecret'] ?? '';
        $this->endPoint = $config['endPoint'] ?? '';
        $this->internalEndPoint = $config['internal_endPoint'] ?? $this->endPoint;
        $this->cdn = $config['cdn'] ?? '';
        $this->ossUrl = "https://{$this->bucket}.{$this->endPoint}";
    }

    /**
     * 获取 OSS 客户端实例
     */
    protected function getClient(): OssClient
    {
        if ($this->ossClient === null) {
            $this->ossClient = new OssClient(
                $this->accessKeyId,
                $this->accessKeySecret,
                "https://{$this->internalEndPoint}"
            );
        }
        return $this->ossClient;
    }

    /**
     * 上传文件到 OSS
     *
     * @param string $objectKey OSS 对象路径（如：im-mvp/prod/images/xxx.jpg）
     * @param string $localFilePath 本地文件路径
     * @return string|false 成功返回文件 URL，失败返回 false
     */
    public function uploadFile(string $objectKey, string $localFilePath): string|false
    {
        try {
            $client = $this->getClient();
            $result = $client->uploadFile($this->bucket, $objectKey, $localFilePath, [
                OssClient::OSS_HEADERS => [
                    'Content-Disposition' => 'inline; filename="' . rawurlencode(basename($objectKey)) . '"',
                ]
            ]);

            $code = $result['info']['http_code'] ?? -1;
            $url = $result['info']['url'] ?? '';

            if ($code == 200 && !empty($url)) {
                // 替换内网地址为外网地址
                $url = str_replace($this->internalEndPoint, $this->endPoint, $url);
                // 如果配置了 CDN，替换为 CDN 地址
                if (!empty($this->cdn)) {
                    $url = str_replace($this->ossUrl, $this->cdn, $url);
                }
                return $url;
            }

            logger()->error('OSS upload failed', ['code' => $code, 'objectKey' => $objectKey]);
            return false;
        }catch (OssException $e) {
            logger()->error('OSS upload exception', [
                'message' => $e->getMessage(),
                'objectKey' => $objectKey,
            ]);
            return false;
        }
    }

    /**
     * 上传图片
     *
     * @param \Hyperf\HttpMessage\Upload\UploadedFile $uploadedFile 上传的文件对象
     * @return array 包含 url 的数组
     * @throws \Exception 上传失败时抛出异常
     */
    public function uploadImage(\Hyperf\HttpMessage\Upload\UploadedFile $uploadedFile): array
    {
        $fileInfo = $uploadedFile->toArray();
        $tmpFile = $fileInfo['tmp_file'];

        // 验证 MIME 类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',            // BMP
            'image/x-ms-bmp',       // BMP (Windows)
            'image/svg+xml',        // SVG
            'image/tiff',           // TIFF
            'image/x-icon',         // ICO
            'image/vnd.microsoft.icon', // ICO
            // HEIC/HEIF 由前端转换为 JPG 后上传
        ];
        if (!in_array($mime, $allowedMimes)) {
            throw new \Exception('Unsupported image format. Supported: JPG, PNG, GIF, WEBP, BMP, SVG, TIFF, ICO');
        }

        // 验证文件扩展名
        $fileExt = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'tif', 'ico'];
        if (!in_array($fileExt, $allowedExts)) {
            throw new \Exception('Unsupported file extension');
        }

        // 验证文件大小（100MB）
        $maxSize = 100 * 1024 * 1024;
        if ($uploadedFile->getSize() > $maxSize) {
            throw new \Exception('Image size cannot exceed 100MB');
        }

        // 生成 OSS 对象路径
        $env = env('APP_ENV', 'dev');
        $date = date('Ymd');
        $fileMd5 = md5_file($tmpFile);
        $fileName = "{$date}_{$fileMd5}.{$fileExt}";
        $objectKey = "im-mvp/{$env}/images/{$fileName}";

        // 上传到 OSS
        $url = $this->uploadFile($objectKey, $tmpFile);

        // 删除临时文件
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        if (!$url) {
            throw new \Exception('Image upload failed, please try again');
        }

        return ['url' => $url];
    }
}

