<?php

/**
 * Проверки генератора программ вебинаров ЦМК.
 *
 * Запуск: php tests/webinar_test.php (PHP 8.1+, без веб-сервера).
 * Сеть не нужна: обход, разбор и вёрстка проверяются на сохранённых страницах
 * сайта «Кодекса» из архива переноса. Хранилище подменяется временной папкой,
 * реальные очередь писем и storage не затрагиваются.
 */

$storage = sys_get_temp_dir() . '/docgen-webinar-test-' . bin2hex(random_bytes(4));
putenv('DOCGEN_STORAGE_DIR=' . $storage);
putenv('DOCGEN_WEBINARS_FIXTURES_DIR=' . dirname(__DIR__) . '/webinars/data/fixtures');
register_shutdown_function(static function () use ($storage): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    };
    $remove($storage);
});

chdir(dirname(__DIR__));
require __DIR__ . '/../features.php';
require __DIR__ . '/../webinars/source.php';
require __DIR__ . '/../webinars/http.php';
require __DIR__ . '/../webinars/docx.php';
require __DIR__ . '/../webinars/store.php';
require __DIR__ . '/../webinars/telegram.php';
require __DIR__ . '/../webinars/monitor.php';

$failures = 0;
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $label\n";
    }
}

$golden = json_decode((string) gzdecode((string) file_get_contents(__DIR__ . '/../webinars/data/fixtures/golden.json.gz')), true);
check(is_array($golden) && count($golden['render'] ?? []) === 16, 'эталон 16 документов загружен');

// --- Обход сайта и разбор ---------------------------------------------------

$chain = webinarsFetchChain(['mode' => 'chain']);
check(count($chain['entries']) === 16, 'цепочка прочитала 16 карточек');
check(count($chain['home'] ?? []) === 9, 'главная дала 9 карточек витрины');
check(webinarsSlug($chain['entries'][0]['url']) === 'tpk', 'первая карточка цепочки — /tpk');

$bySlug = [];
foreach ($chain['entries'] as $entry) {
    $bySlug[webinarsSlug($entry['url'])] = $entry;
}
$identical = 0;
foreach ($golden['cards'] as $slug => $card) {
    $parsed = $bySlug[$slug] ?? null;
    if ($parsed === null) {
        continue;
    }
    $same = $parsed['title'] === $card['title']
        && $parsed['kind'] === $card['kind']
        && $parsed['with_whom'] === $card['with_whom']
        && $parsed['day'] === $card['day']
        // Время в разборе — «ЧЧ:ММ»; снимок Python-версии хранит «ЧЧ:ММ:СС».
        && $parsed['start'] === substr((string) $card['start'], 0, 5)
        && $parsed['end'] === substr((string) $card['end'], 0, 5)
        && $parsed['abstract'] === $card['abstract']
        && $parsed['audience'] === $card['audience']
        && $parsed['closing'] === $card['closing']
        && $parsed['fee'] === $card['fee']
        && $parsed['access_and_discounts'] === $card['access_and_discounts']
        && array_map('strval', $parsed['warnings']) === array_map('strval', $card['warnings']);
    foreach ($card['speakers'] as $index => $speaker) {
        $same = $same && ($parsed['speakers'][$index][0] ?? '') === $speaker[0]
            && ($parsed['speakers'][$index][1] ?? '') === $speaker[1];
    }
    $same = $same && count($parsed['topics']) === count($card['topics']);
    foreach ($card['topics'] as $index => $topic) {
        $same = $same && ($parsed['topics'][$index]['title'] ?? '') === $topic['title']
            && ($parsed['topics'][$index]['details'] ?? '') === $topic['details'];
    }
    if ($same) {
        $identical++;
    } else {
        echo "  разбор $slug отличается от эталона\n";
    }
}
check($identical === 16, 'разбор всех 16 карточек один в один с Python-версией');

