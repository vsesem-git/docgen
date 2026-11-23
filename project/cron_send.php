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
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
