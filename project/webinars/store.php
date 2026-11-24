<?php
declare(strict_types=1);

/**
 * Генератор программ вебинаров ЦМК — приватное хранение состояния.
 *
 * Всё, что создаётся в процессе работы, лежит в DOCGEN_STORAGE_DIR/webinars:
 * снимок расписания, история сформированных программ, редактура, настройки
 * Telegram и свежие выгрузки. Ничего из этого не попадает в web-root.
 */

define('WEBINARS_STORAGE_DIR', DOCGEN_STORAGE_DIR . '/webinars');
define('WEBINARS_STATE_FILE', WEBINARS_STORAGE_DIR . '/state.json');
define('WEBINARS_EDITORIAL_FILE', WEBINARS_STORAGE_DIR . '/editorial.json');
define('WEBINARS_TELEGRAM_FILE', WEBINARS_STORAGE_DIR . '/telegram.json');
define('WEBINARS_LAYOUT_FILE', WEBINARS_STORAGE_DIR . '/layout.docx');
define('WEBINARS_BUNDLES_DIR', WEBINARS_STORAGE_DIR . '/bundles');
define('WEBINARS_SAVED_DIR', WEBINARS_STORAGE_DIR . '/saved');
define('WEBINARS_PROGRESS_DIR', WEBINARS_STORAGE_DIR . '/progress');
define('WEBINARS_MONITOR_LOCK', WEBINARS_STORAGE_DIR . '/monitor.lock');
define('WEBINARS_PACKAGE_LAYOUT', dirname(__DIR__) . '/webinars/assets/layout-cmk.docx');
define('WEBINARS_PACKAGE_EDITORIAL', dirname(__DIR__) . '/webinars/data/editorial.default.json');
define('WEBINARS_PACKAGE_SNAPSHOT', dirname(__DIR__) . '/webinars/data/snapshot.json');
define('WEBINARS_LIBRARY_DIR', dirname(__DIR__) . '/webinars/data/library');
define('WEBINARS_BUNDLE_TTL_SECONDS', 3600);
define('WEBINARS_CHECK_TIMES', ['07:00', '13:30', '20:00']);

/** Московское время независимо от часового пояса сервера. */
function webinarsNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
}

function webinarsStamp(?DateTimeImmutable $moment = null): string
{
    return ($moment ?? webinarsNow())->format('Y-m-d\TH:i:sP');
}

function webinarsBoot(): void
{
    docgenEnsureDir(WEBINARS_STORAGE_DIR, 0700);
}

/**
 * Состояние генератора: снимок расписания, история и служебные отметки.
 *
 * @return array<string, mixed>
 */
function webinarsState(): array
{
    $state = docgenReadJson(WEBINARS_STATE_FILE, []);
    if (!isset($state['version'])) {
        $state = webinarsSeedState();
    }
    $state['events'] = is_array($state['events'] ?? null) ? $state['events'] : [];
    $state['generated'] = is_array($state['generated'] ?? null) ? $state['generated'] : [];
    $state['notices'] = is_array($state['notices'] ?? null) ? $state['notices'] : [];
    $state['checks'] = is_array($state['checks'] ?? null) ? $state['checks'] : [];
    return $state;
}

/**
 * Первый запуск: известные карточки из снимка 18.11.2026 считаются известными,
 * но ни одна не помечается «сформировано» и не ставится в очередь уведомлений.
 *
 * @return array<string, mixed>
 */
function webinarsSeedState(): array
{
    $events = [];
    $snapshot = docgenReadJson(WEBINARS_PACKAGE_SNAPSHOT, []);
    foreach ((array) ($snapshot['webinars'] ?? []) as $entry) {
        if (!is_array($entry) || empty($entry['url']) || empty($entry['day'])) {
            continue;
        }
        $event = webinarsNormalizeStoredEvent($entry);
        if ($event === null) {
            continue;
        }
        $event['is_new'] = false;
        $events[webinarsEventKey($event)] = $event;
    }
    ksort($events);
    $state = [
        'version' => 1,
        'checked_at' => (string) ($snapshot['checked_at'] ?? ''),
        'origin' => 'snapshot',
        'home' => [],
        'events' => $events,
        'generated' => [],
        'notices' => [],
        'checks' => [],
        'monitor' => ['enabled' => false],
    ];
    if (is_array($snapshot['homepage'] ?? null)) {
        $state['home'] = array_values(array_filter(array_map('strval', $snapshot['homepage'])));
    }
    webinarsWriteState($state);
    return $state;
}

