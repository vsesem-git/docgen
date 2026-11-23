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
define('DOCGEN_SMTP_ACCOUNTS_FILE', DOCGEN_SETTINGS_DIR . '/smtp-accounts.json');
define('DOCGEN_SMTP_ACCOUNT_SECRETS_FILE', DOCGEN_SECRETS_DIR . '/smtp-account-secrets.json');
define('DOCGEN_SMTP_MAX_ACCOUNTS', 20);
define('DOCGEN_LETTER_TEMPLATE_FILE', DOCGEN_SETTINGS_DIR . '/letter-template.json');
define('DOCGEN_MAILING_SETTINGS_FILE', DOCGEN_SETTINGS_DIR . '/mailing.json');
define('DOCGEN_BATCH_DIR', DOCGEN_STORAGE_DIR . '/batches');
define('DOCGEN_CRON_LOCK_FILE', DOCGEN_STORAGE_DIR . '/mailer-cron.lock');
define('DOCGEN_CRON_STATE_FILE', DOCGEN_STORAGE_DIR . '/mailer-cron-state.json');
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

function safeFilename(string $value): string
{
    $value = preg_replace('/[\\\\\\/\\:\\*\\?\"<>\\|\\x00-\\x1F]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\\s+/u', ' ', trim($value)) ?? trim($value);
    $value = trim($value, ' .-_');
    if ($value === '') {
        $value = 'Документ';
    }
    return mb_substr($value, 0, 110, 'UTF-8');
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
    $store = docgenSmtpAccountStore();
    $account = docgenSmtpAccount($store['defaultId']);
    $settings = $account;
    $publicAccounts = [];
    foreach ($store['accounts'] as $item) {
        $publicAccounts[] = docgenSmtpAccountPublic($item);
    }
    return [
        'ok' => true,
        'accounts' => $publicAccounts,
        'accountId' => $account['id'],
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
        'passwordConfigured' => docgenSmtpAccountSecret($account['id']) !== '',
        'passwordMask' => docgenSmtpAccountSecret($account['id']) !== '' ? '••••••••' : '',
    ];
}

/** All SMTP mailboxes. The legacy single settings file becomes the first account. */
function docgenSmtpAccountStore(): array
{
    $stored = docgenReadJson(DOCGEN_SMTP_ACCOUNTS_FILE, []);
    $raw = $stored['accounts'] ?? null;
    if (!is_array($raw) || $raw === []) {
        $raw = [array_merge(docgenSmtpSettings(), ['id' => 'default', 'label' => 'Основной'])];
    }
    $accounts = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || strlen($id) > 80 || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            continue;
        }
        $label = trim((string) ($item['label'] ?? ''));
        $accounts[] = array_merge(docgenSmtpDefaults(), $item, [
            'id' => $id,
            'label' => $label !== '' ? mb_substr($label, 0, 100, 'UTF-8') : 'Ящик',
        ]);
    }
    if ($accounts === []) {
        $accounts = [array_merge(docgenSmtpDefaults(), ['id' => 'default', 'label' => 'Основной'])];
    }
    $defaultId = trim((string) ($stored['defaultId'] ?? ''));
    $ids = array_column($accounts, 'id');
    if (!in_array($defaultId, $ids, true)) {
        $defaultId = $accounts[0]['id'];
    }
    return ['accounts' => $accounts, 'defaultId' => $defaultId];
}

function docgenSmtpAccount(string $id): array
{
    $store = docgenSmtpAccountStore();
    foreach ($store['accounts'] as $account) {
        if ($account['id'] === $id) {
            return $account;
        }
    }
    return $store['accounts'][0];
}

function docgenSmtpAccountSecret(string $id): string
{
    $stored = docgenReadJson(DOCGEN_SMTP_ACCOUNT_SECRETS_FILE, []);
    $map = $stored['accounts'] ?? [];
    if (is_array($map) && array_key_exists($id, $map)) {
        return (string) $map[$id];
    }
    return $id === 'default' ? (string) (docgenSmtpSecret()['password'] ?? '') : '';
}

function docgenSmtpAccountPublic(array $account): array
{
    $public = [];
    foreach (array_keys(docgenSmtpDefaults()) as $key) {
        $public[$key] = $account[$key];
    }
    $public['id'] = $account['id'];
    $public['label'] = $account['label'];
    $public['passwordConfigured'] = docgenSmtpAccountSecret($account['id']) !== '';
    return $public;
}

function docgenSmtpWriteAccount(array $account, string $password, bool $clearPassword): array
{
    $store = docgenSmtpAccountStore();
    $found = false;
    foreach ($store['accounts'] as $index => $existing) {
        if ($existing['id'] === $account['id']) {
            $store['accounts'][$index] = array_merge($existing, $account);
            $found = true;
            break;
        }
    }
    if (!$found) {
        if (count($store['accounts']) >= DOCGEN_SMTP_MAX_ACCOUNTS) {
            throw new RuntimeException('Можно добавить не более ' . DOCGEN_SMTP_MAX_ACCOUNTS . ' SMTP-ящиков.');
        }
        $store['accounts'][] = $account;
    }
    docgenWriteJson(DOCGEN_SMTP_ACCOUNTS_FILE, $store);

    $secrets = docgenReadJson(DOCGEN_SMTP_ACCOUNT_SECRETS_FILE, []);
    $map = $secrets['accounts'] ?? [];
    if (!is_array($map)) {
        $map = [];
    }
    if ($clearPassword) {
        $map[$account['id']] = '';
    } elseif ($password !== '') {
        $map[$account['id']] = $password;
    } elseif (!array_key_exists($account['id'], $map)) {
        $map[$account['id']] = docgenSmtpAccountSecret($account['id']);
    }
    docgenWriteJson(DOCGEN_SMTP_ACCOUNT_SECRETS_FILE, ['accounts' => $map]);
    return $account;
}

/**
 * Global letter layout shared by all DOCX/HTML templates. Patterns use the same
 * {column} codes as the documents; the resulting letter is always editable in
 * the queue before a manual send.
 */
function docgenLetterTemplateDefaults(): array
{
    $legacy = docgenSmtpAccount(docgenSmtpAccountStore()['defaultId']);
    return [
        'filenamePattern' => '{Регион} — {Фамилия И.О.} — {ИНН}',
        'toPattern' => '{Основной email}',
        'ccPattern' => '{Доп./старые email}',
        'subjectPattern' => (string) ($legacy['subjectTemplate'] ?? 'Документ'),
        'bodyPattern' => (string) ($legacy['bodyTemplate'] ?? "Добрый день.\n\nНаправляем документ во вложении."),
        'smtpAccountId' => 'default',
    ];
}

