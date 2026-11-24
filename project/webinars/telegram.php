<?php
declare(strict_types=1);

/**
 * Telegram Bot API для уведомлений о новых карточках.
 *
 * Токен и чат хранятся только в приватном каталоге (0600) или в окружении службы.
 * Токен никогда не возвращается в интерфейс и не попадает в журналы: сообщения об
 * ошибках содержат только код ответа.
 */

class WebinarsTelegramError extends RuntimeException
{
}

const WEBINARS_TELEGRAM_TOKEN_PATTERN = '/^[0-9]{5,20}:[A-Za-z0-9_-]{20,100}$/';
const WEBINARS_TELEGRAM_TEST = "ЦМК · Тестовое уведомление. Бот подключён: здесь будут сообщения о новых вебинарах ЦМК.";

function webinarsTelegramCheckToken(string $token): string
{
    $token = trim($token);
    if (preg_match(WEBINARS_TELEGRAM_TOKEN_PATTERN, $token) !== 1) {
        throw new WebinarsTelegramError('Токен бота неверного формата. Получите новый в BotFather.');
    }
    return $token;
}

function webinarsTelegramCheckChat(string $chat): string
{
    $chat = trim($chat);
    if (preg_match('/^-?[0-9]{1,20}$/', $chat) !== 1 || (int) $chat === 0) {
        throw new WebinarsTelegramError('Нужен числовой ID чата Telegram.');
    }
    return $chat;
}

/**
 * Запрос к Bot API. Токен передаётся в пути HTTPS-запроса — это требование Bot API,
 * поэтому в текстах ошибок его нет, а переадресации запрещены.
 *
 * @param array<string, mixed> $payload
 */
function webinarsTelegramCall(string $token, string $method, array $payload = [], int $timeout = 20): mixed
{
    webinarsTelegramCheckToken($token);
    // Проверки без сети: DOCGEN_WEBINARS_TELEGRAM_STUB=1 возвращает правдоподобные
    // ответы Bot API. В рабочей установке переменная не задаётся.
    if (trim((string) getenv('DOCGEN_WEBINARS_TELEGRAM_STUB')) === '1') {
        return webinarsTelegramStub($method, $payload);
    }
    $url = 'https://api.telegram.org/bot' . $token . '/' . rawurlencode($method);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $body = json_encode($payload === [] ? new stdClass() : $payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new WebinarsTelegramError('Не удалось подготовить запрос к Telegram.');
    }

    $status = 0;
    $responseBody = '';
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $responseBody = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($curl);
        unset($curl);
        if ($status === 0) {
            // Текст сетевой ошибки не выводим: он может содержать полный URL с токеном.
            throw new WebinarsTelegramError('Нет связи с Telegram. Проверьте сеть и попробуйте снова.');
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($body),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $responseBody = (string) @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $match) === 1) {
            $status = (int) $match[1];
        }
        if ($status === 0) {
            throw new WebinarsTelegramError('Нет связи с Telegram. Проверьте сеть и попробуйте снова.');
        }
    }

    $data = json_decode($responseBody, true);
    if ($status === 200 && is_array($data) && ($data['ok'] ?? false) === true && array_key_exists('result', $data)) {
        return $data['result'];
    }
    $code = is_array($data) && isset($data['error_code']) ? (int) $data['error_code'] : $status;
    throw new WebinarsTelegramError(webinarsTelegramErrorText($code, $method));
}

/** Ответы Bot API для автономных проверок (только при DOCGEN_WEBINARS_TELEGRAM_STUB=1). */
function webinarsTelegramStub(string $method, array $payload): mixed
{
    $file = WEBINARS_STORAGE_DIR . '/telegram-stub.json';
    $state = docgenReadJson($file, ['updates' => [], 'sent' => 0]);
    switch ($method) {
        case 'getMe':
            return ['username' => 'cmk_docgen_test_bot'];
        case 'getUpdates':
            $updates = (array) ($state['updates'] ?? []);
            $offset = (int) ($payload['offset'] ?? 0);
            $updates = array_values(array_filter($updates, static fn($u): bool => is_array($u) && (int) ($u['update_id'] ?? 0) >= $offset));
            if (isset($payload['limit'])) {
                $updates = array_slice($updates, 0, (int) $payload['limit']);
            }
            return array_map(static fn(array $u): array => ['update_id' => (int) $u['update_id'], 'message' => $u['message']], $updates);
        case 'sendMessage':
            $state['sent'] = (int) ($state['sent'] ?? 0) + 1;
            $state['last_chat'] = (string) ($payload['chat_id'] ?? '');
            docgenWriteJson($file, $state);
            return ['message_id' => $state['sent']];
        case 'setWebhook':
            return true;
    }
    return true;
}

