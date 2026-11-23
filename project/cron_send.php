<?php
declare(strict_types=1);

// CLI entrypoint for the systemd timer / cron. It is kept outside public/ and
// never needs a browser session: only the local scheduler can execute it.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/features.php';

$result = docgenCronSend();
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
