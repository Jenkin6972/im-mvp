<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Model\SystemConfig;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * 系统配置控制器
 */
class SystemConfigController
{
    /**
     * 获取SDK文案配置（公开接口，无需认证）
     * GET /config/sdk-texts
     */
    public function sdkTexts(): array
    {
        $texts = SystemConfig::getSdkTexts();
        return json_success($texts);
    }

    /**
     * 获取所有配置（需要管理员权限）
     * GET /admin/config
     */
    public function index(): array
    {
        $configs = SystemConfig::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(function ($config) {
                return [
                    'id' => $config->id,
                    'key' => $config->key,
                    'value' => json_decode($config->value, true) ?? $config->value,
                    'group' => $config->group,
                    'description' => $config->description,
                ];
            });

        // 按分组整理
        $grouped = [];
        foreach ($configs as $config) {
            $grouped[$config['group']][] = $config;
        }

        return json_success([
            'list' => $configs,
            'grouped' => $grouped,
            'current_language' => SystemConfig::getValue('sdk_language', 'en'),
        ]);
    }

    /**
     * 更新单个配置
     * PUT /admin/config/{key}
     */
    public function update(string $key, RequestInterface $request): array
    {
        $config = SystemConfig::where('key', $key)->first();
        if (!$config) {
            return json_error('配置项不存在');
        }

        $value = $request->input('value');
        if ($value === null) {
            return json_error('value 参数不能为空');
        }

        // 如果是字符串且不是JSON，直接保存；否则编码为JSON
        if (is_string($value)) {
            // 检查是否已经是有效的JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $config->value = $value;
            } else {
                $config->value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $config->value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $config->save();

        return json_success([
            'key' => $config->key,
            'value' => json_decode($config->value, true) ?? $config->value,
        ], '更新成功');
    }

    /**
     * 批量更新配置
     * PUT /admin/config/batch
     */
    public function batchUpdate(RequestInterface $request): array
    {
        $configs = $request->input('configs', []);
        if (empty($configs)) {
            return json_error('configs 参数不能为空');
        }

        $updated = 0;
        foreach ($configs as $key => $value) {
            $config = SystemConfig::where('key', $key)->first();
            if ($config) {
                if (is_array($value)) {
                    $config->value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } else {
                    $config->value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $config->save();
                $updated++;
            }
        }

        return json_success(['updated' => $updated], '批量更新成功');
    }

    /**
     * 切换语言
     * PUT /admin/config/language
     */
    public function setLanguage(RequestInterface $request): array
    {
        $language = $request->input('language', 'en');
        if (!in_array($language, ['zh', 'en'])) {
            return json_error('不支持的语言，只支持 zh 或 en');
        }

        SystemConfig::setValue('sdk_language', $language, 'general', '客户端默认语言');

        return json_success(['language' => $language], '语言设置成功');
    }
}

