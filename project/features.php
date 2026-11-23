<?php
/** Local test-application features: private storage, templates, PDF queue, SMTP and login. */

define('DOCGEN_STORAGE_DIR', rtrim((string) (getenv('DOCGEN_STORAGE_DIR') ?: (dirname(__DIR__) . '/storage')), DIRECTORY_SEPARATOR));
define('RECORD_EDITS_FILE', DOCGEN_STORAGE_DIR . '/record_edits.json');
define('DOWNLOADS_DIR', DOCGEN_STORAGE_DIR . '/downloads');
define('DOCGEN_TEMPLATES_DIR', DOCGEN_STORAGE_DIR . '/templates');
define('DOCGEN_QUEUE_DIR', DOCGEN_STORAGE_DIR . '/queue');
define('DOCGEN_SETTINGS_DIR', DOCGEN_STORAGE_DIR . '/config');
define('DOCGEN_SECRETS_DIR', DOCGEN_STORAGE_DIR . '/secrets');
define('DOCGEN_SMTP_SETTINGS_FILE', DOCGEN_SETTINGS_DIR . '/smtp.json');
define('DOCGEN_SMTP_SECRET_FILE', DOCGEN_SECRETS_DIR . '/smtp-secret.json');
define('DOCGEN_DADATA_SECRET_FILE', DOCGEN_SECRETS_DIR . '/dadata-api-key.json');
define('DOCGEN_MANAGER_CHECKS_FILE', DOCGEN_STORAGE_DIR . '/manager_checks.json');
define('DOCGEN_TABLE_DATA_FILE', DOCGEN_STORAGE_DIR . '/table_data.json');
define('DOCGEN_EXCEL_IMPORT_DIR', DOCGEN_STORAGE_DIR . '/excel-imports');
define('DOCGEN_DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');
define('DOCGEN_DADATA_BATCH_SIZE', 20);
define('DOCGEN_TEMPLATE_INDEX', DOCGEN_TEMPLATES_DIR . '/index.json');
define('DOCGEN_DOWNLOAD_TTL_SECONDS', 86400);
define('DOCGEN_MAX_HTML_TEMPLATE_BYTES', 1_000_000);
// Only the interactive "Check SMTP" probe is time-boxed. Actual mail delivery
// keeps PHPMailer's normal timeout, so a slow server can still accept a letter.
define('DOCGEN_SMTP_CONNECT_TIMEOUT_SECONDS', 8);

function docgenEnsureDir(string $directory, int $mode = 0700): void
{
    if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать приватную папку хранения.');
    }
    @chmod($directory, $mode);
}

function docgenReadJson(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : $fallback;
}

function docgenWriteJson(string $path, array $payload): void
{
    docgenEnsureDir(dirname($path));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new RuntimeException('Не удалось сериализовать данные приложения.');
    }
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось записать приватные данные приложения.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось завершить сохранение приватных данных.');
    }
    @chmod($path, 0600);
}

function docgenEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function docgenAuthHash(): string
{
    return trim((string) getenv('DOCGEN_ADMIN_PASSWORD_HASH'));
}

function docgenCsrfToken(): string
{
    // docgenBoot() closes the session before any network I/O. The page still
    // needs the token for its forms, so keep it in memory for this request.
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (!isset($_SESSION['docgen_csrf']) || !is_string($_SESSION['docgen_csrf'])) {
        $_SESSION['docgen_csrf'] = bin2hex(random_bytes(32));
    }
    $cached = $_SESSION['docgen_csrf'];
    return $cached;
}

function docgenVerifyCsrf(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    $expected = docgenCsrfToken();
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        sendJson(['ok' => false, 'error' => 'Сеанс проверки формы истёк. Обновите страницу и повторите действие.'], 403);
    }
}

function docgenRenderLogin(string $error = ''): never
{
    $token = docgenCsrfToken();
    http_response_code($error === '' ? 200 : 401);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $safeError = $error !== '' ? '<div class="error">' . docgenEsc($error) . '</div>' : '';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — Формирование документов</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f2f5f9;color:#16283c;font:15px/1.5 system-ui,"Segoe UI",sans-serif}.box{width:min(420px,calc(100% - 28px));background:white;border:1px solid #dde5ee;border-radius:14px;padding:26px;box-shadow:0 12px 35px #16283c18}h1{font-size:20px;margin:0 0 8px;color:#123c61}.muted{color:#5b6b7f;font-size:13px;margin-bottom:18px}label{display:block;font-size:13px;font-weight:650;margin-bottom:6px}input{width:100%;height:42px;padding:8px 10px;border:1px solid #cbd7e3;border-radius:8px;font:inherit}button{width:100%;margin-top:14px;padding:10px;border:0;border-radius:8px;background:#0e4f8a;color:white;font:inherit;font-weight:700;cursor:pointer}.error{padding:9px 11px;margin:0 0 13px;background:#fdecea;color:#9f2e28;border-radius:7px;font-size:13px}</style></head><body><main class="box"><h1>Формирование документов</h1><div class="muted">Войдите, чтобы открыть тестовое приложение.</div>' . $safeError . '<form method="post" action="?action=login"><input type="hidden" name="csrf_token" value="' . docgenEsc($token) . '"><label for="password">Пароль администратора</label><input id="password" type="password" name="password" autocomplete="current-password" required autofocus><button type="submit">Войти</button></form></main></body></html>';
    exit;
}

function docgenBoot(string $action): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        $sessionDir = DOCGEN_STORAGE_DIR . '/sessions';
        docgenEnsureDir($sessionDir, 0700);
        session_save_path($sessionDir);
        session_name('DOCGENSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');

    $hash = docgenAuthHash();
    $authRequired = $hash !== '';
    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            docgenRenderLogin();
        }
        docgenVerifyCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if ($authRequired && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['docgen_authenticated'] = true;
            $_SESSION['docgen_csrf'] = bin2hex(random_bytes(32));
            header('Location: ./', true, 303);
            exit;
        }
        docgenRenderLogin('Неверный пароль. Попробуйте ещё раз.');
    }
    if ($action === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJson(['ok' => false, 'error' => 'Для выхода используйте POST.'], 405);
        }
        docgenVerifyCsrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        header('Location: ./', true, 303);
        exit;
    }
    $authenticated = !$authRequired || !empty($_SESSION['docgen_authenticated']);
    if (!$authenticated) {
        if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            docgenRenderLogin();
        }
        sendJson(['ok' => false, 'error' => 'Требуется вход администратора. Обновите страницу и войдите.'], 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        docgenVerifyCsrf();
    }
    docgenEnsureDir(DOCGEN_STORAGE_DIR);
    // PHP keeps the session file locked until the request finishes. SMTP checks
    // and mail sending talk to a remote server, so without this the next click
    // (including "Save settings") waits for the whole network timeout. All
    // session data is already verified and loaded at this point; nothing below
    // needs to write to the session. Generate the token first: pages rendered
    // later in this request still need it after the session is closed.
    docgenCsrfToken();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function docgenUserTemplateIndex(): array
{
    $items = docgenReadJson(DOCGEN_TEMPLATE_INDEX, []);
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        $type = (string) ($item['type'] ?? '');
        $ext = $type === 'docx' ? 'docx' : ($type === 'html' ? 'html' : '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $ext === '') {
            continue;
        }
        $path = DOCGEN_TEMPLATES_DIR . '/' . $id . '.' . $ext;
        if (!is_file($path)) {
            continue;
        }
        $result[] = [
            'id' => $id,
            'name' => (string) ($item['name'] ?? ('Шаблон ' . $id)),
            'type' => $type,
            'createdAt' => (string) ($item['createdAt'] ?? ''),
            'updatedAt' => (string) ($item['updatedAt'] ?? ''),
            'path' => $path,
        ];
    }
    return $result;
}

function docgenTemplateList(): array
{
    $templates = [[
        'id' => 'sample-docx',
        'name' => 'Письмо-пример.docx',
        'type' => 'docx',
        'sample' => true,
    ]];
    foreach (docgenUserTemplateIndex() as $template) {
        $templates[] = [
            'id' => $template['id'],
            'name' => $template['name'],
            'type' => $template['type'],
            'sample' => false,
        ];
    }
    return $templates;
}

