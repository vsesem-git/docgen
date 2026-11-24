<?php
declare(strict_types=1);

/**
 * Генератор программ вебинаров ЦМК — разбор публичного расписания web-kodeks.ru.
 *
 * Перенос с Python-версии (site_kodeks.py) один-в-один: те же селекторы, те же
 * проверки и те же тексты ошибок. Смысл каждого правила — не «угадать» данные,
 * а отказаться от записи, если карточка не подтверждена.
 */

const WEBINARS_HOME = 'http://www.web-kodeks.ru/';
const WEBINARS_HOSTS = ['web-kodeks.ru', 'www.web-kodeks.ru'];
const WEBINARS_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const WEBINARS_DATE_PATTERN = '/(?<!\d)(\d{1,2})\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s+(20\d\d)(?!\d)/iu';
const WEBINARS_RANGE_PATTERN = '/(\d{1,2})[:.](\d{2})\s*(?:до|[–—−-])\s*(\d{1,2})[:.](\d{2})/u';

/** Ошибка исходных данных: текст показывается оператору как есть. */
class WebinarsSourceError extends RuntimeException
{
}

/** Сеть недоступна или сайт ответил ошибкой. */
class WebinarsNetworkError extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Текст и разметка
// ---------------------------------------------------------------------------

function webinarsNormalizeText(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);
    return preg_replace('/ +([,.;:!?])/u', '$1', $value) ?? $value;
}

/**
 * Только явные опечатки из расписания «Кодекса». Содержательные факты не заменяются.
 */
function webinarsProofread(string $value): string
{
    static $swaps = [
        'обспечение' => 'обеспечение',
        'земельно-правововым' => 'земельно-правовым',
        'гособронзаказа' => 'гособоронзаказа',
        'XML-cхем' => 'XML-схем',
        'требования к обустройству и обслуживания' => 'требования к обустройству и обслуживанию',
        'Требования к обустройству и обслуживания' => 'Требования к обустройству и обслуживанию',
        'при обращения с коммунальными отходами' => 'при обращении с коммунальными отходами',
        'Рекомендацию по ведению' => 'Рекомендации по ведению',
        'нежилых помещениям' => 'нежилых помещений',
        'I-м квартале' => 'I квартале',
        'бразузеры' => 'браузеры',
        'Google Chromе' => 'Google Chrome',
        'промышленой экологии' => 'промышленной экологии',
    ];
    $value = strtr($value, $swaps);
    if (str_starts_with($value, 'словия ')) {
        $value = 'У' . $value;
    }
    return $value;
}

function webinarsClassSelector(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
}

function webinarsXPath(DOMNode $context): DOMXPath
{
    return new DOMXPath($context instanceof DOMDocument ? $context : $context->ownerDocument);
}

/** Текст узла с нормализацией и заменой явных опечаток. */
function webinarsText(?DOMNode $node, ?string $omitClass = null): string
{
    if ($node === null) {
        return '';
    }
    if ($omitClass !== null) {
        $copy = $node->cloneNode(true);
        if ($copy instanceof DOMElement) {
            $inner = webinarsXPath($copy);
            foreach (iterator_to_array($inner->query('.//*[' . webinarsClassSelector($omitClass) . ']', $copy)) as $part) {
                $part->parentNode?->removeChild($part);
            }
        }
        $node = $copy;
    }
    return webinarsProofread(webinarsNormalizeText(webinarsNodeText($node)));
}

/**
 * Текст как get_text(' ') в BeautifulSoup: между узлами ставится пробел, поэтому
 * «ЗАГОЛОВОК:<br>ПРОДОЛЖЕНИЕ» не склеивается в «ЗАГОЛОВОК:ПРОДОЛЖЕНИЕ».
 */
function webinarsNodeText(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return $node->nodeValue ?? '';
    }
    if (!$node instanceof DOMElement) {
        return $node->textContent ?? '';
    }
    $name = strtolower($node->localName);
    if (in_array($name, ['script', 'style', 'noscript'], true)) {
        return '';
    }
    if ($name === 'br') {
        return ' ';
    }
    $parts = [];
    foreach ($node->childNodes as $child) {
        $parts[] = webinarsNodeText($child);
    }
    return implode(' ', $parts);
}