/** @param array<string, mixed> $state */
function webinarsWriteState(array $state): void
{
    webinarsBoot();
    docgenWriteJson(WEBINARS_STATE_FILE, $state);
}

/**
 * Привести карточку к единому виду независимо от источника: свежий разбор,
 * снимок Python-версии или сохранённая копия.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|null
 */
function webinarsNormalizeStoredEvent(array $entry): ?array
{
    $url = (string) ($entry['url'] ?? '');
    $day = (string) ($entry['day'] ?? '');
    if ($url === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        return null;
    }
    $speakers = [];
    foreach ((array) ($entry['speakers'] ?? []) as $speaker) {
        $pair = webinarsSpeakerPair($speaker);
        if ($pair[0] !== '') {
            $speakers[] = $pair;
        }
    }
    $topics = [];
    foreach ((array) ($entry['topics'] ?? []) as $topic) {
        if (is_array($topic) && isset($topic['title'])) {
            $topics[] = ['title' => (string) $topic['title'], 'details' => (string) ($topic['details'] ?? '')];
        } elseif (is_string($topic)) {
            $topics[] = ['title' => $topic, 'details' => ''];
        }
    }
    $access = (string) ($entry['access_and_discounts'] ?? $entry['access'] ?? '');
    return [
        'url' => $url,
        'menu_title' => (string) ($entry['menu_title'] ?? ''),
        'title' => (string) ($entry['title'] ?? ''),
        'kind' => (string) ($entry['kind'] ?? ''),
        'with_whom' => (string) ($entry['with_whom'] ?? ''),
        'day' => $day,
        'start' => webinarsShortTime((string) ($entry['start'] ?? '')),
        'end' => webinarsShortTime((string) ($entry['end'] ?? '')),
        'abstract' => (string) ($entry['abstract'] ?? ''),
        'audience' => (string) ($entry['audience'] ?? ''),
        'speakers' => $speakers,
        'topics' => $topics,
        'closing' => (string) ($entry['closing'] ?? ''),
        'fee' => (string) ($entry['fee'] ?? ''),
        'access_and_discounts' => $access,
        'warnings' => array_values(array_map('strval', (array) ($entry['warnings'] ?? []))),
        'is_new' => (bool) ($entry['is_new'] ?? false),
    ];
}

/**
 * Карточка для списка: то же, что отдаёт панель Python-версии.
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function webinarsRow(array $event, array $state): array
{
    $key = webinarsEventKey($event);
    $generated = $state['generated'][$key] ?? null;
    return [
        'id' => $event['url'],
        'key' => $key,
        'url' => $event['url'],
        'title' => $event['menu_title'] !== '' ? $event['menu_title'] : $event['title'],
        'full_title' => $event['title'],
        'date' => $event['day'],
        'date_ru' => webinarsDateRu($event['day']),
        'day_month' => webinarsDayMonth($event['day']),
        'hours' => webinarsHours($event),
        'speaker_count' => count($event['speakers']),
        'topic_count' => count($event['topics']),
        'is_new' => (bool) ($event['is_new'] ?? false),
        'warnings' => $event['warnings'],
        'generated_at' => is_array($generated) ? (string) ($generated['generated_at'] ?? '') : '',
        'saved_name' => is_array($generated) ? (string) ($generated['filename'] ?? '') : '',
    ];
}

/**
 * Полный каталог для интерфейса: список, состояние источника и заметки мониторинга.
 *
 * @param array<string, mixed>|null $state
 * @return array<string, mixed>
 */