// Строки расписания: getWebs.php и getWebs2.php в порядке jQuery.each.
$list = webinarsScheduleRows(json_decode((string) file_get_contents(__DIR__ . '/../webinars/data/fixtures/list.json'), true));
check(count($list) === 9, 'getWebs.php: 9 записей');
check($list[0]['url'] === 'http://www.web-kodeks.ru/tpk' && $list[0]['day'] === '2026-11-20', 'первая запись списка — /tpk 20.11.2026');
$nav = webinarsScheduleRows(json_decode((string) file_get_contents(__DIR__ . '/../webinars/data/fixtures/nav.json'), true));
check(count($nav) === 56, 'getWebs2.php?all=true: 56 записей');
check(end($nav)['day'] === '2026-12-24', 'последняя запись навигации — 24.12.2026');

// Ошибки исходных данных не превращаются в документ: HTML без карточки отклоняется.
$rejectEmpty = false;
try {
    webinarsParseEvent('<html><body><p>нет данных</p></body></html>', ['url' => 'http://www.web-kodeks.ru/tpk']);
} catch (WebinarsSourceError) {
    $rejectEmpty = true;
}
check($rejectEmpty, 'HTML без карточки отклоняется с понятной ошибкой');
// Дата из списка, не совпадающая с карточкой, — тоже отказ, а не «примерно верно».
$mismatch = false;
$realHtml = (string) gzdecode((string) file_get_contents(__DIR__ . '/../webinars/data/fixtures/card-tpk.html.gz'));
try {
    webinarsParseEvent($realHtml, ['url' => 'http://www.web-kodeks.ru/tpk', 'day' => '2026-11-19']);
} catch (WebinarsSourceError) {
    $mismatch = true;
}
check($mismatch, 'несовпадение даты витрины и карточки отклоняется');

// --- Вёрстка DOCX -----------------------------------------------------------

$editorial = json_decode((string) file_get_contents(__DIR__ . '/../webinars/data/editorial.default.json'), true);
$layout = dirname(__DIR__) . '/webinars/assets/layout-cmk.docx';
$layoutHash = hash_file('sha256', $layout);
$outDir = $storage . '/render';
docgenEnsureDir($outDir);

$matches = 0;
foreach ($golden['cards'] as $slug => $card) {
    $path = $outDir . '/' . $slug . '.docx';
    try {
        webinarsRenderDocx($card, $layout, (array) ($editorial[$card['day'] . '/' . $slug] ?? []), $path);
    } catch (Throwable $error) {
        echo "  $slug: " . $error->getMessage() . "\n";
        continue;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        continue;
    }
    $zip->close();
    $paragraphs = webinarTestDocxParagraphs($path);
    if ($paragraphs === $golden['render'][$slug]['paragraphs']) {
        $matches++;
    } else {
        $index = webinarTestFirstDiff($paragraphs, $golden['render'][$slug]['paragraphs']);
        echo "  $slug: расхождение в абзаце $index\n    стало: " . mb_substr($paragraphs[$index] ?? '(нет)', 0, 70)
            . "\n    эталон: " . mb_substr($golden['render'][$slug]['paragraphs'][$index] ?? '(нет)', 0, 70) . "\n";
    }
}
check($matches === 16, 'текст 16 сформированных документов совпал с эталоном');
check(hash_file('sha256', $layout) === $layoutHash, 'утверждённый бланк не изменён формированием');

// Один DOCX и ZIP: папка на каждый вебинар с одноимённым файлом.
$one = $chain['entries'][16 - 1];
$singlePath = $outDir . '/single.docx';
webinarsRenderDocx($one, $layout, [], $singlePath);
check(filesize($singlePath) > 20000, 'документ одной карточки сформирован');
$name = webinarsFileName($one);
check(str_ends_with($name, '.docx') && mb_strpos($name, ' декабря - ') !== false, 'имя файла: «18 декабря - …»');
$zipPath = $outDir . '/test.zip';
webinarsZip([['name' => $name, 'content' => (string) file_get_contents($singlePath)]], 'test.zip', $zipPath);
$zip = new ZipArchive();
$zip->open($zipPath);
$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entries[] = $zip->statIndex($i)['name'];
}
$zip->close();
check($entries === [preg_replace('/\.docx$/u', '', $name) . '/', preg_replace('/\.docx$/u', '', $name) . '/' . $name], 'в ZIP папка с датой и названием и тот же DOCX внутри');