function webinarsFirstByClass(DOMNode $context, string $class, bool $deep = true): ?DOMElement
{
    $xp = webinarsXPath($context);
    $found = $xp->query(($deep ? './/*[' : './*[') . webinarsClassSelector($class) . ']', $context);
    $node = $found === false ? null : $found->item(0);
    return $node instanceof DOMElement ? $node : null;
}

/** @return list<DOMElement> */
function webinarsAllByClass(DOMNode $context, string $class): array
{
    $xp = webinarsXPath($context);
    $found = $xp->query('.//*[' . webinarsClassSelector($class) . ']', $context);
    $result = [];
    if ($found !== false) {
        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }
    }
    return $result;
}

/** Загрузить страницу так же, как её видит браузер: UTF-8 без предварительного разбора тегов. */
function webinarsLoadHtml(string $html): DOMDocument
{
    // Сайт отдаёт UTF-8 с BOM. libxml иначе примет байты за latin-1 и испортит кириллицу,
    // поэтому сообщаем кодировку явно и убираем BOM до загрузки.
    $html = preg_replace('/^\xEF\xBB\xBF/', '', $html) ?? $html;
    if (!preg_match('/<meta\s+charset=/i', $html)) {
        $html = preg_replace('/<head\b[^>]*>/i', '$0<meta charset="utf-8">', $html, 1) ?? $html;
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc;
}

// ---------------------------------------------------------------------------
// Ссылки и адреса карточек
// ---------------------------------------------------------------------------

/**
 * Разрешить относительную ссылку и проверить, что она ведёт на этот сайт.
 * Внешние хосты, логины в URL и нестандартные порты не принимаются.
 */
function webinarsSameSite(string $value, string $base = WEBINARS_HOME): string
{
    $value = trim($value);
    if ($value === '') {
        throw new WebinarsSourceError('Пустая ссылка на карточку вебинара.');
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $value) === 1 && preg_match('#^https?://#i', $value) !== 1) {
        throw new WebinarsSourceError('Ожидалась ссылка на web-kodeks.ru: ' . $value);
    }
    $parts = parse_url(webinarsResolveUrl($value, $base));
    if (!is_array($parts)) {
        throw new WebinarsSourceError('Некорректная ссылка: ' . $value);
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = $parts['port'] ?? null;
    if (!in_array($scheme, ['http', 'https'], true)
        || !in_array($host, WEBINARS_HOSTS, true)
        || isset($parts['user']) || isset($parts['pass'])
        || !in_array($port, [null, 80, 443], true)) {
        throw new WebinarsSourceError('Ожидалась ссылка на web-kodeks.ru: ' . $value);
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '') {
        $path = '/';
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $path . $query;
}

/** Минимальный разбор относительных ссылок (аналог urljoin). */
function webinarsResolveUrl(string $value, string $base): string
{
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $value) === 1 || str_starts_with($value, '//')) {
        return str_starts_with($value, '//') ? 'http:' . $value : $value;
    }
    $b = parse_url($base);
    if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
        return $value;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($value, '?')) {
        return $origin . ($b['path'] ?? '/') . $value;
    }
    if (str_starts_with($value, '#')) {
        return $origin . ($b['path'] ?? '/') . ($b['query'] ?? '') . $value;
    }
    if (str_starts_with($value, '/')) {
        return $origin . webinarsCollapsePath($value);
    }
    $dir = isset($b['path']) ? substr($b['path'], 0, (int) strrpos($b['path'], '/') + 1) : '/';
    return $origin . webinarsCollapsePath($dir . $value);
}

function webinarsCollapsePath(string $path): string
{
    $query = '';
    if (($q = strpos($path, '?')) !== false) {
        $query = substr($path, $q);
        $path = substr($path, 0, $q);
    }
    if (($h = strpos($path, '#')) !== false) {
        $path = substr($path, 0, $h);
    }
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $index => $segment) {
        if ($segment === '..') {
            if (count($out) > 1) {
                array_pop($out);
            }
            continue;
        }
        if ($segment === '.' || ($segment === '' && $index > 0 && $index < count($segments) - 1)) {
            continue;
        }
        $out[] = $segment;
    }
    $result = implode('/', $out);
    if (!str_starts_with($result, '/')) {
        $result = '/' . $result;
    }
    return $result . $query;
}