function webinarsCatalogPayload(?array $state = null): array
{
    $state ??= webinarsState();
    $rows = [];
    foreach ($state['events'] as $event) {
        $rows[] = webinarsRow($event, $state);
    }
    usort($rows, static fn(array $a, array $b): int => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);
    $last = $state['checks'] === [] ? null : end($state['checks']);
    return [
        'events' => $rows,
        'checked_at' => (string) ($state['checked_at'] ?? ''),
        'origin' => (string) ($state['origin'] ?? 'snapshot'),
        'home' => array_values((array) ($state['home'] ?? [])),
        'pending' => count(array_filter($state['notices'], static fn($notice): bool => is_array($notice) && empty($notice['sent_at']))),
        'last_check' => is_array($last) ? $last : null,
    ];
}

/**
 * Сохранить подтверждённый полный список: первый прогон не шлёт уведомлений,
 * дальше в очередь попадают только впервые найденные карточки.
 *
 * @param list<array<string, mixed>> $entries
 * @param list<string>|null $home
 * @return array{added: list<string>, total: int, checked_at: string}
 */
function webinarsSaveCatalog(array $entries, ?array $home, string $origin = 'live'): array
{
    $state = webinarsState();
    $known = $state['events'];
    $fresh = [];
    foreach ($entries as $entry) {
        $event = webinarsNormalizeStoredEvent($entry);
        if ($event === null) {
            continue;
        }
        $fresh[webinarsEventKey($event)] = $event;
    }
    if ($fresh === []) {
        throw new WebinarsSourceError('Расписание не содержит карточек для сохранения.');
    }
    // Порядок карточек приходит из цепочки сайта — его и сохраняем.
    $ordered = [];
    foreach ($entries as $entry) {
        $event = webinarsNormalizeStoredEvent($entry);
        if ($event !== null) {
            $ordered[webinarsEventKey($event)] = $event;
        }
    }
    $firstRun = $known === [];
    $added = [];
    foreach ($ordered as $key => $event) {
        if (!$firstRun && !isset($known[$key])) {
            $event['is_new'] = true;
            $added[] = $key;
        }
        $ordered[$key] = $event;
    }
    // Карточки, которых больше нет в подтверждённом полном списке, остаются в истории.
    foreach ($known as $key => $event) {
        if (!isset($ordered[$key])) {
            $ordered[$key] = $event;
        }
    }
    $state['events'] = $ordered;
    $state['checked_at'] = webinarsStamp();
    $state['origin'] = $origin;
    $state['home'] = $home === null ? [] : array_values($home);
    if ($added !== []) {
        foreach ($added as $key) {
            $event = $ordered[$key];
            $state['notices'][] = [
                'key' => $key,
                'title' => $event['menu_title'] !== '' ? $event['menu_title'] : $event['title'],
                'date_ru' => webinarsDateRu($event['day']),
                'hours' => webinarsHours($event),
                'url' => $event['url'],
                'added_at' => webinarsStamp(),
                'sent_at' => '',
            ];
        }
    }
    webinarsWriteState($state);
    return ['added' => $added, 'total' => count($ordered), 'checked_at' => $state['checked_at']];
}

/**
 * Записать сформированную программу в историю и в приватный архив.
 *
 * @param array<string, mixed> $event
 */
function webinarsRememberProgram(array $event, string $filename, string $sourcePath): string
{
    webinarsBoot();
    $stamp = webinarsStamp();
    $key = webinarsEventKey($event);
    $id = substr(hash('sha256', $key), 0, 32);
    $target = WEBINARS_SAVED_DIR . '/' . $id . '.docx';
    docgenEnsureDir(WEBINARS_SAVED_DIR, 0700);
    if (!@copy($sourcePath, $target)) {
        throw new RuntimeException('Не удалось сохранить Word в истории.');
    }
    @chmod($target, 0600);
    $state = webinarsState();
    $state['generated'][$key] = [
        'id' => $id,
        'filename' => $filename,
        'generated_at' => $stamp,
        'size' => (int) @filesize($target),
    ];
    if (isset($state['events'][$key])) {
        $state['events'][$key]['is_new'] = false;
    }
    webinarsWriteState($state);
    return $stamp;
}

