<?php
declare(strict_types=1);

/**
 * Генератор программ вебинаров ЦМК — отдельная страница docgen.
 *
 * Данные сайта «Кодекса» оформляются в фирменный Word ЦМК. Генератор живёт в
 * собственной папке webinars/ и не смешивается с формированием писем: общие с
 * основной страницей только вход по паролю, CSRF и приватный каталог storage.
 *
 * Запуск: public/index.php направляет сюда /webinar.php.
 */

/**
 * Общая точка ошибок для кода docgen (features.php зовёт sendJson при
 * истёкшем CSRF и при запросе без входа). index.php объявляет её у себя, а
 * страница вебинаров собирается раньше — здесь нужна своя копия.
 */
if (!function_exists('sendJson')) {
    function sendJson(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

function webinarsJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require __DIR__ . '/features.php';
require __DIR__ . '/webinars/source.php';
require __DIR__ . '/webinars/http.php';
require __DIR__ . '/webinars/docx.php';
require __DIR__ . '/webinars/store.php';
require __DIR__ . '/webinars/telegram.php';
require __DIR__ . '/webinars/monitor.php';

$webinarsAction = (string) ($_GET['action'] ?? '');
// Вход и выход обрабатывает общий код docgen; после входа возвращаемся сюда.
// Адрес возврата нужен и в GET (страница входа), и в POST (проверка пароля):
// роутер открывает эту страницу как /webinar.php, а не как ./.
$_GET['return'] = $_REQUEST['return'] = $_POST['return'] = 'webinar.php';
docgenBoot($webinarsAction);

/** Бланк ЦМК: загруженный в настройках или утверждённый в поставке. */
function webinarsLayoutPath(): string
{
    return is_file(WEBINARS_LAYOUT_FILE) ? WEBINARS_LAYOUT_FILE : WEBINARS_PACKAGE_LAYOUT;
}

function webinarsLayoutInfo(): array
{
    $path = webinarsLayoutPath();
    return [
        'custom' => is_file(WEBINARS_LAYOUT_FILE),
        'name' => basename($path),
        'size' => (int) @filesize($path),
        'modified' => (int) @filemtime($path),
        'sha256' => is_file($path) ? (string) @hash_file('sha256', $path) : '',
    ];
}

/** Найти карточку по адресу среди сохранённого списка. */
function webinarsFindEvent(string $url): array
{
    $state = webinarsState();
    foreach ($state['events'] as $key => $event) {
        if ($event['url'] === $url || $key === $url) {
            return $event;
        }
    }
    throw new InvalidArgumentException('Карточка не найдена в сохранённом списке. Обновите расписание.');
}

/** Сформировать выбранные карточки и положить выгрузку в приватный каталог. */
function webinarsExport(): never
{
    set_time_limit(300);
    $raw = (string) ($_POST['event_ids'] ?? '[]');
    $ids = json_decode($raw, true);
    if (!is_array($ids) || $ids === []) {
        webinarsJson(['ok' => false, 'error' => 'Отметьте хотя бы один вебинар в списке.'], 422);
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
    if (count($ids) > 180) {
        webinarsJson(['ok' => false, 'error' => 'За один раз можно сформировать не более 180 программ.'], 422);
    }
    $state = webinarsState();
    $editorial = webinarsEditorial();
    $events = [];
    foreach ($ids as $id) {
        $found = null;
        foreach ($state['events'] as $event) {
            if ($event['url'] === $id) {
                $found = $event;
                break;
            }
        }
        if ($found === null) {
            webinarsJson(['ok' => false, 'error' => 'Некоторые карточки больше не в списке. Отметьте их снова.'], 404);
        }
        $events[] = $found;
    }

    webinarsBoot();
    $bundles = webinarsBundles();
    webinarsWriteBundles($bundles);
    $bundleId = bin2hex(random_bytes(16));
    $dir = webinarsBundleDir($bundleId);
    docgenEnsureDir($dir, 0700);

    $documents = [];
    $usedNames = [];
    foreach ($events as $event) {
        $edit = (array) ($editorial[webinarsEventKey($event)] ?? []);
        $name = webinarsFileName($event, $edit);
        $original = $name;
        $suffix = 2;
        while (isset($usedNames[mb_strtolower($name, 'UTF-8')])) {
            $name = (string) preg_replace('/\.docx$/u', ' (' . $suffix . ').docx', $original);
            $suffix++;
        }
        $usedNames[mb_strtolower($name, 'UTF-8')] = true;
        $path = $dir . '/' . $bundleId . '-' . count($documents) . '.docx';
        try {
            webinarsRenderDocx($event, webinarsLayoutPath(), $edit, $path);
        } catch (WebinarsSourceError $error) {
            webinarsJson(['ok' => false, 'error' => $error->getMessage()], 422);
        }
        $content = (string) file_get_contents($path);
        $documents[] = ['event' => $event, 'name' => $name, 'file' => basename($path), 'content' => $content];
        try {
            webinarsRememberProgram($event, $name, $path);
        } catch (Throwable $error) {
            webinarsJson(['ok' => false, 'error' => 'Не удалось сохранить программы в историю. Проверьте папку данных и повторите формирование.'], 500);
        }
    }

    $zipName = webinarsBundleName(array_column($documents, 'event'));
    $zipFile = $dir . '/' . $bundleId . '.zip';
    try {
        webinarsZip(array_map(static fn(array $document): array => ['name' => $document['name'], 'content' => $document['content']], $documents), $zipName, $zipFile);
    } catch (Throwable $error) {
        webinarsJson(['ok' => false, 'error' => $error->getMessage()], 500);
    }
    @chmod($zipFile, 0600);

    $bundles = webinarsBundles();
    $bundles[$bundleId] = [
        'made' => time(),
        'zip' => basename($zipFile),
        'zip_name' => $zipName,
        'docs' => array_map(
            static fn(array $document, int $index): array => ['event' => $document['event']['url'], 'name' => $document['name'], 'file' => $document['file']],
            $documents,
            array_keys($documents)
        ),
    ];
    while (count($bundles) > 10) {
        array_shift($bundles);
    }
    webinarsWriteBundles($bundles);

    $generatedAt = webinarsStamp();
    webinarsJson([
        'ok' => true,
        'count' => count($documents),
        'generated_at' => $generatedAt,
        'zip' => ['url' => 'webinar.php?action=bundle&id=' . $bundleId . '&kind=zip', 'filename' => $zipName],
        'documents' => array_map(
            static fn(array $document, int $index): array => [
                'event_id' => $document['event']['url'],
                'url' => 'webinar.php?action=bundle&id=' . $bundleId . '&kind=word&n=' . $index,
                'filename' => $document['name'],
            ],
            $documents,
            array_keys($documents)
        ),
    ], 201);
}

/** Отдать свежую выгрузку (ZIP или один DOCX) с корректным именем файла. */
function webinarsDownload(): never
{
    $bundleId = (string) ($_GET['id'] ?? '');
    $kind = (string) ($_GET['kind'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $bundleId) !== 1) {
        webinarsJson(['ok' => false, 'error' => 'Ссылка на выгрузку некорректна.'], 400);
    }
    $bundles = webinarsBundles();
    $bundle = $bundles[$bundleId] ?? null;
    if (!is_array($bundle)) {
        webinarsJson(['ok' => false, 'error' => 'Время скачивания истекло. Сформируйте файлы заново.'], 404);
    }
    $bundleDir = webinarsBundleDir($bundleId);
    if ($kind === 'zip') {
        $path = $bundleDir . '/' . basename((string) $bundle['zip']);
        $name = (string) $bundle['zip_name'];
        $mime = 'application/zip';
    } else {
        $index = (int) ($_GET['n'] ?? -1);
        $docs = array_values((array) ($bundle['docs'] ?? []));
        if (!isset($docs[$index])) {
            webinarsJson(['ok' => false, 'error' => 'Документ не найден в этой выгрузке.'], 404);
        }
        $path = $bundleDir . '/' . basename((string) $docs[$index]['file']);
        $name = (string) $docs[$index]['name'];
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if (!is_file($path)) {
        webinarsJson(['ok' => false, 'error' => 'Файл выгрузки уже удалён. Сформируйте его заново.'], 404);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename=\"document." . ($kind === 'zip' ? 'zip' : 'docx') . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/** Сохранённый Word карточки: остаётся после перезапуска. */
function webinarsSavedDownload(): never
{
    $event = webinarsFindEvent((string) ($_GET['event'] ?? ''));
    $saved = webinarsSavedProgram($event);
    if ($saved === null) {
        webinarsJson(['ok' => false, 'error' => 'Для этой карточки ещё нет сохранённого Word.'], 404);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($saved['path']));
    header("Content-Disposition: attachment; filename=\"document.docx\"; filename*=UTF-8''" . rawurlencode($saved['filename']));
    header('Cache-Control: no-store');
    readfile($saved['path']);
    exit;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---------------------------------------------------------------------------
// JSON API
// ---------------------------------------------------------------------------

if ($webinarsAction !== '' && $webinarsAction !== 'login' && $webinarsAction !== 'logout' && $webinarsAction !== 'page') {
    $postActions = ['scan', 'export', 'editorial_save', 'layout_upload', 'layout_reset', 'monitor_schedule', 'monitor_check', 'telegram_pair', 'telegram_pair_check', 'telegram_test', 'telegram_disconnect'];
    $getActions = ['catalog', 'detail', 'scan_status', 'progress', 'editorial', 'layout', 'monitor', 'telegram_status', 'bundle', 'saved'];
    if (in_array($webinarsAction, $postActions, true) && $method !== 'POST') {
        webinarsJson(['ok' => false, 'error' => 'Для этого действия используйте POST.'], 405);
    }
    if (in_array($webinarsAction, $getActions, true) && $method !== 'GET') {
        webinarsJson(['ok' => false, 'error' => 'Для этого действия используйте GET.'], 405);
    }
    try {
        switch ($webinarsAction) {
            case 'catalog':
                webinarsJson(['ok' => true, 'catalog' => webinarsCatalogPayload()]);

            case 'detail':
                $event = webinarsFindEvent((string) ($_GET['event'] ?? ''));
                webinarsJson([
                    'ok' => true,
                    'event' => $event + ['date_ru' => webinarsDateRu($event['day']), 'hours' => webinarsHours($event)],
                    'editorial' => (array) (webinarsEditorial()[webinarsEventKey($event)] ?? []),
                    'saved' => webinarsSavedProgram($event) !== null,
                ]);

            case 'scan':
                $mode = (string) ($_POST['mode'] ?? 'chain');
                $start = trim((string) ($_POST['start'] ?? ''));
                if (!in_array($mode, ['chain', 'single'], true) || mb_strlen($start, 'UTF-8') > 250) {
                    webinarsJson(['ok' => false, 'error' => 'Проверьте режим поиска и адрес карточки.'], 400);
                }
                if ($mode === 'single' && $start === '') {
                    webinarsJson(['ok' => false, 'error' => 'Для одного вебинара укажите адрес карточки.'], 400);
                }
                $progress = bin2hex(random_bytes(16));
                webinarsProgressWrite($progress, ['state' => 'working', 'count' => 0, 'message' => 'Открываем расписание…']);
                $result = webinarsFetchChain(
                    ['mode' => $mode, 'start' => $start === '' ? null : $start],
                    static function (int $count) use ($progress): void {
                        webinarsProgressWrite($progress, ['state' => 'working', 'count' => $count, 'message' => 'Проверено карточек: ' . $count . '…']);
                    }
                );
                if ($mode === 'chain' && $start === '') {
                    $saved = webinarsSaveCatalog($result['entries'], $result['home'], 'live');
                    $message = 'Расписание сохранено: ' . $saved['total'] . ' карточек.';
                } else {
                    // Ручной срез не заменяет полный список: он только показывается.
                    $saved = ['added' => [], 'total' => count($result['entries'])];
                    $message = 'Прочитано карточек: ' . count($result['entries']) . '. Общий список не изменён.';
                }
                webinarsProgressWrite($progress, ['state' => 'ready', 'count' => count($result['entries']), 'message' => $message]);
                webinarsJson([
                    'ok' => true,
                    'progress' => $progress,
                    'message' => $message,
                    'catalog' => $mode === 'chain' && $start === '' ? webinarsCatalogPayload() : null,
                    'single' => $mode === 'single' || $start !== ''
                        ? array_map(static fn(array $event): array => webinarsRow($event, webinarsState()), $result['entries'])
                        : [],
                ]);

            case 'scan_status':
                webinarsJson(['ok' => true, 'progress' => (object) webinarsProgressRead((string) ($_GET['token'] ?? ''))]);

            case 'progress':
                webinarsJson(['ok' => true, 'progress' => (object) webinarsProgressRead((string) ($_GET['token'] ?? ''))]);

            case 'export':
                webinarsExport();

            case 'bundle':
                webinarsDownload();

            case 'saved':
                webinarsSavedDownload();

            case 'editorial':
                $event = webinarsFindEvent((string) ($_GET['event'] ?? ''));
                webinarsJson([
                    'ok' => true,
                    'key' => webinarsEventKey($event),
                    'editorial' => (array) (webinarsEditorial()[webinarsEventKey($event)] ?? []),
                    'event' => $event,
                ]);

            case 'editorial_save':
                $raw = (string) ($_POST['entry'] ?? '');
                $key = (string) ($_POST['key'] ?? '');
                $entry = json_decode($raw, true);
                if (!is_array($entry)) {
                    webinarsJson(['ok' => false, 'error' => 'Не удалось прочитать текст редактуры.'], 400);
                }
                webinarsSaveEditorial($key, $entry);
                webinarsJson(['ok' => true, 'notice' => 'Редактура сохранена. Она применится при следующем формировании.']);

            case 'layout':
                webinarsJson(['ok' => true, 'layout' => webinarsLayoutInfo() + ['package' => basename(WEBINARS_PACKAGE_LAYOUT)]]);

            case 'layout_upload':
                $file = $_FILES['layout'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                    webinarsJson(['ok' => false, 'error' => 'Выберите DOCX-файл бланка.'], 400);
                }
                if ((int) $file['size'] > 20_000_000) {
                    webinarsJson(['ok' => false, 'error' => 'Бланк больше 20 МБ — вероятно, это не Word-шаблон.'], 413);
                }
                $probe = WEBINARS_STORAGE_DIR . '/layout.candidate.' . bin2hex(random_bytes(4)) . '.docx';
                webinarsBoot();
                if (!@move_uploaded_file((string) $file['tmp_name'], $probe)) {
                    webinarsJson(['ok' => false, 'error' => 'Не удалось сохранить загруженный бланк.'], 500);
                }
                try {
                    webinarsValidateLayout($probe);
                    if (!@rename($probe, WEBINARS_LAYOUT_FILE)) {
                        throw new RuntimeException('Не удалось заменить активный бланк.');
                    }
                    @chmod(WEBINARS_LAYOUT_FILE, 0600);
                } catch (Throwable $error) {
                    @unlink($probe);
                    webinarsJson(['ok' => false, 'error' => $error->getMessage()], 422);
                }
                webinarsJson(['ok' => true, 'layout' => webinarsLayoutInfo(), 'notice' => 'Бланк заменён. Структура проверена: шапка, ведущие, программа, условия и анкета на месте.']);

            case 'layout_reset':
                @unlink(WEBINARS_LAYOUT_FILE);
                webinarsJson(['ok' => true, 'layout' => webinarsLayoutInfo(), 'notice' => 'Вернулся утверждённый бланк из поставки.']);

            case 'monitor':
                webinarsJson(['ok' => true, 'monitor' => webinarsMonitorStatus()]);

            case 'monitor_schedule':
                $enabled = (string) ($_POST['enabled'] ?? '') === '1';
                webinarsMonitorToggle($enabled);
                webinarsJson([
                    'ok' => true,
                    'monitor' => webinarsMonitorStatus(),
                    'notice' => $enabled
                        ? 'Расписание включено. Проверки: 07:00, 13:30, 20:00 МСК — их выполняет служба на сервере.'
                        : 'Автоматические проверки приостановлены. Сохранённый список не изменится.',
                ]);

            case 'monitor_check':
                $result = webinarsMonitorCheckNow();
                webinarsJson(['ok' => true, 'result' => $result, 'catalog' => webinarsCatalogPayload(), 'monitor' => webinarsMonitorStatus()], $result['ok'] ? 200 : 200);

            case 'telegram_status':
                $config = webinarsTelegramConfig();
                $status = webinarsMonitorStatus();
                webinarsJson([
                    'ok' => true,
                    'configured' => $config !== null,
                    'env' => $config !== null && !empty($config['env']),
                    'pending' => $status['pending'],
                ]);

            case 'telegram_pair':
                // Шаг 1: проверить токен и выдать код. Ответ короткий — код виден
                // сразу, а не после трёх минут ожидания, которые обрывал браузер.
                if (webinarsTelegramConfig() !== null && !empty(webinarsTelegramConfig()['env'])) {
                    webinarsJson(['ok' => false, 'error' => 'Telegram задан окружением службы. Для настройки из панели уберите переменные DOCGEN_WEBINARS_TELEGRAM_*.'], 409);
                }
                $token = webinarsTelegramCheckToken((string) ($_POST['token'] ?? ''));
                $pair = webinarsTelegramPairStart($token);
                webinarsJson([
                    'ok' => true,
                    'pair' => [
                        'code' => $pair['code'],
                        'username' => $pair['username'],
                        'url' => $pair['url'],
                        'expires' => $pair['expires'],
                    ],
                    'message' => 'Откройте @' . $pair['username'] . ', нажмите «Запустить» и отправьте код в личном чате.',
                ]);

            case 'telegram_pair_check':
                // Шаг 2: интерфейс сам опрашивает этот эндпоинт; каждый вызов —
                // один короткий getUpdates, поэтому запрос не висит минутами.
                if (webinarsTelegramConfig() !== null && !empty(webinarsTelegramConfig()['env'])) {
                    webinarsJson(['ok' => false, 'error' => 'Telegram задан окружением службы. Для настройки из панели уберите переменные DOCGEN_WEBINARS_TELEGRAM_*.'], 409);
                }
                $token = webinarsTelegramCheckToken((string) ($_POST['token'] ?? ''));
                $check = webinarsTelegramPairCheck($token);
                webinarsJson([
                    'ok' => true,
                    'state' => $check['state'],
                    'message' => $check['message'],
                    'monitor' => $check['state'] === 'done' ? webinarsMonitorStatus() : null,
                ]);

            case 'telegram_test':
                $config = webinarsTelegramConfig();
                if ($config === null) {
                    webinarsJson(['ok' => false, 'error' => 'Сначала привяжите бота к личному чату.'], 409);
                }
                try {
                    webinarsTelegramSend($config['token'], $config['chat_id'], WEBINARS_TELEGRAM_TEST);
                } catch (WebinarsTelegramError $error) {
                    webinarsJson(['ok' => false, 'error' => $error->getMessage()], 400);
                }
                webinarsJson(['ok' => true, 'notice' => 'Telegram подтвердил отправку тестового сообщения.']);

            case 'telegram_disconnect':
                if (webinarsTelegramConfig() !== null && !empty(webinarsTelegramConfig()['env'])) {
                    webinarsJson(['ok' => false, 'error' => 'Удалите настройки Telegram из окружения службы и перезапустите её.'], 409);
                }
                webinarsTelegramForget();
                webinarsJson(['ok' => true, 'monitor' => webinarsMonitorStatus(), 'notice' => 'Чат отвязан. Новые уведомления останутся в очереди.']);

            default:
                webinarsJson(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
        }
    } catch (WebinarsSourceError | WebinarsNetworkError $error) {
        webinarsJson(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (WebinarsTelegramError $error) {
        webinarsJson(['ok' => false, 'error' => $error->getMessage()], 400);
    } catch (InvalidArgumentException $error) {
        webinarsJson(['ok' => false, 'error' => $error->getMessage()], 400);
    } catch (Throwable $error) {
        error_log('webinar.php: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
        webinarsJson(['ok' => false, 'error' => 'Не удалось обработать запрос. Подробности в журнале службы.'], 500);
    }
}

/** Проверить структуру бланка тем же условием, что и формирование. */
function webinarsValidateLayout(string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new WebinarsSourceError('Файл не похож на DOCX: не удалось открыть пакет.');
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    $doc = new DOMDocument();
    if ($xml === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
        throw new WebinarsSourceError('В бланке нет основного текста документа.');
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', WEBINARS_NS_W);
    $paras = webinarsBodyParagraphs($xp);
    $tables = $xp->query('/w:document/w:body/w:tbl');
    if (count($tables) !== 3 || count($paras) !== 28
        || webinarsParagraphText($paras[1]) !== 'Уважаемые коллеги!'
        || webinarsParagraphText($paras[6]) !== 'ВЕДУЩИЕ ВЕБИНАРА'
        || webinarsParagraphText($paras[11]) !== 'ПРОГРАММА ВЕБИНАРА'
        || webinarsParagraphText($paras[23]) !== 'УСЛОВИЯ УЧАСТИЯ'
        || webinarsParagraphText($paras[26]) !== 'Анкета для регистрации на вебинар') {
        throw new WebinarsSourceError('Это не утверждённый бланк ЦМК: ожидаются шапка, «ВЕДУЩИЕ ВЕБИНАРА», «ПРОГРАММА ВЕБИНАРА», «УСЛОВИЯ УЧАСТИЯ» и анкета.');
    }
}

// ---------------------------------------------------------------------------
// Страница
// ---------------------------------------------------------------------------

$webinarsCsrf = docgenCsrfToken();
$webinarsCatalog = webinarsCatalogPayload();
$webinarsLayout = webinarsLayoutInfo();
$webinarsMonitor = webinarsMonitorStatus();
$webinarsAuth = docgenAuthHash() !== '';
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Генератор программ вебинаров · docgen</title>
<link rel="stylesheet" href="assets/webinar.css">
</head>
<body data-csrf="<?= docgenEsc($webinarsCsrf) ?>">
<svg class="sprite" aria-hidden="true"><symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 5v5h-5M4 19v-5h5m-4-4a7.5 7.5 0 0 1 12.8-3.3L20 9m-16 6 2.2 2.3A7.5 7.5 0 0 0 19 14"/></symbol><symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.7" cy="10.7" r="6.5"/><path d="m16 16 4 4"/></symbol><symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3.5" y="5.4" width="17" height="15" rx="2.3"/><path d="M7.8 3.6v4m8.4-4v4M4 10.5h16"/></symbol><symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8zm0 0v5h5M8.5 12h7m-7 4h7"/></symbol><symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3.5v11m-4.5-4.2 4.5 4.5 4.5-4.5M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/></symbol><symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol><symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol><symbol id="i-external" viewBox="0 0 24 24"><path d="M13 5h6v6m0-6-8 8m6 0v5H6V7h5"/></symbol><symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol><symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 9a6 6 0 1 0-12 0c0 6-2 6.5-2 7.5h16c0-1-2-1.5-2-7.5m-8 10a2 2 0 0 0 4 0"/></symbol><symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5s9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.6"/></symbol><symbol id="i-people" viewBox="0 0 24 24"><circle cx="9" cy="7.5" r="2.8"/><path d="M3.5 19v-2a3.5 3.5 0 0 1 3.5-3.5h4a3.5 3.5 0 0 1 3.5 3.5v2"/></symbol><symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01m-.01 6h.01m-.01 6h.01"/></symbol><symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5m0 3.7h.01"/></symbol><symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.1 2.5 7.4 7.5 9.5 5-2.1 7.5-5.4 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/></symbol><symbol id="i-archive" viewBox="0 0 24 24"><rect x="3.5" y="4" width="17" height="4" rx="1"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 5h4"/></symbol><symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16zM13.5 6.5l4 4"/></symbol><symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol></svg>

<header class="topbar">
  <div class="frame topbar-inner">
    <div class="brand">
      <a class="back" href="./" title="Вернуться к формированию документов">← docgen</a>
      <div><span class="brand-name">Генератор ЦМК</span><span class="brand-caption">Программы вебинаров</span></div>
    </div>
    <div class="top-actions">
      <span class="template-badge"><svg class="icon"><use href="#i-shield"/></svg><span>Фирменный бланк ЦМК</span></span>
      <button type="button" class="button button-help" id="open-monitor" title="Мониторинг новых карточек"><svg class="icon"><use href="#i-bell"/></svg><span>Мониторинг</span></button>
      <button type="button" class="button button-help" id="open-layout" title="Бланк ЦМК"><svg class="icon"><use href="#i-upload"/></svg><span>Бланк</span></button>
      <button type="button" class="button button-help" id="open-help" title="Справка"><svg class="icon"><use href="#i-eye"/></svg><span>Справка</span></button>
    </div>
  </div>
</header>

<main class="frame workspace">
  <section class="hero">
    <div class="hero-copy">
      <p class="eyebrow"><span></span> ЦЕНТР МЕЖРЕГИОНАЛЬНЫХ КОММУНИКАЦИЙ</p>
      <h1>Программы вебинаров</h1>
      <p class="hero-description">Из расписания «Кодекса» в Word — на фирменном бланке ЦМК. Сайт и файлы Dropbox не изменяются.</p>
    </div>
  </section>

  <section class="card source-card">
    <div class="card-head"><div><h2>Источник расписания</h2><span class="source-domain">web-kodeks.ru</span></div></div>
    <form id="scan-form" class="source-form" novalidate>
      <fieldset class="mode-toggle">
        <label><input type="radio" name="scan-mode" value="chain" checked><span>По цепочке</span></label>
        <label><input type="radio" name="scan-mode" value="single"><span>Один вебинар</span></label>
      </fieldset>
      <label class="source-input"><span class="visually-hidden">Адрес карточки</span><input id="start-url" name="start" autocomplete="off" maxlength="250" placeholder="Начать с главной"></label>
      <button type="submit" class="button button-outline refresh-button" id="scan-button"><svg class="icon"><use href="#i-refresh"/></svg><span>Обновить с сайта</span></button>
    </form>
    <p id="scan-status" class="scan-feedback" role="status" hidden></p>
  </section>

  <div class="workspace-grid">
    <section class="card schedule">
      <div class="schedule-head">
        <div class="heading-group"><h2>Расписание</h2><span id="event-count" class="number-pill" aria-label="Количество вебинаров">—</span></div>
        <p class="subtle schedule-range" id="schedule-range">Загружаем список…</p>
        <div class="catalog-status" id="catalog-status" hidden><span class="status-dot"></span><span id="catalog-status-text"></span></div>
      </div>
      <div class="schedule-tools">
        <label class="search-input"><svg class="icon"><use href="#i-search"/></svg><span class="visually-hidden">Поиск вебинаров</span><input type="search" id="event-search" placeholder="Название или дата" autocomplete="off"></label>
        <button type="button" class="button button-outline date-toggle" id="date-toggle" aria-controls="date-controls" aria-expanded="false"><svg class="icon"><use href="#i-calendar"/></svg><span>Период</span><span id="date-indicator" class="date-indicator" hidden></span></button>
      </div>
      <div class="date-controls" id="date-controls" hidden>
        <label>С <input type="date" id="date-from"></label>
        <label>По <input type="date" id="date-to"></label>
        <button id="date-reset" type="button" class="button button-quiet">Сбросить даты</button>
        <p id="date-error" class="date-error" role="alert" hidden>Дата начала должна быть не позже даты окончания.</p>
      </div>
      <div class="filter-tabs">
        <button class="filter-tab" type="button" id="show-all" aria-pressed="true">Все вебинары</button>
        <button class="filter-tab" type="button" id="show-todo" aria-pressed="false">К подготовке <span id="todo-count" class="tab-number">0</span></button>
        <button class="filter-tab" type="button" id="show-done" aria-pressed="false">Сформировано <span id="done-count" class="tab-number">0</span></button>
      </div>
      <div id="table-scroll" class="table-scroll" tabindex="0">
        <table class="table">
          <colgroup><col class="col-select"><col class="col-date"><col><col class="col-action"></colgroup>
          <thead><tr><th scope="col"><input type="checkbox" id="select-visible" aria-label="Выбрать все показанные вебинары" disabled></th><th scope="col">Дата и время</th><th scope="col">Вебинар</th><th scope="col"><span class="visually-hidden">Карточка</span></th></tr></thead>
          <tbody id="events-body"></tbody>
        </table>
        <div id="table-empty" class="table-empty" role="status"><span class="spinner" aria-hidden="true"></span><p>Загружаем расписание…</p></div>
      </div>
      <div class="schedule-footer"><span id="shown-count">—</span><button id="clear-selection" class="button button-quiet" type="button" hidden>Снять выбор</button></div>
    </section>

    <aside class="sidebar">
      <section class="card export-card">
        <div class="aside-top"><span class="feature-icon"><svg class="icon"><use href="#i-file"/></svg></span><span class="format-badge">DOCX + ZIP</span></div>
        <h2>Экспорт программ</h2>
        <p class="subtle">Шапка, контакты и анкета — из бланка ЦМК.</p>
        <div class="selection-box"><span class="selection-value" id="selected-count">0</span><div><strong id="selected-label">Выбрано вебинар</strong><span id="selection-hint">Отметьте карточки в списке</span></div></div>
        <button type="button" class="button button-primary export-button" id="make-docs" disabled><svg class="icon"><use href="#i-file"/></svg><span>Сформировать Word</span></button>
        <div class="result-box" id="export-result" hidden>
          <span class="result-status"><svg class="icon"><use href="#i-check"/></svg> <strong id="result-text">Файл готов</strong></span>
          <a id="download-word" class="button button-primary export-button" download hidden><svg class="icon"><use href="#i-download"/></svg><span>Скачать Word</span></a>
          <a id="download-zip" class="button button-outline export-button" download><svg class="icon"><use href="#i-archive"/></svg><span>Скачать ZIP</span></a>
          <details class="word-list" id="batch-words" hidden><summary>Скачать Word по одному</summary><ul id="word-links"></ul></details>
          <button id="remake-docs" type="button" class="button button-quiet">Сформировать заново</button>
        </div>
        <p class="export-note" id="export-note">Ссылки появятся после формирования</p>
      </section>

      <section class="card preview-card">
        <p class="eyebrow preview-kicker"><svg class="icon"><use href="#i-eye"/></svg> КАРТОЧКА ВЕБИНАРА</p>
        <div id="preview-empty" class="subtle">Выберите вебинар, чтобы открыть его карточку.</div>
        <div id="preview-content" hidden>
          <p class="preview-date" id="preview-date"></p><h3 id="preview-title"></h3>
          <div class="preview-stats"><span><svg class="icon"><use href="#i-people"/></svg><span id="speaker-count"></span></span><span><svg class="icon"><use href="#i-list"/></svg><span id="topic-count"></span></span></div>
          <div class="preview-actions">
            <button type="button" class="button button-outline" id="open-program">Смотреть программу</button>
            <button type="button" class="button button-outline" id="open-editorial"><svg class="icon"><use href="#i-edit"/></svg><span>Редактура</span></button>
            <a id="source-link" href="#" target="_blank" rel="noopener noreferrer" class="button button-icon" aria-label="Открыть страницу источника"><svg class="icon"><use href="#i-external"/></svg></a>
          </div>
          <a class="saved-link" id="saved-word" download hidden><svg class="icon"><use href="#i-download"/></svg><span>Сохранённый Word</span></a>
        </div>
      </section>
    </aside>
  </div>

  <footer class="footer"><span>ЦМК · Генератор программ</span><a href="http://www.web-kodeks.ru/" target="_blank" rel="noopener noreferrer">Источник: web-kodeks.ru <svg class="icon"><use href="#i-external"/></svg></a></footer>
</main>

<dialog id="program-dialog" class="dialog" aria-labelledby="program-dialog-heading">
  <div class="dialog-head"><div><p class="eyebrow">ПРОГРАММА С САЙТА</p><h2 id="program-dialog-heading">Вебинар</h2><p id="program-dialog-date" class="subtle"></p></div><form method="dialog"><button type="submit" class="button button-icon" aria-label="Закрыть"><svg class="icon"><use href="#i-close"/></svg></button></form></div>
  <div id="program-dialog-body" class="dialog-body"></div>
  <div class="dialog-footer"><a class="button button-outline" id="program-source" href="#" rel="noopener noreferrer" target="_blank">Открыть на сайте <svg class="icon"><use href="#i-external"/></svg></a><form method="dialog"><button type="submit" class="button button-primary">Закрыть</button></form></div>
</dialog>

<dialog id="editorial-dialog" class="dialog" aria-labelledby="editorial-heading">
  <div class="dialog-head"><div><p class="eyebrow">РУЧНАЯ РЕДАКТУРА</p><h2 id="editorial-heading">Формулировки для документа</h2><p id="editorial-date" class="subtle"></p></div><form method="dialog"><button type="submit" class="button button-icon" aria-label="Закрыть"><svg class="icon"><use href="#i-close"/></svg></button></form></div>
  <div class="dialog-body">
    <p class="help-note">Пустое поле — значение берётся из карточки сайта или общий текст. Правки применяются к этому вебинару при следующем формировании.</p>
    <div class="editorial-grid">
      <label class="field"><span>Короткое название для файла</span><input id="ed-file_title" type="text" maxlength="200" placeholder="Например: Оплата труда медработников в 2027 году"></label>
      <label class="field"><span>«Участие в вебинаре позволит…»</span><textarea id="ed-benefit" rows="3" maxlength="1500" placeholder="подготовиться к…; уточнить…; разобраться в…"></textarea></label>
      <label class="field"><span>Аудитория (в таблице «О проведении»)</span><input id="ed-audience" type="text" maxlength="400" placeholder="Руководителям и специалистам…"></label>
      <label class="field"><span>Участие (после «с участием»)</span><input id="ed-with_whom" type="text" maxlength="400" placeholder="представителей ФАС России"></label>
      <label class="field"><span>Окончание вводной фразы</span><input id="ed-intro_suffix" type="text" maxlength="10" placeholder=":"></label>
    </div>
    <details class="topics-editor"><summary>Названия тем программы</summary><div id="ed-topics"></div></details>
    <p id="editorial-feedback" class="control-feedback" role="status" hidden></p>
  </div>
  <div class="dialog-footer"><button type="button" class="button button-quiet" id="editorial-clear">Сбросить все поля</button><form method="dialog"><button type="submit" class="button button-outline">Закрыть</button></form><button type="button" class="button button-primary" id="editorial-save">Сохранить редактуру</button></div>
</dialog>

<dialog id="monitor-dialog" class="dialog monitor-dialog" aria-labelledby="monitor-heading">
  <div class="dialog-head"><div><p class="eyebrow">ПРОВЕРКА РАСПИСАНИЯ</p><h2 id="monitor-heading">Мониторинг Telegram</h2></div><form method="dialog"><button type="submit" class="button button-icon" aria-label="Закрыть"><svg class="icon"><use href="#i-close"/></svg></button></form></div>
  <div class="dialog-body">
    <div class="monitor-overview">
      <div class="monitor-summary"><strong id="monitor-state" role="status">Проверяем состояние…</strong><span id="monitor-last"></span><span id="monitor-next"></span><span id="monitor-note"></span></div>
      <div class="monitor-timebox"><span class="eyebrow">ЕЖЕДНЕВНО · МСК</span><div class="monitor-times" id="monitor-times"><span>07:00</span><span>13:30</span><span>20:00</span></div></div>
    </div>
    <section class="control-section">
      <div><h3>Автопроверка сайта</h3><p>Расписание проверяет служба на сервере. Браузер можно закрыть.</p></div>
      <div class="control-actions"><button type="button" id="monitor-toggle" class="button button-outline"><svg class="icon"><use href="#i-calendar"/></svg><span>Включить расписание</span></button><button type="button" id="monitor-check-now" class="button button-outline"><svg class="icon"><use href="#i-refresh"/></svg><span>Проверить сейчас</span></button></div>
    </section>
    <section class="control-section">
      <div class="control-title"><div><h3>Привязка бота</h3><p>Чат получает дату, тему и ссылку на новые вебинары.</p></div><span class="telegram-pill" id="monitor-telegram">Проверяем…</span></div>
      <p id="telegram-env-note" class="help-note" hidden>Привязка задана окружением службы. Для замены через панель уберите переменные DOCGEN_WEBINARS_TELEGRAM_*.</p>
      <form id="telegram-form" autocomplete="off">
        <label for="telegram-token">Токен от BotFather</label>
        <div class="control-input-row"><input id="telegram-token" type="password" maxlength="125" autocomplete="new-password" placeholder="Вставьте токен" spellcheck="false"><button type="submit" id="telegram-connect" class="button button-primary">Привязать бота</button></div>
      </form>
      <div class="pair-box" id="pair-box" hidden>
        <p>Откройте <a id="pair-bot" href="#" target="_blank" rel="noopener noreferrer"></a>, нажмите «Запустить» и отправьте код в личном чате:</p>
        <div class="pair-code-row"><code id="pair-code"></code></div>
        <p class="subtle" id="pair-remaining"></p>
      </div>
      <p class="control-feedback" id="monitor-feedback" role="status" hidden></p>
      <div class="control-actions telegram-actions">
        <button type="button" id="telegram-test" class="button button-outline" hidden>Отправить тест</button>
        <button type="button" id="telegram-disconnect" class="button button-quiet" hidden>Отвязать бота</button>
      </div>
    </section>
  </div>
  <div class="dialog-footer align-right"><form method="dialog"><button type="submit" class="button button-outline">Закрыть</button></form></div>
</dialog>

<dialog id="layout-dialog" class="dialog help-dialog" aria-labelledby="layout-heading">
  <div class="dialog-head"><div><p class="eyebrow">ФИРМЕННЫЙ БЛАНК</p><h2 id="layout-heading">Бланк программы ЦМК</h2></div><form method="dialog"><button type="submit" class="button button-icon" aria-label="Закрыть"><svg class="icon"><use href="#i-close"/></svg></button></form></div>
  <div class="dialog-body">
    <p>Бланк заполняется как есть: шапка, реквизиты, анкета и оформление остаются утверждёнными. Заменить его можно только на другой бланк той же структуры — проверка выполняется до сохранения.</p>
    <dl class="layout-meta"><dt>Активный файл</dt><dd id="layout-name">—</dd><dt>Размер</dt><dd id="layout-size">—</dd><dt>Источник</dt><dd id="layout-source">—</dd></dl>
    <label class="dropzone" id="layout-drop"><svg class="icon"><use href="#i-upload"/></svg><span>Перетащите DOCX бланка или нажмите, чтобы выбрать</span><input type="file" id="layout-file" accept=".docx" hidden></label>
    <p class="control-feedback" id="layout-feedback" role="status" hidden></p>
  </div>
  <div class="dialog-footer"><button type="button" class="button button-quiet" id="layout-reset">Вернуть утверждённый</button><form method="dialog"><button type="submit" class="button button-primary">Закрыть</button></form></div>
</dialog>

<dialog id="help-dialog" class="dialog help-dialog" aria-labelledby="help-heading">
  <div class="dialog-head"><div><p class="eyebrow">ГЕНЕРАТОР ЦМК</p><h2 id="help-heading">Как работать с генератором</h2></div><form method="dialog"><button type="submit" class="button button-icon" aria-label="Закрыть"><svg class="icon"><use href="#i-close"/></svg></button></form></div>
  <div class="dialog-body"><ol class="help-steps">
    <li><strong>Обновите расписание.</strong> В режиме «По цепочке» оставьте адрес пустым для старта с главной. Чтобы начать с другой карточки, вставьте её адрес или код, например <code>/med</code>.</li>
    <li><strong>Выберите карточки.</strong> «К подготовке» показывает вебинары без сохранённого Word. Метка «Новое» означает, что карточка появилась после известного расписания.</li>
    <li><strong>Сформируйте Word.</strong> Для одной карточки доступны и Word, и ZIP; для нескольких — общий ZIP и отдельные файлы. В ZIP каждая программа лежит в своей папке с датой и названием.</li>
    <li><strong>Проверьте редактуру.</strong> Короткое название для файла и формулировка «Участие в вебинаре позволит…» задаются в карточке и сохраняются для этого вебинара.</li>
  </ol><p class="help-note">Сайт «Кодекса» и файлы в Dropbox генератор не изменяет, формы регистрации не отправляет.</p></div>
  <div class="dialog-footer align-right"><form method="dialog"><button type="submit" class="button button-primary">Понятно</button></form></div>
</dialog>

<div id="toast" class="toast" role="status" aria-live="polite" hidden><svg class="icon"><use id="toast-icon" href="#i-check"/></svg><span id="toast-text"></span><button type="button" id="toast-close" class="button button-icon" aria-label="Закрыть уведомление"><svg class="icon"><use href="#i-close"/></svg></button></div>
<noscript><div class="no-script">Чтобы воспользоваться генератором, включите JavaScript в браузере.</div></noscript>
<script>
window.DOCGEN_WEBINARS = <?= json_encode([
    'csrf' => $webinarsCsrf,
    'catalog' => $webinarsCatalog,
    'layout' => $webinarsLayout,
    'monitor' => $webinarsMonitor,
    'auth' => $webinarsAuth,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/webinar.js"></script>
</body>
</html>