/** Канонический адрес карточки: главная отдаёт HTTP-ссылки, порт и www сохраняем как есть. */
function webinarsCanonicalEvent(string $value): string
{
    $parts = parse_url(webinarsSameSite($value));
    $path = trim((string) ($parts['path'] ?? ''), '/');
    if ($path === '') {
        throw new WebinarsSourceError('Ожидалась ссылка на карточку вебинара, а не на главную.');
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return 'http://www.web-kodeks.ru/' . $path . $query;
}

/** Принять введённый оператором адрес: полную ссылку или короткий код карточки (/med, med). */
function webinarsStartAddress(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new WebinarsSourceError('Укажите адрес карточки вебинара.');
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $value) !== 1) {
        $value = WEBINARS_HOME . ltrim($value, '/');
    }
    return webinarsCanonicalEvent($value);
}

// ---------------------------------------------------------------------------
// Список «Ближайшие вебинары» и навигация
// ---------------------------------------------------------------------------

/**
 * Записи из getWebs.php / getWebs2.php в том порядке, в котором их читает jQuery.each:
 * числовые ключи по возрастанию, затем остальные в порядке ответа.
 *
 * @return list<array{url: string, title: string, day: ?string, date_text: string}>
 */
function webinarsScheduleRows(mixed $raw): array
{
    if (is_array($raw)) {
        $isList = array_is_list($raw);
        if ($isList) {
            $rows = $raw;
        } else {
            $numeric = [];
            $other = [];
            foreach ($raw as $key => $value) {
                if (is_int($key) || (is_string($key) && preg_match('/^(0|[1-9]\d{0,9})$/', $key) === 1 && (int) $key < 4294967295)) {
                    $numeric[(int) $key] = $value;
                } else {
                    $other[] = $value;
                }
            }
            ksort($numeric);
            $rows = array_merge(array_values($numeric), $other);
        }
    } else {
        throw new WebinarsSourceError('Неизвестный формат списка вебинаров.');
    }
    $values = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['url']) || !is_string($row['url']) || trim($row['url']) === '') {
            throw new WebinarsSourceError('В расписании есть запись без ссылки.');
        }
        $day = null;
        if (!empty($row['date']) && is_string($row['date'])) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']);
            if ($parsed === false) {
                throw new WebinarsSourceError('В расписании некорректная дата: ' . $row['date']);
            }
            $day = $parsed->format('Y-m-d');
        }
        $values[] = [
            'url' => webinarsCanonicalEvent($row['url']),
            'title' => webinarsNormalizeText((string) ($row['title'] ?? '')),
            'day' => $day,
            'date_text' => webinarsNormalizeText((string) ($row['dateText'] ?? '')),
        ];
    }
    return $values;
}

/** @return list<array{url: string, title: string, day: ?string, date_text: string}> */
function webinarsListing(DOMDocument $home, string $homeUrl = WEBINARS_HOME): array
{
    $xp = new DOMXPath($home);
    $endpoint = null;
    if (preg_match('/(["\'])([\w\/.-]*getWebs\.php)\1/i', $home->saveHTML() ?: '', $match) === 1) {
        $endpoint = $match[2];
    }
    if ($endpoint !== null) {
        $raw = webinarsFetchJson(webinarsSameSite($endpoint, $homeUrl));
        $found = webinarsScheduleRows($raw['data']);
    } else {
        $courses = $xp->query("//ul[" . webinarsClassSelector('js-get-webinars') . ' or ' . webinarsClassSelector('mainpage__vebinars__menu') . ']');
        $node = $courses === false ? null : $courses->item(0);
        if (!$node instanceof DOMElement || preg_match('/display\s*:\s*none/i', (string) $node->getAttribute('style')) === 1) {
            throw new WebinarsSourceError('Актуальный список на главной не найден. Укажите адрес карточки вручную.');
        }
        $found = [];
        foreach ($xp->query('.//a[@href]', $node) as $anchor) {
            $label = webinarsFirstByClass($anchor, 'date');
            $title = webinarsFirstByClass($anchor, 'underline');
            if ($label !== null && $title !== null) {
                $found[] = [
                    'url' => webinarsCanonicalEvent((string) $anchor->getAttribute('href')),
                    'title' => webinarsText($title),
                    'day' => null,
                    'date_text' => webinarsText($label),
                ];
            }
        }
    }
    if ($found === []) {
        throw new WebinarsSourceError('На главной нет опубликованных вебинаров.');
    }
    $unique = [];
    foreach ($found as $entry) {
        if (isset($unique[$entry['url']]) && $unique[$entry['url']] !== $entry) {
            throw new WebinarsSourceError('Одна карточка указана для разных записей: ' . $entry['url']);
        }
        $unique[$entry['url']] = $entry;
    }
    return array_values($unique);
}

