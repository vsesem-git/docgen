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
 * Привязка личного чата одноразовым кодом: оператор открывает бота, нажимает
 * «Запустить» и присылает код. Панель ждёт код короткими запросами getUpdates.
 *
 * @param callable(string, string):void $onCode имя бота и код для экрана
 * @return array{username: string, chat_id: string}
 */
function webinarsTelegramPair(string $token, callable $onCode, int $seconds = 180): array
{
    $username = webinarsTelegramUsername($token);
    $code = 'ЦМК-' . strtoupper(bin2hex(random_bytes(5)));
    $onCode($username, $code);
    $deadline = microtime(true) + $seconds;
    $offset = 0;
    while (microtime(true) < $deadline) {
        $polling = max(1, min(10, (int) floor($deadline - microtime(true))));
        $updates = webinarsTelegramCall($token, 'getUpdates', [
            'offset' => $offset,
            'timeout' => $polling,
            'allowed_updates' => ['message'],
        ], $polling + 8);
        if (!is_array($updates)) {
            throw new WebinarsTelegramError('Telegram вернул неверный список сообщений.');
        }
        foreach ($updates as $item) {
            if (!is_array($item) || !isset($item['update_id'])) {
                continue;
            }
            $offset = max($offset, (int) $item['update_id'] + 1);
            $message = $item['message'] ?? null;
            if (!is_array($message) || trim((string) ($message['text'] ?? '')) !== $code) {
                continue;
            }
            $chat = $message['chat'] ?? null;
            if (is_array($chat) && ($chat['type'] ?? '') === 'private') {
                return ['username' => $username, 'chat_id' => webinarsTelegramCheckChat((string) ($chat['id'] ?? ''))];
            }
        }
        usleep(150000);
    }
    throw new WebinarsTelegramError('Код не получен. Откройте личный чат с ботом и повторите привязку.');
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