/** @return array{path: string, filename: string}|null */
function webinarsSavedProgram(array $event): ?array
{
    $state = webinarsState();
    $key = webinarsEventKey($event);
    $generated = $state['generated'][$key] ?? null;
    if (!is_array($generated)) {
        return null;
    }
    $path = WEBINARS_SAVED_DIR . '/' . (string) ($generated['id'] ?? '') . '.docx';
    if (!is_file($path)) {
        return null;
    }
    return ['path' => $path, 'filename' => (string) ($generated['filename'] ?? 'Программа.docx')];
}

/** Имя файла с датой и названием: так оно попадает и в папку ZIP. */
function webinarsFileName(array $event, array $edit = []): string
{
    $title = (string) ($edit['file_title'] ?? '');
    if ($title === '') {
        $title = (string) ($event['menu_title'] !== '' ? $event['menu_title'] : webinarsPlainTitle((string) $event['title']));
    }
    $stem = safeFilename(webinarsDayMonthPlain((string) $event['day']) . ' - ' . $title);
    $stem = rtrim(mb_substr($stem, 0, 95, 'UTF-8'), ' .');
    return $stem . '.docx';
}

/**
 * Собрать ZIP: отдельная папка на каждый вебинар с одноимённым DOCX,
 * как в исходной версии.
 *
 * @param list<array{name: string, content: string}> $documents
 */
function webinarsZip(array $documents, string $zipName, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать ZIP-архив.');
    }
    try {
        foreach ($documents as $document) {
            $folder = (string) preg_replace('/\.docx$/u', '', $document['name']);
            $zip->addEmptyDir($folder);
            if (!$zip->addFromString($folder . '/' . $document['name'], $document['content'])) {
                throw new RuntimeException('Не удалось добавить документ в ZIP-архив.');
            }
        }
    } finally {
        $zip->close();
    }
}

/** Имя общего архива для нескольких карточек: «Программы ЦМК — 20.11.2026–24.12.2026.zip». */
function webinarsBundleName(array $events): string
{
    $days = array_values(array_unique(array_map(static fn(array $event): string => (string) $event['day'], $events)));
    sort($days);
    if (count($events) === 1) {
        return webinarsFileName($events[0]) === '' ? 'Программа ЦМК.zip' : preg_replace('/\.docx$/u', '.zip', webinarsFileName($events[0]));
    }
    $first = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $days[0]);
    $last = DateTimeImmutable::createFromFormat('!Y-m-d', (string) end($days));
    $span = $first === false ? '' : $first->format('d.m.Y');
    if ($first !== false && $last !== false && $last != $first) {
        $span .= '–' . $last->format('d.m.Y');
    }
    return 'Программы ЦМК — ' . $span . '.zip';
}

/**
 * Свежая выгрузка живёт час, как в Python-версии; сохранённый Word остаётся.
 *
 * @return array<string, array{made: int, zip: string, zip_name: string, docs: list<array{event: string, name: string}>}>
 */
function webinarsBundles(): array
{
    $index = docgenReadJson(WEBINARS_BUNDLES_DIR . '/index.json', []);
    $now = time();
    $clean = [];
    foreach ($index as $id => $bundle) {
        $dir = webinarsBundleDir((string) $id);
        if (!is_array($bundle) || ($now - (int) ($bundle['made'] ?? 0)) > WEBINARS_BUNDLE_TTL_SECONDS) {
            foreach ((array) ($bundle['docs'] ?? []) as $doc) {
                @unlink($dir . '/' . basename((string) ($doc['file'] ?? '')));
            }
            @unlink($dir . '/' . basename((string) ($bundle['zip'] ?? 'x')));
            @rmdir($dir);
            continue;
        }
        $clean[(string) $id] = $bundle;
    }
    return $clean;
}

/** Папка одной выгрузки: Word-файлы и общий ZIP складываются внутрь неё. */
function webinarsBundleDir(string $bundleId): string
{
    return WEBINARS_BUNDLES_DIR . '/' . basename($bundleId);
}