function docgenResolveTemplate(): array
{
    if (isset($_FILES['template']) && is_array($_FILES['template']) && (int) ($_FILES['template']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $path = uploadedTemplatePath();
        if (!is_string($path)) {
            throw new RuntimeException('Не удалось прочитать загруженный DOCX.');
        }
        return [
            'id' => '__upload__',
            'name' => basename((string) ($_FILES['template']['name'] ?? 'Загруженный шаблон.docx')),
            'type' => 'docx',
            'path' => $path,
            'sample' => false,
        ];
    }
    $id = trim((string) ($_POST['template_id'] ?? ''));
    if ($id === '' && (string) ($_POST['use_sample'] ?? '') === '1') {
        $id = 'sample-docx';
    }
    if ($id === 'sample-docx') {
        validateDocxPath(SAMPLE_TEMPLATE);
        return ['id' => $id, 'name' => 'Письмо-пример.docx', 'type' => 'docx', 'path' => SAMPLE_TEMPLATE, 'sample' => true];
    }
    foreach (docgenUserTemplateIndex() as $template) {
        if ($template['id'] !== $id) {
            continue;
        }
        if ($template['type'] === 'docx') {
            validateDocxPath($template['path']);
        } elseif (filesize($template['path']) > DOCGEN_MAX_HTML_TEMPLATE_BYTES) {
            throw new RuntimeException('HTML-шаблон превышает ограничение 1 МБ.');
        }
        $template['sample'] = false;
        if ($template['type'] === 'html') {
            $template['content'] = (string) file_get_contents($template['path']);
        }
        return $template;
    }
    throw new RuntimeException('Выбранный шаблон не найден. Обновите список шаблонов.');
}

function docgenScanHtmlVariables(string $html): array
{
    $variables = [];
    if (preg_match_all('/(?<!\{)\{([^{}:\n;]{1,120})\}(?!\})/u', $html, $matches)) {
        foreach ($matches[1] as $field) {
            $field = trim($field);
            if ($field !== '' && !isUuidLike($field)) {
                $variables[$field] = true;
            }
        }
    }
    $fields = array_keys($variables);
    return [
        'fields' => $fields,
        'syntax' => $fields ? 'filldocuments' : 'none',
        'syntaxLabel' => $fields ? 'HTML · коды {поле}' : 'Переменные не найдены',
    ];
}

function docgenScanTemplate(array $template): array
{
    $info = $template['type'] === 'docx'
        ? scanDocxVariables((string) $template['path'])
        : docgenScanHtmlVariables((string) ($template['content'] ?? file_get_contents((string) $template['path'])));
    $info['type'] = $template['type'];
    $info['templateName'] = $template['name'];
    $info['templateId'] = $template['id'];
    return $info;
}

function docgenSanitizeHtml(string $html): string
{
    // HTML templates are authored by the local operator. Strip executable content
    // and external fetches so conversions remain local and deterministic.
    $html = preg_replace('#<\s*(script|iframe|object|embed|form|applet)\b[^>]*>.*?<\s*/\s*\1\s*>#isu', '', $html) ?? $html;
    $html = preg_replace('#<\s*(script|iframe|object|embed|form|applet)\b[^>]*/?>#isu', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
    $html = preg_replace('/\s+(src|href|poster)\s*=\s*("|\')\s*(?:https?:|file:|data:|javascript:|\/\/)[^"\']*\2/iu', '', $html) ?? $html;
    $html = preg_replace('/@import\s+(?:url\()?[^;]+;?/iu', '', $html) ?? $html;
    $html = preg_replace('/url\(\s*("|\')?\s*(?:https?:|file:|data:|javascript:|\/\/)[^)]*\)/iu', 'none', $html) ?? $html;
    if (!preg_match('/<html\b/i', $html)) {
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Документ</title></head><body>' . $html . '</body></html>';
    } elseif (!preg_match('/<meta\s+charset=/i', $html)) {
        $html = preg_replace('/<head\b[^>]*>/i', '$0<meta charset="utf-8">', $html, 1) ?? $html;
    }
    return $html;
}

function docgenReplaceCodes(string $text, array $values, bool $htmlEscape = false): string
{
    foreach ($values as $name => $value) {
        $replacement = (string) $value;
        if ($htmlEscape) {
            $replacement = htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $text = str_replace('{' . $name . '}', $replacement, $text);
        $text = str_replace('${' . $name . '}', $replacement, $text);
    }
    return $text;
}

function docgenEmailAddresses(string $text): array
{
    if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $matches)) {
        return [];
    }
    $result = [];
    $seen = [];
    foreach ($matches[0] as $candidate) {
        $candidate = trim($candidate, ".,;:()[]{}<>\"' ");
        if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $key = mb_strtolower($candidate, 'UTF-8');
        if (!isset($seen[$key])) {
            $result[] = $candidate;
            $seen[$key] = true;
        }
    }
    return $result;
}

function docgenHasUnparsedEmailText(string $text): bool
{
    $pattern = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';
    preg_match_all($pattern, $text, $matches);
    $remaining = preg_replace($pattern, '', $text) ?? $text;
    $remaining = preg_replace('/[\s,;<>]+/u', '', $remaining) ?? $remaining;
    return trim($remaining) !== '';
}

function docgenSmtpDefaults(): array
{
    return [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'fromEmail' => '',
        'fromName' => '',
        'replyTo' => '',
        'subjectTemplate' => 'Документ',
        'bodyTemplate' => "Добрый день.\n\nНаправляем документ во вложении.",
    ];
}

function docgenSmtpSettings(): array
{
    return array_merge(docgenSmtpDefaults(), docgenReadJson(DOCGEN_SMTP_SETTINGS_FILE, []));
}

function docgenSmtpSecret(): array
{
    return docgenReadJson(DOCGEN_SMTP_SECRET_FILE, []);
}

function docgenSmtpPublicPayload(): array
{
    $settings = docgenSmtpSettings();
    return [
        'ok' => true,
        'settings' => [
            'host' => (string) $settings['host'],
            'port' => (int) $settings['port'],
            'encryption' => (string) $settings['encryption'],
            'username' => (string) $settings['username'],
            'fromEmail' => (string) $settings['fromEmail'],
            'fromName' => (string) $settings['fromName'],
            'replyTo' => (string) $settings['replyTo'],
            'subjectTemplate' => (string) $settings['subjectTemplate'],
            'bodyTemplate' => (string) $settings['bodyTemplate'],
        ],
        'passwordConfigured' => (string) (docgenSmtpSecret()['password'] ?? '') !== '',
        'passwordMask' => (string) (docgenSmtpSecret()['password'] ?? '') !== '' ? '••••••••' : '',
    ];
}

function docgenDadataSecret(): array
{
    return docgenReadJson(DOCGEN_DADATA_SECRET_FILE, []);
}

function docgenDadataToken(): string
{
    return trim((string) (docgenDadataSecret()['token'] ?? ''));
}

function docgenManagerChecks(): array
{
    $checks = docgenReadJson(DOCGEN_MANAGER_CHECKS_FILE, []);
    return array_filter($checks, static fn($value): bool => is_array($value));
}

function docgenDadataPublicPayload(): array
{
    $tokenConfigured = docgenDadataToken() !== '';
    $checks = docgenManagerChecks();
    $lastCheckedAt = '';
    foreach ($checks as $check) {
        $candidate = (string) ($check['checkedAt'] ?? '');
        if ($candidate > $lastCheckedAt) {
            $lastCheckedAt = $candidate;
        }
    }
    return [
        'ok' => true,
        'configured' => $tokenConfigured,
        'tokenMask' => $tokenConfigured ? '••••••••' : '',
        'checkedCount' => count($checks),
        'lastCheckedAt' => $lastCheckedAt,
    ];
}

function docgenSaveDadataSettings(): never
{
    $token = trim((string) ($_POST['token'] ?? ''));
    $token = preg_replace('/^Token\\s+/i', '', $token) ?? $token;
    if (strlen($token) > 4096 || preg_match('/[\\r\\n\\x00-\\x1F\\x7F]/', $token)) {
        sendJson(['ok' => false, 'error' => 'Проверьте API-ключ DaData: он пустой либо содержит недопустимые символы.'], 422);
    }
    $secret = docgenDadataSecret();
    if (!empty($_POST['clear_token'])) {
        $secret['token'] = '';
    } elseif ($token !== '') {
        $secret['token'] = $token;
    }
    try {
        docgenWriteJson(DOCGEN_DADATA_SECRET_FILE, ['token' => (string) ($secret['token'] ?? '')]);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(docgenDadataPublicPayload());
}

function docgenManagerCompareKey(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\\s+/u', ' ', $value) ?? $value);
}

function docgenLocalManagerValues(array $fields): array
{
    $name = trim((string) ($fields['Руководитель'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($fields['ФИО'] ?? ''));
    }
    $position = trim((string) ($fields['Должность руководителя'] ?? ''));
    if ($position === '') {
        $position = trim((string) ($fields['Должность'] ?? ''));
    }
    return [$name, $position];
}

function docgenManagerCheckBase(string $status, string $message, string $recordId, string $inn, string $kpp, array $fields): array
{
    [$localName, $localPosition] = docgenLocalManagerValues($fields);
    return [
        'recordId' => $recordId,
        'status' => $status,
        'message' => $message,
        'inn' => $inn,
        'kpp' => $kpp,
        'localName' => $localName,
        'localPosition' => $localPosition,
        'managerName' => '',
        'managerPost' => '',
        'organizationName' => '',
        'nameMatch' => null,
        'positionMatch' => null,
        'checkedAt' => date(DATE_ATOM),
    ];
}

function docgenDadataRequest(string $token, string $query): array
{
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'В PHP выключен allow_url_fopen, поэтому подключение к DaData недоступно.'];
    }
    $body = json_encode(['query' => $query], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'Не удалось подготовить запрос к DaData.'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Token " . $token,
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $response = @file_get_contents(DOCGEN_DADATA_API_URL, false, $context);
    $statusCode = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/i', (string) $header, $matches)) {
            $statusCode = (int) $matches[1];
        }
    }
    if (!is_string($response)) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'Не удалось связаться с DaData. Проверьте интернет-соединение и сертификаты PHP.'];
    }
    if ($statusCode === 401 || $statusCode === 403) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'DaData отклонила API-ключ или доступ к методу. Проверьте ключ и тариф.'];
    }
    if ($statusCode === 429) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'DaData сообщила о превышении лимита запросов. Остановили пакет; повторите позже.'];
    }
    if ($statusCode >= 500) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'DaData временно недоступна. Пакет остановлен; повторите позже.'];
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'DaData вернула ошибку запроса (HTTP ' . $statusCode . '). Проверьте API-ключ и параметры.'];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'stopBatch' => true, 'error' => 'DaData вернула ответ в неожиданном формате.'];
    }
    return ['ok' => true, 'stopBatch' => false, 'data' => $decoded];
}

