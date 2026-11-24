<?php
declare(strict_types=1);

/**
 * CLI-точка планировщика генератора вебинаров.
 *
 * Запускается тем же таймером, что и очередь писем docgen (раз в минуту).
 * Проверка идёт только в минуту слота 07:00, 13:30 или 20:00 МСК и только когда
 * расписание включено в панели. Без сессии и без браузера.
 *
 *   php webinars_tick.php            # один шаг планировщика
 *   php webinars_tick.php --force    # проверка немедленно (отладка на сервере)
 */
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/features.php';
require __DIR__ . '/webinars/source.php';
require __DIR__ . '/webinars/http.php';
require __DIR__ . '/webinars/docx.php';
require __DIR__ . '/webinars/store.php';
require __DIR__ . '/webinars/telegram.php';
require __DIR__ . '/webinars/monitor.php';
require __DIR__ . '/webinars/bot.php';

$arguments = array_slice((array) ($argv ?? []), 1);
$force = in_array('--force', $arguments, true);
try {
    $result = $force ? ['ok' => true, 'slot' => 'manual'] + webinarsMonitorCheckNow() : webinarsMonitorTick();
} catch (Throwable $error) {
    // STDERR есть только в CLI; при запуске из веб-обвязки пишем в лог ошибок.
    if (defined('STDERR')) {
        fwrite(STDERR, 'webinars_tick: ' . $error->getMessage() . PHP_EOL);
    } else {
        error_log('webinars_tick: ' . $error->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