/**
 * @param array<string, array{made: int, zip: string, zip_name: string, docs: list<array{event: string, name: string}>}> $bundles
 */
function webinarsWriteBundles(array $bundles): void
{
    docgenEnsureDir(WEBINARS_BUNDLES_DIR, 0700);
    docgenWriteJson(WEBINARS_BUNDLES_DIR . '/index.json', $bundles);
}

/** Редактура: файл на сервере плюс правки, сделанные в интерфейсе. */
function webinarsEditorial(): array
{
    if (is_file(WEBINARS_EDITORIAL_FILE)) {
        $data = docgenReadJson(WEBINARS_EDITORIAL_FILE, []);
        return is_array($data) ? $data : [];
    }
    $seed = docgenReadJson(WEBINARS_PACKAGE_EDITORIAL, []);
    if ($seed !== []) {
        webinarsBoot();
        docgenWriteJson(WEBINARS_EDITORIAL_FILE, $seed);
    }
    return $seed;
}

/** @param array<string, mixed> $entry */
function webinarsSaveEditorial(string $key, array $entry): void
{
    if (preg_match('#^\d{4}-\d{2}-\d{2}/[A-Za-z0-9_-]+$#', $key) !== 1) {
        throw new InvalidArgumentException('Некорректный ключ редактуры.');
    }
    $all = webinarsEditorial();
    $clean = [];
    foreach (['file_title', 'benefit', 'audience', 'with_whom', 'intro_suffix'] as $field) {
        $value = trim((string) ($entry[$field] ?? ''));
        if ($value !== '') {
            $clean[$field] = mb_substr($value, 0, 2000, 'UTF-8');
        }
    }
    if (isset($entry['rename_topics']) && is_array($entry['rename_topics'])) {
        $topics = [];
        foreach ($entry['rename_topics'] as $number => $text) {
            $text = trim((string) $text);
            if ($text !== '' && preg_match('/^\d{1,2}$/', (string) $number) === 1) {
                $topics[(string) $number] = mb_substr($text, 0, 400, 'UTF-8');
            }
        }
        if ($topics !== []) {
            $clean['rename_topics'] = $topics;
        }
    }
    if ($clean === []) {
        unset($all[$key]);
    } else {
        $all[$key] = $clean;
    }
    docgenWriteJson(WEBINARS_EDITORIAL_FILE, $all);
}

/**
 * Состояние Telegram: токен и чат в приватном файле, права 0600.
 *
 * @return array{token: string, chat_id: string}|null
 */
function webinarsTelegramConfig(): ?array
{
    $envToken = trim((string) getenv('DOCGEN_WEBINARS_TELEGRAM_TOKEN'));
    $envChat = trim((string) getenv('DOCGEN_WEBINARS_TELEGRAM_CHAT_ID'));
    if ($envToken !== '' && $envChat !== '') {
        return ['token' => $envToken, 'chat_id' => $envChat, 'env' => true];
    }
    if (!is_file(WEBINARS_TELEGRAM_FILE)) {
        return null;
    }
    $data = docgenReadJson(WEBINARS_TELEGRAM_FILE, []);
    $token = trim((string) ($data['token'] ?? ''));
    $chat = trim((string) ($data['chat_id'] ?? ''));
    if ($token === '' || $chat === '') {
        return null;
    }
    return ['token' => $token, 'chat_id' => $chat, 'env' => false];
}

/** @return array<string, mixed> */
function webinarsTelegramSave(string $token, string $chat): array
{
    webinarsBoot();
    $config = ['token' => $token, 'chat_id' => $chat, 'saved_at' => webinarsStamp()];
    docgenWriteJson(WEBINARS_TELEGRAM_FILE, $config);
    @chmod(WEBINARS_TELEGRAM_FILE, 0600);
    return $config;
}

function webinarsTelegramForget(): void
{
    @unlink(WEBINARS_TELEGRAM_FILE);
}