function docgenDadataMatchingParties(array $suggestions, string $inn, string $kpp): array
{
    $matches = [];
    foreach ($suggestions as $suggestion) {
        if (!is_array($suggestion) || !is_array($suggestion['data'] ?? null)) {
            continue;
        }
        $data = $suggestion['data'];
        $candidateInn = preg_replace('/\\D+/', '', (string) ($data['inn'] ?? '')) ?? '';
        $candidateKpp = preg_replace('/\\D+/', '', (string) ($data['kpp'] ?? '')) ?? '';
        if ($candidateInn !== $inn || ($kpp !== '' && $candidateKpp !== $kpp)) {
            continue;
        }
        $matches[] = $suggestion;
    }
    return $matches;
}

function docgenDadataPartyIsAmbiguous(array $suggestions, string $inn, string $kpp): bool
{
    $matches = docgenDadataMatchingParties($suggestions, $inn, $kpp);
    if (count($matches) > 1) {
        return true;
    }
    if ($matches === [] || $kpp !== '') {
        return false;
    }
    $data = $matches[0]['data'];
    return (int) ($data['branch_count'] ?? 0) > 0
        || strtoupper((string) ($data['branch_type'] ?? '')) === 'BRANCH';
}

function docgenDadataExactParty(array $suggestions, string $inn, string $kpp): ?array
{
    if (docgenDadataPartyIsAmbiguous($suggestions, $inn, $kpp)) {
        return null;
    }
    $matches = docgenDadataMatchingParties($suggestions, $inn, $kpp);
    return count($matches) === 1 ? $matches[0] : null;
}

function docgenCompareManager(array $record, string $inn, string $kpp, array $suggestion): array
{
    $recordId = (string) ($record['id'] ?? '');
    $fields = baseFields($record);
    $comparison = docgenManagerCheckBase('', '', $recordId, $inn, $kpp, $fields);
    $data = is_array($suggestion['data'] ?? null) ? $suggestion['data'] : [];
    $management = is_array($data['management'] ?? null) ? $data['management'] : [];
    $remoteName = trim((string) ($management['name'] ?? ''));
    $remotePost = trim((string) ($management['post'] ?? ''));
    $orgName = trim((string) ($data['name']['full_with_opf'] ?? $suggestion['value'] ?? ''));
    $comparison['managerName'] = $remoteName;
    $comparison['managerPost'] = $remotePost;
    $comparison['organizationName'] = $orgName;
    if ($remoteName === '') {
        $comparison['status'] = 'no_manager';
        $comparison['message'] = 'DaData не вернула ФИО действующего руководителя для этой записи.';
        return $comparison;
    }
    $nameMatch = $comparison['localName'] !== ''
        ? docgenManagerCompareKey($comparison['localName']) === docgenManagerCompareKey($remoteName)
        : null;
    $positionMatch = ($comparison['localPosition'] !== '' && $remotePost !== '')
        ? docgenManagerCompareKey($comparison['localPosition']) === docgenManagerCompareKey($remotePost)
        : null;
    $comparison['nameMatch'] = $nameMatch;
    $comparison['positionMatch'] = $positionMatch;
    if ($nameMatch === null) {
        $comparison['status'] = 'local_missing';
        $comparison['message'] = 'В локальной записи не заполнено ФИО руководителя; сравнение имени невозможно.';
    } elseif ($nameMatch === false && $positionMatch === false) {
        $comparison['status'] = 'both_mismatch';
        $comparison['message'] = 'Не совпадают ФИО и должность руководителя. Проверьте запись вручную.';
    } elseif ($nameMatch === false) {
        $comparison['status'] = 'name_mismatch';
        $comparison['message'] = 'ФИО руководителя не совпадает с DaData. Проверьте запись вручную.';
    } elseif ($positionMatch === false) {
        $comparison['status'] = 'position_mismatch';
        $comparison['message'] = 'ФИО совпадает, должность отличается. Проверьте её вручную.';
    } else {
        $comparison['status'] = 'match';
        $comparison['message'] = $positionMatch === null
            ? 'ФИО совпадает; должность нельзя сравнить, так как одно из полей не заполнено.'
            : 'ФИО и должность совпадают после нормализации регистра, пробелов и знаков препинания.';
    }
    return $comparison;
}

