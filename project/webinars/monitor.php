<?php
declare(strict_types=1);

/**
 * Планировщик автопроверок: 07:00, 13:30, 20:00 МСК.
 *
 * Запускается каждую минуту тем же таймером, что и очередь писем docgen
 * (cron_send.php). Пропущенный слот не догоняется задним числом: проверка идёт
 * только в минуту слота, а повторная обработка того же слота исключена отметкой
 * в состоянии и блокировкой файла.
 */

/**
 * Один шаг планировщика. Возвращает описание того, что сделано, чтобы это можно
 * было увидеть в журнале службы.
 *
 * @return array<string, mixed>
 */
function webinarsMonitorTick(?DateTimeImmutable $now = null): array
{
    $now = $now ?? webinarsNow();
    if (!webinarsMonitorEnabled()) {
        return ['ok' => true, 'skipped' => 'disabled'];
    }
    $slot = webinarsDueSlot($now);
    if ($slot === null) {
        return ['ok' => true, 'skipped' => 'no_slot', 'next' => webinarsNextSlot($now)];
    }
    $state = webinarsState();
    if ((string) ($state['monitor']['last_slot'] ?? '') === $slot) {
        return ['ok' => true, 'skipped' => 'already_done', 'slot' => $slot];
    }
    // На чистой установке каталога данных ещё нет: без него fopen() не создаёт
    // файл блокировки, и проверка молча считалась бы занятой.
    webinarsBoot();
    $lock = @fopen(WEBINARS_MONITOR_LOCK, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['ok' => true, 'skipped' => 'busy', 'slot' => $slot];
    }
    try {
        // Слот отмечается до проверки: рестарт службы не вызовет повторную рассылку.
        $state = webinarsState();
        $state['monitor'] = array_merge((array) ($state['monitor'] ?? []), ['last_slot' => $slot, 'started_at' => webinarsStamp($now)]);
        webinarsWriteState($state);
        $result = webinarsCheckNow(['mode' => 'chain']);
        return ['ok' => true, 'slot' => $slot] + $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Ручной запуск проверки из интерфейса. Пропуски слотов не трогает.
 *
 * @return array<string, mixed>
 */
function webinarsMonitorCheckNow(): array
{
    webinarsBoot();
    $lock = @fopen(WEBINARS_MONITOR_LOCK, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Проверка уже выполняется. Дождитесь результата.');
    }
    try {
        return webinarsCheckNow(['mode' => 'chain']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Состояние мониторинга для панели. Токен и чат-ID не раскрываются. */
function webinarsMonitorStatus(): array
{
    $state = webinarsState();
    $config = webinarsTelegramConfig();
    $last = is_array($state['last_check'] ?? null) ? $state['last_check'] : null;
    return [
        'enabled' => webinarsMonitorEnabled(),
        'configured' => $config !== null,
        'env' => $config !== null && !empty($config['env']),
        'pending' => count(webinarsPendingNotices($state)),
        'times' => WEBINARS_CHECK_TIMES,
        'next_check' => webinarsMonitorEnabled() ? webinarsNextSlot() : null,
        'last_at' => is_array($last) ? (string) ($last['at'] ?? '') : '',
        'last_note' => is_array($last) ? (string) ($last['note'] ?? '') : '',
        'last_ok' => is_array($last) ? (bool) ($last['ok'] ?? false) : null,
    ];
}