/** Неактивная кнопка «Следующий вебинар»: ссылки нет вовсе или она пустая. */
function webinarsInertHref(string $href): bool
{
    $value = strtolower(rtrim((string) preg_replace('/\s+/', '', $href), ';'));
    return in_array($value, ['', '#', 'javascript:void(0)', 'javascript:void0'], true);
}

/**
 * Конец цепочки подтверждается тем же запросом, который делает /js/script.js:
 * getWebs2.php?all=true. Если скрипт изменился — конец не подтверждён.
 *
 * @return list<array{url: string, title: string, day: ?string, date_text: string}>
 */
function webinarsNavigation(DOMDocument $page, string $base): array
{
    $src = null;
    $xp = new DOMXPath($page);
    foreach ($xp->query('//script[@src]') as $script) {
        $path = (string) parse_url((string) $script->getAttribute('src'), PHP_URL_PATH);
        if (str_ends_with($path, '/js/script.js')) {
            $src = (string) $script->getAttribute('src');
            break;
        }
    }
    if ($src === null) {
        throw new WebinarsSourceError('Кнопка неактивна, но скрипт навигации не найден; конец цепочки не подтверждён.');
    }
    $code = webinarsFetch(webinarsSameSite($src, $base))['body'];
    if (preg_match('/(["\'])([\w.\/-]*getWebs2\.php)\1/', $code, $match) !== 1
        || preg_match('/\ball\s*:\s*true\b/', $code) !== 1
        || !str_contains($code, '.js-next')) {
        throw new WebinarsSourceError('Навигация сайта изменилась; конец цепочки не подтверждён.');
    }
    $raw = webinarsFetchJson(webinarsSameSite($match[2] . '?all=true', $base));
    $values = webinarsScheduleRows($raw['data']);
    if ($values === []) {
        throw new WebinarsSourceError('Сайт вернул пустую навигацию; конец цепочки не подтверждён.');
    }
    return $values;
}

// ---------------------------------------------------------------------------
// Карточка вебинара
// ---------------------------------------------------------------------------

/**
 * Разобрать карточку. Обязательные блоки: заголовок, вид, дата+время по Москве,
 * аннотация, аудитория, ведущие и программа. Если чего-то нет — ошибка, а не
 * документ с пустыми полями.
 *
 * @param array{url: string, title?: string, day?: ?string, date_text?: string} $item
 * @return array<string, mixed>
 */