function docgenCheckManagers(): never
{
    set_time_limit(360);
    $token = docgenDadataToken();
    if ($token === '') {
        sendJson(['ok' => false, 'error' => 'Сначала сохраните API-ключ DaData в настройках.'], 422);
    }
    $ids = json_decode((string) ($_POST['selected_ids'] ?? '[]'), true);
    if (!is_array($ids)) {
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать выбранные записи.'], 400);
    }
    $cleanIds = [];
    foreach ($ids as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $id = trim((string) $id);
        if ($id !== '' && strlen($id) <= 120) {
            $cleanIds[] = $id;
        }
    }
    $ids = array_values(array_unique($cleanIds));
    if ($ids === []) {
        sendJson(['ok' => false, 'error' => 'Отметьте записи для ручной сверки.'], 422);
    }
    if (count($ids) > DOCGEN_DADATA_BATCH_SIZE) {
        sendJson(['ok' => false, 'error' => 'За один запрос можно сверить не более ' . DOCGEN_DADATA_BATCH_SIZE . ' записей. Приложение отправляет выбранные записи пакетами автоматически.'], 422);
    }

    $dataset = loadDataset();
    $byId = [];
    foreach (($dataset['records'] ?? []) as $record) {
        if (is_array($record) && isset($record['id'])) {
            $byId[(string) $record['id']] = $record;
        }
    }
    $checks = docgenManagerChecks();
    $processed = 0;
    $updatedIds = [];
    $stopBatch = false;
    $stopReason = '';
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            continue;
        }
        $record = $byId[$id];
        $fields = baseFields($record);
        $inn = preg_replace('/\\D+/', '', (string) ($fields['ИНН'] ?? '')) ?? '';
        $rawKpp = preg_replace('/\\D+/', '', (string) ($fields['КПП'] ?? '')) ?? '';
        $kpp = strlen($rawKpp) === 9 ? $rawKpp : '';
        if (!in_array(strlen($inn), [10, 12], true)) {
            $checks[$id] = docgenManagerCheckBase('invalid_inn', 'В записи отсутствует корректный ИНН; запрос в DaData не отправлялся.', $id, $inn, $kpp, $fields);
            docgenWriteJson(DOCGEN_MANAGER_CHECKS_FILE, $checks);
            $processed++;
            $updatedIds[] = $id;
            continue;
        }
        $query = $inn . ($kpp !== '' ? '/' . $kpp : '');
        $api = docgenDadataRequest($token, $query);
        if (empty($api['ok'])) {
            $checks[$id] = docgenManagerCheckBase('error', (string) ($api['error'] ?? 'Ошибка запроса DaData.'), $id, $inn, $kpp, $fields);
            docgenWriteJson(DOCGEN_MANAGER_CHECKS_FILE, $checks);
            $processed++;
            $updatedIds[] = $id;
            if (!empty($api['stopBatch'])) {
                $stopBatch = true;
                $stopReason = (string) ($api['error'] ?? 'Запрос остановлен.');
                break;
            }
            continue;
        }
        $rawSuggestions = $api['data']['suggestions'] ?? [];
        $suggestions = is_array($rawSuggestions) ? $rawSuggestions : [];
        $suggestion = docgenDadataExactParty($suggestions, $inn, $kpp);
        if ($suggestion === null) {
            if (docgenDadataPartyIsAmbiguous($suggestions, $inn, $kpp)) {
                $statusMessage = $kpp !== ''
                    ? 'DaData вернула несколько точных записей по ИНН/КПП. Сверка остановлена, проверьте реквизиты вручную.'
                    : 'По ИНН найдено несколько филиалов либо организация сообщает о филиалах, но КПП в карточке отсутствует. Сверка остановлена, укажите КПП и запустите её снова.';
                $checks[$id] = docgenManagerCheckBase('ambiguous', $statusMessage, $id, $inn, $kpp, $fields);
            } else {
                $statusMessage = $kpp !== ''
                    ? 'Точное совпадение по ИНН/КПП не найдено. Сверьте реквизиты; другая организация автоматически не выбиралась.'
                    : 'Организация с таким ИНН не найдена в ответе DaData.';
                $checks[$id] = docgenManagerCheckBase('not_found', $statusMessage, $id, $inn, $kpp, $fields);
            }
        } else {
            $checks[$id] = docgenCompareManager($record, $inn, $kpp, $suggestion);
        }
        docgenWriteJson(DOCGEN_MANAGER_CHECKS_FILE, $checks);
        $processed++;
        $updatedIds[] = $id;
        if ($processed < count($ids)) {
            usleep(100000);
        }
    }
    $selectedChecks = [];
    foreach ($ids as $id) {
        if (isset($checks[$id])) {
            $selectedChecks[$id] = $checks[$id];
        }
    }
    sendJson([
        'ok' => true,
        'checks' => (object) $selectedChecks,
        'updatedIds' => $updatedIds,
        'processed' => $processed,
        'total' => count($ids),
        'stop' => $stopBatch,
        'stopReason' => $stopReason,
    ]);
}

function docgenQueuePath(string $id): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new RuntimeException('Идентификатор письма некорректен.');
    }
    return DOCGEN_QUEUE_DIR . '/' . $id;
}

function docgenQueueMetaPath(string $id): string
{
    return docgenQueuePath($id) . '/meta.json';
}

function docgenReadQueueItem(string $id): array
{
    $path = docgenQueueMetaPath($id);
    $meta = docgenReadJson($path, []);
    if (!$meta || (string) ($meta['id'] ?? '') !== $id) {
        throw new RuntimeException('Письмо в очереди не найдено.');
    }
    return $meta;
}

function docgenSaveQueueItem(array $meta): void
{
    $id = (string) ($meta['id'] ?? '');
    $path = docgenQueuePath($id);
    docgenWriteJson($path . '/meta.json', $meta);
}

function docgenQueueList(): array
{
    if (!is_dir(DOCGEN_QUEUE_DIR)) {
        return [];
    }
    $items = [];
    foreach (scandir(DOCGEN_QUEUE_DIR) ?: [] as $id) {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            continue;
        }
        $meta = docgenReadJson(DOCGEN_QUEUE_DIR . '/' . $id . '/meta.json', []);
        if (($meta['id'] ?? '') === $id) {
            $items[] = $meta;
        }
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
    return array_slice($items, 0, 500);
}

function docgenRegisterDownload(string $sourcePath, string $filename, string $format, int $created, int $skipped): string
{
    if (!in_array($format, ['zip', 'docx', 'pdf'], true)) {
        throw new RuntimeException('Формат скачивания не поддерживается.');
    }
    docgenEnsureDir(DOWNLOADS_DIR);
    $id = bin2hex(random_bytes(16));
    $dir = DOWNLOADS_DIR . '/' . $id;
    docgenEnsureDir($dir);
    $stored = $dir . '/payload.' . $format;
    if (!copy($sourcePath, $stored)) {
        cleanupTree($dir);
        throw new RuntimeException('Не удалось сохранить сформированный файл для скачивания.');
    }
    docgenWriteJson($dir . '/meta.json', [
        'format' => $format,
        'filename' => safeFilename($filename),
        'created' => $created,
        'skipped' => $skipped,
        'expiresAt' => time() + DOCGEN_DOWNLOAD_TTL_SECONDS,
    ]);
    return '?action=download&id=' . $id;
}

function docgenStreamDownload(): never
{
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        sendJson(['ok' => false, 'error' => 'Ссылка на результат некорректна.'], 400);
    }
    $dir = DOWNLOADS_DIR . '/' . $id;
    $meta = docgenReadJson($dir . '/meta.json', []);
    if (!$meta || (int) ($meta['expiresAt'] ?? 0) < time()) {
        cleanupTree($dir);
        sendJson(['ok' => false, 'error' => 'Ссылка на результат истекла или файл удалён. Сформируйте документы снова.'], 410);
    }
    $format = (string) ($meta['format'] ?? '');
    if (!in_array($format, ['zip', 'docx', 'pdf'], true)) {
        sendJson(['ok' => false, 'error' => 'Неизвестный формат результата.'], 500);
    }
    $path = $dir . '/payload.' . $format;
    if (!is_file($path)) {
        sendJson(['ok' => false, 'error' => 'Файл результата не найден.'], 404);
    }
    $mime = match ($format) {
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        default => 'application/zip',
    };
    $fallback = match ($format) {'pdf' => 'document.pdf', 'docx' => 'document.docx', default => 'documents.zip'};
    $filename = safeFilename((string) ($meta['filename'] ?? $fallback));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($path);
    exit;
}

function docgenQueueFile(): never
{
    $id = (string) ($_GET['id'] ?? '');
    $format = (string) ($_GET['format'] ?? 'pdf');
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($format, ['pdf', 'docx'], true)) {
        sendJson(['ok' => false, 'error' => 'Параметры файла очереди некорректны.'], 400);
    }
    $meta = docgenReadQueueItem($id);
    $path = docgenQueuePath($id) . '/document.' . $format;
    if (!is_file($path)) {
        sendJson(['ok' => false, 'error' => 'Файл документа не найден.'], 404);
    }
    $inline = $format === 'pdf' && (string) ($_GET['inline'] ?? '') === '1';
    $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $name = safeFilename((string) ($meta['filename'] ?? 'document')) . '.' . $format;
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="document.' . $format . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function docgenConvertToPdf(array $inputPaths, string $outputDir, string $profileDir): array
{
    if (!$inputPaths) {
        return ['ok' => false, 'error' => 'Нет сформированных документов для PDF-конвертации.'];
    }
    $binary = trim((string) (getenv('DOCGEN_LIBREOFFICE_BIN') ?: 'libreoffice'));
    if ($binary === '') {
        $binary = 'libreoffice';
    }
    docgenEnsureDir($outputDir);
    docgenEnsureDir($profileDir);
    $command = [
        $binary,
        '-env:UserInstallation=file://' . $profileDir,
        '--headless',
        '--convert-to',
        'pdf',
        '--outdir',
        $outputDir,
    ];
    foreach ($inputPaths as $path) {
        $command[] = $path;
    }
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'Не удалось запустить LibreOffice. Установите libreoffice-writer.'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $exitCode = -1;
    do {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) - $started > 180) {
            proc_terminate($process, 15);
            usleep(500000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return ['ok' => false, 'error' => 'PDF-конвертация превысила лимит 180 секунд.'];
        }
        usleep(100000);
    } while (true);
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode < 0) {
        $exitCode = $closedCode;
    }
    $missing = [];
    foreach ($inputPaths as $path) {
        $pdf = $outputDir . '/' . pathinfo($path, PATHINFO_FILENAME) . '.pdf';
        if (!is_file($pdf) || filesize($pdf) === 0) {
            $missing[] = basename($path);
        }
    }
    return [
        'ok' => $missing === [],
        'error' => $missing === [] ? '' : 'Не удалось создать PDF для: ' . implode(', ', array_slice($missing, 0, 8)) . (($exitCode !== 0) ? ' (код LibreOffice ' . $exitCode . ')' : '') . '. ' . trim($stderr . ' ' . $stdout),
        'missing' => $missing,
        'output' => trim($stdout . ' ' . $stderr),
    ];
}