function docgenLetterTemplate(): array
{
    return array_merge(docgenLetterTemplateDefaults(), docgenReadJson(DOCGEN_LETTER_TEMPLATE_FILE, []));
}

function docgenSaveLetterTemplate(): never
{
    $fields = [
        'filenamePattern' => trim((string) ($_POST['filenamePattern'] ?? '')),
        'toPattern' => trim((string) ($_POST['toPattern'] ?? '')),
        'ccPattern' => trim((string) ($_POST['ccPattern'] ?? '')),
        'subjectPattern' => trim((string) ($_POST['subjectPattern'] ?? '')),
        'bodyPattern' => (string) ($_POST['bodyPattern'] ?? ''),
    ];
    $limits = ['filenamePattern' => 300, 'toPattern' => 1000, 'ccPattern' => 1000, 'subjectPattern' => 500, 'bodyPattern' => 20_000];
    $labels = ['filenamePattern' => 'имя вложенного файла', 'toPattern' => 'Кому', 'ccPattern' => 'копия', 'subjectPattern' => 'тема письма', 'bodyPattern' => 'текст письма'];
    foreach ($limits as $key => $limit) {
        if (mb_strlen($fields[$key], 'UTF-8') > $limit) {
            sendJson(['ok' => false, 'error' => 'Слишком длинное поле: ' . $labels[$key] . '.'], 422);
        }
    }
    $accountId = trim((string) ($_POST['smtpAccountId'] ?? ''));
    $store = docgenSmtpAccountStore();
    if ($accountId === '') {
        $accountId = $store['defaultId'];
    }
    if (!in_array($accountId, array_column($store['accounts'], 'id'), true)) {
        sendJson(['ok' => false, 'error' => 'Выберите существующий SMTP-ящик отправителя.'], 422);
    }
    $settings = array_merge(docgenLetterTemplateDefaults(), $fields, ['smtpAccountId' => $accountId]);
    try {
        docgenWriteJson(DOCGEN_LETTER_TEMPLATE_FILE, $settings);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(['ok' => true, 'settings' => $settings, 'accountId' => $accountId]);
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

/** DaData returns epoch milliseconds in management.start_date. */
function docgenFormatDaDataDate($value): string
{
    $timestamp = 0;
    if (is_numeric($value) && (int) $value > 0) {
        $timestamp = intdiv((int) $value, 1000);
    } elseif (is_string($value) && trim($value) !== '') {
        $parsed = strtotime(trim($value));
        $timestamp = $parsed === false ? 0 : $parsed;
    }
    if ($timestamp <= 0) {
        return '';
    }
    $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $month = $months[(int) gmdate('n', $timestamp) - 1] ?? '';
    return trim(gmdate('j', $timestamp) . ' ' . $month . ' ' . gmdate('Y', $timestamp) . ' г.');
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
        'managerStartDate' => '',
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
    $comparison['managerStartDate'] = docgenFormatDaDataDate($management['start_date'] ?? '');
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

/** Fields offered in the manual DaData update dialog. Nothing is applied automatically. */
function docgenDadataFieldCatalog(): array
{
    return [
        ['key' => 'name.full_with_opf', 'label' => 'Наименование организации (полное с ОПФ)'],
        ['key' => 'name.short_with_opf', 'label' => 'Краткое наименование с ОПФ'],
        ['key' => 'name.full', 'label' => 'Наименование организации (без ОПФ)'],
        ['key' => 'name.short', 'label' => 'Краткое наименование'],
        ['key' => 'inn', 'label' => 'ИНН'],
        ['key' => 'kpp', 'label' => 'КПП'],
        ['key' => 'ogrn', 'label' => 'ОГРН'],
        ['key' => 'ogrn_date', 'label' => 'Дата регистрации'],
        ['key' => 'address.unstructured', 'label' => 'Юридический адрес'],
        ['key' => 'address.full', 'label' => 'Адрес (структурированный)'],
        ['key' => 'management.name', 'label' => 'Руководитель'],
        ['key' => 'management.post', 'label' => 'Должность руководителя'],
        ['key' => 'management.start_date', 'label' => 'Дата назначения на должность'],
        ['key' => 'opf.full', 'label' => 'Организационно-правовая форма'],
        ['key' => 'okved', 'label' => 'Основной ОКВЭД'],
        ['key' => 'state.status', 'label' => 'Статус организации'],
        ['key' => 'type', 'label' => 'Тип организации'],
    ];
}

/** Flatten the exact DaData party into preview values keyed by catalog key. */
function docgenDadataFlattenValues(array $suggestion): array
{
    $data = is_array($suggestion['data'] ?? null) ? $suggestion['data'] : [];
    $values = [];
    $add = static function (string $key, $value) use (&$values): void {
        if (!is_scalar($value)) {
            return;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $values[$key] = mb_substr($text, 0, 5000, 'UTF-8');
        }
    };
    $name = is_array($data['name'] ?? null) ? $data['name'] : [];
    foreach (['full_with_opf', 'short_with_opf', 'full', 'short'] as $part) {
        $add('name.' . $part, $name[$part] ?? '');
    }
    foreach (['inn', 'kpp', 'ogrn', 'okved', 'type'] as $key) {
        $add($key, $data[$key] ?? '');
    }
    $add('ogrn_date', docgenFormatDaDataDate($data['ogrn_date'] ?? ''));
    $address = is_array($data['address'] ?? null) ? $data['address'] : [];
    $add('address.unstructured', $address['unstructured'] ?? '');
    $add('address.full', $address['full'] ?? '');
    $management = is_array($data['management'] ?? null) ? $data['management'] : [];
    $add('management.name', $management['name'] ?? '');
    $add('management.post', $management['post'] ?? '');
    $add('management.start_date', docgenFormatDaDataDate($management['start_date'] ?? ''));
    $opf = is_array($data['opf'] ?? null) ? $data['opf'] : [];
    $add('opf.full', $opf['full'] ?? '');
    $state = is_array($data['state'] ?? null) ? $data['state'] : [];
    $add('state.status', $state['status'] ?? '');
    return $values;
}

/** Read-only DaData fetch for the mapping dialog. Never writes record fields. */
function docgenDadataFetchForUpdate(): never
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
        sendJson(['ok' => false, 'error' => 'Отметьте записи для обновления через DaData.'], 422);
    }
    if (count($ids) > DOCGEN_DADATA_BATCH_SIZE) {
        sendJson(['ok' => false, 'error' => 'За один запрос можно обновить не более ' . DOCGEN_DADATA_BATCH_SIZE . ' записей.'], 422);
    }

    $dataset = loadDataset();
    $byId = [];
    foreach (($dataset['records'] ?? []) as $record) {
        if (is_array($record) && isset($record['id'])) {
            $byId[(string) $record['id']] = $record;
        }
    }
    $rows = [];
    $stopBatch = false;
    $stopReason = '';
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            continue;
        }
        $record = $byId[$id];
        $fields = baseFields($record);
        $inn = preg_replace('/\D+/', '', (string) ($fields['ИНН'] ?? '')) ?? '';
        $rawKpp = preg_replace('/\D+/', '', (string) ($fields['КПП'] ?? '')) ?? '';
        $kpp = strlen($rawKpp) === 9 ? $rawKpp : '';
        $row = [
            'id' => $id,
            'label' => trim((string) ($fields['Наименование (ЕГРЮЛ/лист)'] ?? $fields['Наименование'] ?? $fields['ФИО'] ?? $fields['Руководитель'] ?? $id)),
            'inn' => $inn,
            'kpp' => $kpp,
            'status' => 'error',
            'message' => '',
            'values' => new stdClass(),
        ];
        if (!in_array(strlen($inn), [10, 12], true)) {
            $row['message'] = 'Корректный ИНН не заполнен — запрос к DaData не отправлялся.';
            $rows[$id] = $row;
            continue;
        }
        $api = docgenDadataRequest($token, $inn . ($kpp !== '' ? '/' . $kpp : ''));
        if (empty($api['ok'])) {
            $row['message'] = (string) ($api['error'] ?? 'Ошибка запроса DaData.');
            $rows[$id] = $row;
            if (!empty($api['stopBatch'])) {
                $stopBatch = true;
                $stopReason = $row['message'];
                break;
            }
            continue;
        }
        $rawSuggestions = $api['data']['suggestions'] ?? [];
        $suggestions = is_array($rawSuggestions) ? $rawSuggestions : [];
        $suggestion = docgenDadataExactParty($suggestions, $inn, $kpp);
        if ($suggestion === null) {
            $row['status'] = docgenDadataPartyIsAmbiguous($suggestions, $inn, $kpp) ? 'ambiguous' : 'not_found';
            $row['message'] = $row['status'] === 'ambiguous'
                ? 'Найдено несколько похожих записей или филиалов. Ничего не выбрано автоматически; уточните КПП.'
                : 'Точного совпадения по ИНН/КПП не найдено. Другая организация не подставлялась.';
            $rows[$id] = $row;
            continue;
        }
        $row['status'] = 'ok';
        $row['message'] = 'Данные загружены. Сопоставьте поля и сохраните карточки вручную.';
        $row['values'] = (object) docgenDadataFlattenValues($suggestion);
        $rows[$id] = $row;
        if ($id !== $ids[count($ids) - 1]) {
            usleep(100000);
        }
    }
    sendJson([
        'ok' => true,
        'fields' => docgenDadataFieldCatalog(),
        'rows' => (object) $rows,
        'processed' => count($rows),
        'total' => count($ids),
        'stop' => $stopBatch,
        'stopReason' => $stopReason,
    ]);
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