// --- Хранение: снимок, история, новые карточки ------------------------------

$state = webinarsState();
check(count($state['events']) === 16, 'первый запуск: 16 известных карточек из снимка');
check($state['origin'] === 'snapshot' && $state['checked_at'] === '2026-11-18', 'первый запуск: снимок на 18.11.2026');
check(webinarsCatalogPayload()['pending'] === 0, 'снимок не ставит уведомления в очередь');
check(count(array_filter($state['events'], static fn(array $event): bool => !empty($event['is_new']))) === 0, 'у карточек снимка нет метки «Новое»');

$saved = webinarsRememberProgram($one, $name, $singlePath);
check($saved !== '', 'программа записана в историю');
$restored = webinarsSavedProgram($one);
check($restored !== null && $restored['filename'] === $name, 'сохранённый Word доступен по карточке');
$row = array_values(array_filter(webinarsCatalogPayload()['events'], static fn(array $event): bool => $event['id'] === $one['url']))[0] ?? [];
check(($row['generated_at'] ?? '') !== '', 'карточка отмечена «Сформировано» в списке');

// Новая карточка: добавляется в очередь уведомлений, повторно — нет.
$extra = $chain['entries'][0];
$extra['url'] = 'http://www.web-kodeks.ru/new-card';
$extra['day'] = '2027-01-15';
$added = webinarsSaveCatalog(array_merge($chain['entries'], [$extra]), $chain['home'], 'live');
check(count($added['added']) === 1, 'новая карточка отмечена как найденная');
check(webinarsCatalogPayload()['pending'] === 1, 'новая карточка попала в очередь уведомлений');
webinarsMarkNoticesSent($added['added']);
check(webinarsCatalogPayload()['pending'] === 0, 'отправленное уведомление не повторяется');
$again = webinarsSaveCatalog(array_merge($chain['entries'], [$extra]), $chain['home'], 'live');
check($again['added'] === [], 'повторное обновление не считает карточку новой');
check(count(webinarsState()['events']) === 17, 'история хранит и новую, и прежние карточки');

// Редактура: сохраняется по ключу и применяется при формировании.
webinarsSaveEditorial('2026-11-20/tpk', [
    'file_title' => 'Подключение к сетям ЖКХ',
    'benefit' => 'разобрать новые правила и расчет платы.',
    'rename_topics' => ['1' => 'Новые правила подключения'],
]);
$edit = webinarsEditorial()['2026-11-20/tpk'] ?? [];
check(($edit['file_title'] ?? '') === 'Подключение к сетям ЖКХ' && ($edit['rename_topics']['1'] ?? '') === 'Новые правила подключения', 'редактура сохранена');
$editedPath = $outDir . '/edited.docx';
webinarsRenderDocx($bySlug['tpk'], $layout, $edit, $editedPath);
$editedParagraphs = webinarTestDocxParagraphs($editedPath);
check(in_array('УЧАСТИЕ В ВЕБИНАРЕ ПОЗВОЛИТ разобрать новые правила и расчет платы.', $editedParagraphs, true), 'редактура «участие позволит» попала в документ');
check((bool) array_filter($editedParagraphs, static fn(string $text): bool => str_starts_with($text, '1. Новые правила подключения.')), 'переименованная тема попала в документ');
check(webinarsFileName($bySlug['tpk'], $edit) === '20 ноября - Подключение к сетям ЖКХ.docx', 'короткое название задаёт имя файла');

// --- Telegram и расписание (без сети) ---------------------------------------

