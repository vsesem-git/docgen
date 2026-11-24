<?php
declare(strict_types=1);

/**
 * HTTP-слой генератора: только web-kodeks.ru, без переадресаций на другие хосты,
 * с повторами на временные ошибки. Канал можно подменить в тестах
 * (DOCGEN_WEBINARS_FIXTURES_DIR) — тогда запросы читаются из сохранённых файлов.
 */

/**
 * @return array{url: string, body: string, code: int}
 */
function webinarsFetch(string $url, int $attempts = 4): array
{
    $fixtures = getenv('DOCGEN_WEBINARS_FIXTURES_DIR');
    if (is_string($fixtures) && $fixtures !== '') {
        return webinarsFetchFixture($url, $fixtures);
    }
    $lastError = 'Нет ответа от сайта.';
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $current = $url;
        for ($hop = 0; $hop < 4; $hop++) {
            $response = webinarsHttpGet($current);
            if ($response['code'] >= 300 && $response['code'] < 400 && isset($response['location']) && $response['location'] !== '') {
                // Редирект разрешён только на тот же сайт — иначе это уже другой источник.
                $current = webinarsSameSite((string) $response['location'], $current);
                continue 2;
            }
            if ($response['code'] >= 200 && $response['code'] < 300) {
                return ['url' => $current, 'body' => $response['body'], 'code' => $response['code']];
            }
            $lastError = 'Сайт ответил ошибкой ' . $response['code'] . '.';
            if (!in_array($response['code'], [429, 500, 502, 503, 504], true)) {
                throw new WebinarsNetworkError('Сайт ответил ошибкой ' . $response['code'] . '.');
            }
        }
        if ($attempt < $attempts) {
            usleep((int) (500000 * $attempt));
        }
    }
    throw new WebinarsNetworkError($lastError);
}

/**
 * @return array{url: string, data: mixed, code: int}
 */
function webinarsFetchJson(string $url): array
{
    $response = webinarsFetch($url);
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new WebinarsSourceError('Сайт вернул некорректный список вебинаров.');
    }
    return ['url' => $response['url'], 'data' => $data, 'code' => $response['code']];
}

/**
 * @return array{body: string, code: int, location?: string}
 */
function webinarsHttpGet(string $url): array
{
    $headers = [
        'User-Agent: KodeksWebinarProgram/1.0 (public webinar schedule reader)',
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
        'Accept-Encoding: identity',
        'Connection: close',
    ];
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        $error = curl_error($curl);
        unset($curl);
        if (!is_string($body) || $body === '') {
            if ($code === 0) {
                throw new WebinarsNetworkError('Нет связи с сайтом: ' . ($error !== '' ? $error : 'пустой ответ.'));
            }
        }
        if ($code === 0) {
            throw new WebinarsNetworkError('Нет связи с сайтом.');
        }
        return ['body' => (string) $body, 'code' => $code, 'location' => $location];
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 25,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $rawHeaders = http_get_last_response_headers() ?? [];
    if ($body === false) {
        throw new WebinarsNetworkError('Нет связи с сайтом.');
    }
    $code = 0;
    $location = '';
    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $code = (int) $match[1];
        } elseif (preg_match('/^Location:\s*(.+)$/i', $line, $match) === 1) {
            $location = trim($match[1]);
        }
    }
    return ['body' => $body, 'code' => $code, 'location' => $location];
}

/**
 * Тестовый канал: сохранённые страницы из архива переноса. URL-карта такая же,
 * как у сайта, поэтому логика обхода проверяется без интернета.
 *
 * @return array{url: string, body: string, code: int}
 */
function webinarsFetchFixture(string $url, string $directory): array
{
    $parts = parse_url($url);
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $query = (string) ($parts['query'] ?? '');
    $file = null;
    if ($path === '') {
        $file = $directory . '/home.html.gz';
    } elseif ($path === 'getWebs.php') {
        $file = $directory . '/list.json';
    } elseif ($path === 'getWebs2.php' && str_contains($query, 'all=true')) {
        $file = $directory . '/nav.json';
    } elseif ($path === 'js/script.js') {
        $file = $directory . '/script.js';
    } elseif (preg_match('#^[a-z0-9-]+$#', $path) === 1 && is_file($directory . '/card-' . $path . '.html.gz')) {
        $file = $directory . '/card-' . $path . '.html.gz';
    }
    if ($file === null || !is_file($file)) {
        throw new WebinarsNetworkError('В сохранённом наборе нет страницы ' . $url . '.');
    }
    $raw = (string) file_get_contents($file);
    if (str_ends_with($file, '.gz')) {
        $raw = (string) gzdecode($raw);
    }
    return ['url' => $url, 'body' => $raw, 'code' => 200];
}
