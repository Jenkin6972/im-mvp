<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 系统配置模型
 * 
 * @property int $id
 * @property string $key
 * @property string $value
 * @property string $group
 * @property string $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SystemConfig extends Model
{
    protected ?string $table = 'system_config';

    protected array $fillable = ['key', 'value', 'group', 'description'];

    protected array $casts = [];

    /**
     * 获取配置值
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $config = self::where('key', $key)->first();
        if (!$config) {
            return $default;
        }
        
        $value = json_decode($config->value, true);
        return $value === null ? $config->value : $value;
    }

    /**
     * 设置配置值
     */
    public static function setValue(string $key, mixed $value, string $group = 'general', string $description = ''): bool
    {
        $jsonValue = is_string($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : json_encode($value, JSON_UNESCAPED_UNICODE);
        
        return (bool) self::updateOrCreate(
            ['key' => $key],
            ['value' => $jsonValue, 'group' => $group, 'description' => $description]
        );
    }

    /**
     * 获取多语言文本
     * @param string $key 配置键
     * @param string $lang 语言代码 (zh, en)
     * @param array $replacements 替换参数，如 ['{count}' => 5]
     */
    public static function getText(string $key, ?string $lang = null, array $replacements = []): string
    {
        // 如果没有指定语言，从配置获取默认语言
        if ($lang === null) {
            $lang = self::getValue('sdk_language', 'en');
            // 去除可能的引号
            if (is_string($lang)) {
                $lang = trim($lang, '"');
            }
        }

        $value = self::getValue($key);
        
        if (is_array($value) && isset($value[$lang])) {
            $text = $value[$lang];
        } elseif (is_array($value) && isset($value['en'])) {
            $text = $value['en']; // fallback to English
        } elseif (is_string($value)) {
            $text = $value;
        } else {
            $text = '';
        }

        // 替换占位符
        if (!empty($replacements)) {
            $text = str_replace(array_keys($replacements), array_values($replacements), $text);
        }

        return $text;
    }

    /**
     * 获取分组下的所有配置
     */
    public static function getByGroup(string $group): array
    {
        $configs = self::where('group', $group)->get();
        $result = [];
        foreach ($configs as $config) {
            $result[$config->key] = json_decode($config->value, true) ?? $config->value;
        }
        return $result;
    }

    /**
     * 获取SDK所需的所有文案配置
     */
    public static function getSdkTexts(?string $lang = null): array
    {
        if ($lang === null) {
            $lang = self::getValue('sdk_language', 'en');
            if (is_string($lang)) {
                $lang = trim($lang, '"');
            }
        }

        $sdkTexts = self::getByGroup('sdk_texts');
        $result = ['language' => $lang];
        
        foreach ($sdkTexts as $key => $value) {
            if (is_array($value) && isset($value[$lang])) {
                $result[$key] = $value[$lang];
            } elseif (is_array($value) && isset($value['en'])) {
                $result[$key] = $value['en'];
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
}

