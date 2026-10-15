<?php

declare(strict_types=1);

namespace Wps\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Structured JSON logger to stdout/stderr (TS §12.1).
 */
final class Logger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private int $minLevel;

    public function __construct(string $minLevel = LogLevel::INFO)
    {
        $this->minLevel = self::LEVELS[$minLevel] ?? 1;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $weight = self::LEVELS[$level] ?? 1;
        if ($weight < $this->minLevel) {
            return;
        }
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                ];
            }
        }
        $record = [
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level === LogLevel::WARNING ? 'warning' : (string)$level,
            'message' => (string)$message,
            'context' => (object)$context,
            'pid' => getmypid(),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        file_put_contents($weight >= self::LEVELS[LogLevel::ERROR] ? 'php://stderr' : 'php://stdout', $line);
    }
}