function webinarsParseEvent(string $html, array $item): array
{
    $doc = webinarsLoadHtml($html);
    $xp = new DOMXPath($doc);

    $mast = null;
    foreach ($xp->query('//*[' . webinarsClassSelector('first-frame__content') . ']') as $candidate) {
        if ($xp->query('.//*[' . webinarsClassSelector('first-frame__content__title') . ']', $candidate)->length > 0) {
            $mast = $candidate;
            break;
        }
    }
    if (!$mast instanceof DOMElement) {
        throw new WebinarsSourceError('Нет заголовка карточки вебинара.');
    }
    $titleNode = webinarsFirstByClass($mast, 'first-frame__content__title');
    $fullTitle = webinarsText($titleNode);
    $meta = [];
    foreach (webinarsAllByClass($mast, 'first-frame__content__text') as $node) {
        $meta[] = webinarsText($node);
    }

    $timing = '';
    foreach ($meta as $line) {
        if (preg_match(WEBINARS_DATE_PATTERN, $line) === 1 && preg_match(WEBINARS_RANGE_PATTERN, $line) === 1) {
            $timing = $line;
            break;
        }
    }
    if ($timing === '' || preg_match(WEBINARS_DATE_PATTERN, $timing, $dm) !== 1
        || preg_match(WEBINARS_RANGE_PATTERN, $timing, $hr) !== 1) {
        throw new WebinarsSourceError('Не удалось подтвердить полную дату и время трансляции по Москве.');
    }
    $month = array_search(mb_strtolower($dm[2], 'UTF-8'), WEBINARS_MONTHS, true);
    if ($month === false) {
        throw new WebinarsSourceError('Не удалось определить месяц трансляции.');
    }
    $day = sprintf('%04d-%02d-%02d', (int) $dm[3], $month + 1, (int) $dm[1]);
    $start = sprintf('%02d:%02d', (int) $hr[1], (int) $hr[2]);
    $end = sprintf('%02d:%02d', (int) $hr[3], (int) $hr[4]);
    if ($end <= $start) {
        throw new WebinarsSourceError('Время окончания трансляции не позже времени начала.');
    }
    if (mb_strpos(mb_strtolower($timing, 'UTF-8'), 'московск') === false) {
        throw new WebinarsSourceError('Не удалось подтвердить, что время указано по Москве.');
    }
    if (!empty($item['day']) && $item['day'] !== $day) {
        throw new WebinarsSourceError('Дата в списке (' . $item['day'] . ') не совпадает с карточкой (' . $day . ').');
    }
    if (!empty($item['date_text'])) {
        $expected = '/^0?' . (int) $dm[1] . '\s+' . preg_quote(WEBINARS_MONTHS[$month], '/') . '\b/iu';
        if (preg_match($expected, (string) $item['date_text']) !== 1) {
            throw new WebinarsSourceError('Дата в списке («' . $item['date_text'] . '») не совпадает с карточкой (' . $day . ').');
        }
    }

    $kindLine = '';
    foreach ($meta as $line) {
        if ($line !== $timing && mb_stripos($line, 'вебинар') !== false) {
            $kindLine = $line;
            break;
        }
    }
    $kind = $kindLine;
    $who = '';
    if (($pos = mb_strpos($kindLine, ' с участием ')) !== false) {
        $kind = mb_substr($kindLine, 0, $pos);
        $who = ltrim(mb_substr($kindLine, $pos + mb_strlen(' с участием ')));
    }

    $columns = $xp->query('//*[@id="conception"]//*[' . webinarsClassSelector('layout') . ']//*[' . webinarsClassSelector('clearfix') . ']/*[' . webinarsClassSelector('content__half-col') . ']');
    if ($columns === false || $columns->length < 2) {
        throw new WebinarsSourceError('Не найдены аннотация и описание аудитории.');
    }
    $abstract = webinarsText($columns->item(0), 'h5');
    $about = webinarsText($columns->item(1), 'h5');
    $audienceText = (string) preg_split('/\s*В работе вебинара\b/u', $about, 2)[0];
    $audience = $audienceText;
    if (preg_match('/\b(руководителей|специалистов|представителей|работников)\b.*/iu', $audienceText, $aud) === 1) {
        $audience = 'Для ' . rtrim($aud[0], '.') . '.';
    }

    $speakers = [];
    foreach ($xp->query('//*[' . webinarsClassSelector('experts') . ']//*[' . webinarsClassSelector('expert__item') . ']') as $node) {
        $nameNode = webinarsFirstByClass($node, 'expert__item__name');
        $role = webinarsText($node, 'expert__item__name');
        if ($nameNode !== null && webinarsText($nameNode) !== '' && $role !== '') {
            $speakers[] = [webinarsText($nameNode), rtrim($role, " .;") . '.'];
        } elseif (webinarsText($node) !== '') {
            throw new WebinarsSourceError('В карточке эксперта нет имени или описания должности.');
        }
    }

    $topics = [];
    $closing = [];
    foreach ($xp->query('//*[' . webinarsClassSelector('programm') . ']//*[' . webinarsClassSelector('programm__item') . ']') as $node) {
        $label = webinarsFirstByClass($node, 'programm__item__title');
        if ($label === null) {
            continue;
        }
        $name = webinarsText($label);
        $details = webinarsText($node, 'programm__item__title');
        if (preg_match('/^(ответы на вопросы|практические рекомендации)\b/iu', $name) === 1 && $details === '') {
            $closing[] = mb_strtolower(trim($name, " .;:"), 'UTF-8');
        } elseif ($name !== '') {
            $topics[] = ['title' => $name, 'details' => $details];
        }
    }
    if ($fullTitle === '' || $kind === '' || $abstract === '' || $audience === '' || $speakers === [] || $topics === []) {
        throw new WebinarsSourceError('Не найден один из обязательных блоков: название, описание, аудитория, эксперты или программа.');
    }

    $warnings = [];
    $fee = '';
    $extra = '';
    foreach ($xp->query('//*[' . webinarsClassSelector('conditions') . ']//*[' . webinarsClassSelector('conditions__text__item') . ']') as $node) {
        $headingNode = webinarsFirstByClass($node, 'conditions__text__title');
        if ($headingNode === null) {
            continue;
        }
        $heading = mb_strtolower(webinarsText($headingNode), 'UTF-8');
        $details = webinarsText($node, 'conditions__text__title');
        if (mb_strpos($heading, 'стоимост') !== false) {
            $inclusion = 'Участие в вебинаре включает в себя:';
            $pos = mb_strpos($details, $inclusion);
            if ($pos === false) {
                $fee = trim($details);
                $extra = '';
            } else {
                $fee = trim(mb_substr($details, 0, $pos));
                $extra = 'В стоимость входит: ' . trim(mb_substr($details, $pos + mb_strlen($inclusion)));
            }
        }
    }
    if ($fee === '') {
        $warnings[] = 'На странице не найдена стоимость; в документе она не указана.';
    }
    webinarsValidateFormat($doc, $start, $end);

    return [
        'url' => (string) $item['url'],
        'menu_title' => (string) ($item['title'] ?? ''),
        'title' => $fullTitle,
        'kind' => $kind,
        'with_whom' => $who,
        'day' => $day,
        'start' => $start,
        'end' => $end,
        'abstract' => $abstract,
        'audience' => $audience,
        'speakers' => $speakers,
        'topics' => $topics,
        'closing' => implode(' и ', array_values(array_unique($closing))),
        'fee' => $fee,
        'access_and_discounts' => $extra,
        'warnings' => $warnings,
    ];
}

