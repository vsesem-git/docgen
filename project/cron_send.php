<?php
declare(strict_types=1);

// CLI entrypoint for the systemd timer / cron. It is kept outside public/ and
// never needs a browser session: only the local scheduler can execute it.
// With --account=ID the coordinator starts one worker per mailbox, so each
// mailbox has its own send stream and its own letters-per-minute limit.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/features.php';

$accountId = '';
foreach (array_slice((array) ($argv ?? []), 1) as $argument) {
    if (str_starts_with((string) $argument, '--account=')) {
        $accountId = trim(substr((string) $argument, strlen('--account=')));
    }
}
if ($accountId !== '' && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $accountId) !== 1) {
    fwrite(STDERR, "Invalid account id\n");
    exit(1);
}

$result = $accountId === '' ? docgenCronSend(null, true) : docgenCronSend($accountId, false);

// The same one-minute timer also runs the webinar generator schedule (07:00,
// 13:30, 20:00 Moscow time). A generator failure never blocks letter sending
// and is reported separately.
$webinars = ['ok' => true, 'skipped' => 'not_started'];
if ($accountId === '') {
    try {
        require __DIR__ . '/webinars/source.php';
        require __DIR__ . '/webinars/http.php';
        require __DIR__ . '/webinars/docx.php';
        require __DIR__ . '/webinars/store.php';
        require __DIR__ . '/webinars/telegram.php';
        require __DIR__ . '/webinars/monitor.php';
        require __DIR__ . '/webinars/bot.php';
        $webinars = webinarsMonitorTick();
    } catch (Throwable $error) {
        $webinars = ['ok' => false, 'error' => mb_substr($error->getMessage(), 0, 300, 'UTF-8')];
    }
}

echo json_encode(['ok' => true] + $result + ['webinars' => $webinars], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