function docgenBuildDocumentSource(array $template, array $templateInfo, array $values, string $sourcePath): void
{
    if ($template['type'] === 'docx') {
        $processor = new \PhpOffice\PhpWord\TemplateProcessor((string) $template['path']);
        $syntax = (string) ($templateInfo['syntax'] ?? 'phpword');
        if ($syntax === 'mixed') {
            throw new RuntimeException('В одном DOCX смешаны переменные {поле} и ${поле}. Оставьте один формат.');
        }
        $macroOpen = $syntax === 'filldocuments' ? '{' : '${';
        $processor->setMacroChars($macroOpen, '}');
        foreach ($values as $key => $value) {
            $processor->setValue((string) $key, (string) $value);
        }
        $processor->saveAs($sourcePath);
        unset($processor);
        return;
    }
    $html = docgenSanitizeHtml((string) ($template['content'] ?? file_get_contents((string) $template['path'])));
    $html = docgenReplaceCodes($html, $values, true);
    if (file_put_contents($sourcePath, $html, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать HTML-документ.');
    }
}

function docgenGenerate(): never
{
    set_time_limit(300);
    cleanupExpiredDownloads();
    $dataset = loadDataset();
    $byId = [];
    foreach (($dataset['records'] ?? []) as $record) {
        if (isset($record['id'])) {
            $byId[(string) $record['id']] = $record;
        }
    }
    $ids = json_decode((string) ($_POST['selected_ids'] ?? '[]'), true);
    if (!is_array($ids)) {
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать выбранные записи.'], 400);
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
    if (!$ids) {
        sendJson(['ok' => false, 'error' => 'Сначала отметьте хотя бы одну запись.'], 422);
    }
    if (count($ids) > MAX_ROWS_PER_RUN) {
        sendJson(['ok' => false, 'error' => 'За один запуск можно обработать не более ' . MAX_ROWS_PER_RUN . ' записей. Разделите пакет.'], 422);
    }
    try {
        $template = docgenResolveTemplate();
        $templateInfo = docgenScanTemplate($template);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    if (($templateInfo['syntax'] ?? '') === 'mixed') {
        sendJson(['ok' => false, 'error' => 'В DOCX смешаны переменные {поле} и ${поле}. Оставьте один формат.'], 422);
    }
    if (empty($templateInfo['fields'])) {
        sendJson(['ok' => false, 'error' => 'В шаблоне не найдены коды полей.'], 422);
    }
    $overrides = json_decode((string) ($_POST['overrides'] ?? '{}'), true);
    if (!is_array($overrides)) {
        $overrides = [];
    }
    docgenEnsureDir(DOCGEN_STORAGE_DIR);
    $workRoot = DOCGEN_STORAGE_DIR . '/tmp';
    docgenEnsureDir($workRoot);
    $jobDir = $workRoot . '/' . bin2hex(random_bytes(16));
    docgenEnsureDir($jobDir);
    $sourceDir = $jobDir . '/sources';
    $pdfDir = $jobDir . '/pdf';
    docgenEnsureDir($sourceDir);
    docgenEnsureDir($pdfDir);

    $errors = [];
    $prepared = [];
    $sourceNumber = 0;
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            $errors[] = ['id' => $id, 'region' => '', 'reason' => 'Запись не найдена в наборе.'];
            continue;
        }
        $record = $byId[$id];
        $fields = baseFields($record);
        $rowOverrides = isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : [];
        $fields = array_merge($fields, $rowOverrides);
        $values = [];
        $missing = [];
        foreach ($templateInfo['fields'] as $fieldName) {
            $value = $fields[$fieldName] ?? '';
            if (!is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $fieldName;
            } else {
                $values[$fieldName] = trim((string) $value);
            }
        }
        if ($missing) {
            $errors[] = ['id' => $id, 'region' => (string) ($fields['Регион'] ?? ''), 'reason' => 'Не заполнены поля: ' . implode(', ', $missing)];
            continue;
        }
        $sourceNumber++;
        $extension = $template['type'] === 'docx' ? 'docx' : 'html';
        $sourcePath = $sourceDir . '/doc_' . str_pad((string) $sourceNumber, 4, '0', STR_PAD_LEFT) . '.' . $extension;
        try {
            docgenBuildDocumentSource($template, $templateInfo, $values, $sourcePath);
            $fields['__resolvedValues'] = $values;
            $prepared[] = ['id' => $id, 'record' => $record, 'fields' => $fields, 'sourcePath' => $sourcePath, 'number' => $sourceNumber];
        } catch (Throwable $e) {
            $errors[] = ['id' => $id, 'region' => (string) ($fields['Регион'] ?? ''), 'reason' => 'Ошибка генерации документа: ' . $e->getMessage()];
        }
    }
    if (!$prepared) {
        $summary = array_map(static fn(array $item): string => ($item['region'] !== '' ? $item['region'] . ': ' : '') . ($item['id'] ?? '') . ' — ' . $item['reason'], $errors);
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось подготовить документы.', 'details' => $summary], 422);
    }
    $sourcePaths = array_column($prepared, 'sourcePath');
    $conversion = docgenConvertToPdf($sourcePaths, $pdfDir, $jobDir . '/lo-profile');
    if (!empty($conversion['missing'])) {
        $missingSet = array_fill_keys($conversion['missing'], true);
        foreach ($prepared as $item) {
            if (isset($missingSet[basename($item['sourcePath'])])) {
                $errors[] = ['id' => $item['id'], 'region' => (string) ($item['fields']['Регион'] ?? ''), 'reason' => $conversion['error'] ?: 'Не удалось сформировать PDF.'];
            }
        }
    }
    if (empty($conversion['ok']) && empty($conversion['missing'])) {
        foreach ($prepared as $item) {
            $errors[] = ['id' => $item['id'], 'region' => (string) ($item['fields']['Регион'] ?? ''), 'reason' => $conversion['error'] ?: 'Ошибка PDF-конвертации.'];
        }
    }

    $settings = docgenSmtpSettings();
    $queueCreated = 0;
    $downloadedDocuments = 0;
    $archivePath = $jobDir . '/Пакет_документов.zip';
    $archive = new ZipArchive();
    if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось собрать ZIP с документами.'], 500);
    }
    $usedArchiveNames = [];
    foreach ($prepared as $item) {
        $sourcePath = $item['sourcePath'];
        $pdfPath = $pdfDir . '/' . pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
        $fields = $item['fields'];
        $values = $fields['__resolvedValues'];
        unset($fields['__resolvedValues']);
        $region = (string) ($fields['Регион'] ?? 'Без региона');
        $person = (string) ($fields['Фамилия И.О.'] ?? $fields['ФИО'] ?? $fields['Руководитель'] ?? 'Адресат');
        $inn = (string) ($fields['ИНН'] ?? '');
        $baseName = safeFilename($region . ' — ' . $person . ($inn !== '' ? ' — ' . $inn : ' — ' . $item['id']));
        if (is_file($pdfPath) && filesize($pdfPath) > 0) {
            $queueId = bin2hex(random_bytes(16));
            $queuePath = DOCGEN_QUEUE_DIR . '/' . $queueId;
            try {
                docgenEnsureDir($queuePath);
                if (!copy($pdfPath, $queuePath . '/document.pdf')) {
                    throw new RuntimeException('Не удалось сохранить PDF в очереди.');
                }
                $hasDocx = $template['type'] === 'docx' && is_file($sourcePath);
                if ($hasDocx && !copy($sourcePath, $queuePath . '/document.docx')) {
                    throw new RuntimeException('Не удалось сохранить DOCX в очереди.');
                }
                $to = docgenEmailAddresses((string) ($fields['Основной email'] ?? ''));
                $cc = docgenEmailAddresses((string) ($fields['Доп./старые email'] ?? ''));
                $toKeys = array_fill_keys(array_map(static fn(string $email): string => mb_strtolower($email, 'UTF-8'), $to), true);
                $cc = array_values(array_filter($cc, static fn(string $email): bool => !isset($toKeys[mb_strtolower($email, 'UTF-8')])));
                $emailValues = array_merge($fields, $values);
                $subject = docgenReplaceCodes((string) ($settings['subjectTemplate'] ?? 'Документ'), $emailValues);
                $body = docgenReplaceCodes((string) ($settings['bodyTemplate'] ?? ''), $emailValues);
                $meta = [
                    'id' => $queueId,
                    'recordId' => $item['id'],
                    'region' => $region,
                    'recipientName' => (string) ($fields['ФИО'] ?? $fields['Руководитель'] ?? ''),
                    'position' => (string) ($fields['Должность'] ?? $fields['Должность руководителя'] ?? ''),
                    'templateName' => (string) $template['name'],
                    'filename' => $baseName,
                    'to' => implode('; ', $to),
                    'cc' => implode('; ', $cc),
                    'sourcePrimaryEmail' => (string) ($fields['Основной email'] ?? ''),
                    'sourceAdditionalEmails' => (string) ($fields['Доп./старые email'] ?? ''),
                    'subject' => trim(preg_replace('/[\r\n]+/u', ' ', $subject) ?? $subject),
                    'body' => $body,
                    'hasDocx' => $hasDocx,
                    'status' => 'queued',
                    'lastError' => count($to) ? '' : 'В исходной записи не найден корректный основной email. Заполните получателя перед отправкой.',
                    'createdAt' => date(DATE_ATOM),
                    'sentAt' => null,
                    'reviewedAt' => null,
                ];
                docgenSaveQueueItem($meta);
                $queueCreated++;
            } catch (Throwable $e) {
                cleanupTree($queuePath);
                $errors[] = ['id' => $item['id'], 'region' => $region, 'reason' => 'Не удалось добавить письмо в очередь: ' . $e->getMessage()];
            }
            $zipName = $baseName . '.pdf';
            if (isset($usedArchiveNames[$zipName])) {
                $zipName = safeFilename($region . ' — ' . $person . ' — ' . $item['id']) . '.pdf';
            }
            $usedArchiveNames[$zipName] = true;
            $archive->addFile($pdfPath, $zipName);
            if ($template['type'] === 'docx' && is_file($sourcePath)) {
                $docxName = pathinfo($zipName, PATHINFO_FILENAME) . '.docx';
                if (isset($usedArchiveNames[$docxName])) {
                    $docxName = safeFilename($region . ' — ' . $person . ' — ' . $item['id']) . '.docx';
                }
                $usedArchiveNames[$docxName] = true;
                $archive->addFile($sourcePath, $docxName);
            }
            $downloadedDocuments++;
        } elseif ($template['type'] === 'docx' && is_file($sourcePath)) {
            // Preserve the DOCX output even if LibreOffice could not create its PDF.
            $docxName = $baseName . '.docx';
            if (isset($usedArchiveNames[$docxName])) {
                $docxName = safeFilename($region . ' — ' . $person . ' — ' . $item['id']) . '.docx';
            }
            $usedArchiveNames[$docxName] = true;
            $archive->addFile($sourcePath, $docxName);
        }
    }
    if ($errors) {
        $lines = ['Результат формирования PDF и писем', 'Поставлено в очередь: ' . $queueCreated, 'Пропущено/ошибок: ' . count($errors), ''];
        foreach ($errors as $error) {
            $lines[] = ($error['region'] !== '' ? $error['region'] . ' · ' : '') . ($error['id'] ?? '') . ' — ' . $error['reason'];
        }
        $archive->addFromString('Отчет_по_ошибкам.txt', implode("\r\n", $lines));
    }
    $archive->close();
    if ($queueCreated === 0) {
        $summary = array_map(static fn(array $item): string => ($item['region'] !== '' ? $item['region'] . ': ' : '') . ($item['id'] ?? '') . ' — ' . $item['reason'], $errors);
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Ни один PDF не попал в очередь. Проверьте LibreOffice и обязательные поля.', 'details' => $summary], 422);
    }
    $downloadUrl = '';
    if (is_file($archivePath) && filesize($archivePath) > 0) {
        try {
            $downloadUrl = docgenRegisterDownload($archivePath, 'Пакет_документов_' . date('Ymd_His') . '.zip', 'zip', $downloadedDocuments, count($errors));
        } catch (Throwable $e) {
            $errors[] = ['id' => '', 'region' => '', 'reason' => $e->getMessage()];
        }
    }
    cleanupTree($jobDir);
    sendJson([
        'ok' => true,
        'downloadUrl' => $downloadUrl,
        'filename' => 'Пакет_документов.zip',
        'created' => $queueCreated,
        'documents' => $downloadedDocuments,
        'skipped' => count($errors),
        'details' => array_slice(array_map(static fn(array $item): string => ($item['region'] !== '' ? $item['region'] . ': ' : '') . ($item['id'] ?? '') . ' — ' . $item['reason'], $errors), 0, 12),
        'queueUrl' => '#emailQueue',
    ]);
}

