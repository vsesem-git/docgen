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
$requestPath = is_string($requestPath) ? $requestPath : '/';

// Генератор программ вебинаров: отдельная страница со своим API и статикой.
if (basename($requestPath) === 'webinar.php') {
    require dirname(__DIR__) . '/webinar.php';
    exit;
}
if (str_starts_with($requestPath, '/assets/workinar-')) {
    // Опечатка в адресе не должна отдавать 404 с текстом приложения.
    http_response_code(404);
    exit;
}
if (str_starts_with($requestPath, '/assets/')) {
    $asset = basename($requestPath);
    $allowed = [
        'webinar.css' => 'text/css; charset=utf-8',
        'webinar.js' => 'application/javascript; charset=utf-8',
        'golos-cyrillic.woff2' => 'font/woff2',
        'golos-latin.woff2' => 'font/woff2',
        'logo.svg' => 'image/svg+xml',
        'hero.svg' => 'image/svg+xml',
    ];
    $assetDir = dirname(__DIR__) . '/public/assets';
    if (isset($allowed[$asset]) && is_file($assetDir . '/' . $asset)) {
        header('Content-Type: ' . $allowed[$asset]);
        header('Content-Length: ' . filesize($assetDir . '/' . $asset));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($assetDir . '/' . $asset);
        exit;
    }
    // Неизвестный файл в /assets/ — это 404, а не главная страница с кодом 200:
    // иначе опечатка в пути тихо ломает подключение стилей и скриптов.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not Found';
    exit;
}
// Менеджер очереди: сама страница и её вход. Данные очереди по-прежнему
// обслуживает общий диспетчер index.php — у него свои ?action= для очереди.
if (basename($requestPath) === 'queue.php') {
    $queueAction = (string) ($_GET['action'] ?? '');
    if ($queueAction === '' || $queueAction === 'login' || $queueAction === 'logout' || $queueAction === 'page') {
        require dirname(__DIR__) . '/queue_manager.php';
        exit;
    }
}

require dirname(__DIR__) . '/index.php';