function webinarsTelegramErrorText(int $code, string $method): string
{
    if (in_array($code, [401, 404], true)) {
        return 'Telegram отклонил токен. Перевыпустите его в BotFather или выполните привязку заново.';
    }
    if (in_array($code, [400, 403], true) && $method === 'sendMessage') {
        return 'Бот не смог написать в указанный чат. Нажмите «Запустить» у бота и проверьте привязку.';
    }
    if ($code === 409) {
        return 'Этот бот уже использует webhook или другой приёмник. Для привязки возьмите отдельного бота.';
    }
    if ($code === 429) {
        return 'Telegram временно ограничил отправку. Повторите позже.';
    }
    return 'Telegram не подтвердил запрос. Уведомление останется в очереди для повтора.';
}

function webinarsTelegramUsername(string $token): string
{
    $me = webinarsTelegramCall($token, 'getMe');
    $name = is_array($me) ? (string) ($me['username'] ?? '') : '';
    if (preg_match('/^[A-Za-z0-9_]{4,32}$/', $name) !== 1) {
        throw new WebinarsTelegramError('Telegram не вернул имя бота.');
    }
    return $name;
}

/** Отправить одно сообщение; ограничение Telegram — 4096 символов. */
function webinarsTelegramSend(string $token, string $chat, string $text): void
{
    webinarsTelegramCall($token, 'sendMessage', [
        'chat_id' => webinarsTelegramCheckChat($chat),
        'text' => mb_substr($text, 0, 4000, 'UTF-8'),
        'link_preview_options' => ['is_disabled' => true],
    ]);
}

/**
 * Привязка личного чата одноразовым кодом.
 *
 * Раньше это был один синхронный запрос на 180 секунд: браузер обрывал его по
 * таймауту раньше, чем панель успевала показать код, и привязка не работала
 * вовсе. Теперь шаги короткие и независимые:
 *   webinarsTelegramPairStart() — проверяет токен, выдаёт код и сразу отвечает;
 *   webinarsTelegramPairCheck() — один короткий getUpdates на каждое нажатие
 *                                 интерфейса, пока оператор не пришлёт код.
 *
 * Ожидание хранится в приватном файле, поэтому переживает любой запрос.
 */

/** Ожидание привязки: код, имя бота и момент истечения. */
function webinarsTelegramPairFile(): string
{
    return WEBINARS_STORAGE_DIR . '/telegram-pair.json';
}

function webinarsTelegramPairForget(): void
{
    @unlink(webinarsTelegramPairFile());
}

/** @return array{code: string, username: string, expires: int}|null */
function webinarsTelegramPairPending(): ?array
{
    $path = webinarsTelegramPairFile();
    if (!is_file($path)) {
        return null;
    }
    $data = docgenReadJson($path, []);
    $code = (string) ($data['code'] ?? '');
    $expires = (int) ($data['expires'] ?? 0);
    if ($code === '' || $expires < time()) {
        webinarsTelegramPairForget();
        return null;
    }
    return ['code' => $code, 'username' => (string) ($data['username'] ?? ''), 'expires' => $expires];
}

/**
 * Шаг 1: проверить токен и выдать код. Отвечает за секунды, а не за минуты.
 *
 * @return array{code: string, username: string, expires: int, url: string}
 */
function webinarsTelegramPairStart(string $token, int $seconds = 180): array
{
    $username = webinarsTelegramUsername($token);
    // Старые обновления не нужны: ищем только сообщение с новым кодом.
    try {
        $updates = webinarsTelegramCall($token, 'getUpdates', ['timeout' => 0, 'limit' => 1]);
        if (is_array($updates) && $updates !== []) {
            $last = end($updates);
            if (is_array($last) && isset($last['update_id'])) {
                // Подтверждаем прочитанное, чтобы поиск кода шёл по свежим событиям.
                webinarsTelegramCall($token, 'getUpdates', ['offset' => (int) $last['update_id'] + 1, 'timeout' => 0, 'limit' => 1]);
            }
        }
    } catch (WebinarsTelegramError $error) {
        // Бот без истории или уже опрошенный — это не повод отказывать в привязке.
        if (str_contains($error->getMessage(), 'webhook')) {
            throw $error;
        }
    }
    $code = 'ЦМК-' . strtoupper(bin2hex(random_bytes(5)));
    $expires = time() + $seconds;
    webinarsBoot();
    docgenWriteJson(webinarsTelegramPairFile(), [
        'code' => $code,
        'username' => $username,
        'expires' => $expires,
        'token_hash' => hash('sha256', $token),
        'saved_at' => webinarsStamp(),
    ]);
    @chmod(webinarsTelegramPairFile(), 0600);
    return ['code' => $code, 'username' => $username, 'expires' => $expires, 'url' => 'https://t.me/' . $username];
}

/**
 * Шаг 2: один короткий опрос Telegram. Интерфейс вызывает его раз в пару секунд.
 *
 * @return array{state: 'waiting'|'done'|'expired', chat_id?: string, message: string}
 */
