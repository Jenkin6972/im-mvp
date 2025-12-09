<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());

        $data = json_encode([
            'code' => -1,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => explode("\n", $throwable->getTraceAsString()),
        ], JSON_UNESCAPED_UNICODE);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(500)
            ->withBody(new SwooleStream($data));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}