function docgenBatchPath(string $id): string
{
    return DOCGEN_BATCH_DIR . '/' . $id;
}

function docgenBatchMeta(string $id): array
{
    if ($id === 'legacy') {
        $items = array_values(array_filter(docgenQueueList(), static fn(array $item): bool => empty($item['batchId'])));
        $state = docgenReadJson(DOCGEN_BATCH_DIR . '/legacy-status.json', []);
        return [
            'id' => 'legacy', 'name' => 'Письма без пачки', 'itemCount' => count($items), 'subject' => '',
            'status' => (string) ($state['status'] ?? 'review'),
            'mode' => (string) ($state['mode'] ?? ''),
            'createdAt' => $items[0]['createdAt'] ?? ($state['createdAt'] ?? date(DATE_ATOM)),
            'reviewedAt' => $state['reviewedAt'] ?? null,
            'priority' => (int) ($state['priority'] ?? 0),
            'resumeStatus' => (string) ($state['resumeStatus'] ?? ''),
            'sentCount' => 0, 'failedCount' => 0, 'lastError' => '',
        ];
    }
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        return [];
    }
    $meta = docgenReadJson(docgenBatchPath($id) . '/meta.json', []);
    return ($meta['id'] ?? '') === $id ? $meta : [];
}

function docgenSaveBatch(array $batch): void
{
    $id = (string) ($batch['id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        throw new RuntimeException('Некорректный идентификатор пачки.');
    }
    docgenEnsureDir(docgenBatchPath($id));
    docgenWriteJson(docgenBatchPath($id) . '/meta.json', $batch);
}

function docgenSaveLegacyBatchState(array $batch): void
{
    docgenEnsureDir(DOCGEN_BATCH_DIR);
    docgenWriteJson(DOCGEN_BATCH_DIR . '/legacy-status.json', [
        'status' => (string) ($batch['status'] ?? 'review'),
        'mode' => (string) ($batch['mode'] ?? ''),
        'createdAt' => (string) ($batch['createdAt'] ?? date(DATE_ATOM)),
        'reviewedAt' => $batch['reviewedAt'] ?? null,
        'priority' => (int) ($batch['priority'] ?? 0),
        'resumeStatus' => (string) ($batch['resumeStatus'] ?? ''),
    ]);
}

function docgenPersistBatch(array $batch): void
{
    if ((string) ($batch['id'] ?? '') === 'legacy') {
        docgenSaveLegacyBatchState($batch);
        return;
    }
    docgenSaveBatch($batch);
}

function docgenRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        docgenRemoveTree($path . '/' . $entry);
    }
    @rmdir($path);
}

/** Old batches have no priority; keep their existing newest-first order. */
function docgenBatchOrder(array $a, array $b): int
{
    $priorityA = (int) ($a['priority'] ?? 0);
    $priorityB = (int) ($b['priority'] ?? 0);
    if ($priorityA !== $priorityB) {
        return $priorityA <=> $priorityB;
    }
    return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
}

/**
 * Assign a definitive 1..N order so up/down moves are stable even for batches
 * created before priorities existed.
 */