function webinarsTelegramPairCheck(string $token): array
{
    $pending = webinarsTelegramPairPending();
    if ($pending === null) {
        return ['state' => 'expired', 'message' => 'Код устарел. Нажмите «Привязать бота» ещё раз.'];
    }
    $updates = webinarsTelegramCall($token, 'getUpdates', [
        'timeout' => 0,
        'limit' => 20,
        'allowed_updates' => ['message'],
    ]);
    if (!is_array($updates)) {
        throw new WebinarsTelegramError('Telegram вернул неверный список сообщений.');
    }
    $maxUpdate = 0;
    foreach ($updates as $item) {
        if (!is_array($item) || !isset($item['update_id'])) {
            continue;
        }
        $maxUpdate = max($maxUpdate, (int) $item['update_id']);
        $message = $item['message'] ?? null;
        if (!is_array($message) || trim((string) ($message['text'] ?? '')) !== $pending['code']) {
            continue;
        }
        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || ($chat['type'] ?? '') !== 'private') {
            return ['state' => 'waiting', 'message' => 'Код отправлен не в личном чате. Напишите боту в личные сообщения.'];
        }
        $chatId = webinarsTelegramCheckChat((string) ($chat['id'] ?? ''));
        webinarsTelegramSend($token, $chatId, WEBINARS_TELEGRAM_TEST);
        webinarsTelegramSave($token, $chatId);
        webinarsTelegramPairForget();
        return ['state' => 'done', 'chat_id' => $chatId, 'message' => 'Бот привязан. Тестовое сообщение отправлено в этот чат.'];
    }
    if ($maxUpdate > 0) {
        // Прочитанные обновления больше не нужны, но offset не должен пропустить код.
        webinarsTelegramCall($token, 'getUpdates', ['offset' => $maxUpdate + 1, 'timeout' => 0, 'limit' => 1]);
    }
    return ['state' => 'waiting', 'message' => 'Код пока не получен. Отправьте его боту в личном чате.'];
}

/**
 * Сообщения о новых карточках. Telegram ограничивает длину, поэтому очередь
 * разбивается на несколько сообщений; ключи фиксируются после успешной отправки.
 *
 * @param list<array<string, mixed>> $notices
 * @return list<array{keys: list<string>, text: string}>
 */
function webinarsTelegramBatches(array $notices): array
{
    $header = 'ЦМК · Новые вебинары';
    $batches = [];
    $message = $header;
    $keys = [];
    foreach ($notices as $notice) {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($notice['title'] ?? '')));
        if (mb_strlen($title, 'UTF-8') > 350) {
            $title = mb_substr($title, 0, 349, 'UTF-8') . '…';
        }
        $item = "\n\n" . ($notice['date_ru'] ?? '') . ' · ' . ($notice['hours'] ?? '') . " МСК\n" . $title . "\n" . ($notice['url'] ?? '');
        if (mb_strlen($message . $item, 'UTF-8') > 3900 && $keys !== []) {
            $batches[] = ['keys' => $keys, 'text' => $message];
            $message = $header;
            $keys = [];
        }
        $message .= $item;
        $keys[] = (string) ($notice['key'] ?? '');
    }
    if ($keys !== []) {
        $batches[] = ['keys' => $keys, 'text' => $message];
    }
    return $batches;
}

/**
 * Проверка сайта и отправка уведомлений, как в Python-версии: неудачный обход не
 * заменяет сохранённый список, а неотправленные уведомления остаются в очереди.
 *
 * @param array{mode?: string, start?: ?string} $options
 * @return array<string, mixed>
 */
function webinarsCheckNow(array $options = []): array
{
    $notes = [];
    $siteOk = false;
    $sendOk = true;
    $added = 0;
    $sent = 0;
    try {
        $result = webinarsFetchChain($options + ['mode' => 'chain']);
        $saved = webinarsSaveCatalog($result['entries'], $result['home'], 'live');
        $siteOk = true;
        $added = count($saved['added']);
        $notes[] = 'Проверено карточек: ' . count($result['entries']) . '.';
        if ($added > 0) {
            $notes[] = 'Новых карточек: ' . $added . '.';
        }
    } catch (Throwable $error) {
        $notes[] = 'Не удалось обновить сайт. Предыдущий список сохранён. ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8');
    }

    $state = webinarsState();
    $pending = webinarsPendingNotices($state);
    $config = webinarsTelegramConfig();
    if ($pending !== [] && $config !== null) {
        try {
            foreach (webinarsTelegramBatches($pending) as $batch) {
                webinarsTelegramSend($config['token'], $config['chat_id'], $batch['text']);
                webinarsMarkNoticesSent($batch['keys']);
                $sent += count($batch['keys']);
            }
            unset($config);
            if ($sent > 0) {
                $notes[] = 'Уведомлено о карточках: ' . $sent . '.';
            }
        } catch (WebinarsTelegramError $error) {
            $sendOk = false;
            $notes[] = $error->getMessage();
        }
    } elseif ($pending !== []) {
        $notes[] = 'Telegram не настроен; новые карточки сохранены для отправки.';
    } elseif ($siteOk) {
        $notes[] = 'Нет новых карточек для уведомления.';
    }

    $result = [
        'ok' => $siteOk && $sendOk,
        'site_ok' => $siteOk,
        'send_ok' => $sendOk,
        'added' => $added,
        'sent' => $sent,
        'note' => implode(' ', $notes),
    ];
    webinarsRecordCheck($result);
    return $result;
}