function docgenSaveTemplate(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['ok' => false, 'error' => 'Для сохранения шаблона используйте POST.'], 405);
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $type = (string) ($_POST['type'] ?? '');
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 180) {
        sendJson(['ok' => false, 'error' => 'Укажите название шаблона (не более 180 символов).'], 422);
    }
    if (!in_array($type, ['docx', 'html'], true)) {
        sendJson(['ok' => false, 'error' => 'Поддерживаются DOCX- и HTML-шаблоны.'], 422);
    }
    $items = docgenUserTemplateIndex();
    $existing = null;
    if ($id !== '') {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                $existing = $item;
                break;
            }
        }
        if (!$existing) {
            sendJson(['ok' => false, 'error' => 'Редактируемый шаблон не найден.'], 404);
        }
        if ($existing['type'] !== $type) {
            sendJson(['ok' => false, 'error' => 'Нельзя менять тип уже сохранённого шаблона.'], 422);
        }
    } else {
        $id = bin2hex(random_bytes(16));
    }
    docgenEnsureDir(DOCGEN_TEMPLATES_DIR);
    $extension = $type === 'docx' ? 'docx' : 'html';
    $path = DOCGEN_TEMPLATES_DIR . '/' . $id . '.' . $extension;
    if ($type === 'docx') {
        $uploaded = uploadedTemplatePath();
        if ($uploaded === null) {
            sendJson(['ok' => false, 'error' => 'Выберите DOCX-файл для сохранения в шаблоны.'], 422);
        }
        if (!copy($uploaded, $path)) {
            sendJson(['ok' => false, 'error' => 'Не удалось сохранить DOCX-шаблон.'], 500);
        }
        validateDocxPath($path);
    } else {
        $content = (string) ($_POST['content'] ?? '');
        if (trim($content) === '') {
            sendJson(['ok' => false, 'error' => 'HTML-шаблон не должен быть пустым.'], 422);
        }
        if (strlen($content) > DOCGEN_MAX_HTML_TEMPLATE_BYTES) {
            sendJson(['ok' => false, 'error' => 'HTML-шаблон превышает ограничение 1 МБ.'], 422);
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            sendJson(['ok' => false, 'error' => 'Не удалось сохранить HTML-шаблон.'], 500);
        }
        @chmod($path, 0600);
    }
    $newItem = [
        'id' => $id,
        'name' => $name,
        'type' => $type,
        'createdAt' => $existing['createdAt'] ?? date(DATE_ATOM),
        'updatedAt' => date(DATE_ATOM),
    ];
    $next = [];
    $replaced = false;
    foreach (docgenReadJson(DOCGEN_TEMPLATE_INDEX, []) as $item) {
        if (is_array($item) && ($item['id'] ?? '') === $id) {
            $next[] = $newItem;
            $replaced = true;
        } elseif (is_array($item)) {
            $next[] = $item;
        }
    }
    if (!$replaced) {
        $next[] = $newItem;
    }
    try {
        docgenWriteJson(DOCGEN_TEMPLATE_INDEX, $next);
        $saved = docgenResolveTemplateFromId($id);
        $info = docgenScanTemplate($saved);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(['ok' => true, 'template' => ['id' => $id, 'name' => $name, 'type' => $type], 'templates' => docgenTemplateList(), 'info' => $info]);
}

function docgenResolveTemplateFromId(string $id): array
{
    $_POST['template_id'] = $id;
    return docgenResolveTemplate();
}

function docgenDeleteTemplate(): never
{
    $id = trim((string) ($_POST['id'] ?? ''));
    $items = docgenUserTemplateIndex();
    $found = null;
    foreach ($items as $item) {
        if ($item['id'] === $id) {
            $found = $item;
            break;
        }
    }
    if (!$found) {
        sendJson(['ok' => false, 'error' => 'Сохранённый шаблон не найден.'], 404);
    }
    @unlink($found['path']);
    $raw = docgenReadJson(DOCGEN_TEMPLATE_INDEX, []);
    $raw = array_values(array_filter($raw, static fn($item): bool => !is_array($item) || (string) ($item['id'] ?? '') !== $id));
    docgenWriteJson(DOCGEN_TEMPLATE_INDEX, $raw);
    sendJson(['ok' => true, 'templates' => docgenTemplateList()]);
}