/** Время в разделе «Формат» должно совпадать с шапкой карточки. */
function webinarsValidateFormat(DOMDocument $doc, string $start, string $end): void
{
    $xp = new DOMXPath($doc);
    $blocks = [];
    foreach ($xp->query('//*[@id="tab3"]/following-sibling::*[' . webinarsClassSelector('place') . ']//*[' . webinarsClassSelector('place__title') . ']') as $node) {
        $blocks[] = webinarsText($node->parentNode);
    }
    $joined = implode(' ', $blocks);
    if (preg_match_all(WEBINARS_RANGE_PATTERN, $joined, $matches, PREG_SET_ORDER) > 0) {
        $first = $matches[0];
        $rangeStart = sprintf('%02d:%02d', (int) $first[1], (int) $first[2]);
        $rangeEnd = sprintf('%02d:%02d', (int) $first[3], (int) $first[4]);
        if ($rangeStart !== $start || $rangeEnd !== $end) {
            throw new WebinarsSourceError('Время в шапке отличается от времени в разделе «Формат».');
        }
    }
}

// ---------------------------------------------------------------------------
// Полный обход «по цепочке»
// ---------------------------------------------------------------------------

/**
 * Прочитать цепочку от первой карточки на главной (или от заданного адреса) до
 * подтверждённого конца. Каждый шаг — отдельный HTTP-запрос, чтобы веб-версия
 * могла показывать прогресс и не упираться в таймаут PHP.
 *
 * @param array{mode: string, start?: ?string} $options
 * @param callable(int):void|null $onPage
 * @return array{entries: list<array<string,mixed>>, home: ?list<string>, pages: int}
 */
function webinarsFetchChain(array $options, ?callable $onPage = null): array
{
    $mode = (string) ($options['mode'] ?? 'chain');
    $start = $options['start'] ?? null;
    $maxPages = 180;

    $homeItems = null;
    if ($mode === 'single') {
        if (!is_string($start) || trim($start) === '') {
            throw new WebinarsSourceError('Для одного вебинара нужен адрес карточки.');
        }
        $url = webinarsStartAddress($start);
        $response = webinarsFetch($url);
        $pages = [[['url' => $url, 'title' => '', 'day' => null, 'date_text' => ''], $response['body']]];
        if ($onPage !== null) {
            $onPage(1);
        }
    } elseif (is_string($start) && trim($start) !== '') {
        $pages = webinarsFollowing(webinarsStartAddress($start), null, $maxPages, $onPage);
    } else {
        $home = webinarsFetch(WEBINARS_HOME);
        $homeItems = webinarsListing(webinarsLoadHtml($home['body']), $home['url']);
        $pages = webinarsFollowing(null, $homeItems, $maxPages, $onPage);
    }

    $entries = [];
    foreach ($pages as [$item, $html]) {
        try {
            $entries[] = webinarsParseEvent($html, $item);
        } catch (WebinarsSourceError $error) {
            throw new WebinarsSourceError($item['url'] . ': ' . $error->getMessage());
        }
    }
    if ($entries === []) {
        throw new WebinarsSourceError('Карточки не найдены.');
    }
    return [
        'entries' => $entries,
        'home' => $homeItems === null ? null : array_map(static fn(array $item): string => $item['url'], $homeItems),
        'pages' => count($entries),
    ];
}