$notices = [];
for ($i = 0; $i < 24; $i++) {
    $notices[] = [
        'key' => 'k' . $i,
        'title' => str_repeat('Длинное название вебинара ', 40),
        'date_ru' => '24 ноября 2026 года',
        'hours' => '10:00–16:00',
        'url' => 'http://www.web-kodeks.ru/str' . $i,
    ];
}
$batches = webinarsTelegramBatches($notices);
$batchedKeys = [];
foreach ($batches as $batch) {
    $batchedKeys = array_merge($batchedKeys, $batch['keys']);
    check(mb_strlen($batch['text'], 'UTF-8') <= 3900, 'сообщение в пределах лимита Telegram');
}
check(count($batches) > 1 && $batchedKeys === array_column($notices, 'key'), 'длинная очередь разбивается на сообщения без потери карточек');
check(str_contains(end($batches)['text'], '24 ноября 2026 года') && str_contains(end($batches)['text'], 'http://www.web-kodeks.ru/str'), 'в сообщении есть дата, тема и ссылка');
$invalid = 0;
foreach (['', 'не токен', '12345:короткий'] as $bad) {
    try {
        webinarsTelegramCheckToken($bad);
    } catch (WebinarsTelegramError) {
        $invalid++;
    }
}
check($invalid === 3, 'некорректные токены бота отклоняются');
check(webinarsTelegramCheckChat('-1001234567890') === '-1001234567890', 'числовой ID чата принимается');

// --- Привязка бота: два коротких шага вместо одного 180-секундного запроса ---
// Именно долгий запрос ломал привязку: браузер обрывал его раньше, чем
// появлялся код, и оператор видел «Код не получен».
putenv('DOCGEN_WEBINARS_TELEGRAM_STUB=1');
$pairToken = '9011524943:AAGqTja64rY3SmVJQb_9XDGL78pqG1tGbb4';
$stubFile = $storage . '/webinars/telegram-stub.json';
$stubWrite = static function (array $updates) use ($stubFile): void {
    docgenEnsureDir(dirname($stubFile), 0700);
    file_put_contents($stubFile, json_encode(['sent' => 0, 'updates' => $updates]));
};
$stubRead = static function () use ($stubFile): array {
    return is_file($stubFile) ? (array) json_decode((string) file_get_contents($stubFile), true) : [];
};

$pairStarted = microtime(true);
$pair = webinarsTelegramPairStart($pairToken);
check(microtime(true) - $pairStarted < 1.0, 'привязка: код выдаётся сразу, без ожидания');
check(preg_match('/^ЦМК-[A-F0-9]{10}$/', $pair['code']) === 1, 'привязка: код вида ЦМК-XXXXXXXXXX');
check($pair['username'] === 'cmk_docgen_test_bot' && str_starts_with($pair['url'], 'https://t.me/'), 'привязка: имя и ссылка на бота');
check(webinarsTelegramPairCheck($pairToken)['state'] === 'waiting', 'привязка: до сообщения — waiting');

$stubWrite([['update_id' => 100, 'message' => ['text' => 'привет', 'chat' => ['id' => 1, 'type' => 'private']]]]);
check(webinarsTelegramPairCheck($pairToken)['state'] === 'waiting', 'привязка: постороннее сообщение не привязывает чат');

$stubWrite([['update_id' => 101, 'message' => ['text' => $pair['code'], 'chat' => ['id' => -1001234567890, 'type' => 'group']]]]);
$groupAnswer = webinarsTelegramPairCheck($pairToken);
check($groupAnswer['state'] === 'waiting' && str_contains($groupAnswer['message'], 'личн'), 'привязка: код из группы не принимается');

$stubWrite([['update_id' => 102, 'message' => ['text' => $pair['code'], 'chat' => ['id' => 777123456, 'type' => 'private']]]]);
$paired = webinarsTelegramPairCheck($pairToken);
check($paired['state'] === 'done' && ($paired['chat_id'] ?? '') === '777123456', 'привязка: код из личного чата привязывает бота');
check(($stubRead()['sent'] ?? 0) === 1, 'привязка: тестовое сообщение отправлено один раз');
$savedTelegram = webinarsTelegramConfig();
check($savedTelegram !== null && $savedTelegram['chat_id'] === '777123456', 'привязка: токен и чат сохранены');
check(webinarsTelegramPairCheck($pairToken)['state'] === 'expired', 'привязка: повторный опрос после успеха — expired');
webinarsTelegramForget();
check(webinarsTelegramConfig() === null, 'привязка: отвязка удаляет токен');
putenv('DOCGEN_WEBINARS_TELEGRAM_STUB');