function docgenNormalizeBatchPriorities(): array
{
    $batches = docgenBatchList();
    foreach ($batches as $index => $batch) {
        $batch['priority'] = $index + 1;
        docgenPersistBatch($batch);
        $batches[$index] = $batch;
    }
    usort($batches, 'docgenBatchOrder');
    return $batches;
}

function docgenNextBatchPriority(): int
{
    // Batches created before this field exist have priority 0; append after them.
    return count(docgenBatchList()) + 1;
}

function docgenBatchList(?string $status = null): array
{
    $batches = [];
    if (is_dir(DOCGEN_BATCH_DIR)) {
        foreach (scandir(DOCGEN_BATCH_DIR) ?: [] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
                continue;
            }
            $batch = docgenBatchMeta($id);
            if ($batch && ($status === null || ($batch['status'] ?? '') === $status)) {
                $batches[] = $batch;
            }
        }
    }
    $legacy = docgenBatchMeta('legacy');
    if (($legacy['itemCount'] ?? 0) > 0 && ($status === null || $status === 'review')) {
        $batches[] = $legacy;
    }
    usort($batches, 'docgenBatchOrder');
    return $batches;
}

function docgenBatchItems(string $batchId): array
{
    $items = docgenQueueList();
    if ($batchId === 'legacy') {
        return array_values(array_filter($items, static fn(array $item): bool => empty($item['batchId'])));
    }
    return array_values(array_filter($items, static fn(array $item): bool => ($item['batchId'] ?? '') === $batchId));
}

function docgenMailingSettings(): array
{
    return array_merge(['lettersPerMinute' => 1], docgenReadJson(DOCGEN_MAILING_SETTINGS_FILE, []));
}