function docgenTemplateContent(): never
{
    $id = trim((string) ($_GET['id'] ?? ''));
    try {
        $template = docgenResolveTemplateFromId($id);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 404);
    }
    if (($template['type'] ?? '') !== 'html') {
        sendJson(['ok' => false, 'error' => 'Редактор доступен только для HTML-шаблонов.'], 422);
    }
    sendJson(['ok' => true, 'id' => $id, 'name' => $template['name'], 'type' => 'html', 'content' => (string) ($template['content'] ?? '')]);
}

function docgenAnalyze(): never
{
    try {
        $template = docgenResolveTemplate();
        $info = docgenScanTemplate($template);
        if (($info['syntax'] ?? '') === 'mixed') {
            sendJson(['ok' => false, 'error' => 'В DOCX смешаны переменные {поле} и ${поле}. Используйте один формат.'], 422);
        }
        $dataset = loadDataset();
        $sourceKey = (string) ($_POST['source_key'] ?? '');
        $info['analysis'] = buildFieldAnalysis($info, $dataset['records'] ?? [], $sourceKey);
        $info['ok'] = true;
        sendJson($info);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

/**
 * Only a bare hostname (or a bracketed IPv6 literal) is accepted. A URL,
 * whitespace or a path here makes PHPMailer resolve a bogus name and can stall
 * the check for a long time, so reject it while saving instead.
 */
function docgenValidSmtpHost(string $host): bool
{
    if (preg_match('/^\[[0-9A-Fa-f:]+\]$/', $host) === 1) {
        return true;
    }
    return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
}

function docgenSaveSmtpSettings(): never
{
    $host = trim((string) ($_POST['host'] ?? ''));
    $port = (int) ($_POST['port'] ?? 587);
    $encryption = (string) ($_POST['encryption'] ?? 'tls');
    $username = trim((string) ($_POST['username'] ?? ''));
    $fromEmail = trim((string) ($_POST['fromEmail'] ?? ''));
    $fromName = trim((string) ($_POST['fromName'] ?? ''));
    $replyTo = trim((string) ($_POST['replyTo'] ?? ''));
    $subjectTemplate = trim((string) ($_POST['subjectTemplate'] ?? ''));
    $bodyTemplate = (string) ($_POST['bodyTemplate'] ?? '');
    if ($port < 1 || $port > 65535) {
        sendJson(['ok' => false, 'error' => 'Порт SMTP должен быть числом от 1 до 65535.'], 422);
    }
    if ($host !== '' && !docgenValidSmtpHost($host)) {
        sendJson(['ok' => false, 'error' => 'Укажите SMTP-сервер в формате mail.example.org (без http:// и без пути).'], 422);
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        sendJson(['ok' => false, 'error' => 'Выберите TLS, SSL или без шифрования.'], 422);
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        sendJson(['ok' => false, 'error' => 'Проверьте email отправителя.'], 422);
    }
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        sendJson(['ok' => false, 'error' => 'Проверьте Reply-To email.'], 422);
    }
    if (strlen($host) > 255 || mb_strlen($username, 'UTF-8') > 500 || mb_strlen($fromName, 'UTF-8') > 250 || mb_strlen($subjectTemplate, 'UTF-8') > 500 || mb_strlen($bodyTemplate, 'UTF-8') > 20_000) {
        sendJson(['ok' => false, 'error' => 'Одно из полей SMTP превышает допустимый размер.'], 422);
    }
    $settings = [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'replyTo' => $replyTo,
        'subjectTemplate' => $subjectTemplate !== '' ? $subjectTemplate : 'Документ',
        'bodyTemplate' => $bodyTemplate,
    ];
    $secret = docgenSmtpSecret();
    $newPassword = (string) ($_POST['password'] ?? '');
    if (strlen($newPassword) > 4096) {
        sendJson(['ok' => false, 'error' => 'Пароль превышает допустимую длину.'], 422);
    }
    if (!empty($_POST['clear_password'])) {
        $secret['password'] = '';
    } elseif ($newPassword !== '') {
        $secret['password'] = $newPassword;
    }
    try {
        docgenWriteJson(DOCGEN_SMTP_SETTINGS_FILE, $settings);
        docgenWriteJson(DOCGEN_SMTP_SECRET_FILE, ['password' => (string) ($secret['password'] ?? '')]);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(docgenSmtpPublicPayload());
}

/**
 * @param array|null $override  optional unsaved settings from the "Check SMTP" form
 * @param string|null $overridePassword optional password from the same form ('' clears it)
 */
function docgenBuildMailer(?array $override = null, ?string $overridePassword = null): \PHPMailer\PHPMailer\PHPMailer
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer не установлен. Проверьте vendor/ и composer.lock.');
    }
    $settings = $override === null ? docgenSmtpSettings() : array_merge(docgenSmtpDefaults(), $override);
    $secret = docgenSmtpSecret();
    if ($overridePassword !== null) {
        $secret['password'] = $overridePassword;
    }
    $host = trim((string) ($settings['host'] ?? ''));
    $fromEmail = trim((string) ($settings['fromEmail'] ?? ''));
    if ($host === '' || $fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Заполните SMTP-сервер и корректный email отправителя в настройках.');
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = (int) ($settings['port'] ?? 587);
    $mailer->SMTPAuth = trim((string) ($settings['username'] ?? '')) !== '';
    $mailer->Username = (string) ($settings['username'] ?? '');
    $mailer->Password = (string) ($secret['password'] ?? '');
    // Normal delivery timeout, as before the UI fix. Only the connectivity
    // probe in docgenTestSmtp() gets the shorter 8-second limit.
    $mailer->Timeout = 30;
    $mailer->SMTPKeepAlive = false;
    $mailer->CharSet = 'UTF-8';
    $mailer->Encoding = 'base64';
    $mailer->SMTPDebug = 0;
    $mailer->SMTPSecure = match ((string) ($settings['encryption'] ?? 'tls')) {
        'ssl' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
        'tls' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        default => '',
    };
    $mailer->setFrom($fromEmail, (string) ($settings['fromName'] ?? ''));
    $replyTo = trim((string) ($settings['replyTo'] ?? ''));
    if ($replyTo !== '') {
        $mailer->addReplyTo($replyTo);
    }
    $mailer->isHTML(false);
    return $mailer;
}

/** Settings typed in the form, used by the "Check SMTP" button without saving them. */
function docgenSmtpSettingsFromRequest(): array
{
    $host = trim((string) ($_POST['host'] ?? ''));
    $port = (int) ($_POST['port'] ?? 587);
    $encryption = (string) ($_POST['encryption'] ?? 'tls');
    $username = trim((string) ($_POST['username'] ?? ''));
    $fromEmail = trim((string) ($_POST['fromEmail'] ?? ''));
    if ($host === '' || !docgenValidSmtpHost($host)) {
        throw new RuntimeException('Укажите SMTP-сервер в формате mail.example.org (без http:// и без пути).');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Порт SMTP должен быть числом от 1 до 65535.');
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        throw new RuntimeException('Выберите TLS, SSL или без шифрования.');
    }
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Укажите корректный email отправителя.');
    }
    // An empty password field means "keep the saved secret", exactly as in
    // docgenSaveSmtpSettings(). Only an explicit checkbox clears it.
    $password = null;
    if (!empty($_POST['clear_password'])) {
        $password = '';
    } elseif ((string) ($_POST['password'] ?? '') !== '') {
        $password = (string) $_POST['password'];
    }
    return [[
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'fromEmail' => $fromEmail,
        'fromName' => trim((string) ($_POST['fromName'] ?? '')),
        'replyTo' => trim((string) ($_POST['replyTo'] ?? '')),
    ], $password];
}

