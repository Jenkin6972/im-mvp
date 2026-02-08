<?php

declare(strict_types=1);

/**
 * ============================================================================
 * 阿里云 OSS 对象存储配置
 * ============================================================================
 *
 * 【配置说明】
 * 用于图片上传到阿里云 OSS 对象存储服务
 *
 * 【安全提示】
 * 生产环境中，敏感信息应通过环境变量配置，不要直接写在代码中
 */

return [
    // Bucket 名称
    'bucket' => env('OSS_BUCKET', ''),

    // Access Key ID
    'accessKeyId' => env('OSS_ACCESS_KEY_ID', ''),

    // Access Key Secret
    'accessKeySecret' => env('OSS_ACCESS_KEY_SECRET', ''),

    // OSS Endpoint（外网访问）
    'endPoint' => env('OSS_ENDPOINT', ''),

    // OSS Internal Endpoint（内网访问，如果服务器在同区域可用内网加速）
    'internal_endPoint' => env('OSS_INTERNAL_ENDPOINT', ''),

    // CDN 地址（可选，如果配置了 CDN 加速）
    'cdn' => env('OSS_CDN', ''),
];

