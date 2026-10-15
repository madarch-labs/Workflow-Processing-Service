<?php

declare(strict_types=1);

namespace Wps\Services;

use Swoole\Table;

/**
 * Prometheus metrics (TS §12.2) kept in a Swoole\Table so that every worker
 * increments the same counters. Histograms are exported as summaries (_sum/_count).
 */
final class MetricsService
{
    public const EVENTS_RECEIVED = 'wps_events_received_total';
    public const EVENTS_PROCESSED = 'wps_events_processed_total';
    public const EVENTS_SKIPPED = 'wps_events_skipped_total';
    public const EVENTS_INVALID = 'wps_events_invalid_total';
    public const PROCESSES_STARTED = 'wps_processes_started_total';
    public const EXECUTIONS_COMPLETED = 'wps_executions_completed_total';
    public const EXECUTIONS_FAILED = 'wps_executions_failed_total';
    public const EXECUTION_DURATION = 'wps_execution_duration_seconds';
    public const ACTION_DURATION = 'wps_action_duration_seconds';
    public const RETRY_COUNT = 'wps_retry_count_total';
    public const RUN_ONCE_SKIPPED = 'wps_run_once_skipped_total';
    public const RUN_ONCE_NO_ID = 'wps_run_once_no_id_total';

    private const HELP = [
        self::EVENTS_RECEIVED => ['counter', 'Всего получено событий'],
        self::EVENTS_PROCESSED => ['counter', 'Обработано событий (с процессом)'],
        self::EVENTS_SKIPPED => ['counter', 'События без подходящих процессов'],
        self::EVENTS_INVALID => ['counter', 'Некорректные события (отправлены в DLQ)'],
        self::PROCESSES_STARTED => ['counter', 'Запущено процессов'],
        self::EXECUTIONS_COMPLETED => ['counter', 'Успешно завершено'],
        self::EXECUTIONS_FAILED => ['counter', 'Завершено с ошибкой'],
        self::EXECUTION_DURATION => ['summary', 'Длительность выполнения'],
        self::ACTION_DURATION => ['summary', 'Длительность действия'],
        self::RETRY_COUNT => ['counter', 'Количество retry'],
        self::RUN_ONCE_SKIPPED => ['counter', 'Запуски, заблокированные однократным запуском (run_once)'],
        self::RUN_ONCE_NO_ID => ['counter', 'run_once не применён: у записи нет id'],
    ];

    public function __construct(private readonly ?Table $table)
    {
    }

    public static function createShared(): self
    {
        if (!class_exists(Table::class)) {
            return new self(null);
        }
        $table = new Table(64);
        $table->column('value', Table::TYPE_FLOAT);
        $table->create();
        return new self($table);
    }

    public function inc(string $name, float $by = 1.0): void
    {
        $this->table?->incr($name, 'value', $by);
    }

    public function observe(string $name, float $seconds): void
    {
        $this->inc($name . '_sum', $seconds);
        $this->inc($name . '_count');
    }

    public function get(string $name): float
    {
        $row = $this->table?->get($name);
        return is_array($row) ? (float)$row['value'] : 0.0;
    }

    /**
     * @param array<string, float|int> $gauges extra gauges computed at scrape time
     */
    public function render(array $gauges = []): string
    {
        $out = [];
        foreach (self::HELP as $name => [$type, $help]) {
            $out[] = "# HELP {$name} {$help}";
            $out[] = "# TYPE {$name} {$type}";
            if ($type === 'summary') {
                $out[] = $name . '_sum ' . $this->format($this->get($name . '_sum'));
                $out[] = $name . '_count ' . $this->format($this->get($name . '_count'));
            } else {
                $out[] = $name . ' ' . $this->format($this->get($name));
            }
        }
        foreach ($gauges as $name => $value) {
            $out[] = "# TYPE {$name} gauge";
            $out[] = $name . ' ' . $this->format((float)$value);
        }
        return implode("\n", $out) . "\n";
    }

    private function format(float $v): string
    {
        return (floor($v) === $v && abs($v) < 1e15) ? (string)(int)$v : sprintf('%.6F', $v);
    }
}