/** Записи в очереди уведомлений, которые ещё не отправлены. */
function webinarsPendingNotices(array $state): array
{
    $pending = [];
    foreach ($state['notices'] as $notice) {
        if (is_array($notice) && empty($notice['sent_at'])) {
            $pending[] = $notice;
        }
    }
    return $pending;
}

/** Отметить отправленные уведомления; повторной отправки не будет. */
function webinarsMarkNoticesSent(array $keys): void
{
    if ($keys === []) {
        return;
    }
    $state = webinarsState();
    $stamp = webinarsStamp();
    $wanted = array_fill_keys($keys, true);
    foreach ($state['notices'] as $index => $notice) {
        if (is_array($notice) && isset($notice['key'], $wanted[$notice['key']])) {
            $state['notices'][$index]['sent_at'] = $stamp;
        }
    }
    webinarsWriteState($state);
}

/** Включено ли расписание автопроверок. */
function webinarsMonitorEnabled(): bool
{
    $state = webinarsState();
    return !empty($state['monitor']['enabled']);
}

function webinarsMonitorToggle(bool $enabled): void
{
    $state = webinarsState();
    $state['monitor'] = ['enabled' => $enabled, 'changed_at' => webinarsStamp()];
    webinarsWriteState($state);
}

/**
 * Записать результат проверки. Список хранится целиком, поэтому обрезаем
 * журнал: важны последние проверки и последняя ошибка.
 *
 * @param array<string, mixed> $result
 */
function webinarsRecordCheck(array $result): void
{
    $state = webinarsState();
    $result['at'] = $result['at'] ?? webinarsStamp();
    $state['checks'][] = $result;
    if (count($state['checks']) > 40) {
        $state['checks'] = array_slice($state['checks'], -40);
    }
    $state['last_check'] = $result;
    webinarsWriteState($state);
}

/** Ближайший слот автопроверки: 07:00, 13:30, 20:00 МСК. */
function webinarsNextSlot(?DateTimeImmutable $now = null): string
{
    $now = $now ?? webinarsNow();
    foreach (WEBINARS_CHECK_TIMES as $slot) {
        $candidate = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $slot . ':00', $now->getTimezone());
        if ($candidate > $now) {
            return $candidate->format('Y-m-d\TH:i:sP');
        }
    }
    $tomorrow = $now->modify('+1 day');
    return new DateTimeImmutable($tomorrow->format('Y-m-d') . ' ' . WEBINARS_CHECK_TIMES[0] . ':00', $now->getTimezone())->format('Y-m-d\TH:i:sP');
}

/** Слот, который наступил в эту минуту (без догона пропусков задним числом). */
function webinarsDueSlot(?DateTimeImmutable $now = null): ?string
{
    $now = $now ?? webinarsNow();
    $slot = $now->format('H:i');
    return in_array($slot, WEBINARS_CHECK_TIMES, true) ? $now->format('Y-m-d') . 'T' . $slot : null;
}

/** Файл прогресса долгой операции: интерфейс опрашивает его, пока идёт обход. */
function webinarsProgressPath(string $token): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        throw new InvalidArgumentException('Некорректный идентификатор операции.');
    }
    return WEBINARS_PROGRESS_DIR . '/' . $token . '.json';
}

function webinarsProgressWrite(string $token, array $payload): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        return;
    }
    webinarsBoot();
    docgenEnsureDir(WEBINARS_PROGRESS_DIR, 0700);
    docgenWriteJson(webinarsProgressPath($token), $payload + ['at' => webinarsStamp()]);
}

function webinarsProgressRead(string $token): array
{
    try {
        return docgenReadJson(webinarsProgressPath($token), []);
    } catch (Throwable) {
        return [];
    }
}

function webinarsCleanupProgress(): void
{
    if (!is_dir(WEBINARS_PROGRESS_DIR)) {
        return;
    }
    $limit = time() - 3600;
    foreach (glob(WEBINARS_PROGRESS_DIR . '/*.json') ?: [] as $path) {
        if ((int) @filemtime($path) < $limit) {
            @unlink($path);
        }
    }
}
