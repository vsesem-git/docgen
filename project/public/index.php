<?php
declare(strict_types=1);

// Only this directory is exposed by the PHP test server. Application code,
// record data, saved templates, queue files and SMTP secrets stay outside it.
//
// Ubuntu installs run `php -S ... -t public public/index.php`, so every URL
// reaches this router first. Without this branch /queue.php silently renders
// the main page instead of the queue manager. Serve the manager page here;
// its ?action=... requests still go through the main JSON dispatch below.
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (basename((string) $requestPath) === 'queue.php' && !isset($_GET['action'])) {
    require dirname(__DIR__) . '/queue_manager.php';
    exit;
}

require dirname(__DIR__) . '/index.php';
