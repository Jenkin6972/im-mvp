<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Psr\Container\ContainerInterface;

/**
 * ============================================================================
 * 启动验证监听器 - 在服务启动前检查配置
 * ============================================================================
 *
 * 【功能说明】
 * 在服务启动之前检查关键配置项，确保安全设置正确。
 * 如果配置不正确，会输出警告或阻止启动。
 *
 * 【检查项】
 * - JWT_SECRET：必须自定义，不能使用默认值
 */
#[Listener]
class BootstrapListener implements ListenerInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $this->checkJwtSecret();
    }

    /**
     * 检查 JWT 密钥配置
     *
     * JWT_SECRET 是用于签名和验证 Token 的密钥，
     * 如果使用默认值，攻击者可以伪造任意用户的 Token。
     */
    private function checkJwtSecret(): void
    {
        $jwtSecret = env('JWT_SECRET', 'im-mvp-secret');
        $defaultSecrets = ['im-mvp-secret', 'secret', 'jwt-secret', ''];

        if (in_array($jwtSecret, $defaultSecrets, true)) {
            $message = <<<'MSG'

╔══════════════════════════════════════════════════════════════════════════════╗
║                          ⚠️  安全警告 / SECURITY WARNING                      ║
╠══════════════════════════════════════════════════════════════════════════════╣
║                                                                              ║
║  JWT_SECRET 正在使用默认值或为空！                                            ║
║  JWT_SECRET is using default value or is empty!                              ║
║                                                                              ║
║  这是一个严重的安全隐患，攻击者可能伪造登录令牌。                               ║
║  This is a serious security risk. Attackers may forge login tokens.         ║
║                                                                              ║
║  请在 .env 文件中设置一个强密钥：                                              ║
║  Please set a strong secret in .env file:                                    ║
║                                                                              ║
║  JWT_SECRET=your-random-secret-at-least-32-characters-long                   ║
║                                                                              ║
║  建议使用以下命令生成随机密钥：                                                ║
║  Suggested command to generate random secret:                                ║
║                                                                              ║
║  php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"                          ║
║                                                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

MSG;
            // 在非生产环境输出警告，生产环境直接退出
            $appEnv = env('APP_ENV', 'dev');
            if ($appEnv === 'production' || $appEnv === 'prod') {
                echo $message;
                echo "\n❌ 生产环境不允许使用默认 JWT_SECRET，服务启动已终止。\n";
                echo "❌ Default JWT_SECRET is not allowed in production. Server startup aborted.\n\n";
                exit(1);
            } else {
                echo $message;
                echo "⚠️  开发环境暂时允许启动，但请尽快配置 JWT_SECRET。\n\n";
            }
        }
    }
}