/**
 * @param list<array{url: string, title: string, day: ?string, date_text: string}>|null $homeItems
 * @return list<array{0: array<string,mixed>, 1: string}>
 */
function webinarsFollowing(?string $start, ?array $homeItems, int $maxPages, ?callable $onPage): array
{
    $homepage = [];
    foreach ($homeItems ?? [] as $item) {
        $homepage[$item['url']] = $item;
    }
    if ($start === null) {
        if ($homeItems === null || $homeItems === []) {
            throw new WebinarsSourceError('На главной нет опубликованных вебинаров.');
        }
        $current = $homeItems[0];
    } else {
        $current = ['url' => $start, 'title' => '', 'day' => null, 'date_text' => ''];
    }

    $result = [];
    $visited = [];
    $catalog = null;
    while (true) {
        if (isset($visited[$current['url']])) {
            throw new WebinarsSourceError('Цикл в кнопке «Следующий вебинар»: повтор ' . $current['url'] . '.');
        }
        if (count($visited) >= $maxPages) {
            throw new WebinarsSourceError('Достигнут предел ' . $maxPages . ' карточек; конец цепочки не подтверждён.');
        }
        $visited[$current['url']] = true;
        $address = $current['url'];
        $response = webinarsFetch($address);
        $page = webinarsLoadHtml($response['body']);
        $xp = new DOMXPath($page);
        $button = $xp->query('//a[' . webinarsClassSelector('js-next') . ']')->item(0);
        if (!$button instanceof DOMElement) {
            foreach ($xp->query('//*[' . webinarsClassSelector('first-frame__bottom') . ']//a[@href]') as $anchor) {
                if (mb_strtolower(webinarsText($anchor), 'UTF-8') === 'следующий вебинар') {
                    $button = $anchor;
                    break;
                }
            }
        }
        if (!$button instanceof DOMElement) {
            throw new WebinarsSourceError('Кнопка «Следующий вебинар» не найдена; конец цепочки не подтверждён.');
        }
        $href = (string) $button->getAttribute('href');
        if (webinarsInertHref($href)) {
            if ($catalog === null) {
                $catalog = webinarsNavigation($page, $response['url']);
            }
            $metaDate = $xp->query('//meta[@name="date"]/@content')->item(0);
            if ($metaDate === null) {
                throw new WebinarsSourceError('У карточки нет даты для сопоставления с навигацией.');
            }
            $day = trim((string) $metaDate->nodeValue);
            // JS перебирает весь список: при совпадении даты берёт последнее совпадение.
            $indices = [];
            foreach ($catalog as $index => $candidate) {
                if ($candidate['day'] === $day) {
                    $indices[] = $index;
                }
            }
            if ($indices === []) {
                throw new WebinarsSourceError('Даты карточки нет в навигации; конец цепочки не подтверждён.');
            }
            $index = end($indices);
            $recorded = $catalog[$index];
            if (!in_array($recorded['url'], [$current['url'], webinarsCanonicalEvent($response['url'])], true)) {
                throw new WebinarsSourceError('Дата найдена у другой карточки в навигации; проверьте дубли в расписании.');
            }
            if (!empty($current['day']) && $current['day'] !== $recorded['day']) {
                throw new WebinarsSourceError('Даты витрины и навигации отличаются: ' . $current['day'] . ' / ' . $recorded['day'] . '.');
            }
            $result[] = [$recorded, $response['body']];
            if ($onPage !== null) {
                $onPage(count($result));
            }
            if ($index === array_key_last($catalog)) {
                break;
            }
            $current = $catalog[$index + 1];
        } else {
            $result[] = [$current, $response['body']];
            if ($onPage !== null) {
                $onPage(count($result));
            }
            $next = webinarsCanonicalEvent(webinarsSameSite($href, $response['url']));
            $current = $homepage[$next] ?? ['url' => $next, 'title' => '', 'day' => null, 'date_text' => ''];
        }
    }

    $missing = array_diff(array_keys($homepage), array_map(static fn(array $pair): string => $pair[0]['url'], $result));
    if ($missing !== []) {
        sort($missing);
        throw new WebinarsSourceError('Цепочка не охватила карточки с главной: ' . implode(', ', $missing));
    }
    return $result;
}