check(webinarsDueSlot(new DateTimeImmutable('2026-11-24 13:30:00', new DateTimeZone('Europe/Moscow'))) === '2026-11-24T13:30', 'слот 13:30 МСК срабатывает в свою минуту');
check(webinarsDueSlot(new DateTimeImmutable('2026-11-24 13:31:00', new DateTimeZone('Europe/Moscow'))) === null, 'через минуту слот уже не активен');
// 10:30 UTC — это 13:30 МСК, но webinarsDueSlot работает с МСК-временем процесса:
// слот определяется по московским часам после приведения момента к Москве.
check(webinarsDueSlot((new DateTimeImmutable('2026-11-24 10:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Moscow'))) === '2026-11-24T13:30', '10:30 UTC = 13:30 МСК попадает в слот');
check(webinarsNextSlot(new DateTimeImmutable('2026-11-24 20:30:00', new DateTimeZone('Europe/Moscow'))) === '2026-11-25T07:00:00+03:00', 'после 20:00 следующая проверка — 07:00');
check(webinarsMonitorTick(new DateTimeImmutable('2026-11-24 13:30:00', new DateTimeZone('Europe/Moscow')))['skipped'] === 'disabled', 'выключенное расписание не проверяет сайт');

webinarsMonitorToggle(true);
$firstTick = webinarsMonitorTick(new DateTimeImmutable('2026-11-24 13:30:00', new DateTimeZone('Europe/Moscow')));
$secondTick = webinarsMonitorTick(new DateTimeImmutable('2026-11-24 13:30:30', new DateTimeZone('Europe/Moscow')));
check(($firstTick['ok'] ?? false) === true && ($firstTick['slot'] ?? '') === '2026-11-24T13:30', 'включённое расписание проверяет сайт в минуту слота');
check(($secondTick['skipped'] ?? '') === 'already_done', 'тот же слот не проверяется повторно');
$status = webinarsMonitorStatus();
check($status['enabled'] === true && count($status['times']) === 3, 'состояние мониторинга отдаёт три слота');
check(!str_contains(json_encode($status, JSON_UNESCAPED_UNICODE) ?: '', 'token'), 'состояние мониторинга не содержит токен');

// --- Безопасность -----------------------------------------------------------

$rejected = 0;
foreach (['http://example.org/x', 'http://www.web-kodeks.ru:8080/x', 'https://user:pass@www.web-kodeks.ru/x', 'ftp://www.web-kodeks.ru/x'] as $bad) {
    try {
        webinarsCanonicalEvent($bad);
    } catch (WebinarsSourceError) {
        $rejected++;
    }
}
check($rejected === 4, 'внешние и подозрительные адреса не принимаются');
check(webinarsStartAddress('/med') === 'http://www.web-kodeks.ru/med', 'короткий код карточки превращается в адрес');
check(webinarsPlainTitle('НОВЫЕ УСЛОВИЯ РАСЧЕТА В ЖКХ') === 'Новые условия расчета в ЖКХ', 'заголовок: первая буква заглавная, аббревиатура сохраняется');

// ---------------------------------------------------------------------------

echo $failures === 0 ? "OK: $checks checks passed\n" : "FAILED: $failures of $checks checks\n";
exit($failures === 0 ? 0 : 1);

function webinarTestDocxParagraphs(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    $doc = new DOMDocument();
    if ($xml === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
        return [];
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', WEBINARS_NS_W);
    $paragraphs = [];
    foreach (webinarsBodyParagraphs($xp) as $paragraph) {
        $text = '';
        foreach ($xp->query('.//w:t', $paragraph) as $node) {
            $text .= $node->textContent;
        }
        $paragraphs[] = $text;
    }
    return $paragraphs;
}

function webinarTestFirstDiff(array $got, array $want): int
{
    $limit = min(count($got), count($want));
    for ($i = 0; $i < $limit; $i++) {
        if ($got[$i] !== $want[$i]) {
            return $i;
        }
    }
    return $limit;
}
