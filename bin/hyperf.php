#!/usr/bin/env php
<?php

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

/**
 * 禁用 Swoole 对 cURL 的 Hook
 *
 * 原因：阿里云 OSS SDK 使用原生 cURL 上传文件，
 * 而 Swoole 的 HOOK_CURL 会导致 Content-Length 头丢失，
 * 造成 "MissingContentLength" 错误。
 */
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

// Self-called anonymous function that creates its own scope and keeps the global namespace clean.
(function () {
    Hyperf\Di\ClassLoader::init();
    /** @var Psr\Container\ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
    $application->run();
})();

