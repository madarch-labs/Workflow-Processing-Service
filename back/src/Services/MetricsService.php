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
    public const STEP_ERRORS_HANDLED = 'wps_step_errors_handled_total';
    public const EMAILS_SENT = 'wps_emails_sent_total';
    public const HTTP_REQUESTS = 'wps_http_requests_total';
    public const CRM_LINKS = 'wps_crm_link_total';
    public const CONDITION_BRANCHES = 'wps_condition_branch_total';
    public const PROCESS_STOPPED = 'wps_process_stopped_total';
    public const LOOP_ITEMS = 'wps_loop_items_total';
    public const LOOP_ITEM_FAILURES = 'wps_loop_item_failures_total';
    public const LOOP_OVERFLOW = 'wps_loop_overflow_total';
    public const UPDATE_RECORDS_ITEMS = 'wps_update_records_items_total';
    public const AGGREGATE_RECORDS = 'wps_aggregate_records_scanned';
    public const NOTIFICATIONS_SENT = 'wps_notifications_sent_total';
    public const NOTIFICATIONS_FAILED = 'wps_notifications_failed_total';
    public const ASSIGNMENTS = 'wps_assign_total';
    public const CONDITION_EXPRESSION_ERRORS = 'wps_condition_expression_errors_total';
    public const EXECUTIONS_PARKED = 'wps_executions_parked_total';
    public const EXECUTIONS_WOKEN = 'wps_executions_woken_total';
    public const WAIT_RESUME_FAILED = 'wps_wait_resume_failed_total';
    public const WAIT_LATENESS = 'wps_wait_lateness_seconds';
    public const EMAILS_FAILED = 'wps_emails_failed_total';
    public const EMAILS_RATE_LIMITED = 'wps_emails_rate_limited_total';
    public const RUN_ONCE_SKIPPED = 'wps_run_once_skipped_total';
    public const RUN_ONCE_NO_ID = 'wps_run_once_no_id_total';
    public const CONDITION_CAST_ERRORS = 'wps_condition_cast_errors_total';
    public const CONDITION_CHANGE_INFO_MISSING = 'wps_condition_change_info_missing_total';
    public const PROGRAM_COMPILE = 'wps_program_compile_total';
    public const PROGRAM_RESUME_MISMATCH = 'wps_program_resume_mismatch_total';
    public const INSTRUCTIONS_EXECUTED = 'wps_instructions_executed_total';
    public const SECRET_RESOLVE = 'wps_secret_resolve_total';
    public const SECRET_RESOLVE_ERRORS = 'wps_secret_resolve_errors_total';
    public const SIDE_EFFECTS_DONE = 'wps_side_effects_done_total';
    public const SIDE_EFFECTS_FAILED = 'wps_side_effects_failed_total';
    public const SIDE_EFFECTS_REPLAYED = 'wps_side_effects_replayed_total';
    public const SIDE_EFFECTS_UNKNOWN = 'wps_side_effects_unknown_total';
    public const TEMPLATE_RENDER_ERRORS = 'wps_template_render_errors_total';
    public const EVENTS_LEGACY_FORMAT = 'wps_events_legacy_format_total';
    public const EVENTS_MALFORMED_FIELDS = 'wps_events_malformed_fields_total';
    public const EVENTS_SKIPPED_BY_TYPE = 'wps_events_skipped_by_type_total';
    public const EVENTS_UNSUPPORTED_TYPE = 'wps_events_unsupported_type_total';

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
        self::STEP_ERRORS_HANDLED => ['counter', 'Ошибки шагов, обработанные on_error (continue / goto)'],
        self::EMAILS_SENT => ['counter', 'Отправлено писем (send_email)'],
        self::HTTP_REQUESTS => ['counter', 'Выполнено HTTP-запросов (http_request)'],
        self::CRM_LINKS => ['counter', 'Шаги link_records / unlink_records'],
        self::CONDITION_BRANCHES => ['counter', 'Выполнено блоков condition (выбор ветки)'],
        self::PROCESS_STOPPED => ['counter', 'Выполнения, завершённые шагом stop_process'],
        self::LOOP_ITEMS => ['counter', 'Элементы, обработанные циклами foreach'],
        self::LOOP_ITEM_FAILURES => ['counter', 'Элементы foreach с ошибкой (on_item_error: continue)'],
        self::LOOP_OVERFLOW => ['counter', 'Циклы foreach, превысившие max_items'],
        self::UPDATE_RECORDS_ITEMS => ['counter', 'Записи CRM, обновлённые шагами update_records'],
        self::AGGREGATE_RECORDS => ['summary', 'Записи CRM, прочитанные типом изменения aggregate'],
        self::NOTIFICATIONS_SENT => ['counter', 'Доставленные уведомления (notify), по доставкам'],
        self::NOTIFICATIONS_FAILED => ['counter', 'Каналы notify с ошибкой'],
        self::ASSIGNMENTS => ['counter', 'Шаги assign (назначение ответственного)'],
        self::CONDITION_EXPRESSION_ERRORS => ['counter', 'Ошибки вычисления выражений в условиях'],
        self::EXECUTIONS_PARKED => ['counter', 'Выполнения, переведённые в ожидание (waiting)'],
        self::EXECUTIONS_WOKEN => ['counter', 'Ожидающие выполнения, продолженные после срока'],
        self::WAIT_RESUME_FAILED => ['counter', 'Неудачные попытки продолжить ожидающее выполнение'],
        self::WAIT_LATENESS => ['summary', 'Опоздание пробуждения относительно срока, с'],
        self::EMAILS_FAILED => ['counter', 'Ошибки отправки писем'],
        self::EMAILS_RATE_LIMITED => ['counter', 'Получатели, пропущенные из-за лимита писем в час'],
        self::RUN_ONCE_SKIPPED => ['counter', 'Запуски, заблокированные однократным запуском (run_once)'],
        self::RUN_ONCE_NO_ID => ['counter', 'run_once не применён: у записи нет id'],
        self::CONDITION_CAST_ERRORS => ['counter', 'Ошибки приведения типа в условиях (cast)'],
        self::CONDITION_CHANGE_INFO_MISSING => ['counter', 'Операторы изменения на событии без сведений об изменениях (старый формат, ручной запуск)'],
        self::PROGRAM_COMPILE => ['counter', 'Компиляции определений процессов в программу (без кэша)'],
        self::PROGRAM_RESUME_MISMATCH => ['counter', 'Восстановление отклонено: программа изменилась после обновления'],
        self::INSTRUCTIONS_EXECUTED => ['counter', 'Выполнено инструкций программ'],
        self::SECRET_RESOLVE => ['counter', 'Разрешено ссылок на секреты'],
        self::SECRET_RESOLVE_ERRORS => ['counter', 'Ошибки разрешения ссылок на секреты'],
        self::SIDE_EFFECTS_DONE => ['counter', 'Внешние вызовы выполнены и записаны в журнал'],
        self::SIDE_EFFECTS_FAILED => ['counter', 'Внешние вызовы с окончательной ошибкой'],
        self::SIDE_EFFECTS_REPLAYED => ['counter', 'Повтор шага: результат взят из журнала без повторного вызова'],
        self::SIDE_EFFECTS_UNKNOWN => ['counter', 'Неизвестный исход внешнего вызова (UNKNOWN_OUTCOME)'],
        self::TEMPLATE_RENDER_ERRORS => ['counter', 'Ошибки подстановки шаблонов'],
        self::EVENTS_LEGACY_FORMAT => ['counter', 'События старого формата (без event_type)'],
        self::EVENTS_SKIPPED_BY_TYPE => ['counter', 'События без процессов, подписанных на их тип (trigger_on)'],
        self::EVENTS_UNSUPPORTED_TYPE => ['counter', 'События с неподдерживаемым event_type (link, unlink …)'],
        self::EVENTS_MALFORMED_FIELDS => ['counter', 'Отброшенные служебные поля события неверного типа (before, changed_fields, wps_origin)'],
    ];

    public function __construct(private readonly ?Table $table)
    {
    }

    public static function createShared(): self
    {
        if (!class_exists(Table::class)) {
            return new self(null);
        }
        $table = new Table(256);
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