function docgenTestSmtp(): never
{
    try {
        // Be explicit: even if this handler is ever called outside docgenBoot(),
        // no other request may be blocked by the session file while we connect.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $override = null;
        $overridePassword = null;
        if (isset($_POST['host'])) {
            [$override, $overridePassword] = docgenSmtpSettingsFromRequest();
        }
        $mailer = docgenBuildMailer($override, $overridePassword);
        // Bound only this connectivity probe; sending from the queue is unchanged.
        $mailer->Timeout = DOCGEN_SMTP_CONNECT_TIMEOUT_SECONDS;
        $mailer->getSMTPInstance()->Timelimit = DOCGEN_SMTP_CONNECT_TIMEOUT_SECONDS;
        if ($mailer->smtpConnect()) {
            $mailer->smtpClose();
            sendJson(['ok' => true, 'message' => 'Соединение с SMTP установлено, сервер принял параметры авторизации.']);
        }
        $detail = trim((string) $mailer->ErrorInfo);
        $hint = ' Проверьте адрес и порт из контейнера: для порта 587 выбирайте STARTTLS, для 465 — SSL/TLS; провайдер также может блокировать исходящий SMTP-порт.';
        if (stripos($detail, 'subjectAltName') !== false || stripos($detail, 'certificate') !== false) {
            $hint .= ' Сертификат выдан на другое имя: укажите в поле SMTP-сервер каноническое имя из сертификата вместо алиаса.';
        }
        sendJson(['ok' => false, 'error' => 'Не удалось подключиться к SMTP-серверу.' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 400, 'UTF-8') : '') . $hint], 502);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => 'Проверка SMTP не прошла: ' . mb_substr($e->getMessage(), 0, 800, 'UTF-8')], 502);
    }
}

function docgenUpdateQueueItem(): never
{
    $id = trim((string) ($_POST['id'] ?? ''));
    try {
        $meta = docgenReadQueueItem($id);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 404);
    }
    if (($meta['status'] ?? '') === 'sent') {
        sendJson(['ok' => false, 'error' => 'Уже отправленное письмо нельзя изменить.'], 409);
    }
    $to = trim((string) ($_POST['to'] ?? ''));
    $cc = trim((string) ($_POST['cc'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    if (mb_strlen($to, 'UTF-8') > 2000 || mb_strlen($cc, 'UTF-8') > 4000 || mb_strlen($subject, 'UTF-8') > 500 || mb_strlen($body, 'UTF-8') > 20_000) {
        sendJson(['ok' => false, 'error' => 'Одно из полей письма превышает ограничение по длине.'], 422);
    }
    if (preg_match('/[\r\n]/u', $subject)) {
        sendJson(['ok' => false, 'error' => 'Тема письма не должна содержать переносы строк.'], 422);
    }
    $meta['to'] = $to;
    $meta['cc'] = $cc;
    $meta['subject'] = $subject;
    $meta['body'] = $body;
    $meta['lastError'] = '';
    docgenSaveQueueItem($meta);
    sendJson(['ok' => true, 'item' => $meta]);
}

function docgenSendQueueItem(): never
{
    if ((string) ($_POST['confirmed'] ?? '') !== '1') {
        sendJson(['ok' => false, 'error' => 'Перед отправкой подтвердите, что проверили PDF, получателей, тему и текст.'], 422);
    }
    $id = trim((string) ($_POST['id'] ?? ''));
    try {
        $dir = docgenQueuePath($id);
        $meta = docgenReadQueueItem($id);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 404);
    }
    if (($meta['status'] ?? '') === 'sent') {
        sendJson(['ok' => false, 'error' => 'Это письмо уже отправлено.'], 409);
    }
    if (($meta['status'] ?? '') === 'sending') {
        sendJson(['ok' => false, 'error' => 'Статус отправки неизвестен. Не повторяйте её, пока не проверите папку «Отправленные».'], 409);
    }
    $pdfPath = $dir . '/document.pdf';
    if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
        sendJson(['ok' => false, 'error' => 'PDF-вложение отсутствует.'], 404);
    }
    $toText = (string) ($meta['to'] ?? '');
    $ccText = (string) ($meta['cc'] ?? '');
    if (docgenHasUnparsedEmailText($toText) || docgenHasUnparsedEmailText($ccText)) {
        sendJson(['ok' => false, 'error' => 'Проверьте адреса: используйте email-адреса, разделённые запятыми или точками с запятой.'], 422);
    }
    $to = docgenEmailAddresses($toText);
    $cc = docgenEmailAddresses($ccText);
    if (!$to) {
        sendJson(['ok' => false, 'error' => 'Укажите хотя бы один корректный основной email (поле «Кому»).'], 422);
    }
    $lock = @fopen($dir . '/.send.lock', 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        sendJson(['ok' => false, 'error' => 'Это письмо уже обрабатывается.'], 409);
    }
    try {
        $meta = docgenReadQueueItem($id);
        if (($meta['status'] ?? '') === 'sent' || ($meta['status'] ?? '') === 'sending') {
            sendJson(['ok' => false, 'error' => 'Статус письма уже изменился. Обновите очередь.'], 409);
        }
        $meta['status'] = 'sending';
        $meta['sendStartedAt'] = date(DATE_ATOM);
        $meta['lastError'] = '';
        docgenSaveQueueItem($meta);
        $mailer = docgenBuildMailer();
        foreach ($to as $email) {
            $mailer->addAddress($email);
        }
        $toKeys = array_fill_keys(array_map(static fn(string $email): string => mb_strtolower($email, 'UTF-8'), $to), true);
        foreach ($cc as $email) {
            if (!isset($toKeys[mb_strtolower($email, 'UTF-8')])) {
                $mailer->addCC($email);
            }
        }
        $mailer->Subject = trim((string) ($meta['subject'] ?? ''));
        $mailer->Body = (string) ($meta['body'] ?? '');
        $mailer->addAttachment($pdfPath, safeFilename((string) ($meta['filename'] ?? 'Документ')) . '.pdf', 'base64', 'application/pdf');
        $mailer->send();
        $meta['status'] = 'sent';
        $meta['sentAt'] = date(DATE_ATOM);
        $meta['lastError'] = '';
        $meta['reviewedAt'] = date(DATE_ATOM);
        docgenSaveQueueItem($meta);
        flock($lock, LOCK_UN);
        fclose($lock);
        sendJson(['ok' => true, 'item' => $meta, 'message' => 'Письмо отправлено одному адресату/группе получателей.']);
    } catch (Throwable $e) {
        try {
            $meta = docgenReadQueueItem($id);
            $meta['status'] = 'failed';
            $meta['lastError'] = mb_substr($e->getMessage(), 0, 1000, 'UTF-8');
            $meta['failedAt'] = date(DATE_ATOM);
            docgenSaveQueueItem($meta);
        } catch (Throwable $ignored) {
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        sendJson(['ok' => false, 'error' => 'Отправка не удалась: ' . mb_substr($e->getMessage(), 0, 800, 'UTF-8')], 502);
    }
}

function docgenDispatch(string $action): void
{
    $postActions = ['save_template', 'delete_template', 'analyze', 'generate', 'save_smtp_settings', 'test_smtp', 'save_dadata_settings', 'check_managers', 'update_queue_item', 'send_queue_item', 'preview_excel_import', 'commit_excel_import', 'export_excel', 'add_table_column', 'add_table_section'];
    $getActions = ['templates', 'template_content', 'smtp_settings', 'dadata_settings', 'queue', 'queue_file', 'download'];
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($action, $postActions, true) && $method !== 'POST') {
        sendJson(['ok' => false, 'error' => 'Для этого действия используйте POST.'], 405);
    }
    if (in_array($action, $getActions, true) && $method !== 'GET') {
        sendJson(['ok' => false, 'error' => 'Для этого действия используйте GET.'], 405);
    }
    switch ($action) {
        case 'templates':
            sendJson(['ok' => true, 'templates' => docgenTemplateList()]);
        case 'save_template':
            docgenSaveTemplate();
        case 'delete_template':
            docgenDeleteTemplate();
        case 'template_content':
            docgenTemplateContent();
        case 'analyze':
            docgenAnalyze();
        case 'generate':
            docgenGenerate();
        case 'smtp_settings':
            sendJson(docgenSmtpPublicPayload());
        case 'save_smtp_settings':
            docgenSaveSmtpSettings();
        case 'test_smtp':
            docgenTestSmtp();
        case 'dadata_settings':
            sendJson(docgenDadataPublicPayload());
        case 'save_dadata_settings':
            docgenSaveDadataSettings();
        case 'check_managers':
            docgenCheckManagers();
        case 'preview_excel_import':
            docgenExcelImportPreview();
        case 'commit_excel_import':
            docgenCommitExcelImport();
        case 'export_excel':
            docgenExportExcel();
        case 'add_table_column':
            docgenAddTableColumn();
        case 'add_table_section':
            docgenAddTableSection();
        case 'queue':
            sendJson(['ok' => true, 'items' => docgenQueueList()]);
        case 'queue_file':
            docgenQueueFile();
        case 'update_queue_item':
            docgenUpdateQueueItem();
        case 'send_queue_item':
            docgenSendQueueItem();
        case 'download':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                sendJson(['ok' => false, 'error' => 'Для скачивания используйте GET.'], 405);
            }
            docgenStreamDownload();
    }
}

?>