function docgenSaveMailingSettings(): never
{
    $raw = (string) ($_POST['lettersPerMinute'] ?? '1');
    $perMinute = (int) $raw;
    if (!ctype_digit($raw) || $perMinute < 1 || $perMinute > 60) {
        sendJson(['ok' => false, 'error' => 'Укажите от 1 до 60 писем в минуту.'], 422);
    }
    $settings = ['lettersPerMinute' => $perMinute];
    try {
        docgenWriteJson(DOCGEN_MAILING_SETTINGS_FILE, $settings);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(['ok' => true, 'settings' => $settings]);
}

function docgenReviewBatch(): never
{
    $batchId = trim((string) ($_POST['batch_id'] ?? ''));
    $mode = (string) ($_POST['mode'] ?? '');
    if (!in_array($mode, ['manual', 'auto'], true)) {
        sendJson(['ok' => false, 'error' => 'Выберите режим очереди.'], 422);
    }
    if ((string) ($_POST['confirmed'] ?? '') !== '1') {
        sendJson(['ok' => false, 'error' => 'Подтвердите, что проверили все письма пачки.'], 422);
    }
    $batch = docgenBatchMeta($batchId);
    if (!$batch || ($batch['status'] ?? '') !== 'review') {
        sendJson(['ok' => false, 'error' => 'Пачка не найдена или уже передана в очередь.'], 404);
    }
    $items = docgenBatchItems($batchId);
    if (!$items) {
        sendJson(['ok' => false, 'error' => 'В пачке нет писем.'], 422);
    }
    if ($mode === 'auto') {
        $broken = 0;
        foreach ($items as $item) {
            $dir = docgenQueuePath((string) $item['id']);
            if (docgenEmailAddresses((string) ($item['to'] ?? '')) === []
                || !is_file($dir . '/document.pdf')
                || filesize($dir . '/document.pdf') === 0) {
                $broken++;
            }
        }
        if ($broken > 0) {
            sendJson(['ok' => false, 'error' => 'В ' . $broken . ' письмах нет получателя или PDF. Исправьте их перед автоотправкой.'], 422);
        }
    }
    $batch['status'] = $mode === 'auto' ? 'scheduled' : 'manual';
    $batch['mode'] = $mode;
    $batch['reviewedAt'] = date(DATE_ATOM);
    $batch['updatedAt'] = date(DATE_ATOM);
    foreach ($items as $item) {
        if (($item['status'] ?? '') !== 'queued') {
            continue;
        }
        if ($mode === 'auto') {
            $item['status'] = 'scheduled';
        }
        $item['batchReviewedAt'] = $batch['reviewedAt'];
        docgenSaveQueueItem($item);
    }
    if ($batchId !== 'legacy') {
        docgenSaveBatch($batch);
    } else {
        docgenSaveLegacyBatchState($batch);
    }
    sendJson(['ok' => true, 'batch' => $batch, 'itemCount' => count($items)]);
}

function docgenCancelBatch(): never
{
    $batch = docgenBatchMeta(trim((string) ($_POST['batch_id'] ?? '')));
    if (!$batch || !in_array(($batch['status'] ?? ''), ['manual', 'scheduled', 'sending', 'paused'], true)) {
        sendJson(['ok' => false, 'error' => 'Пачку нельзя остановить.'], 404);
    }
    foreach (docgenBatchItems((string) $batch['id']) as $item) {
        if (in_array(($item['status'] ?? ''), ['sent', 'cancelled'], true)) {
            continue;
        }
        $item['status'] = 'cancelled';
        $item['lastError'] = 'Пачка остановлена оператором.';
        docgenSaveQueueItem($item);
    }
    $batch['status'] = 'cancelled';
    $batch['updatedAt'] = date(DATE_ATOM);
    if ($batch['id'] !== 'legacy') {
        docgenSaveBatch($batch);
    } else {
        docgenSaveLegacyBatchState($batch);
    }
    sendJson(['ok' => true, 'batch' => $batch]);
}

function docgenRetryBatch(): never
{
    $batch = docgenBatchMeta(trim((string) ($_POST['batch_id'] ?? '')));
    if (!$batch || !in_array(($batch['status'] ?? ''), ['done', 'cancelled', 'scheduled', 'manual', 'sending', 'paused'], true)) {
        sendJson(['ok' => false, 'error' => 'Для этой пачки повтор недоступен.'], 404);
    }
    $mode = ($batch['mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $retried = 0;
    foreach (docgenBatchItems((string) $batch['id']) as $item) {
        if (($item['status'] ?? '') !== 'failed') {
            continue;
        }
        $item['status'] = $mode === 'auto' ? 'scheduled' : 'queued';
        $item['lastError'] = '';
        unset($item['failedAt'], $item['sendStartedAt']);
        docgenSaveQueueItem($item);
        $retried++;
    }
    $batch['status'] = $mode === 'auto' ? 'scheduled' : 'manual';
    $batch['updatedAt'] = date(DATE_ATOM);
    if ($batch['id'] !== 'legacy') {
        docgenSaveBatch($batch);
    } else {
        docgenSaveLegacyBatchState($batch);
    }
    sendJson(['ok' => true, 'batch' => $batch, 'retried' => $retried]);
}

function docgenPauseBatch(): never
{
    $batch = docgenBatchMeta(trim((string) ($_POST['batch_id'] ?? '')));
    if (!$batch || !in_array(($batch['status'] ?? ''), ['manual', 'scheduled'], true)) {
        sendJson(['ok' => false, 'error' => 'Пачку можно поставить на паузу только из ручной очереди или расписания.'], 404);
    }
    $previous = (string) $batch['status'];
    $batch['resumeStatus'] = $previous;
    $batch['status'] = 'paused';
    $batch['pausedAt'] = date(DATE_ATOM);
    $batch['updatedAt'] = date(DATE_ATOM);
    foreach (docgenBatchItems((string) $batch['id']) as $item) {
        if (!in_array(($item['status'] ?? ''), ['queued', 'scheduled'], true)) {
            continue;
        }
        $item['resumeItemStatus'] = (string) $item['status'];
        $item['status'] = 'paused';
        $item['lastError'] = '';
        docgenSaveQueueItem($item);
    }
    docgenPersistBatch($batch);
    sendJson(['ok' => true, 'batch' => $batch]);
}

function docgenResumeBatch(): never
{
    $batch = docgenBatchMeta(trim((string) ($_POST['batch_id'] ?? '')));
    if (!$batch || ($batch['status'] ?? '') !== 'paused') {
        sendJson(['ok' => false, 'error' => 'Пачка не на паузе.'], 404);
    }
    $mode = ($batch['mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $resumeStatus = ($batch['resumeStatus'] ?? '') === 'manual' ? 'manual' : ($mode === 'auto' ? 'scheduled' : 'manual');
    $batch['status'] = $resumeStatus;
    $batch['resumeStatus'] = '';
    $batch['updatedAt'] = date(DATE_ATOM);
    unset($batch['pausedAt']);
    foreach (docgenBatchItems((string) $batch['id']) as $item) {
        if (($item['status'] ?? '') !== 'paused') {
            continue;
        }
        $itemStatus = ($item['resumeItemStatus'] ?? '') === 'queued' ? 'queued' : ($mode === 'auto' ? 'scheduled' : 'queued');
        if ($mode === 'auto' && ($item['resumeItemStatus'] ?? '') === 'queued') {
            $itemStatus = 'scheduled';
        }
        $item['status'] = $itemStatus;
        unset($item['resumeItemStatus']);
        docgenSaveQueueItem($item);
    }
    docgenPersistBatch($batch);
    sendJson(['ok' => true, 'batch' => $batch]);
}

function docgenDeleteBatch(): never
{
    $batch = docgenBatchMeta(trim((string) ($_POST['batch_id'] ?? '')));
    if (!$batch) {
        sendJson(['ok' => false, 'error' => 'Пачка не найдена.'], 404);
    }
    if (($batch['status'] ?? '') === 'sending') {
        sendJson(['ok' => false, 'error' => 'Пачка сейчас отправляется. Дождитесь завершения текущего письма.'], 409);
    }
    if ((string) ($_POST['confirm'] ?? '') !== '1') {
        sendJson(['ok' => false, 'error' => 'Подтвердите удаление пачки и всех её писем.'], 422);
    }
    foreach (docgenBatchItems((string) $batch['id']) as $item) {
        docgenRemoveTree(docgenQueuePath((string) $item['id']));
    }
    if ((string) $batch['id'] === 'legacy') {
        @unlink(DOCGEN_BATCH_DIR . '/legacy-status.json');
    } else {
        docgenRemoveTree(docgenBatchPath((string) $batch['id']));
    }
    sendJson(['ok' => true, 'deleted' => (string) $batch['id']]);
}

function docgenMoveBatch(): never
{
    $id = trim((string) ($_POST['batch_id'] ?? ''));
    $direction = (string) ($_POST['direction'] ?? '');
    if (!in_array($direction, ['up', 'down'], true)) {
        sendJson(['ok' => false, 'error' => 'Укажите направление перемещения.'], 422);
    }
    $batches = docgenNormalizeBatchPriorities();
    $index = -1;
    foreach ($batches as $position => $batch) {
        if ((string) ($batch['id'] ?? '') === $id) {
            $index = $position;
            break;
        }
    }
    if ($index < 0) {
        sendJson(['ok' => false, 'error' => 'Пачка не найдена.'], 404);
    }
    $other = $direction === 'up' ? $index - 1 : $index + 1;
    if (!isset($batches[$other])) {
        sendJson(['ok' => false, 'error' => 'Пачка уже крайняя в этом направлении.'], 422);
    }
    $current = $batches[$index];
    $neighbor = $batches[$other];
    $currentPriority = (int) $current['priority'];
    $current['priority'] = (int) $neighbor['priority'];
    $neighbor['priority'] = $currentPriority;
    docgenPersistBatch($current);
    docgenPersistBatch($neighbor);
    sendJson(['ok' => true, 'moved' => $id, 'direction' => $direction]);
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

    $accountId = trim((string) ($_POST['smtp_account_id'] ?? ''));
    $store = docgenSmtpAccountStore();
    $settings = $accountId !== '' ? docgenSmtpAccount($accountId) : docgenSmtpAccount($store['defaultId']);
    $letter = docgenLetterTemplate();
    $batchId = bin2hex(random_bytes(16));
    docgenEnsureDir(docgenBatchPath($batchId));
    $sourceNames = [];
    foreach ($ids as $selectedId) {
        if (isset($byId[$selectedId])) {
            $sourceNames[(string) ($byId[$selectedId]['source'] ?? '')] = true;
        }
    }
    $sourceNames = array_values(array_filter(array_keys($sourceNames), static fn(string $name): bool => $name !== ''));
    $batchName = count($sourceNames) === 1 ? $sourceNames[0] : (count($sourceNames) > 1 ? 'Несколько наборов' : 'Все наборы');
    $batchSubject = '';
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
        $emailValues = array_merge($fields, $values);
        $emailValues['Наименование файла'] = (string) $template['name'];
        $emailValues['наименование файла'] = (string) $template['name'];
        $filenamePattern = docgenReplaceCodes((string) $letter['filenamePattern'], $emailValues);
        $filenamePattern = trim((string) (preg_replace('/\s+[—–-]\s*$/u', '', trim($filenamePattern)) ?? $filenamePattern));
        $baseName = $filenamePattern !== ''
            ? safeFilename($filenamePattern)
            : safeFilename($region . ' — ' . $person . ($inn !== '' ? ' — ' . $inn : ' — ' . $item['id']));
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
                $toPattern = trim((string) $letter['toPattern']);
                $ccPattern = trim((string) $letter['ccPattern']);
                $toSource = $toPattern !== '' ? docgenReplaceCodes($toPattern, $emailValues) : (string) ($fields['Основной email'] ?? '');
                $ccSource = $ccPattern !== '' ? docgenReplaceCodes($ccPattern, $emailValues) : (string) ($fields['Доп./старые email'] ?? '');
                $to = docgenEmailAddresses($toSource);
                $cc = docgenEmailAddresses($ccSource);
                $toKeys = array_fill_keys(array_map(static fn(string $email): string => mb_strtolower($email, 'UTF-8'), $to), true);
                $cc = array_values(array_filter($cc, static fn(string $email): bool => !isset($toKeys[mb_strtolower($email, 'UTF-8')])));
                $subject = docgenReplaceCodes((string) $letter['subjectPattern'], $emailValues);
                $body = docgenReplaceCodes((string) $letter['bodyPattern'], $emailValues);
                $meta = [
                    'id' => $queueId,
                    'batchId' => $batchId,
                    'recordId' => $item['id'],
                    'region' => $region,
                    'recipientName' => (string) ($fields['ФИО'] ?? $fields['Руководитель'] ?? ''),
                    'position' => (string) ($fields['Должность'] ?? $fields['Должность руководителя'] ?? ''),
                    'templateName' => (string) $template['name'],
                    'smtpAccountId' => (string) $settings['id'],
                    'smtpAccountLabel' => (string) ($settings['label'] ?? ''),
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
                if ($batchSubject === '') {
                    $batchSubject = trim((string) ($meta['subject'] ?? ''));
                }
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
        @rmdir(docgenBatchPath($batchId));
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
    docgenSaveBatch([
        'id' => $batchId,
        'name' => mb_substr($batchName, 0, 160, 'UTF-8'),
        'itemCount' => $queueCreated,
        'subject' => $batchSubject,
        'status' => 'review',
        'mode' => '',
        'priority' => docgenNextBatchPriority(),
        'createdAt' => date(DATE_ATOM),
        'reviewedAt' => null,
        'smtpAccountId' => (string) ($settings['id'] ?? ''),
        'smtpAccountLabel' => (string) ($settings['label'] ?? ''),
        'sentCount' => 0,
        'failedCount' => 0,
        'lastError' => '',
    ]);
    sendJson([
        'ok' => true,
        'downloadUrl' => $downloadUrl,
        'filename' => 'Пакет_документов.zip',
        'batchId' => $batchId,
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
    $accountId = trim((string) ($_POST['account_id'] ?? ''));
    if ($accountId === '') {
        $accountId = 'default';
    }
    if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $accountId) !== 1) {
        sendJson(['ok' => false, 'error' => 'Некорректный идентификатор SMTP-ящика.'], 422);
    }
    $existingAccount = docgenSmtpAccount($accountId);
    // Letter text is configured globally in the letter editor now. Keep old
    // per-account values intact when the simplified SMTP form omits them.
    $subjectTemplate = array_key_exists('subjectTemplate', $_POST)
        ? trim((string) $_POST['subjectTemplate'])
        : (string) ($existingAccount['subjectTemplate'] ?? 'Документ');
    $bodyTemplate = array_key_exists('bodyTemplate', $_POST)
        ? (string) $_POST['bodyTemplate']
        : (string) ($existingAccount['bodyTemplate'] ?? '');
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
    $label = trim((string) ($_POST['label'] ?? ''));
    if (mb_strlen($label, 'UTF-8') > 100) {
        sendJson(['ok' => false, 'error' => 'Название SMTP-ящика слишком длинное.'], 422);
    }
    $account = array_merge($settings, [
        'id' => $accountId,
        'label' => $label !== '' ? $label : (docgenSmtpAccount($accountId)['label'] ?? 'Основной'),
    ]);
    try {
        // Keep the legacy single-account files in sync for older installations.
        docgenWriteJson(DOCGEN_SMTP_SETTINGS_FILE, $settings);
        docgenWriteJson(DOCGEN_SMTP_SECRET_FILE, ['password' => (string) ($secret['password'] ?? '')]);
        docgenSmtpWriteAccount($account, $newPassword, !empty($_POST['clear_password']));
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $payload = docgenSmtpPublicPayload();
    $payload['accountId'] = $accountId;
    sendJson($payload);
}

/** Create an empty mailbox draft; the operator fills and saves it explicitly. */
function docgenAddSmtpAccount(): never
{
    $store = docgenSmtpAccountStore();
    if (count($store['accounts']) >= DOCGEN_SMTP_MAX_ACCOUNTS) {
        sendJson(['ok' => false, 'error' => 'Можно добавить не более ' . DOCGEN_SMTP_MAX_ACCOUNTS . ' SMTP-ящиков.'], 422);
    }
    $label = trim((string) ($_POST['label'] ?? ''));
    if ($label === '') {
        $label = 'Ящик ' . (count($store['accounts']) + 1);
    }
    try {
        do {
            $id = 'acc_' . bin2hex(random_bytes(5));
        } while (in_array($id, array_column($store['accounts'], 'id'), true));
        $account = array_merge(docgenSmtpDefaults(), ['id' => $id, 'label' => mb_substr($label, 0, 100, 'UTF-8')]);
        docgenSmtpWriteAccount($account, '', false);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $payload = docgenSmtpPublicPayload();
    $payload['accountId'] = $id;
    sendJson($payload);
}

function docgenDeleteSmtpAccount(): never
{
    $store = docgenSmtpAccountStore();
    $id = trim((string) ($_POST['account_id'] ?? ''));
    if (count($store['accounts']) <= 1) {
        sendJson(['ok' => false, 'error' => 'Нельзя удалить единственный SMTP-ящик.'], 422);
    }
    $remaining = [];
    foreach ($store['accounts'] as $account) {
        if ($account['id'] !== $id) {
            $remaining[] = $account;
        }
    }
    if (count($remaining) === count($store['accounts'])) {
        sendJson(['ok' => false, 'error' => 'SMTP-ящик не найден.'], 404);
    }
    try {
        docgenWriteJson(DOCGEN_SMTP_ACCOUNTS_FILE, [
            'accounts' => $remaining,
            'defaultId' => $store['defaultId'] === $id ? $remaining[0]['id'] : $store['defaultId'],
        ]);
        $secrets = docgenReadJson(DOCGEN_SMTP_ACCOUNT_SECRETS_FILE, []);
        $map = $secrets['accounts'] ?? [];
        if (is_array($map)) {
            unset($map[$id]);
            docgenWriteJson(DOCGEN_SMTP_ACCOUNT_SECRETS_FILE, ['accounts' => $map]);
        }
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
    // The X-Mailer header is informational only; it needlessly announces the
    // library version to every recipient. PHPMailer uses null to omit it.
    $mailer->XMailer = null;
    // Use the authenticated From domain for Message-ID instead of the short
    // container hostname (which otherwise produces IDs like <... @docgen>).
    $fromDomain = '';
    $fromAt = strrpos($fromEmail, '@');
    if ($fromAt !== false) {
        $fromDomain = strtolower(substr($fromEmail, $fromAt + 1));
    }
    if ($fromDomain !== '' && preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $fromDomain) === 1) {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $nonce = md5(uniqid('docgen', true));
        }
        $mailer->MessageID = '<' . $nonce . '@' . $fromDomain . '>';
    }
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
        $accountId = trim((string) ($_POST['account_id'] ?? ''));
        if (isset($_POST['host'])) {
            [$override, $formPassword] = docgenSmtpSettingsFromRequest();
            $overridePassword = $formPassword ?? ($accountId !== '' ? docgenSmtpAccountSecret($accountId) : null);
        } elseif ($accountId !== '') {
            $override = docgenSmtpAccount($accountId);
            $overridePassword = docgenSmtpAccountSecret($accountId);
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

/** Deliver one already validated queue item. Marks it sending/failed/sent. */
function docgenDeliverPreparedItem(array $meta, string $dir, string $pdfPath, array $to, array $cc): array
{
    $id = (string) ($meta['id'] ?? '');
    $lock = @fopen($dir . '/.send.lock', 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Это письмо уже обрабатывается.');
    }
    try {
        $current = docgenReadQueueItem($id);
        if (($current['status'] ?? '') === 'sent' || ($current['status'] ?? '') === 'sending') {
            throw new RuntimeException('Статус письма уже изменился. Обновите очередь.');
        }
        $current['status'] = 'sending';
        $current['sendStartedAt'] = date(DATE_ATOM);
        $current['lastError'] = '';
        docgenSaveQueueItem($current);
        $queueAccountId = trim((string) ($current['smtpAccountId'] ?? ''));
        if ($queueAccountId !== '') {
            $queueAccount = docgenSmtpAccount($queueAccountId);
            if (($queueAccount['id'] ?? '') !== $queueAccountId) {
                throw new RuntimeException('SMTP-ящик, выбранный при формировании письма, был удалён. Восстановите его или сформируйте очередь заново.');
            }
            $mailer = docgenBuildMailer($queueAccount, docgenSmtpAccountSecret($queueAccountId));
        } else {
            $mailer = docgenBuildMailer();
        }
        foreach ($to as $email) {
            $mailer->addAddress($email);
        }
        $toKeys = array_fill_keys(array_map(static fn(string $email): string => mb_strtolower($email, 'UTF-8'), $to), true);
        foreach ($cc as $email) {
            if (!isset($toKeys[mb_strtolower($email, 'UTF-8')])) {
                $mailer->addCC($email);
            }
        }
        $mailer->Subject = trim((string) ($current['subject'] ?? ''));
        $mailer->Body = (string) ($current['body'] ?? '');
        $mailer->addAttachment($pdfPath, safeFilename((string) ($current['filename'] ?? 'Документ')) . '.pdf', 'base64', 'application/pdf');
        $mailer->send();
        $current['status'] = 'sent';
        $current['sentAt'] = date(DATE_ATOM);
        $current['lastError'] = '';
        $current['reviewedAt'] = date(DATE_ATOM);
        docgenSaveQueueItem($current);
        return ['ok' => true, 'item' => $current];
    } catch (Throwable $e) {
        try {
            $failed = docgenReadQueueItem($id);
            $failed['status'] = 'failed';
            $failed['lastError'] = mb_substr($e->getMessage(), 0, 1000, 'UTF-8');
            $failed['failedAt'] = date(DATE_ATOM);
            docgenSaveQueueItem($failed);
        } catch (Throwable $ignored) {
        }
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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
    try {
        $result = docgenDeliverPreparedItem($meta, $dir, $pdfPath, $to, $cc);
        sendJson(['ok' => true, 'item' => $result['item'], 'message' => 'Письмо отправлено одному адресату/группе получателей.']);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => 'Отправка не удалась: ' . mb_substr($e->getMessage(), 0, 800, 'UTF-8')], 502);
    }
}
/** One CLI run: at most N letters across all opt-in batches, rate limited. */
function docgenCronSend(): array
{
    $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '', 'batches' => []];
    $lock = @fopen(DOCGEN_CRON_LOCK_FILE, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        $result['reason'] = 'already_running';
        return $result;
    }
    try {
        $limit = max(1, min(60, (int) docgenMailingSettings()['lettersPerMinute']));
        $state = docgenReadJson(DOCGEN_CRON_STATE_FILE, []);
        $lastSendAt = (float) ($state['lastSendAt'] ?? 0);
        $batches = docgenBatchList();
        $batchById = [];
        foreach ($batches as $batch) {
            $batchById[(string) $batch['id']] = $batch;
        }
        $items = docgenQueueList();
        foreach ($items as $item) {
            if (($item['status'] ?? '') !== 'sending') {
                continue;
            }
            $batch = $batchById[(string) ($item['batchId'] ?? '')] ?? [];
            if (!in_array(($batch['status'] ?? ''), ['scheduled', 'sending'], true)) {
                continue;
            }
            $started = strtotime((string) ($item['sendStartedAt'] ?? ''));
            if ($started !== false && time() - $started > 600) {
                $item['status'] = 'scheduled';
                $item['lastError'] = 'Отправка прервана, повтор по расписанию.';
                unset($item['sendStartedAt']);
                docgenSaveQueueItem($item);
            }
        }
        $due = [];
        foreach ($items as $item) {
            if (($item['status'] ?? '') !== 'scheduled') {
                continue;
            }
            $batch = $batchById[(string) ($item['batchId'] ?? '')] ?? [];
            if (!in_array(($batch['status'] ?? ''), ['scheduled', 'sending'], true)) {
                continue;
            }
            $due[] = $item;
        }
        if (!$due) {
            $result['reason'] = 'no_due';
            return $result;
        }
        usort($due, static function (array $a, array $b) use ($batchById): int {
            $priorityA = (int) ($batchById[(string) ($a['batchId'] ?? '')]['priority'] ?? 0);
            $priorityB = (int) ($batchById[(string) ($b['batchId'] ?? '')]['priority'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }
            return strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? ''));
        });
        $due = array_slice($due, 0, $limit);
        $affected = [];
        foreach ($due as $index => $item) {
            $target = $lastSendAt + ($index + 1) * (60.0 / $limit);
            $wait = $target - microtime(true);
            if ($wait > 0) {
                if ($wait > 65) {
                    $result['skipped']++;
                    continue;
                }
                usleep((int) ($wait * 1_000_000));
            }
            $batchId = (string) ($item['batchId'] ?? '');
            $affected[$batchId] = true;
            $dir = docgenQueuePath((string) $item['id']);
            $pdfPath = $dir . '/document.pdf';
            $toText = (string) ($item['to'] ?? '');
            $ccText = (string) ($item['cc'] ?? '');
            $to = docgenHasUnparsedEmailText($toText) || docgenHasUnparsedEmailText($ccText) ? [] : docgenEmailAddresses($toText);
            $cc = docgenHasUnparsedEmailText($ccText) ? [] : docgenEmailAddresses($ccText);
            if (!$to || !is_file($pdfPath) || filesize($pdfPath) === 0) {
                $failed = docgenReadQueueItem((string) $item['id']);
                $failed['status'] = 'failed';
                $failed['lastError'] = 'Нет корректного получателя или PDF-вложения.';
                $failed['failedAt'] = date(DATE_ATOM);
                docgenSaveQueueItem($failed);
                $result['failed']++;
            } else {
                try {
                    docgenDeliverPreparedItem($item, $dir, $pdfPath, $to, $cc);
                    $result['sent']++;
                } catch (Throwable $e) {
                    $result['failed']++;
                }
            }
            $lastSendAt = microtime(true);
            docgenWriteJson(DOCGEN_CRON_STATE_FILE, ['lastSendAt' => $lastSendAt, 'updatedAt' => date(DATE_ATOM)]);
        }
        foreach (array_keys($affected) as $batchId) {
            $batch = docgenBatchMeta((string) $batchId);
            if (!$batch || $batchId === 'legacy') {
                continue;
            }
            $batchItems = docgenBatchItems((string) $batchId);
            $sent = 0;
            $failed = 0;
            $pending = 0;
            foreach ($batchItems as $batchItem) {
                $status = (string) ($batchItem['status'] ?? '');
                if ($status === 'sent') {
                    $sent++;
                } elseif ($status === 'failed') {
                    $failed++;
                } elseif (in_array($status, ['queued', 'scheduled', 'sending', 'paused'], true)) {
                    $pending++;
                }
            }
            $batch['sentCount'] = $sent;
            $batch['failedCount'] = $failed;
            $batch['status'] = $pending > 0 ? 'scheduled' : 'done';
            $batch['updatedAt'] = date(DATE_ATOM);
            docgenSaveBatch($batch);
            $result['batches'][] = ['id' => $batchId, 'status' => $batch['status'], 'sent' => $sent, 'failed' => $failed];
        }
        if ($result['sent'] === 0 && $result['failed'] === 0 && $result['skipped'] > 0) {
            $result['reason'] = 'rate_limited';
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function docgenDispatch(string $action): void
{
    $postActions = ['save_template', 'delete_template', 'analyze', 'generate', 'save_smtp_settings', 'add_smtp_account', 'delete_smtp_account', 'save_letter_template', 'test_smtp', 'save_dadata_settings', 'check_managers', 'dadata_fetch', 'save_records_bulk', 'update_queue_item', 'send_queue_item', 'review_batch', 'cancel_batch', 'retry_batch', 'pause_batch', 'resume_batch', 'delete_batch', 'move_batch', 'save_mailing_settings', 'preview_excel_import', 'commit_excel_import', 'export_excel', 'add_table_column', 'add_table_section'];
    $getActions = ['templates', 'template_content', 'smtp_settings', 'letter_template', 'mailing_settings', 'dadata_settings', 'queue', 'queue_file', 'download'];
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
        case 'letter_template':
            $letter = docgenLetterTemplate();
            sendJson(['ok' => true, 'settings' => $letter, 'accountId' => $letter['smtpAccountId']]);
        case 'mailing_settings':
            sendJson(['ok' => true, 'settings' => docgenMailingSettings()]);
        case 'save_mailing_settings':
            docgenSaveMailingSettings();
        case 'save_smtp_settings':
            docgenSaveSmtpSettings();
        case 'save_letter_template':
            docgenSaveLetterTemplate();
        case 'add_smtp_account':
            docgenAddSmtpAccount();
        case 'delete_smtp_account':
            docgenDeleteSmtpAccount();
        case 'test_smtp':
            docgenTestSmtp();
        case 'dadata_settings':
            sendJson(docgenDadataPublicPayload());
        case 'save_dadata_settings':
            docgenSaveDadataSettings();
        case 'check_managers':
            docgenCheckManagers();
        case 'dadata_fetch':
            docgenDadataFetchForUpdate();
        case 'save_records_bulk':
            handleBulkSaveRecords();
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
            sendJson(['ok' => true, 'items' => docgenQueueList(), 'batches' => docgenBatchList()]);
        case 'queue_file':
            docgenQueueFile();
        case 'update_queue_item':
            docgenUpdateQueueItem();
        case 'send_queue_item':
            docgenSendQueueItem();
        case 'review_batch':
            docgenReviewBatch();
        case 'cancel_batch':
            docgenCancelBatch();
        case 'retry_batch':
            docgenRetryBatch();
        case 'pause_batch':
            docgenPauseBatch();
        case 'resume_batch':
            docgenResumeBatch();
        case 'delete_batch':
            docgenDeleteBatch();
        case 'move_batch':
            docgenMoveBatch();
        case 'download':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                sendJson(['ok' => false, 'error' => 'Для скачивания используйте GET.'], 405);
            }
            docgenStreamDownload();
    }
}

?>