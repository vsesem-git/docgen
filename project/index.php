<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;
use morphos\Russian\AdjectiveDeclension;
use morphos\Russian\Cases as RussianCases;
use morphos\Russian\NounDeclension;

const DATA_FILE = __DIR__ . '/data/records.json';
const DEMO_OVERRIDES_FILE = __DIR__ . '/data/demo_overrides.json';
const SAMPLE_TEMPLATE = __DIR__ . '/samples/letter_example.docx';
const DOWNLOAD_TTL_SECONDS = 86400;
const MAX_TEMPLATE_BYTES = 20_000_000;
const MAX_ROWS_PER_RUN = 300;

require __DIR__ . '/features.php';
require __DIR__ . '/table_features.php';

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function loadJsonFile(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function loadDataset(): array
{
    // A restored database lives in private storage and takes precedence over
    // the read-only book shipped with the application.
    $source = is_file(DOCGEN_DATA_OVERRIDE_FILE) ? DOCGEN_DATA_OVERRIDE_FILE : DATA_FILE;
    return docgenMergeUserTableData(loadJsonFile($source, ['origin' => '', 'sheets' => [], 'records' => []]));
}

function loadDemoOverrides(): array
{
    $source = is_file(DOCGEN_DEMO_OVERRIDES_FILE) ? DOCGEN_DEMO_OVERRIDES_FILE : DEMO_OVERRIDES_FILE;
    return loadJsonFile($source, []);
}

function loadRecordEdits(): array
{
    return loadJsonFile(RECORD_EDITS_FILE, []);
}

function baseFields(array $record): array
{
    static $manualValues = null;
    static $savedEdits = null;
    static $customColumns = null;
    if ($manualValues === null) {
        $manualValues = loadDemoOverrides();
    }
    if ($savedEdits === null) {
        $savedEdits = loadRecordEdits();
    }
    $fields = recordFields($record);
    $id = (string) ($record['id'] ?? '');
    $manual = $manualValues[$id] ?? [];
    if (is_array($manual)) {
        foreach ($manual as $name => $value) {
            if (!array_key_exists($name, $fields)) {
                $fields[$name] = $value;
            }
        }
    }
    $edits = $savedEdits[$id] ?? [];
    if (is_array($edits)) {
        foreach ($edits as $name => $value) {
            $fields[$name] = $value;
        }
    }
    if ($customColumns === null) {
        $customColumns = docgenTableData()['columns'];
    }
    foreach ($customColumns as $column) {
        if (!array_key_exists($column, $fields)) {
            $fields[$column] = '';
        }
    }
    return $fields;
}

function preserveWordCase(string $original, string $inflected): string
{
    $letters = preg_replace('/[^\p{L}]/u', '', $original) ?? '';
    if ($letters !== '' && mb_strtoupper($letters, 'UTF-8') === $letters) {
        return mb_strtoupper($inflected, 'UTF-8');
    }
    $first = mb_substr($original, 0, 1, 'UTF-8');
    if ($first !== '' && mb_strtoupper($first, 'UTF-8') === $first && mb_strtolower($first, 'UTF-8') !== $first) {
        return mb_strtoupper(mb_substr($inflected, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($inflected, 1, null, 'UTF-8');
    }
    return mb_strtolower($inflected, 'UTF-8');
}

function inflectTitleAdjective(string $word, string $case): string
{
    $lower = mb_strtolower($word, 'UTF-8');
    if (mb_substr($lower, -2, null, 'UTF-8') === 'ий') {
        $stem = mb_substr($lower, 0, -2, 'UTF-8');
        $ending = match ($case) {
            RussianCases::GENITIVE, RussianCases::ACCUSATIVE => 'его',
            RussianCases::DATIVE => 'ему',
            RussianCases::ABLATIVE => 'им',
            RussianCases::PREPOSITIONAL => 'ем',
            default => '',
        };
        if ($case === RussianCases::NOMINATIVE) {
            return $word;
        }
        return preserveWordCase($word, $stem . $ending);
    }
    try {
        return preserveWordCase($word, AdjectiveDeclension::getCase($word, $case, true, 'm'));
    } catch (Throwable $e) {
        return $word;
    }
}

function inflectTitleNoun(string $word, string $case): string
{
    try {
        return preserveWordCase($word, NounDeclension::getCase($word, $case, true));
    } catch (Throwable $e) {
        return $word;
    }
}

function inflectJobTitleSegment(string $segment, string $case): array
{
    $pattern = '/^(\\s*)(?:(временно)\\s+)?(?:(первый|второй|главный|старший|младший|ведущий)\\s+)?(исполняющий|управляющий|заместитель|председатель|министр|губернатор|вице-губернатор|глава|директор|начальник|руководитель|мэр|член|секретарь|советник|командующий|администратор|уполномоченный|премьер-министр|врач)\\b/iu';
    $recognized = false;
    $result = preg_replace_callback($pattern, static function (array $m) use ($case, &$recognized): string {
        $recognized = true;
        $leading = $m[1] ?? '';
        $temporary = $m[2] ?? '';
        $ordinal = $m[3] ?? '';
        $head = $m[4] ?? '';
        $parts = [];
        if ($temporary !== '') {
            $parts[] = $temporary;
        }
        if ($ordinal !== '') {
            $parts[] = inflectTitleAdjective($ordinal, $case);
        }
        if (in_array(mb_strtolower($head, 'UTF-8'), ['исполняющий', 'управляющий'], true)) {
            $parts[] = inflectTitleAdjective($head, $case);
        } else {
            $parts[] = inflectTitleNoun($head, $case);
        }
        return $leading . implode(' ', $parts);
    }, $segment, 1);
    $result = is_string($result) ? $result : $segment;

    // In compound titles such as «Глава (Раис) Республики», inflect the explanatory synonym too.
    $result = preg_replace_callback('/\\(([^)]*)\\)/u', static function (array $m) use ($case): string {
        $inside = preg_replace_callback('/\\b(раис)\\b/iu', static fn(array $word): string => inflectTitleNoun($word[1], $case), $m[1]);
        return '(' . ($inside ?? $m[1]) . ')';
    }, $result) ?? $result;
    return [$result, $recognized];
}

function jobTitleCaseForms(string $title): array
{
    $cases = [
        'nominative' => RussianCases::NOMINATIVE,
        'genitive' => RussianCases::GENITIVE,
        'dative' => RussianCases::DATIVE,
        'accusative' => RussianCases::ACCUSATIVE,
        'ablative' => RussianCases::ABLATIVE,
        'prepositional' => RussianCases::PREPOSITIONAL,
    ];
    $forms = [];
    $recognizedAny = false;
    foreach ($cases as $label => $case) {
        $parts = preg_split('/(\\s+[—–-]\\s+|[,;]\\s*)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            $forms[$label] = $title;
            continue;
        }
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                [$parts[$index], $recognized] = inflectJobTitleSegment($part, $case);
                $recognizedAny = $recognizedAny || $recognized;
            }
        }
        $forms[$label] = implode('', $parts);
    }
    return ['cases' => $forms, 'recognized' => $recognizedAny];
}

/** Conservative gender hint: patronymic first, then a clearly gendered first name. */
function recipientGender(string $fullName): string
{
    $parts = preg_split('/\s+/u', mb_strtolower(trim($fullName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) < 2) {
        return '';
    }
    $patronymic = (string) end($parts);
    if (preg_match('/(ич|ович|евич)$/u', $patronymic) === 1) {
        return 'male';
    }
    if (preg_match('/(на|вна|ична)$/u', $patronymic) === 1) {
        return 'female';
    }
    $firstName = (string) $parts[1];
    if (preg_match('/(ай|ей|ий|ой|ый|й|ь)$/u', $firstName) === 1) {
        return 'male';
    }
    if (preg_match('/(а|я)$/u', $firstName) === 1) {
        return 'female';
    }
    return '';
}

function recipientInflections(string $fullName, string $jobTitle): array
{
    $nameCases = [];
    $nameError = '';
    if (trim($fullName) !== '') {
        try {
            $result = \morphos\Russian\getNameCases(trim($fullName));
            if (is_array($result)) {
                $nameCases = $result;
            } else {
                $nameError = 'Не удалось разобрать ФИО автоматически.';
            }
        } catch (Throwable $e) {
            $nameError = 'Не удалось разобрать ФИО автоматически.';
        }
    }
    $job = jobTitleCaseForms($jobTitle);
    $gender = recipientGender($fullName);
    return [
        'ok' => true,
        'nameCases' => $nameCases,
        'nameError' => $nameError,
        'jobCases' => $job['cases'],
        'jobRecognized' => $job['recognized'],
        'gender' => $gender,
        'salutation' => $gender === 'female' ? 'Уважаемая,' : ($gender === 'male' ? 'Уважаемый,' : ''),
    ];
}

function saveRecordEdits(array $edits): void
{
    $directory = dirname(RECORD_EDITS_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать папку для правок.');
    }
    $temp = RECORD_EDITS_FILE . '.' . bin2hex(random_bytes(5)) . '.tmp';
    $json = json_encode($edits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($temp);
        throw new RuntimeException('Не удалось сохранить карточку записи.');
    }
    if (!rename($temp, RECORD_EDITS_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Не удалось завершить сохранение карточки записи.');
    }
}

/** Bulk save only the values the operator explicitly mapped and confirmed in the preview. */
function handleBulkSaveRecords(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['ok' => false, 'error' => 'Для сохранения карточек используйте POST.'], 405);
    }
    $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
    $submittedEdits = json_decode((string) ($_POST['edits'] ?? '{}'), true);
    $newFields = json_decode((string) ($_POST['new_fields'] ?? '[]'), true);
    if (!is_array($ids) || !is_array($submittedEdits) || !is_array($newFields)) {
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать сопоставление карточек.'], 400);
    }
    $cleanIds = [];
    foreach ($ids as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $id = trim((string) $id);
        if ($id !== '' && strlen($id) <= 120 && !in_array($id, $cleanIds, true)) {
            $cleanIds[] = $id;
        }
    }
    if ($cleanIds === [] || count($cleanIds) > MAX_ROWS_PER_RUN) {
        sendJson(['ok' => false, 'error' => 'Выберите от 1 до ' . MAX_ROWS_PER_RUN . ' карточек для сохранения.'], 422);
    }

    $dataset = loadDataset();
    $byId = [];
    foreach (($dataset['records'] ?? []) as $candidate) {
        if (is_array($candidate) && isset($candidate['id'])) {
            $byId[(string) $candidate['id']] = $candidate;
        }
    }
    foreach ($cleanIds as $id) {
        if (!isset($byId[$id])) {
            sendJson(['ok' => false, 'error' => 'Одна из выбранных записей больше не существует.'], 404);
        }
    }

    $cleanEdits = [];
    foreach ($cleanIds as $id) {
        $fields = $submittedEdits[$id] ?? [];
        if (!is_array($fields) || $fields === []) {
            continue;
        }
        $clean = [];
        foreach ($fields as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if (mb_strlen($value, 'UTF-8') > 5000) {
                sendJson(['ok' => false, 'error' => 'Одно из полей превышает ограничение 5 000 символов.'], 422);
            }
            $clean[$name] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        }
        if ($clean !== []) {
            $cleanEdits[$id] = $clean;
        }
    }
    if ($cleanEdits === []) {
        sendJson(['ok' => false, 'error' => 'Нет сопоставленных полей для сохранения. Сначала сопоставьте данные DaData с колонками.'], 422);
    }

    try {
        $addedColumns = docgenEnsureColumns($newFields);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    $edits = loadRecordEdits();
    foreach ($cleanEdits as $id => $fields) {
        $existing = $edits[$id] ?? [];
        $edits[$id] = array_merge(is_array($existing) ? $existing : [], $fields);
    }
    try {
        saveRecordEdits($edits);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $savedRecords = [];
    foreach ($cleanEdits as $id => $fields) {
        $record = $byId[$id];
        $record['fields'] = array_merge(baseFields($record), $edits[$id]);
        $savedRecords[] = $record;
    }
    sendJson([
        'ok' => true,
        'records' => $savedRecords,
        'savedIds' => array_keys($cleanEdits),
        'savedFields' => array_sum(array_map('count', $cleanEdits)),
        'newColumns' => $addedColumns,
    ]);
}

function cleanupExpiredDownloads(): void
{
    if (!is_dir(DOWNLOADS_DIR)) {
        return;
    }
    foreach (scandir(DOWNLOADS_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !preg_match('/^[a-f0-9]{32}$/', $entry)) {
            continue;
        }
        $directory = DOWNLOADS_DIR . '/' . $entry;
        $metaPath = $directory . '/meta.json';
        $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
        $expiry = is_array($meta) ? (int) ($meta['expiresAt'] ?? 0) : 0;
        if ($expiry === 0 || $expiry < time()) {
            cleanupTree($directory);
        }
    }
}

function streamStoredDownload(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendJson(['ok' => false, 'error' => 'Для скачивания используйте GET.'], 405);
    }
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        sendJson(['ok' => false, 'error' => 'Ссылка на результат некорректна.'], 400);
    }
    $directory = DOWNLOADS_DIR . '/' . $id;
    $metaPath = $directory . '/meta.json';
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    if (!is_array($meta) || (int) ($meta['expiresAt'] ?? 0) < time()) {
        cleanupTree($directory);
        sendJson(['ok' => false, 'error' => 'Ссылка на результат истекла или файл уже удалён. Сформируйте документы ещё раз.'], 410);
    }
    $format = ($meta['format'] ?? '') === 'docx' ? 'docx' : (($meta['format'] ?? '') === 'zip' ? 'zip' : '');
    if ($format === '') {
        sendJson(['ok' => false, 'error' => 'Неизвестный формат результата.'], 500);
    }
    $path = $directory . '/payload.' . $format;
    if (!is_file($path)) {
        sendJson(['ok' => false, 'error' => 'Файл результата не найден. Сформируйте документы ещё раз.'], 404);
    }
    $filename = (string) ($meta['filename'] ?? ($format === 'docx' ? 'Документ.docx' : 'Пакет_DOCX.zip'));
    $fallback = $format === 'docx' ? 'document.docx' : 'documents.zip';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/zip'));
    header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Documents-Created: ' . (int) ($meta['created'] ?? 0));
    header('X-Documents-Skipped: ' . (int) ($meta['skipped'] ?? 0));
    header('X-Document-Format: ' . $format);
    readfile($path);
    exit;
}

function isUuidLike(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', trim($value));
}

/**
 * Scan Word text paragraphs only (not XML attributes), so GUIDs in image metadata
 * are not mistaken for template variables. Both FillDocuments {field} and PHPWord
 * ${field} forms are recognized.
 */
function scanDocxVariables(string $path): array
{
    $zip = new ZipArchive();
    $opened = $zip->open($path);
    if ($opened !== true || $zip->locateName('word/document.xml') === false) {
        if ($opened === true) {
            $zip->close();
        }
        throw new RuntimeException('Файл не похож на корректный DOCX-шаблон.');
    }

    $variables = [];
    $hasLegacy = false;
    $hasPhpWord = false;
    $wordNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || !preg_match('#^word/(document|header\d+|footer\d+|footnotes|endnotes)\.xml$#', $name)) {
            continue;
        }
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || $xml === '') {
            continue;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            continue;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', $wordNs);
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t', $paragraph) as $textNode) {
                $text .= $textNode->textContent;
            }
            if ($text === '') {
                continue;
            }
            if (preg_match_all('/\$\{([^{}]+)\}/u', $text, $matches)) {
                $hasPhpWord = true;
                foreach ($matches[1] as $variable) {
                    $variable = trim($variable);
                    if ($variable !== '' && !isUuidLike($variable)) {
                        $variables[$variable] = true;
                    }
                }
            }
            if (preg_match_all('/(?<!\$)\{([^{}]+)\}/u', $text, $matches)) {
                foreach ($matches[1] as $variable) {
                    $variable = trim($variable);
                    if ($variable !== '' && !isUuidLike($variable)) {
                        $hasLegacy = true;
                        $variables[$variable] = true;
                    }
                }
            }
        }
    }
    $zip->close();

    $names = array_keys($variables);
    return [
        'fields' => $names,
        'syntax' => $hasLegacy && $hasPhpWord ? 'mixed' : ($hasLegacy ? 'filldocuments' : ($hasPhpWord ? 'phpword' : 'none')),
        'syntaxLabel' => $hasLegacy && $hasPhpWord ? 'Смешанный формат' : ($hasLegacy ? 'FillDocuments {поле}' : ($hasPhpWord ? 'PHPWord ${поле}' : 'переменные не найдены')),
    ];
}

function validateDocxPath(string $path): void
{
    if (!is_file($path) || filesize($path) > MAX_TEMPLATE_BYTES) {
        throw new RuntimeException('Шаблон отсутствует или превышает ограничение 20 МБ.');
    }
    $zip = new ZipArchive();
    $opened = $zip->open($path);
    if ($opened !== true) {
        throw new RuntimeException('Не удалось открыть DOCX как ZIP-пакет. Проверьте файл.');
    }
    $valid = $zip->locateName('word/document.xml') !== false;
    $zip->close();
    if (!$valid) {
        throw new RuntimeException('В файле не найдена основная часть Word-документа. Нужен файл .docx.');
    }
}

function uploadedTemplatePath(): ?string
{
    if (!isset($_FILES['template']) || !is_array($_FILES['template'])) {
        return null;
    }
    $file = $_FILES['template'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ошибка загрузки шаблона DOCX.');
    }
    $originalName = (string) ($file['name'] ?? '');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'docx') {
        throw new RuntimeException('Поддерживаются только шаблоны .docx.');
    }
    if ((int) ($file['size'] ?? 0) > MAX_TEMPLATE_BYTES) {
        throw new RuntimeException('Размер шаблона превышает 20 МБ.');
    }
    $path = (string) ($file['tmp_name'] ?? '');
    validateDocxPath($path);
    return $path;
}

function templateFromRequest(): array
{
    $uploaded = uploadedTemplatePath();
    if ($uploaded !== null) {
        return ['path' => $uploaded, 'name' => basename((string) $_FILES['template']['name']), 'sample' => false];
    }
    if (isset($_POST['use_sample']) && (string) $_POST['use_sample'] === '1') {
        validateDocxPath(SAMPLE_TEMPLATE);
        return ['path' => SAMPLE_TEMPLATE, 'name' => 'Письмо-пример.docx', 'sample' => true];
    }
    throw new RuntimeException('Загрузите DOCX-шаблон или выберите пример.');
}

function recordFields(array $record): array
{
    $fields = $record['fields'] ?? [];
    return is_array($fields) ? $fields : [];
}

function buildFieldAnalysis(array $templateInfo, array $records, string $sourceKey = ''): array
{
    $sourceRecords = array_values(array_filter($records, static function ($record) use ($sourceKey) {
        return $sourceKey === '' || ($record['source_key'] ?? '') === $sourceKey;
    }));
    $columns = [];
    foreach ($sourceRecords as $record) {
        foreach (array_keys(baseFields($record)) as $column) {
            $columns[$column] = true;
        }
    }
    $fields = [];
    foreach ($templateInfo['fields'] as $name) {
        $fields[] = [
            'name' => $name,
            'mapped' => isset($columns[$name]),
            'source' => isset($columns[$name]) ? $name : null,
        ];
    }
    return $fields;
}

function cleanupTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            cleanupTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function handleSaveRecord(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['ok' => false, 'error' => 'Для сохранения записи используйте POST.'], 405);
    }
    $id = trim((string) ($_POST['id'] ?? ''));
    $submitted = json_decode((string) ($_POST['fields'] ?? '{}'), true);
    if ($id === '' || !is_array($submitted)) {
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать карточку записи.'], 400);
    }

    $dataset = loadDataset();
    $record = null;
    foreach (($dataset['records'] ?? []) as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $id) {
            $record = $candidate;
            break;
        }
    }
    if ($record === null) {
        sendJson(['ok' => false, 'error' => 'Запись не найдена в демо-наборе.'], 404);
    }

    $clean = [];
    foreach ($submitted as $name => $value) {
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || preg_match('/[\\x00-\\x1F\\x7F]/u', $name)) {
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string) $value);
        if (mb_strlen($value, 'UTF-8') > 5000) {
            sendJson(['ok' => false, 'error' => 'Одно из полей превышает ограничение 5 000 символов.'], 422);
        }
        $clean[$name] = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', '', $value) ?? $value;
    }

    $edits = loadRecordEdits();
    $existing = $edits[$id] ?? [];
    $edits[$id] = array_merge(is_array($existing) ? $existing : [], $clean);
    try {
        saveRecordEdits($edits);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $record['fields'] = array_merge(baseFields($record), $edits[$id]);
    sendJson(['ok' => true, 'record' => $record, 'savedFields' => array_keys($clean)]);
}

function generateDocuments(): never
{
    set_time_limit(240);
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
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать выбранные строки.'], 400);
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn($id) => $id !== '')));
    if (count($ids) === 0) {
        sendJson(['ok' => false, 'error' => 'Сначала отметьте хотя бы одну запись.'], 422);
    }
    if (count($ids) > MAX_ROWS_PER_RUN) {
        sendJson(['ok' => false, 'error' => 'В демо можно сформировать не более ' . MAX_ROWS_PER_RUN . ' документов за один запуск. Уточните фильтр или разделите пакет.'], 422);
    }

    try {
        $template = templateFromRequest();
        $templateInfo = scanDocxVariables($template['path']);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    if ($templateInfo['syntax'] === 'mixed') {
        sendJson(['ok' => false, 'error' => 'В одном шаблоне смешаны {поле} и ${поле}. Оставьте один формат переменных.'], 422);
    }
    if (count($templateInfo['fields']) === 0) {
        sendJson(['ok' => false, 'error' => 'В DOCX не найдены переменные для подстановки.'], 422);
    }

    $overrides = json_decode((string) ($_POST['overrides'] ?? '{}'), true);
    if (!is_array($overrides)) {
        $overrides = [];
    }
    $workRoot = __DIR__ . '/../docgen_demo_storage/tmp';
    if (!is_dir($workRoot) && !mkdir($workRoot, 0700, true) && !is_dir($workRoot)) {
        sendJson(['ok' => false, 'error' => 'Не удалось создать временную папку для обработки.'], 500);
    }
    $jobDir = $workRoot . '/' . bin2hex(random_bytes(8));
    if (!mkdir($jobDir, 0700, true)) {
        sendJson(['ok' => false, 'error' => 'Не удалось подготовить пакет документов.'], 500);
    }

    $archivePath = $jobDir . '/Пакет_DOCX.zip';
    $archive = new ZipArchive();
    if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось создать ZIP-архив.'], 500);
    }

    $created = 0;
    $errors = [];
    $counter = 0;
    $singleDocxPath = null;
    $singleFilename = '';
    $macroOpen = $templateInfo['syntax'] === 'filldocuments' ? '{' : '${';

    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            $errors[] = ['id' => $id, 'region' => '', 'reason' => 'Запись не найдена в демо-наборе.'];
            continue;
        }
        $record = $byId[$id];
        $fields = baseFields($record);
        $rowOverrides = [];
        if (isset($overrides[$id]) && is_array($overrides[$id])) {
            $rowOverrides = $overrides[$id];
        }

        $values = [];
        $missing = [];
        foreach ($templateInfo['fields'] as $variable) {
            if (array_key_exists($variable, $rowOverrides) && trim((string) $rowOverrides[$variable]) !== '') {
                $values[$variable] = trim((string) $rowOverrides[$variable]);
            } elseif (array_key_exists($variable, $fields) && trim((string) $fields[$variable]) !== '') {
                $values[$variable] = (string) $fields[$variable];
            } else {
                $missing[] = $variable;
            }
        }
        if ($missing) {
            $errors[] = [
                'id' => $id,
                'region' => (string) ($fields['Регион'] ?? ''),
                'reason' => 'Не сопоставлены поля: ' . implode(', ', $missing),
            ];
            continue;
        }

        try {
            $processor = new TemplateProcessor($template['path']);
            $processor->setMacroChars($macroOpen, '}');
            foreach ($values as $key => $value) {
                $processor->setValue($key, $value);
            }
            $docxPath = $jobDir . '/doc_' . str_pad((string) (++$counter), 4, '0', STR_PAD_LEFT) . '.docx';
            $processor->saveAs($docxPath);
            unset($processor);

            $region = (string) ($fields['Регион'] ?? 'Без региона');
            $person = (string) ($fields['Фамилия И.О.'] ?? $fields['ФИО'] ?? 'Адресат');
            $inn = (string) ($fields['ИНН'] ?? '');
            $base = safeFilename($region . ' — ' . $person . ($inn !== '' ? ' — ' . $inn : ''));
            $archiveName = $base . '.docx';
            if (!$archive->addFile($docxPath, $archiveName)) {
                $errors[] = ['id' => $id, 'region' => $region, 'reason' => 'Не удалось добавить документ в ZIP.'];
                @unlink($docxPath);
                continue;
            }
            $singleDocxPath = $docxPath;
            $singleFilename = $archiveName;
            $created++;
        } catch (Throwable $e) {
            $errors[] = [
                'id' => $id,
                'region' => (string) ($fields['Регион'] ?? ''),
                'reason' => 'Ошибка обработки DOCX: ' . $e->getMessage(),
            ];
        }
    }

    if ($errors) {
        $lines = [
            'Результат демо-формирования DOCX',
            'Создано документов: ' . $created,
            'Пропущено/ошибок: ' . count($errors),
            '',
        ];
        foreach ($errors as $error) {
            $lines[] = ($error['region'] !== '' ? $error['region'] . ' · ' : '') . ($error['id'] ?? '') . ' — ' . $error['reason'];
        }
        $archive->addFromString('Отчет_по_ошибкам.txt', implode("\r\n", $lines));
    }
    $archive->close();

    if ($created === 0) {
        $summary = array_map(static function ($item) {
            return ($item['region'] !== '' ? $item['region'] . ': ' : '') . $item['reason'];
        }, $errors);
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не создан ни один DOCX.', 'details' => $summary], 422);
    }

    $singleDownload = $created === 1 && count($errors) === 0 && is_string($singleDocxPath) && is_file($singleDocxPath);
    if ($singleDownload) {
        $sourcePath = $singleDocxPath;
        $format = 'docx';
        $downloadName = $singleFilename !== '' ? $singleFilename : 'Документ.docx';
    } else {
        $sourcePath = $archivePath;
        $format = 'zip';
        $downloadName = 'Пакет_DOCX_' . date('Ymd_His') . '.zip';
    }

    if (!is_dir(DOWNLOADS_DIR) && !mkdir(DOWNLOADS_DIR, 0700, true) && !is_dir(DOWNLOADS_DIR)) {
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось подготовить ссылку для скачивания.'], 500);
    }
    $downloadId = bin2hex(random_bytes(16));
    $downloadDirectory = DOWNLOADS_DIR . '/' . $downloadId;
    if (!mkdir($downloadDirectory, 0700, true)) {
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось создать временное хранилище результата.'], 500);
    }
    $storedPath = $downloadDirectory . '/payload.' . $format;
    $metadata = [
        'format' => $format,
        'filename' => $downloadName,
        'created' => $created,
        'skipped' => count($errors),
        'expiresAt' => time() + DOWNLOAD_TTL_SECONDS,
    ];
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!copy($sourcePath, $storedPath) || !is_string($metadataJson) || file_put_contents($downloadDirectory . '/meta.json', $metadataJson, LOCK_EX) === false) {
        cleanupTree($downloadDirectory);
        cleanupTree($jobDir);
        sendJson(['ok' => false, 'error' => 'Не удалось сохранить сформированный файл для скачивания.'], 500);
    }
    cleanupTree($jobDir);
    sendJson([
        'ok' => true,
        'downloadUrl' => '?action=download&id=' . $downloadId,
        'filename' => $downloadName,
        'format' => $format,
        'created' => $created,
        'skipped' => count($errors),
        'expiresInSeconds' => DOWNLOAD_TTL_SECONDS,
    ]);
}

$action = (string) ($_GET['action'] ?? '');
docgenBoot($action);
docgenDispatch($action);
if ($action === 'download') {
    streamStoredDownload();
}
if ($action === 'inflect') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['ok' => false, 'error' => 'Для склонения используйте POST.'], 405);
    }
    sendJson(recipientInflections(trim((string) ($_POST['full_name'] ?? '')), trim((string) ($_POST['job_title'] ?? ''))));
}
if ($action === 'save_record') {
    handleSaveRecord();
}
if ($action === 'data') {
    $dataset = loadDataset();
    $records = $dataset['records'] ?? [];
    foreach ($records as &$record) {
        if (is_array($record)) {
            $record['fields'] = baseFields($record);
        }
    }
    unset($record);
    sendJson([
        'origin' => $dataset['origin'] ?? '',
        'sheets' => $dataset['sheets'] ?? [],
        'records' => $records,
        'demoOverrides' => loadDemoOverrides(),
        'managerChecks' => (object) docgenManagerChecks(),
        'customColumns' => $dataset['customColumns'] ?? [],
    ]);
}
if ($action === 'analyze') {
    try {
        $template = templateFromRequest();
        $info = scanDocxVariables($template['path']);
        if ($info['syntax'] === 'mixed') {
            sendJson(['ok' => false, 'error' => 'В шаблоне смешаны {поле} и ${поле}. Используйте один формат.'], 422);
        }
        $dataset = loadDataset();
        $sourceKey = (string) ($_POST['source_key'] ?? '');
        $info['analysis'] = buildFieldAnalysis($info, $dataset['records'] ?? [], $sourceKey);
        $info['ok'] = true;
        $info['templateName'] = $template['name'];
        sendJson($info);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}
if ($action === 'download_sample') {
    validateDocxPath(SAMPLE_TEMPLATE);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header("Content-Disposition: attachment; filename*=UTF-8''letter_example.docx");
    header('Content-Length: ' . filesize(SAMPLE_TEMPLATE));
    header('Cache-Control: no-store');
    readfile(SAMPLE_TEMPLATE);
    exit;
}
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    generateDocuments();
}

$sampleInfo = ['fields' => [], 'syntax' => 'none', 'syntaxLabel' => 'Переменные не найдены'];
try {
    validateDocxPath(SAMPLE_TEMPLATE);
    $sampleInfo = scanDocxVariables(SAMPLE_TEMPLATE);
} catch (Throwable $e) {
    // The UI still loads and reports the missing sample template below.
}
$sampleInfo['analysis'] = buildFieldAnalysis($sampleInfo, loadDataset()['records'] ?? [], 'guber');
$sampleInfo['type'] = 'docx';
$sampleInfo['templateId'] = 'sample-docx';
$sampleInfo['templateName'] = 'Письмо-пример.docx';

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Демо формирования документов — Единая книга ведомств</title>
<style>
:root {
  --bg:#f2f5f9; --card:#fff; --ink:#16283c; --muted:#5b6b7f; --line:#dde5ee;
  --brand:#0e4f8a; --brand-dark:#0a3a66; --brand-soft:#eef5fc; --ok:#0f7b4f;
  --ok-bg:#e7f6ef; --bad:#b3261e; --bad-bg:#fdecea; --warn:#a96300;
  --warn-bg:#fff6e5; --warn-line:#f0c36d; --head:#0d3557; --head-line:#2a557d;
  --radius:12px; --shadow:0 1px 2px rgba(16,40,64,.06),0 8px 24px rgba(16,40,64,.07);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.45}
button,input,select{font:inherit}
.app-header{background:linear-gradient(115deg,#0a3154 0%,#12588f 70%,#1668a8 100%);color:#fff;padding:22px 28px 20px;box-shadow:0 2px 14px rgba(7,35,62,.18)}
.header-inner{max-width:1480px;margin:auto;display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
.app-header h1{margin:0 0 6px;font-size:22px;font-weight:650;letter-spacing:.2px}
.app-header .sub{display:flex;align-items:center;gap:8px;color:#cfe3f5;font-size:13px}
.app-header .sub svg{flex:none}
.header-badge{border:1px solid rgba(255,255,255,.28);background:rgba(255,255,255,.1);border-radius:999px;padding:7px 12px;color:#e9f4ff;white-space:nowrap;font-size:12px;font-weight:650}.header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.logout-button{padding:7px 10px;border:1px solid rgba(255,255,255,.38);border-radius:8px;background:rgba(255,255,255,.08);color:#fff;font-size:12px}.logout-button:hover{background:rgba(255,255,255,.16)}
.webinar-button{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border:1px solid rgba(255,255,255,.5);border-radius:8px;background:#fff;color:#0e4f8a;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap}
.webinar-button:hover{background:#e8f2ff;border-color:#fff}.webinar-button svg{flex:none}
main{max-width:1480px;margin:0 auto;padding:22px 24px 48px}
.notice{border:1px solid #c9d9ea;border-radius:10px;background:#f8fbff;color:#39536f;padding:11px 14px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start}
.notice strong{color:#173b60}
.notice ol{margin:5px 0 0;padding-left:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 24px}
".notice li{padding-left:2px}
.help-accordion{display:block;padding:0}.help-accordion>summary{cursor:pointer;list-style:none;padding:11px 14px;display:flex;align-items:center;gap:9px;color:#173b60;font-weight:750}.help-accordion>summary::-webkit-details-marker{display:none}.help-accordion>summary:after{content:'＋';margin-left:auto;color:var(--brand);font-weight:800}.help-accordion[open]>summary:after{content:'−'}.help-accordion>div{padding:3px 14px 13px;border-top:1px solid #e7edf3}.help-accordion ol{counter-reset:help-step;list-style:none;margin:9px 0 0;padding:0;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:7px 22px}.help-accordion li{counter-increment:help-step;position:relative;padding:5px 0 5px 30px;border-bottom:1px dashed #e3ecf4}.help-accordion li:before{content:counter(help-step);position:absolute;left:0;top:5px;width:21px;height:21px;border-radius:50%;display:grid;place-items:center;background:#e8f2fc;color:#175387;font-size:10.5px;font-weight:800}
.top-grid{display:grid;grid-template-columns:minmax(280px,.88fr) minmax(400px,1.12fr);gap:16px;margin-bottom:16px}
@media(max-width:850px){.top-grid{grid-template-columns:1fr}.header-inner{display:block}.header-badge{display:inline-flex;margin-top:12px}}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.card-head{padding:15px 18px 10px;display:flex;align-items:center;gap:10px}
.card-head h2{font-size:15px;margin:0;font-weight:700}
.step{width:26px;height:26px;display:inline-grid;place-items:center;border-radius:8px;background:var(--brand-soft);color:var(--brand);font-weight:750;font-size:12px}
.card-body{padding:0 18px 17px}
.help{font-size:12.5px;color:var(--muted);margin:3px 0 12px}
.control-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}
@media(max-width:520px){.control-grid{grid-template-columns:1fr}}
.field label{display:block;font-size:12px;color:var(--muted);font-weight:650;margin:0 0 5px}
.field select,.field input[type=search]{width:100%;height:40px;border:1px solid #cbd7e3;border-radius:8px;background:#fff;color:var(--ink);padding:8px 10px;outline:none}
.field select:focus,.field input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,79,138,.12)}
.source-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;color:var(--muted);font-size:12px}
.pill{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:4px 10px;font-size:11.5px;font-weight:700;background:#eef2f7;color:var(--muted)}
.pill.ok{background:var(--ok-bg);color:var(--ok)}.pill.warn{background:var(--warn-bg);color:var(--warn)}.pill.bad{background:var(--bad-bg);color:var(--bad)}
.dz{background:#fff;border:1.5px dashed #c6d4e3;border-radius:10px;padding:14px 15px;text-align:center;cursor:pointer;outline:none;transition:.15s;min-height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.dz:hover,.dz:focus-visible,.dz.dragover{border-color:var(--brand);background:var(--brand-soft);box-shadow:0 0 0 4px rgba(14,79,138,.08)}
.dz.loaded{border-style:solid;border-color:#8fd0b2;background:#f4fbf8}
.dz.error{border-style:solid;border-color:#e8a9a3;background:#fff7f6}
.dz-icon{color:var(--brand);margin-bottom:4px}.dz.loaded .dz-icon{color:var(--ok)}
.dz-title{font-size:14px;font-weight:700;margin:0 0 3px}.dz-hint{font-size:12px;color:var(--muted);margin-bottom:8px}
.pill .file-name{max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:550}
.file-actions{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
.link-button{border:0;background:transparent;color:var(--brand);text-decoration:underline;text-underline-offset:2px;padding:3px 5px;cursor:pointer;font-size:12px;font-weight:650}
.toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0 0 13px}
button{border-radius:9px;padding:9px 15px;cursor:pointer;border:1px solid transparent;font-weight:650;transition:background .15s,opacity .15s,box-shadow .15s,color .15s}
button:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.btn-primary{background:var(--brand);color:#fff;box-shadow:0 2px 6px rgba(14,79,138,.3)}
.btn-primary:not(:disabled):hover{background:var(--brand-dark)}
.btn-ghost{background:transparent;color:var(--muted);border-color:var(--line)}
.btn-ghost:not(:disabled):hover{background:#e9eef4;color:var(--ink)}
.btn-small{padding:7px 10px;font-size:12px;border-radius:7px}
.stats{margin-left:auto;display:flex;gap:16px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--muted);box-shadow:var(--shadow)}
.stats b{color:var(--ink);font-weight:750}.stats .blue b{color:var(--brand)}.stats .green b{color:var(--ok)}
@media(max-width:900px){.stats{margin-left:0}}
.search-box{margin-left:auto;min-width:220px;max-width:340px;flex:1}
.search-box input{width:100%;height:38px;padding:8px 11px;border:1px solid var(--line);border-radius:8px;background:#fff;outline:none}
.search-box input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,79,138,.1)}
.mapping-card{margin:0 0 14px;border:1px solid var(--line);border-radius:10px;background:#fff;box-shadow:var(--shadow)}
.mapping-head{padding:11px 14px 7px;display:flex;align-items:center;gap:8px}
.mapping-head strong{font-size:13px}.mapping-body{padding:0 14px 12px;display:flex;gap:7px;flex-wrap:wrap}
.map-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:7px;padding:5px 8px;background:#fafcff;font-size:11.5px;color:var(--muted)}
.map-chip b{color:var(--ink);font-weight:650}.map-chip .state{font-size:10.5px;font-weight:750;color:var(--ok)}.map-chip.manual{background:#fff9ec;border-color:#f0d9a8}.map-chip.manual .state{color:var(--warn)}
.var-chip{cursor:pointer;background:#fff;border:1px solid #bfd2e6;border-radius:7px;padding:5px 9px;font-size:11.5px;color:#174a7e;font-family:inherit}
.var-chip:hover,.var-chip:focus-visible{background:#eaf3fc;border-color:var(--brand);outline:none}
.letter-vars{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.letter-context{display:flex;align-items:center;gap:9px;flex-wrap:wrap;border:1px solid #cfe0ef;background:#f5faff;border-radius:9px;padding:9px 12px;margin:0 0 12px;font-size:12.5px;color:#31516d}
.letter-context-label{font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.4px;color:var(--muted)}
.letter-context b{font-size:14px;color:#123b63}
.letter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;border-bottom:1px solid var(--line);padding-bottom:0}
.letter-tab{background:transparent;border:1px solid transparent;border-bottom:0;border-radius:9px 9px 0 0;padding:9px 14px;color:var(--muted);font-weight:650;font-size:12.5px;margin-bottom:-1px}
.letter-tab:hover{background:#f1f7fd;color:#174a7e}
.letter-tab.active{background:#fff;border-color:var(--line);border-bottom:1px solid #fff;color:#123b63;box-shadow:0 -2px 0 var(--brand) inset}
.letter-tab .tab-mark{color:var(--ok);font-size:10px;margin-left:3px}
.letter-pane{animation:none}
.letter-design-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:15px;margin:0 0 4px}
.letter-html-editor{height:44vh;min-height:320px}
.letter-design-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:9px}
.letter-text-row{margin-top:14px}
.letter-plain-notice{display:block;margin-top:6px;font-size:12px;border-radius:7px;padding:6px 9px;font-weight:650}
.letter-plain-notice.warn{background:#fff6e8;border:1px solid #f0d9a8;color:#8a5a00}
.letter-plain-notice.ok{background:#eef9f2;border:1px solid #b7e0c7;color:#1b6b41}
.queue-preview-switch{grid-column:1/-1;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:-4px 0 2px}
.queue-preview-tab{background:transparent;border:0;border-bottom:2px solid transparent;border-radius:0;padding:4px 2px;color:var(--muted);font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}
.queue-preview-tab:not(.active):hover{background:transparent;color:#174a7e;text-decoration:underline;text-underline-offset:2px}
.queue-preview-tab.active{border-bottom-color:var(--brand);color:#123b63}
.smtp-log-summary{margin:0 0 12px}
.smtp-log-summary .smtp-log-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}
.smtp-log-badge{border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600}
.smtp-log-badge.ok{background:#e8f6ee;color:#1b7f4b}
.smtp-log-badge.bad{background:#fdecea;color:#b42318}
.smtp-log-hint{margin:0;font-size:12.5px;color:#5b6b7f}
.smtp-log-steps{list-style:none;margin:0 0 12px;padding:0;display:grid;gap:6px}
.smtp-log-steps li{display:flex;gap:9px;align-items:flex-start;padding:7px 9px;border:1px solid #e3ebf4;border-radius:7px;font-size:12.5px;background:#fbfdff}
.smtp-log-steps .step-name{font-weight:650;min-width:42px;color:#173b60}
.smtp-log-steps li.bad{border-color:#f3c9c4;background:#fff8f7}
.smtp-log-transcript{margin:0;border:1px solid var(--line);border-radius:8px;background:#0f2233;color:#d9e8f5;padding:11px 12px;max-height:38vh;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.smtp-log-context{display:grid;grid-template-columns:max-content 1fr;gap:3px 12px;margin:10px 0 0;font-size:12.5px}
.smtp-log-context dt{color:#5b6b7f}
.smtp-log-context dd{margin:0;overflow-wrap:anywhere}
.queue-text-preview{grid-column:1;grid-row:2/4;margin:0;border:1px solid var(--line);border-radius:8px;background:#fbfdff;padding:13px 14px;height:56vh;min-height:390px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:13px/1.5 "Segoe UI",system-ui,sans-serif;color:#24394f}
.queue-text-preview.empty{color:var(--bad);font-weight:650;background:#fff7f6;border-color:#efc1bc}
.queue-text-hint{display:block;margin-top:5px;font-size:11.5px;border-radius:7px;padding:6px 8px;font-weight:650}
.queue-text-hint.warn{background:#fff6e8;border:1px solid #f0d9a8;color:#8a5a00}
.letter-badge{display:inline-flex;padding:2px 7px;border-radius:999px;background:#e6f2f3;color:#1a6b70;font-size:10px;font-weight:800;letter-spacing:.3px}
.letter-text-row .field textarea{min-height:80px}
@media(max-width:900px){.letter-design-grid{grid-template-columns:1fr}.letter-html-editor{height:34vh;min-height:240px}}
.legend{display:flex;align-items:center;gap:8px;margin:0 0 9px;color:var(--muted);font-size:12px;flex-wrap:wrap}
.table-section{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.table-wrap{overflow:auto;max-height:63vh;min-height:280px}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;min-width:950px}
thead th{position:sticky;top:0;z-index:3;background:var(--head);color:#fff;text-align:left;padding:11px 10px;border-right:1px solid var(--head-line);font-size:11.5px;white-space:nowrap}
thead th:first-child{width:42px;text-align:center;left:0;z-index:5}
tbody td{padding:8px 10px;border-bottom:1px solid #e7edf3;vertical-align:middle;color:#24394f;background:#fff}
tbody tr:nth-child(even) td{background:#fbfdff}tbody tr:hover td{background:#f1f7fd}tbody tr.selected td{background:#edf6ff}
tbody td:first-child{position:sticky;left:0;z-index:2;text-align:center;width:42px;background:inherit}
tbody tr.selected td:first-child{box-shadow:inset 3px 0 0 var(--brand)}
tbody tr.manager-name-mismatch td:nth-child(3){background:#fff0ee!important;box-shadow:inset 0 0 0 1px #f1b9b4}
tbody tr.manager-position-mismatch td:nth-child(4){background:#fff7e8!important;box-shadow:inset 0 0 0 1px #f0d49a}
.manager-check-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:750;white-space:nowrap;background:#eef2f7;color:#586d82}
.manager-check-badge.match{background:#e7f6ef;color:#0f7b4f}
.manager-check-badge.mismatch{background:#fdecea;color:#a1332b}
.manager-check-badge.position{background:#fff4e0;color:#9a6200}
.manager-check-badge.neutral{background:#eef2f7;color:#586d82}
.manager-check-badge.stale{background:#fff6e5;color:#8b5b00}
.manager-name-note{display:block;font-size:10.5px;color:#a1332b;margin-top:2px;overflow-wrap:anywhere}
.manager-position-note{display:block;font-size:10.5px;color:#9a6200;margin-top:2px;overflow-wrap:anywhere}
.manager-check-detail{border:1px solid var(--line);border-radius:9px;background:#fbfdff;padding:11px 12px;font-size:12px;color:#39536f}
.manager-check-detail.name-mismatch,.manager-check-detail.both-mismatch{border-color:#efc1bc;background:#fff7f6}
.manager-check-detail.position-mismatch{border-color:#f0d49a;background:#fffaf0}
.manager-check-detail.match{border-color:#bfe1ce;background:#f4fbf7}
.manager-check-detail .manager-check-values{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:8px}
.manager-check-detail .manager-check-value{min-width:0;border:1px solid #e2eaf1;border-radius:7px;background:#fff;padding:7px 8px;overflow-wrap:anywhere}
.manager-check-detail .manager-check-value b{display:block;font-size:10.5px;color:#6b7e91;margin-bottom:3px}
.check{width:16px;height:16px;accent-color:var(--brand);cursor:pointer}
.cell-main{font-weight:650;color:#173b60}.cell-sub{font-size:11px;color:var(--muted);margin-top:2px;max-width:320px}
.badge{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:10.5px;font-weight:700;background:#eef2f7;color:#53687f;white-space:nowrap}
.badge.active{background:#e7f6ef;color:#0f7b4f}.badge.inactive{background:#fff4e0;color:#9a6200}.badge.archive{background:#fdecea;color:#a1332b}.badge.custom-record{background:#eef2ff;color:#4945a3}
.manual-input{width:230px;max-width:100%;height:32px;border:1px solid #e1c989;background:#fffdfa;border-radius:6px;padding:5px 7px;font-size:11.5px;color:var(--ink)}
.manual-input:focus{outline:2px solid rgba(240,195,109,.35);border-color:#d29b23}
.edit-record{white-space:nowrap}
.table-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:9px 12px;border-top:1px solid var(--line);color:var(--muted);font-size:11.5px;background:#fbfcfe;flex-wrap:wrap}
.pager{display:flex;align-items:center;gap:7px}.empty-state{padding:60px 20px;text-align:center;color:var(--muted)}.empty-state .big{font-size:16px;color:var(--ink);font-weight:700;margin-bottom:5px}
.footer{max-width:1480px;margin:0 auto;padding:0 24px 22px;color:#8091a6;font-size:11.5px}
.progress-wrap{display:none;align-items:center;gap:10px;background:#edf6ff;border:1px solid #c5ddf4;border-radius:9px;padding:10px 13px;margin:-2px 0 13px;color:#315c82;font-size:12.5px}
.progress-wrap.show{display:flex}.progress-main{display:flex;align-items:center;gap:10px;flex:1}.mini-log{margin-left:auto;max-width:min(620px,55vw);max-height:76px;overflow:auto;border-left:1px solid #b7d4ef;padding-left:11px;font:11px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;color:#466783;white-space:pre-wrap}.mini-log:empty{display:none}.spinner{width:16px;height:16px;border-radius:50%;border:2px solid #b7d4ef;border-top-color:var(--brand);animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.toast{position:fixed;right:22px;bottom:22px;z-index:30;max-width:min(520px,calc(100vw - 36px));padding:12px 16px;border-radius:10px;background:#183a58;color:white;box-shadow:0 8px 24px rgba(0,0,0,.22);display:none;font-size:13px}
.toast.show{display:block}.toast.error{background:#9f2e28}.toast.success{background:#0b7048}.toast.info{background:#8a5a00}
.download-result{border:1px solid #b4d8c5;background:#f1fbf5;border-radius:9px;padding:10px 13px;margin:-3px 0 13px;color:#205e43;font-size:12.5px}
.download-result a{color:#0e6844;font-weight:750;text-underline-offset:2px;margin-left:5px}
.modal-backdrop{position:fixed;inset:0;z-index:40;background:rgba(10,27,45,.56);display:flex;align-items:center;justify-content:center;padding:18px}
body.modal-open{overflow:hidden}
.modal{width:min(1100px,100%);max-height:calc(100vh - 36px);background:#fff;border-radius:14px;box-shadow:0 18px 60px rgba(5,25,45,.32);display:flex;flex-direction:column;overflow:hidden}
.modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:17px 20px;border-bottom:1px solid var(--line);background:#fbfdff}
.modal-head h2{font-size:17px;margin:0 0 3px;color:#173b60}.modal-subtitle{color:var(--muted);font-size:12px}
.modal-close{font-size:21px;line-height:1;padding:5px 10px;border:1px solid var(--line);background:#fff;color:var(--muted)}
.modal-main{overflow:auto;padding:17px 20px 20px}
.template-picker-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.template-picker-row select{flex:1 1 240px;min-width:0}
.template-name-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px}
.template-name-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-bottom:1px}
.template-manager-modal{width:min(900px,100%)}
.template-manager-list{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
.template-row{border:1px solid var(--line);border-radius:11px;padding:12px 13px;background:#fbfdff}
.template-row.current{border-color:#b9d3ea;background:#f4f9ff}
.template-row-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.template-row-title{font-weight:700;color:#173b60;font-size:14px;word-break:break-word}
.template-row-meta{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 9px}
.template-row input[type=text]{width:100%;min-height:36px;border:1px solid #cbd7e3;border-radius:7px;padding:7px 9px;font-size:12.5px}
.template-row-actions{display:flex;gap:7px;flex-wrap:wrap}
.template-row-actions .btn{padding:6px 9px;font-size:12px;border-radius:7px}
.template-manager-new{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:14px}
.modal-hint{border:1px solid #c9d9ea;border-radius:9px;background:#f8fbff;color:#39536f;padding:10px 12px;font-size:12px;margin-bottom:15px}
.modal-section{margin:0 0 18px}
.modal-section h3{font-size:13px;margin:0 0 9px;color:#234765}
.modal-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 13px}
.modal-field{min-width:0}.modal-field label{display:block;font-size:11.5px;color:var(--muted);font-weight:700;margin-bottom:4px}
.modal-field input,.modal-field textarea{width:100%;min-height:38px;border:1px solid #cbd7e3;border-radius:7px;padding:8px 9px;background:#fff;color:var(--ink);font-size:12.5px;outline:none}
.modal-field textarea{min-height:74px;resize:vertical}.modal-field input:focus,.modal-field textarea:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,79,138,.1)}
.inflection-card{border:1px solid #d5e1ec;border-radius:10px;background:#f8fbff;padding:12px 13px;margin:0 0 14px}
.inflection-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:7px}
.inflection-head strong{font-size:12.5px;color:#234765}.inflection-head .small-muted{max-width:650px;text-align:right}
.case-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 9px}
.case-item{border:1px solid #e2eaf1;border-radius:7px;background:#fff;padding:6px 8px;min-width:0}
.case-item b{display:block;font-size:10.5px;color:#6b7e91;font-weight:700;margin-bottom:2px}
.case-item span{font-size:12px;color:#173b60;overflow-wrap:anywhere}
.inflection-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:9px}
.auto-button{padding:5px 9px;font-size:11px;border-radius:7px;border:1px solid #b8cee1;color:var(--brand);background:#fff}
.modal-details{border:1px solid var(--line);border-radius:9px;background:#fbfcfe;padding:10px 12px}
.modal-details summary{cursor:pointer;font-size:12.5px;font-weight:700;color:#315c82}
".modal-details .modal-fields{margin-top:11px}
.record-modal{width:min(1180px,100%)}.record-modal .modal-main{padding:16px 20px 20px;background:#f6f9fc}.record-modal .modal-section{background:#fff;border:1px solid var(--line);border-radius:11px;padding:12px 14px 15px;margin:0 0 12px;box-shadow:0 1px 4px rgba(18,59,99,.06)}.record-modal .modal-section h3{margin:0 0 11px;padding:0 0 8px;border-bottom:1px solid #e8eef4;display:flex;align-items:center;gap:8px;font-size:12.5px;letter-spacing:.01em}.record-modal .modal-section h3:before{content:'';width:4px;height:14px;border-radius:3px;background:var(--brand);flex:none}.record-modal .modal-fields{grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 14px;align-items:start}.record-modal .modal-field{display:flex;flex-direction:column;gap:4px;min-width:0}.record-modal .modal-field.wide{grid-column:span 2}.record-modal .modal-field label{height:34px;min-height:34px;display:flex;align-items:flex-end;margin:0;line-height:1.25;overflow:hidden}.record-modal .modal-field input,.record-modal .modal-field textarea{min-height:38px;height:38px}.record-modal .modal-field textarea{min-height:76px;height:auto}.record-modal .case-grid{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}.record-modal .manager-check-detail .manager-check-values{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.modal-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 20px;border-top:1px solid var(--line);background:#fbfdff}
.modal-actions .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.dadata-map-row{display:grid;grid-template-columns:minmax(210px,1fr) minmax(200px,1fr) minmax(180px,1fr);gap:10px;align-items:center;border:1px solid var(--line);border-radius:9px;background:#fff;padding:9px 11px;margin-bottom:8px}
.dadata-map-row select,.dadata-map-row input{width:100%;min-height:34px;border:1px solid #cbd7e3;border-radius:7px;padding:6px 8px;background:#fff;color:var(--ink);font-size:12px}
.dadata-map-row input[hidden]{display:none}
.dadata-map-label{min-width:0}.dadata-map-label b{display:block;font-size:12px;color:#234765}.dadata-map-label span{display:block;margin-top:3px;font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dadata-preview-card{border:1px solid var(--line);border-radius:10px;background:#fff;padding:11px 13px;margin-bottom:9px}
.dadata-preview-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12px;margin-bottom:8px}
.dadata-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px}
.dadata-preview-change{border:1px solid #e2eaf2;border-radius:8px;background:#fbfdff;padding:8px 9px;font-size:11.5px;min-width:0}
.dadata-preview-change b{display:block;color:#234765;margin-bottom:4px}
.dadata-preview-change .old{color:var(--muted);overflow-wrap:anywhere}.dadata-preview-change .new{color:#176142;font-weight:650;margin-top:3px;overflow-wrap:anywhere}
@media(max-width:760px){.dadata-map-row{grid-template-columns:1fr}}
.small-muted{font-size:11px;color:#7b8da1}
.settings-card,.queue-section{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);margin:0 0 14px;overflow:hidden}
.settings-card details>summary{cursor:pointer;list-style:none;padding:13px 16px;display:flex;align-items:center;gap:12px;color:#173b60}
.settings-card details>summary::-webkit-details-marker{display:none}
.settings-card details>summary:after{content:'＋';margin-left:auto;color:var(--brand);font-weight:800}
.settings-card details[open]>summary:after{content:'−'}
.settings-state{font-size:11px;font-weight:650;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:3px 9px}
.settings-state.ok{background:var(--ok-bg);color:var(--ok);border-color:#c3e5d2}.settings-state.bad{background:var(--bad-bg);color:var(--bad);border-color:#f0c7c3}
.settings-body{padding:0 16px 16px;border-top:1px solid #e7edf3}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px 13px}
.settings-grid .field{min-width:0}.settings-grid .span2{grid-column:1/-1}
.settings-grid input,.settings-grid select,.settings-grid textarea,.editor-name{width:100%;min-height:39px;border:1px solid #cbd7e3;border-radius:8px;padding:8px 10px;color:var(--ink);background:#fff;outline:none}
.settings-grid textarea{resize:vertical}.settings-grid input:focus,.settings-grid select:focus,.settings-grid textarea:focus,.editor-name:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,79,138,.1)}
.settings-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:12px}.settings-actions label{display:flex;align-items:center;gap:5px}.settings-actions input{accent-color:var(--brand)}
.table-tools-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.table-tool-box{min-width:0;border:1px solid #e1e9f0;border-radius:9px;background:#fbfdff;padding:12px}.table-tool-box h3{font-size:13px;color:#204d76;margin:0 0 10px}.table-tool-box .field{margin:0 0 10px}.table-tool-box input[type=file],.table-tool-box input[type=text],.table-tool-box select{width:100%;min-height:38px;border:1px solid #cbd7e3;border-radius:8px;padding:7px 9px;background:#fff;color:var(--ink);font:inherit;font-size:12px}.table-tool-box input:focus,.table-tool-box select:focus{outline:2px solid rgba(14,79,138,.12);border-color:var(--brand)}.table-tool-box .small-muted{display:block;margin:7px 0 10px}.table-inline-form{display:flex;gap:7px}.table-inline-form input{min-width:0;flex:1}.table-inline-form button{white-space:nowrap}.excel-sheet-preview{border:1px solid #dbe5ed;border-radius:9px;margin:10px 0;padding:11px;background:#fbfdff}.excel-sheet-preview-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}.excel-sheet-preview h3{font-size:13px;margin:0;color:#173b60}.excel-sheet-preview select{max-width:360px;min-height:34px;border:1px solid #cbd7e3;border-radius:7px;padding:5px 8px;background:#fff}.excel-import-counts{display:flex;gap:7px;flex-wrap:wrap;margin:8px 0}.excel-import-counts span{font-size:10.5px;border-radius:999px;padding:3px 8px;background:#eef2f7;color:#526b82}.excel-import-counts .update{background:#eaf4ff;color:#165786}.excel-import-counts .insert{background:#e7f6ef;color:#0f7b4f}.excel-import-counts .skip{background:#fff4e0;color:#8b5b00}.excel-sheet-preview .excel-headers{font-size:11px;color:#647b91;margin:5px 0;overflow-wrap:anywhere}.excel-sample-wrap{overflow:auto;max-height:180px}.excel-sample{border-collapse:collapse;font-size:10.5px;min-width:100%;background:#fff}.excel-sample th,.excel-sample td{border:1px solid #dfe7ee;padding:4px 6px;white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis}.excel-sample th{background:#eff5fa;color:#355a7a}.excel-clear-empty{display:flex;gap:7px;align-items:flex-start;margin-top:12px;line-height:1.45}.excel-clear-empty input{accent-color:var(--brand);margin-top:2px}
.queue-section{padding:15px 16px 17px}.queue-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}.queue-head h2{font-size:16px;margin:0 0 3px;color:#173b60}.queue-head p{font-size:12px;color:var(--muted);margin:0;max-width:820px}.queue-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.queue-list{display:grid;gap:9px}.queue-empty{padding:28px 15px;text-align:center;color:var(--muted);border:1px dashed #cbd7e3;border-radius:9px;background:#fbfdff}
.batch-review{border:1px solid var(--line);border-radius:9px;background:#fff;overflow:hidden}.batch-review>summary{padding:11px 13px;cursor:pointer;background:#fbfdff;font-weight:650;color:#173b60}.batch-review-body{padding:11px 13px;border-top:1px solid var(--line)}
.batch-review-item{display:flex;gap:9px;align-items:center;flex-wrap:wrap;padding:7px 9px;border:1px solid #e3ebf4;border-radius:7px;margin-bottom:6px;font-size:12.5px}.batch-review-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:10px}
.batch-account-row{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:10px;padding:9px 10px;border:1px dashed #c3d5e6;border-radius:8px;background:#f7fbff}
.batch-account-row select{border:1px solid #cbd7e3;border-radius:7px;padding:6px 9px;font:inherit;min-width:220px;background:#fff}
.queue-item{border:1px solid var(--line);border-radius:10px;background:#fff;padding:11px 13px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px 14px;align-items:center}.queue-item:hover{border-color:#b4c8db}.queue-item-main{min-width:0}.queue-item-title{font-weight:750;color:#173b60;overflow-wrap:anywhere}.queue-item-sub{font-size:11.5px;color:var(--muted);margin-top:3px;overflow-wrap:anywhere}.queue-item-address{font-size:11.5px;margin-top:5px;overflow-wrap:anywhere}.queue-item-error{color:var(--bad);font-size:11.5px;margin-top:4px}.queue-item-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap;justify-content:flex-end}.status-label{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:10.5px;font-weight:750;background:#eef2f7;color:#586d82;white-space:nowrap}.status-label.queued{background:var(--warn-bg);color:#8b5b00}.status-label.sent{background:var(--ok-bg);color:var(--ok)}.status-label.failed,.status-label.sending{background:var(--bad-bg);color:var(--bad)}
.manager-modal{width:min(1440px,100%);height:calc(100vh - 36px)}.manager-modal .modal-head{padding:12px 16px}.manager-modal .modal-main{padding:0;flex:1;min-height:0;background:#f2f6fb}.manager-modal iframe{display:block;width:100%;height:100%;border:0;background:#f2f6fb}
.queue-modal{width:min(1180px,100%)}.queue-review-main{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr);gap:12px 16px}.queue-preview-toolbar{grid-column:1/-1;display:flex;align-items:center;gap:12px;flex-wrap:wrap}.pdf-preview-frame{grid-column:1;grid-row:2/4;width:100%;height:56vh;min-height:390px;border:1px solid var(--line);border-radius:8px;background:#eaf0f5}.queue-edit-grid{grid-column:2;grid-row:2;display:grid;grid-template-columns:1fr;gap:10px;align-content:start}.queue-edit-grid .span2{grid-column:1/-1}.queue-edit-grid textarea,.queue-edit-grid input{width:100%;border:1px solid #cbd7e3;border-radius:7px;padding:8px;font:inherit;font-size:12.5px}.review-confirm{grid-column:2;grid-row:3;border:1px solid #c9d9ea;border-radius:8px;background:#f8fbff;padding:10px;font-size:12px}.review-confirm label{display:flex;gap:7px;align-items:flex-start;font-weight:700;color:#284761}.review-confirm input{margin:2px 0 0;accent-color:var(--brand)}.review-confirm .small-muted{display:block;margin:5px 0 0 22px}
.html-editor-modal{width:min(1280px,100%)}.html-editor-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:15px}.editor-label{display:block;font-size:12px;font-weight:750;color:#31516d;margin:0 0 6px}.editor-name{margin-bottom:12px}.html-code-editor{display:block;width:100%;height:50vh;min-height:340px;resize:vertical;border:1px solid #cbd7e3;border-radius:8px;padding:11px;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;color:#173b60;background:#fbfdff;tab-size:2}.html-code-editor:focus{outline:2px solid rgba(14,79,138,.13);border-color:var(--brand)}.preview-column{display:flex;flex-direction:column;min-height:390px}.preview-column iframe{flex:1;width:100%;min-height:340px;border:1px solid var(--line);border-radius:8px;background:#fff}
[hidden]{display:none!important}
@media(max-width:650px){
  .modal-backdrop{padding:0}.modal{height:100vh;max-height:100vh;border-radius:0}
  .modal-head,.modal-main,.modal-actions{padding-left:14px;padding-right:14px}
  .modal-fields,.record-modal .modal-fields{grid-template-columns:1fr}.record-modal .modal-field.wide{grid-column:1}.case-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.manager-check-detail .manager-check-values,.record-modal .manager-check-detail .manager-check-values{grid-template-columns:1fr}
  .inflection-head{display:block}.inflection-head .small-muted{text-align:left;max-width:none;margin-top:4px}
  .modal-actions{align-items:stretch;flex-direction:column}.modal-actions .right{justify-content:flex-end}
  .settings-grid,.html-editor-grid,.queue-review-main,.table-tools-grid{grid-template-columns:1fr}
  .table-inline-form{flex-direction:column}.table-inline-form button{width:100%}
  .settings-grid .span2,.queue-preview-toolbar,.pdf-preview-frame,.queue-edit-grid,.review-confirm,.queue-preview-switch,.queue-text-preview{grid-column:1;grid-row:auto}.queue-text-preview{height:44vh;min-height:280px}
  .pdf-preview-frame{height:44vh;min-height:280px}.html-code-editor{height:38vh;min-height:250px}.preview-column{min-height:270px}.preview-column iframe{min-height:250px}
  .queue-head{display:block}.queue-tools{justify-content:flex-start;margin-top:9px}.queue-item{grid-template-columns:1fr}.queue-item-actions{justify-content:flex-start}
}
</style>
</head>
<body>
<header class="app-header">
  <div class="header-inner">
    <div>
      <h1>Формирование документов по Единой книге ведомств</h1>
      <div class="sub">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v6h6M9 13h7M9 17h7"/></svg>
        Выберите адресатов и шаблон DOCX или HTML; письма формируются в PDF и отправляются по одному
      </div>
    </div>
    <div class="header-actions"><a class="webinar-button" href="webinar.php" title="Генератор программ вебинаров ЦМК по расписанию «Кодекса»"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5M8.5 12h7m-7 4h7"/></svg>Генератор вебинаров</a><div class="header-badge">ТЕСТ · XLSX · DOCX · PDF · SMTP</div><?php if (docgenAuthHash() !== ''): ?><form method="post" action="?action=logout"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(docgenCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><button class="logout-button" type="submit">Выйти</button></form><?php endif; ?></div>
  </div>
</header>
<main>
  <details class="notice help-accordion"><summary><span aria-hidden="true">🧭</span> Как пользоваться тестовым приложением</summary><div><ol><li>Выберите набор и регион; найдите нужные организации поиском и отметьте записи вручную.</li><li>Если нужно сравнить руководителя с ЕГРЮЛ, вручную запустите «Сверить руководителей по ИНН» для отмеченных записей; автоматических запросов к DaData нет.</li><li>Чтобы обновить данные организаций, нажмите «Обновить через DaData» для отмеченных записей: в диалоге сопоставьте поля DaData с колонками книги или создайте новые, проверьте список «было → стало» и только затем нажмите «Сохранить карточки».</li><li>Выберите сохранённый DOCX/HTML-шаблон, загрузите DOCX или создайте HTML-шаблон во встроенном редакторе. В блоке «Шаблон письма» задайте имя вложения, получателей, тему, текст и при необходимости HTML-дизайн письма — это письмо сохранится отдельно для выбранного шаблона.</li><li>Откройте карточку адресата, проверьте данные и предложенные формы ФИО/должности; пустые поля заполните явно. Неизвестные значения не подставляются автоматически.</li><li>Нажмите «Сформировать PDF и подготовить очередь»: очередь писем создаётся без выбора ящика — сначала видно, сколько писем и кому. Для DOCX сохраняются также DOCX-файлы; на каждую запись создаётся отдельная очередь с PDF.</li><li>В карточке письма проверьте PDF-вложение, HTML-дизайн и текстовую версию: письмо уходит как multipart/alternative, поэтому текстовая версия обязательна.</li><li>В аккордеоне пачки проверьте письма, выберите ящик «Из какого ящика отправлять», подтвердите проверку и нажмите «поставить в ручную очередь» или, при необходимости, «в расписание (автоотправка)». Дальше пачки видны в <button type="button" class="link-button" data-open-manager>менеджере очереди</button>; ручная отправка по одному остаётся основной.</li></ol><div style="margin-top:7px"><strong>Адреса:</strong> поле «Основной email» становится получателем, «Доп./старые email» — копией (CC); проверьте, не устарели ли адреса. SMTP-настройки вводятся в приложении, пароль хранится в приватном каталоге вне web-root и не отображается повторно. Склонение — только предложение для ручной проверки.</div></details>

  <section class="top-grid">
    <div class="card">
      <div class="card-head"><span class="step">1</span><h2>Выбор таблицы и фильтров</h2></div>
      <div class="card-body">
        <div class="help">Разные листы книги представлены как наборы в единой базе. Архивные организации — отдельный набор.</div>
        <div class="control-grid">
          <div class="field"><label for="sourceSelect">Набор данных</label><select id="sourceSelect"><option value="">Загрузка…</option></select></div>
          <div class="field"><label for="regionSelect">Регион</label><select id="regionSelect"><option value="">Все регионы</option></select></div>
        </div>
        <div class="source-meta"><span class="pill ok" id="dbBadge">Чтение данных…</span><span id="sourceCount"></span></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><span class="step">2</span><h2>Шаблон DOCX или HTML</h2></div>
      <div class="card-body">
        <div class="field" style="margin-bottom:10px"><label for="templateSelect">Выбрать сохранённый шаблон</label>
          <div class="template-picker-row">
            <select id="templateSelect"><option value="sample-docx">Загрузка шаблонов…</option></select>
            <button type="button" class="btn-ghost btn-small" id="openTemplateManager" title="Переименовать, копировать, заменить файл или удалить шаблон">Менеджер шаблонов</button>
          </div>
          <div class="template-name-row">
            <div class="field" style="margin:0;flex:1 1 260px;min-width:0"><label for="templateNameInput">Имя выбранного шаблона</label><input id="templateNameInput" type="text" maxlength="180" autocomplete="off" placeholder="Например: Вебинар 11.12.2028 · тарифы"><span class="small-muted" style="display:block;margin-top:4px">Своё имя вместо названия файла. «Сохранить как копию» создаёт отдельный шаблон с этим именем.</span></div>
            <div class="template-name-actions">
              <button type="button" class="btn-primary btn-small" id="templateRenameBtn">Сохранить имя</button>
              <button type="button" class="btn-ghost btn-small" id="templateSaveAsBtn">Сохранить как копию…</button>
            </div>
          </div>
          <div class="small-muted" id="templateNameFeedback" aria-live="polite" style="margin-top:6px"></div>
        </div>
        <div class="dz loaded" id="dropTemplate" tabindex="0" role="button" aria-label="Загрузить DOCX шаблон">
          <div class="dz-icon"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg></div>
          <div class="dz-title">Загрузить DOCX для текущей работы</div>
          <div class="dz-hint">Перетащите сюда или нажмите для выбора · .docx до 20 МБ</div>
          <div class="pill ok" id="templateStatus" role="status" aria-live="polite"><span class="file-name">Письмо-пример.docx</span></div>
        </div>
        <input type="file" id="templateInput" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden>
        <div class="file-actions">
          <button type="button" class="btn-ghost btn-small" id="saveUploadedTemplate" disabled>Сохранить DOCX в библиотеку</button>
          <button type="button" class="btn-ghost btn-small" id="createHtmlTemplate">Новый HTML-шаблон</button>
          <button type="button" class="btn-ghost btn-small" id="editHtmlTemplate" disabled>Редактировать HTML</button>
          <a class="link-button" href="?action=download_sample">Скачать DOCX-пример</a>
        </div>
        <div class="help" style="margin:9px 0 0">DOCX сохраняет исходный формат; HTML-шаблон формируется в PDF. Поля используют коды вида {Регион}, {ФИО ДП}.</div>
      </div>
    </div>
  </section>

  <section class="settings-card" id="tableToolsCard">
    <details>
      <summary><strong>Импорт, экспорт и структура таблиц</strong><span class="settings-state" id="tableToolsState">XLSX · ручное подтверждение</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>📊</span><div>Импорт только из `.xlsx`, с предварительным просмотром. Существующие записи ищутся по внутреннему ID либо по уникальной паре ИНН/КПП; неоднозначные совпадения пропускаются. По умолчанию пустые ячейки не стирают данные. Каждый лист сопоставляется с разделом по имени или может создать новый раздел; совпавшие записи остаются в исходном разделе. Новые колонки общие для всех разделов. Данные хранятся локально вне web-root.</div></div>
        <div class="table-tools-grid">
          <section class="table-tool-box">
            <h3>Excel-файл</h3>
            <div class="field"><label for="excelImportFile">Импорт книги .xlsx · до 20 МБ</label><input id="excelImportFile" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div>
            <button type="button" class="btn-primary" id="previewExcelImportBtn">Загрузить и проверить</button>
            <div class="small-muted" id="excelImportFeedback" aria-live="polite"></div>
            <div class="field"><label for="excelExportScope">Выгрузить в Excel</label><select id="excelExportScope"><option value="all">Всю базу · отдельный лист на раздел</option><option value="filtered">Текущий фильтр и поиск</option><option value="selected">Только отмеченные строки</option></select></div>
            <button type="button" class="btn-ghost" id="exportExcelBtn">Скачать книгу XLSX</button>
          </section>
          <section class="table-tool-box">
            <h3>Структура таблицы</h3>
            <div class="field"><label for="newTableColumn">Добавить общую колонку</label><div class="table-inline-form"><input id="newTableColumn" type="text" maxlength="120" placeholder="Например: Ответственный"><button type="button" class="btn-ghost" id="addTableColumnBtn">Добавить колонку</button></div></div>
            <div class="field"><label for="newTableSection">Добавить раздел в список наборов</label><div class="table-inline-form"><input id="newTableSection" type="text" maxlength="100" placeholder="Например: Дополнительные адресаты"><button type="button" class="btn-ghost" id="addTableSectionBtn">Добавить раздел</button></div></div>
            <div class="small-muted" id="tableStructureFeedback" aria-live="polite">Колонка будет доступна в карточках всех записей; раздел можно заполнить импортом XLSX-листа с таким именем.</div>
          </section>
        </div>
      </div>
    </details>
  </section>

  <section class="settings-card" id="backupCard">
    <details>
      <summary><strong>Резервная копия: конфигурация и база</strong><span class="settings-state">ZIP · ручное восстановление</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>💾</span><div>Архив содержит книгу адресатов, структуру таблиц, правки карточек, результаты сверки, SMTP/шаблоны писем, лимит рассылки и сохранённые шаблоны. Пароли SMTP и API-ключ DaData включаются только по явной галочке. Пароль администратора и файл <code>app.env</code> не выгружаются. Перед восстановлением автоматически создаётся резервная копия текущего состояния, а замена начинается только после подтверждения в диалоге.</div></div>
        <div class="table-tools-grid">
          <section class="table-tool-box">
            <h3>Выгрузить ZIP</h3>
            <label class="small-muted"><input type="checkbox" id="backupIncludeSecrets"> Включить секреты (пароли SMTP и API-ключ DaData)</label>
            <div style="margin-top:9px"><button type="button" class="btn-primary" id="backupExportBtn">Собрать архив</button></div>
            <div class="small-muted" id="backupExportFeedback" aria-live="polite" style="margin-top:8px"></div>
          </section>
          <section class="table-tool-box">
            <h3>Загрузить ZIP</h3>
            <div class="field"><label for="backupImportFile">Файл резервной копии .zip</label><input id="backupImportFile" type="file" accept=".zip,application/zip"></div>
            <button type="button" class="btn-ghost" id="backupPreviewBtn">Проверить архив</button>
            <div class="small-muted" id="backupImportFeedback" aria-live="polite" style="margin-top:8px"></div>
          </section>
        </div>
      </div>
    </details>
  </section>

  <section class="mapping-card" id="mappingCard">
    <div class="mapping-head"><span class="step">3</span><strong>Переменные шаблона и сопоставление с данными</strong><span class="small-muted" id="syntaxLabel"></span></div>
    <div class="mapping-body" id="mappingBody"><span class="small-muted">Проверяем пример шаблона…</span></div>
  </section>

  <section class="settings-card" id="letterTemplateCard">
    <details id="letterDetails">
      <summary><strong>Шаблон письма: имя файла, получатели, текст</strong><span class="settings-state" id="letterState">Письмо выбранного шаблона</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>✉️</span><div>Письмо настраивается отдельно для каждого шаблона: загрузили DOCX «о новых инвестпрограммах» и HTML-дизайн — у этого шаблона свои имя файла, получатели, тема и текст. Шаблоны без собственной копии показывают общие значения; изменить их здесь — значит сохранить копию только для них. В полях работают коды вида <b>{Фамилия И.О.}</b>; нажмите переменную, чтобы вставить её в последнее редактируемое поле.</div></div>
        <div class="letter-context">
          <span class="letter-context-label">Редактируется письмо шаблона</span>
          <b id="letterTemplateName">—</b>
          <span class="pill" id="letterSourceBadge">общие настройки</span>
          <span class="small-muted" id="letterContextHint"></span>
        </div>
        <div class="letter-tabs" role="tablist" aria-label="Письмо шаблона">
          <button type="button" class="letter-tab active" role="tab" aria-selected="true" data-letter-tab="main" id="letterTabMain">Имя файла, получатели, тема и текст</button>
          <button type="button" class="letter-tab" role="tab" aria-selected="false" data-letter-tab="design" id="letterTabDesign"><span id="letterDesignMark" class="tab-mark" hidden>●</span> HTML-дизайн письма</button>
        </div>
        <div class="letter-pane" data-letter-pane="main">
          <div class="settings-grid">
            <div class="field span2"><label for="letterFilenamePattern">Имя вложенного файла без расширения</label><input id="letterFilenamePattern" type="text" maxlength="300" placeholder="{Регион} — {Фамилия И.О.} — {ИНН}" autocomplete="off"></div>
            <div class="field"><label for="letterToPattern">Кому (основной получатель)</label><input id="letterToPattern" type="text" maxlength="1000" placeholder="{Основной email}" autocomplete="off"></div>
            <div class="field"><label for="letterCcPattern">Копия (CC)</label><input id="letterCcPattern" type="text" maxlength="1000" placeholder="{Доп./старые email}" autocomplete="off"></div>
            <div class="field"><label for="letterSubjectPattern">Тема письма</label><input id="letterSubjectPattern" type="text" maxlength="500" placeholder="Документ — {Регион}" autocomplete="off"></div>
            <div class="field"><label for="letterAccountSelect">От кого — ящик по умолчанию</label><select id="letterAccountSelect"></select><span class="small-muted" style="display:block;margin-top:4px">Итоговый ящик выбирается в панели инструментов перед формированием очереди.</span></div>
          </div>
        </div>
        <div class="letter-pane" data-letter-pane="design" hidden>
          <div class="notice" style="margin:0 0 12px"><span>🎨</span><div>HTML-дизайн становится <b>телом письма</b> (шапка, кнопки, подвал как в рассылке), а PDF/DOCX — вложением. Вставьте полный код письма: работают те же коды <b>{Регион}</b>, <b>{Фамилия И.О.}</b>. Отправка всегда идёт как <b>multipart/alternative</b>: рядом с HTML уходит текстовая версия, иначе письмо получат с пустым или обрезанным текстом в клиентах без HTML. Если поле ниже пустое, текстовая версия будет собрана из HTML — проверьте её в карточке письма. Внешние картинки и скрипты не загружаются.</div></div>
          <div class="letter-design-grid">
            <div>
              <label class="editor-label" for="letterHtmlPattern">HTML + CSS дизайна письма</label>
              <textarea id="letterHtmlPattern" spellcheck="false" class="html-code-editor letter-html-editor" placeholder="&lt;!DOCTYPE html&gt;…" aria-label="HTML-код дизайна письма"></textarea>
              <div class="letter-design-tools">
                <label class="btn-ghost btn-small" for="letterDesignFileInput" style="display:inline-block">Загрузить HTML-файл</label>
                <input type="file" id="letterDesignFileInput" accept=".html,.htm,text/html" hidden>
                <button type="button" class="btn-ghost btn-small" id="letterDesignSampleBtn">Вставить пример письма</button>
                <button type="button" class="btn-ghost btn-small" id="letterDesignClearBtn">Очистить дизайн</button>
                <label class="small-muted"><input type="checkbox" id="letterDesignPreviewFill"> Подставить данные первой отмеченной записи</label>
                <span class="small-muted" id="letterDesignFeedback" aria-live="polite"></span>
              </div>
            </div>
            <div class="preview-column">
              <div class="editor-label">Предпросмотр тела письма</div>
              <iframe id="letterDesignPreview" title="Предпросмотр HTML-дизайна письма" sandbox></iframe>
            </div>
          </div>
        </div>
        <div class="settings-grid letter-text-row">
          <div class="field span2"><label for="letterBodyPattern">Текст письма (текстовая версия письма)</label><textarea id="letterBodyPattern" rows="4" placeholder="Добрый день.{nl}{nl}Направляем документ во вложении."></textarea><span class="small-muted" style="display:block;margin-top:4px">При HTML-дизайне это текстовая версия того же письма — она обязательна для корректной отправки. Оставьте пустой, чтобы собрать её из HTML.</span><span class="letter-plain-notice warn" id="letterPlainNotice" hidden></span></div>
          <div class="field span2"><label for="letterHtmlTextPattern">Отдельная текстовая версия для HTML-дизайна (необязательно)</label><textarea id="letterHtmlTextPattern" rows="3" placeholder="Текстовая версия письма, если она отличается от обычного текста."></textarea></div>
        </div>
        <div style="margin-top:12px"><b class="small-muted">Доступные переменные (нажмите, чтобы вставить)</b><div id="letterVariableList" class="letter-vars"></div><span class="small-muted" style="display:block;margin-top:5px">Список собран из колонок текущей книги и переменных загруженного шаблона. Неизвестные коды остаются как текст; приложение не угадывает значения.</span></div>
        <div class="settings-actions">
          <button class="btn-primary" id="saveLetterTemplateBtn" type="button">Сохранить письмо для этого шаблона</button>
          <button class="btn-ghost" id="copyLetterDefaultsBtn" type="button" hidden>Скопировать общие настройки</button>
          <span class="small-muted" id="letterFeedback" aria-live="polite"></span>
        </div>
      </div>
    </details>
  </section>

  <section class="toolbar">
    <button class="btn-primary" id="generateBtn" disabled>Сформировать PDF и подготовить очередь</button>
    <button class="btn-ghost" id="checkManagersBtn" type="button" disabled>Сверить руководителей по ИНН</button>
    <button class="btn-ghost" id="refreshFromDadataBtn" type="button" disabled>Обновить через DaData</button>
    <button class="btn-ghost" id="selectFilteredBtn" type="button">Выбрать все по фильтру</button>
    <button class="btn-ghost" id="clearSelectionBtn" type="button">Снять выбор</button>
    <div class="search-box"><input type="search" id="searchInput" placeholder="Поиск по региону, ФИО, ИНН…" aria-label="Поиск по записям"></div>
    <div class="stats" id="statsBar"><span>В выборке <b id="filteredCount">0</b></span><span class="green">Отмечено <b id="selectedCount">0</b></span><span class="small-muted" id="pageSelectionHint"></span></div>
  </section>

  <div class="progress-wrap" id="progressWrap"><div class="progress-main"><span class="spinner"></span><span id="progressText">Формируем PDF и DOCX…</span></div><div class="mini-log" id="progressLog" aria-live="polite"></div></div>
  <div class="progress-wrap" id="managerCheckProgress"><span class="spinner"></span><span id="managerCheckProgressText">Сверяем данные DaData…</span><button class="btn-ghost btn-small" type="button" id="cancelManagerCheckBtn">Остановить после текущего пакета</button></div>
  <div class="progress-wrap" id="dadataRefreshProgress"><span class="spinner"></span><span id="dadataRefreshProgressText">Загружаем данные DaData…</span><button class="btn-ghost btn-small" type="button" id="cancelDadataRefreshBtn">Остановить после текущего пакета</button></div>
  <div class="download-result" id="downloadResult" hidden></div>
  <section class="settings-card" id="sendPolicyCard">
    <details>
      <summary><strong>Отправка без блокировок: лимиты mail.ru и поведение «как вручную»</strong><span class="settings-state">важно перед рассылкой</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>🛡️</span><div>Официальные письма по одному адресату отправляются через ваш SMTP-ящик. Почтовый сервис оценивает не только текст, но и темп: ровный поток «письмо в минуту круглосуточно» похож на рассылку. Ниже — что выставлено в приложении и что стоит проверить на стороне mail.ru.</div></div>
        <div class="table-tools-grid">
          <section class="table-tool-box">
            <h3>Что уже делает приложение</h3>
            <ul class="small-muted" style="margin:0;padding-left:18px;line-height:1.6">
              <li><b>Темп.</b> Отправка не чаще 1 письма в минуту на ящик (максимум 6), между письмами добавляется случайная пауза — поток не выглядит ровным.</li>
              <li><b>Окно и дни.</b> Отправка по расписанию идёт только в рабочие часы (по умолчанию 09:00–18:00), по выходным можно отключить совсем.</li>
              <li><b>Дневной лимит.</b> Свой потолок на ящик; в менеджере очереди видно «отправлено сегодня из N».</li>
              <li><b>Реакция на лимит.</b> На ответ сервиса «слишком часто» (421 4.7.0) ящик ставится на паузу 30 минут, письмо остаётся в расписании, а не помечается ошибкой.</li>
              <li><b>Оформление.</b> У письма есть текстовая версия, папка «Отправленные» на сервисе показывает его как обычное письмо, а не рассылку.</li>
            </ul>
          </section>
          <section class="table-tool-box">
            <h3>Что настроить у mail.ru</h3>
            <ul class="small-muted" style="margin:0;padding-left:18px;line-height:1.6">
              <li><b>Отправляйте с корпоративного домена</b> (например, <code>@ваш-домен.ru</code>), а не с личного <code>@mail.ru</code>: у домена выше лимиты и есть Postmaster.</li>
              <li><b>SPF, DKIM и DMARC</b> в DNS домена обязательны, DKIM включён в почтовом сервисе. Без них письма фильтруются ещё до чтения.</li>
              <li><b>PTR (обратная зона)</b> IP вашего релея должна совпадать с именем сервера — это проверяют при доставке.</li>
              <li><b>Не превышайте 30 адресатов</b> в одном письме (официальный лимит mail.ru) и не делайте «скрытую копию» на десятки адресов.</li>
              <li><b>Прогрев.</b> Новый ящик/домен: 10–20 писем в день в первую неделю, дальше +15–20% в день, а не сразу вся пачка.</li>
              <li><b>Postmaster.</b> Зарегистрируйте домен и следите за жалобами и попаданиями в спам.</li>
            </ul>
          </section>
        </div>
        <div class="notice" style="margin-top:14px"><span>✅</span><div><b>Что показал тест mail-tester (10/10).</b> SPF, DKIM (2048), DMARC (p=quarantine), rDNS и 20 черных списков — чисто, письмо не спам. В отчёте остались только подсказки, которые к официальной переписке не относятся: нет HTML-версии (у вас письмо с HTML-дизайном — включите его во вкладке «HTML-дизайн письма»), нет изображений и нет <code>List-Unsubscribe</code>. Заголовок отписки нужен только для массовых рассылок: у официального письма отписки нет, и добавлять её не следует. В шапке отчёта исправлена одна реальная вещь — HELO теперь отправляется доменом отправителя (<code>cmkcenter.ru</code>), а не именем контейнера <code>docgen</code>: SpamAssassin больше не отмечает <code>SPF_HELO_NONE</code>.</div></div>
        <div class="notice warn" style="margin-top:14px"><span>⚠️</span><div>Официальный лимит mail.ru для одного письма — <b>не более 30 получателей</b>. Суточные цифры сервис не публикует. При ошибке <code>421 4.7.0 ratelimit exceeded</code> подождите 15–60 минут и продолжайте меньшими порциями: это временное ограничение скорости, а не отказ в доступе. Для действительно больших объёмов используйте отдельный сервис рассылок — личный ящик для этого не предназначен.</div></div>
        <div class="small-muted" style="margin-top:10px">Лимиты и окно задаются в <button type="button" class="link-button" data-open-manager>менеджере очереди</button> → «Настройки рассылки».</div>
      </div>
    </details>
  </section>
  <section class="settings-card" id="smtpCard">
    <details id="smtpDetails">
      <summary><strong>Настройки SMTP</strong><span class="settings-state" id="smtpState">Не настроено</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>🔒</span><div>Пароль сохраняется вне web-root в приватном каталоге приложения. После сохранения он не отображается; пустое поле пароля оставляет прежний секрет без изменений. Для порта 465 нужно шифрование SSL/TLS, для 587 — STARTTLS; при смене порта шифрование подставится автоматически.</div></div>
        <div class="settings-grid">
          <div class="field"><label for="smtpAccountSelect">SMTP-ящик</label><select id="smtpAccountSelect"></select></div>
          <div class="field"><label for="smtpAccountLabel">Название ящика</label><input id="smtpAccountLabel" type="text" placeholder="Например, Basic для писем" autocomplete="off"></div>
          <div class="field span2"><div class="settings-actions" style="margin:0"><button class="btn-ghost btn-small" id="addSmtpAccountBtn" type="button">+ Добавить ящик</button><button class="btn-ghost btn-small" id="deleteSmtpAccountBtn" type="button">Удалить выбранный</button><span class="small-muted" id="smtpAccountsHint">При формировании очереди можно выбрать, из какого ящика отправлять письма.</span></div></div>
          <div class="field"><label for="smtpHost">SMTP-сервер</label><input id="smtpHost" type="text" placeholder="smtp.example.org" autocomplete="off"></div>
          <div class="field"><label for="smtpPort">Порт</label><input id="smtpPort" type="number" min="1" max="65535" value="587"></div>
          <div class="field"><label for="smtpEncryption">Шифрование</label><select id="smtpEncryption"><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option><option value="none">Без шифрования</option></select></div>
          <div class="field"><label for="smtpUsername">Логин</label><input id="smtpUsername" type="text" autocomplete="username"></div>
          <div class="field"><label for="smtpPassword">Пароль SMTP <span id="smtpPasswordSaved" class="small-muted"></span></label><input id="smtpPassword" type="password" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></div>
          <div class="field"><label for="smtpFromEmail">Email отправителя</label><input id="smtpFromEmail" type="email" autocomplete="email"></div>
          <div class="field"><label for="smtpFromName">Имя отправителя</label><input id="smtpFromName" type="text" autocomplete="organization"></div>
          <div class="field"><label for="smtpReplyTo">Reply-To (необязательно)</label><input id="smtpReplyTo" type="email"></div>
        </div>
        <div class="help" style="margin:9px 0 0">Тема, текст письма, получатели и имя вложения настраиваются выше в блоке «Шаблон письма».</div>
        <div class="settings-actions"><button class="btn-primary" id="saveSmtpBtn" type="button">Сохранить настройки</button><button class="btn-ghost" id="testSmtpBtn" type="button">Проверить SMTP</button><label class="small-muted"><input id="clearSmtpPassword" type="checkbox"> Удалить сохранённый пароль</label><span class="small-muted" id="smtpFeedback"></span></div>
      </div>
    </details>
  </section>
  <section class="settings-card" id="dadataCard">
    <details id="dadataDetails">
      <summary><strong>Сверка руководителей · DaData</strong><span class="settings-state bad" id="dadataState">API-ключ не задан</span></summary>
      <div class="settings-body">
        <div class="notice" style="margin:0 0 14px"><span>🔐</span><div>Вставьте API-ключ DaData. Он хранится только в приватном каталоге вне web-root, маскируется в интерфейсе и не отправляется браузеру при проверке. Запросы выполняются только после ручного запуска для отмеченных записей; используются ИНН и, если есть, КПП. Если по ИНН найдено несколько филиалов, без КПП приложение не выберет первый наугад и пометит результат неоднозначным. Результат сравнивается, но данные карточки автоматически не заменяются. Сравнение игнорирует регистр, пробелы и знаки препинания; инициалы и сокращения требуют ручной проверки. <a href="https://dadata.ru/api/find-party/" target="_blank" rel="noopener">Документация API «Организация по ИНН»</a>.</div></div>
        <div class="settings-grid">
          <div class="field span2"><label for="dadataToken">API-ключ / Token DaData <span id="dadataTokenSaved" class="small-muted"></span></label><input id="dadataToken" type="password" autocomplete="new-password" placeholder="Вставьте API-ключ; после сохранения значение будет скрыто"></div>
        </div>
        <div class="settings-actions"><button class="btn-primary" id="saveDadataBtn" type="button">Сохранить API-ключ</button><label class="small-muted"><input id="clearDadataToken" type="checkbox"> Удалить сохранённый ключ</label><span class="small-muted" id="dadataFeedback"></span></div>
      </div>
    </details>
  </section>
  <div class="legend"><span class="badge active">Действует</span><span class="badge inactive">Не действует</span><span class="badge archive">Архивная запись</span><span class="manager-check-badge match">ФИО совпало</span><span class="manager-check-badge mismatch">ФИО отличается</span><span>Результаты DaData подсвечиваются в таблице; переданные сведения проверяйте вручную.</span></div>

  <section class="table-section">
    <div class="table-wrap" id="tableWrap" hidden>
      <table id="recordsTable">
        <thead id="tableHead"></thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
    <div class="empty-state" id="emptyState"><div class="big">Загружаем адресатов…</div><div>Записи будут показаны после чтения демо-данных.</div></div>
    <div class="table-foot" id="tableFoot" hidden>
      <span id="footShown"></span>
      <span class="pager"><button type="button" class="btn-ghost btn-small" id="prevPage">← Назад</button><span id="pageLabel"></span><button type="button" class="btn-ghost btn-small" id="nextPage">Далее →</button></span>
      <span>Можно отметить строки вручную или выбрать все записи, подходящие под фильтр.</span>
    </div>
  </section>
  <section class="queue-section" id="emailQueue">
    <div class="queue-head"><div><h2>Очередь писем</h2><p>Здесь только пачки, ожидающие ручной проверки. Сначала формируется очередь писем, потом — на этом экране — выбирается ящик, из которого пачка пойдёт: откройте аккордеон, проверьте письма, выберите отправителя и передайте пачку в ручную очередь или, с явным разрешением, в расписание. Остальные пачки — в <button type="button" class="link-button" data-open-manager>менеджере очереди</button>.</p></div><div class="queue-tools"><span class="pill" id="queueCount">Очередь загружается…</span><button type="button" class="btn-ghost btn-small" data-open-manager>Менеджер очереди</button><button type="button" class="btn-ghost btn-small" id="refreshQueueBtn">Обновить очередь</button></div></div>
    <div id="queueList" class="queue-list"><div class="empty-state">Загружаем очередь…</div></div>
  </section>
</main>
<div class="modal-backdrop" id="recordModal" hidden>
  <section class="modal record-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <div><h2 id="modalTitle">Карточка адресата</h2><div class="modal-subtitle" id="modalRecordLabel"></div></div>
      <button type="button" class="modal-close" id="modalCloseBtn" aria-label="Закрыть карточку">×</button>
    </div>
    <div class="modal-main">
      <div class="modal-hint">Проверьте исходные сведения. Формы ФИО и должности ниже рассчитаны автоматически; они являются предложением, а не юридической проверкой. Дательный падеж для шаблона можно отредактировать вручную или применить кнопку «Подставить». Если «Полное наименование для письма» не заполнено, при формировании используется «Наименование по книге / ЕГРЮЛ»; данные DaData для этого поля не подставляются.</div>
      <div id="modalCoreFields"></div>
      <section class="modal-section">
        <h3>Результат ручной сверки DaData</h3>
        <div id="managerCheckDetail" class="manager-check-detail"><span class="small-muted">Сверка ещё не запускалась. Автоматических запросов к DaData нет.</span></div>
      </section>
      <section class="modal-section">
        <h3>Автоматическое склонение для проверки</h3>
        <div class="inflection-card">
          <div class="inflection-head"><strong>ФИО</strong><span class="small-muted">Здесь показаны все падежи, кроме дательного. Дательный подставляется в «ФИО ДП» и используется для обращения ниже.</span></div>
          <div class="case-grid" id="nameForms"><span class="small-muted">Введите ФИО для расчёта форм.</span></div>
          <div class="inflection-actions"><button type="button" class="auto-button" id="applyNameDative">Подставить дательный в «ФИО ДП»</button><span class="small-muted" id="nameInflectNote"></span></div>
        </div>
        <div class="inflection-card">
          <div class="inflection-head"><strong>Должность для обращения</strong><span class="small-muted">Показан только дательный падеж: он подставляется в поле должности. Составные наименования проверьте вручную.</span></div>
          <div class="case-grid" id="jobForms"><span class="small-muted">Введите должность для расчёта форм.</span></div>
          <div class="inflection-actions"><button type="button" class="auto-button" id="applyJobDative">Подставить дательный в поле должности</button><span class="small-muted" id="jobInflectNote"></span></div>
        </div>
      </section>
      <details class="modal-details" id="modalMoreDetails">
        <summary>Дополнительные поля книги и переменные шаблона</summary>
        <div id="modalExtraFields"></div>
      </details>
    </div>
    <div class="modal-actions">
      <span class="small-muted">Правки сохраняются в демо-копии и используются при формировании документов.</span>
      <div class="right"><button type="button" class="btn-ghost" id="modalCancelBtn">Отмена</button><button type="button" class="btn-primary" id="saveCardBtn">Сохранить карточку</button></div>
    </div>
  </section>
</div>
<div class="modal-backdrop" id="dadataRefreshModal" hidden>
  <section class="modal" role="dialog" aria-modal="true" aria-labelledby="dadataRefreshTitle">
    <div class="modal-head">
      <div><h2 id="dadataRefreshTitle">Обновление через DaData</h2><div class="modal-subtitle" id="dadataRefreshSubtitle"></div></div>
      <button type="button" class="modal-close" id="dadataRefreshClose" aria-label="Закрыть окно обновления">×</button>
    </div>
    <div class="modal-main">
      <div class="notice" style="margin-bottom:14px"><span>🔎</span><div>Данные загружены, но <b>ничего не сохранено</b>. Выберите, какие сведения DaData сопоставить с существующими колонками книги, либо создайте новую колонку. Например: «Юридический адрес» → «Юридический адрес», «ИНН» → «ИНН». Поле «Полное наименование» заполняется вручную; если оно пустое, при формировании берётся «Наименование по книге / ЕГРЮЛ». После сопоставления проверьте список изменений и только потом нажмите «Сохранить карточки».</div></div>
      <div id="dadataRefreshSummary" class="small-muted" style="margin-bottom:15px"></div>
      <section class="modal-section">
        <h3>1. Сопоставление полей DaData с колонками книги</h3>
        <div id="dadataMappingList"></div>
      </section>
      <section class="modal-section">
        <h3>2. Список новых данных по отмеченным организациям</h3>
        <div id="dadataSourceList" style="margin-bottom:12px"></div>
        <h3 style="margin-top:12px">Изменения, которые будут сохранены</h3>
        <div id="dadataPreviewList"></div>
      </section>
    </div>
    <div class="modal-actions">
      <span class="small-muted" id="dadataRefreshStatus">Черновик: карточки ещё не изменены.</span>
      <div class="right">
        <button type="button" class="btn-ghost" id="dadataRefreshCancelBtn">Отмена</button>
        <button type="button" class="btn-ghost" id="applyDadataMappingBtn">Сопоставить и показать изменения</button>
        <button type="button" class="btn-primary" id="saveDadataCardsBtn" disabled>Сохранить карточки</button>
      </div>
    </div>
  </section>
</div>
<div class="modal-backdrop" id="backupImportModal" hidden>
  <section class="modal" role="dialog" aria-modal="true" aria-labelledby="backupImportTitle">
    <div class="modal-head"><div><h2 id="backupImportTitle">Восстановление из ZIP</h2><div class="modal-subtitle" id="backupImportSubtitle"></div></div><button type="button" class="modal-close" id="backupImportClose" aria-label="Закрыть окно восстановления">×</button></div>
    <div class="modal-main">
      <div class="notice" style="margin-bottom:14px"><span>⚠️</span><div>Будут заменены перечисленные файлы конфигурации и базы. Текущее состояние будет сохранено в отдельный ZIP перед заменой. Пароль администратора не меняется.</div></div>
      <div id="backupImportList"></div>
    </div>
    <div class="modal-actions"><span class="small-muted" id="backupImportModalFeedback">Ничего не меняется до подтверждения.</span><div class="right"><button type="button" class="btn-ghost" id="backupImportCancelBtn">Отмена</button><button type="button" class="btn-primary" id="confirmBackupImportBtn">Восстановить выбранные файлы</button></div></div>
  </section>
</div>
<div class="modal-backdrop" id="templateManagerModal" hidden>
  <section class="modal template-manager-modal" role="dialog" aria-modal="true" aria-labelledby="templateManagerTitle">
    <div class="modal-head"><div><h2 id="templateManagerTitle">Менеджер шаблонов</h2><div class="modal-subtitle">Переименуйте шаблон, сохраните копию под своим именем, замените DOCX-файл или удалите шаблон. У каждого шаблона своё письмо и HTML-дизайн.</div></div><button type="button" class="modal-close" id="templateManagerClose" aria-label="Закрыть менеджер шаблонов">×</button></div>
    <div class="modal-main">
      <div class="notice" style="margin:0 0 14px"><span>🗂️</span><div>Название — это ваша подпись шаблона, например «Вебинар 11.12.2028 · тарифы». Исходный файл при переименовании не меняется. При замене DOCX-файла письмо, получатели и HTML-дизайн этого шаблона сохраняются.</div></div>
      <div id="templateManagerList" class="template-manager-list"></div>
      <div class="template-manager-new">
        <div class="field" style="margin:0;flex:1 1 260px;min-width:0"><label for="templateManagerNewName">Новый шаблон: своё имя</label><input id="templateManagerNewName" type="text" maxlength="180" autocomplete="off" placeholder="Например: Вебинар 2029 · вода"></div>
        <div class="template-name-actions">
          <button type="button" class="btn-ghost btn-small" id="templateManagerUploadBtn">Загрузить DOCX и сохранить</button>
          <button type="button" class="btn-ghost btn-small" id="templateManagerHtmlBtn">Новый HTML-шаблон</button>
        </div>
      </div>
      <input type="file" id="templateManagerUploadInput" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden>
      <input type="file" id="templateManagerReplaceInput" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden>
    </div>
    <div class="modal-actions"><span class="small-muted" id="templateManagerFeedback" aria-live="polite">Действия применяются только к выбранной строке.</span><div class="right"><button type="button" class="btn-ghost" id="templateManagerDone">Закрыть</button></div></div>
  </section>
</div>
<div class="modal-backdrop" id="htmlTemplateModal" hidden>
  <section class="modal html-editor-modal" role="dialog" aria-modal="true" aria-labelledby="htmlEditorTitle">
    <div class="modal-head"><div><h2 id="htmlEditorTitle">HTML-редактор шаблона</h2><div class="modal-subtitle">Коды полей пишите в фигурных скобках, например {Регион} или {ФИО ДП}. Итог будет сформирован в PDF.</div></div><button type="button" class="modal-close" id="htmlEditorClose" aria-label="Закрыть редактор">×</button></div>
    <div class="modal-main html-editor-grid">
      <div><label class="editor-label" for="htmlTemplateName">Название шаблона</label><input id="htmlTemplateName" class="editor-name" type="text" maxlength="180" placeholder="Например: Письмо-уведомление"><label class="editor-label" for="htmlTemplateContent">HTML + CSS</label><textarea id="htmlTemplateContent" spellcheck="false" class="html-code-editor" aria-label="Код HTML-шаблона"></textarea><div class="small-muted">Для печати используйте CSS @page и обычные HTML-стили. Внешние скрипты и сетевые ресурсы удаляются перед PDF-конвертацией.</div></div>
      <div class="preview-column"><div class="editor-label">Предпросмотр разметки (без данных адресата)</div><iframe id="htmlPreviewFrame" title="Предпросмотр HTML-шаблона" sandbox></iframe></div>
    </div>
    <div class="modal-actions"><span id="htmlTemplateFeedback" class="small-muted"></span><div class="right"><button type="button" class="btn-ghost" id="htmlEditorCancel">Отмена</button><button type="button" class="btn-primary" id="saveHtmlTemplateBtn">Сохранить шаблон</button></div></div>
  </section>
</div>
<div class="modal-backdrop" id="excelImportModal" hidden>
  <section class="modal" role="dialog" aria-modal="true" aria-labelledby="excelImportTitle">
    <div class="modal-head"><div><h2 id="excelImportTitle">Предварительный просмотр импорта</h2><div class="modal-subtitle" id="excelImportSubtitle"></div></div><button type="button" class="modal-close" id="excelImportClose" aria-label="Закрыть предварительный просмотр">×</button></div>
    <div class="modal-main">
      <div class="notice"><span>⚠️</span><div>Существующие записи обновляются только при единственном совпадении по ID записи или точной паре ИНН/КПП. Неоднозначные строки будут пропущены. Формулы берутся из сохранённого значения файла; Excel-формулы на сервере не пересчитываются.</div></div>
      <div id="excelImportPreviewContent"></div>
      <label class="small-muted excel-clear-empty"><input id="excelClearEmpty" type="checkbox"> Разрешить очистку существующих значений пустыми ячейками (по умолчанию выключено)</label>
    </div>
    <div class="modal-actions"><span class="small-muted" id="excelImportModalFeedback">До подтверждения таблицы не изменяются; перед записью создаётся приватная резервная копия.</span><div class="right"><button type="button" class="btn-ghost" id="excelImportCancel">Отмена</button><button type="button" class="btn-primary" id="confirmExcelImportBtn">Подтвердить импорт</button></div></div>
  </section>
</div>
<div class="modal-backdrop" id="queueManagerModal" hidden>
  <section class="modal manager-modal" role="dialog" aria-modal="true" aria-labelledby="queueManagerModalTitle">
    <div class="modal-head"><div><h2 id="queueManagerModalTitle">Менеджер очереди писем</h2><div class="modal-subtitle">Пачки в расписании и ручной очереди, лимиты SMTP-ящиков и ошибки отправки</div></div><button type="button" class="modal-close" id="queueManagerModalClose" aria-label="Закрыть менеджер очереди">×</button></div>
    <div class="modal-main"><iframe id="queueManagerFrame" title="Менеджер очереди писем"></iframe></div>
  </section>
</div>
<div class="modal-backdrop" id="smtpLogModal" hidden>
  <section class="modal" role="dialog" aria-modal="true" aria-labelledby="smtpLogTitle">
    <div class="modal-head"><div><h2 id="smtpLogTitle">Журнал проверки SMTP</h2><div class="modal-subtitle" id="smtpLogSubtitle"></div></div><button type="button" class="modal-close" id="smtpLogClose" aria-label="Закрыть журнал проверки">×</button></div>
    <div class="modal-main">
      <div class="smtp-log-summary"><div class="smtp-log-head"><span class="smtp-log-badge" id="smtpLogBadge">Проверка</span><strong id="smtpLogHeadline"></strong></div><p class="smtp-log-hint" id="smtpLogHint"></p></div>
      <ul class="smtp-log-steps" id="smtpLogSteps"></ul>
      <div id="smtpLogPortsBox" hidden>
        <div class="small-muted" style="margin:0 0 5px">Какие порты реально открываются из этого контейнера</div>
        <ul class="smtp-log-steps" id="smtpLogPorts"></ul>
      </div>
      <div class="small-muted" style="margin:0 0 5px">Полный диалог с сервером (пароль скрыт)</div>
      <pre class="smtp-log-transcript" id="smtpLogTranscript"></pre>
      <dl class="smtp-log-context" id="smtpLogContext"></dl>
    </div>
    <div class="modal-actions"><span class="small-muted" id="smtpLogFeedback">Скопируйте журнал, если нужно показать администратору.</span><div class="right"><button type="button" class="btn-ghost" id="smtpLogCopyBtn">Скопировать журнал</button><button type="button" class="btn-primary" id="smtpLogCloseBtn">Закрыть</button></div></div>
  </section>
</div>
<div class="modal-backdrop" id="queueModal" hidden>
  <section class="modal queue-modal" role="dialog" aria-modal="true" aria-labelledby="queueModalTitle">
    <div class="modal-head"><div><h2 id="queueModalTitle">Проверка письма</h2><div class="modal-subtitle" id="queueItemLabel"></div></div><button type="button" class="modal-close" id="queueModalClose" aria-label="Закрыть предпросмотр">×</button></div>
    <div class="modal-main queue-review-main">
      <div class="queue-preview-toolbar"><a id="queuePdfDownload" class="link-button" href="#" target="_blank" rel="noopener">Открыть PDF отдельно</a><a id="queueDocxDownload" class="link-button" href="#" target="_blank" rel="noopener" hidden>Скачать DOCX</a><span class="small-muted" id="queueItemStatus"></span></div>
      <div class="queue-preview-switch" role="tablist" aria-label="Что проверяем в письме">
        <button type="button" class="link-button queue-preview-tab active" data-queue-preview="pdf" role="tab" aria-selected="true">PDF-вложение</button>
        <button type="button" class="link-button queue-preview-tab" data-queue-preview="design" role="tab" aria-selected="false" id="queueDesignTab" hidden>HTML-дизайн письма</button>
        <button type="button" class="link-button queue-preview-tab" data-queue-preview="text" role="tab" aria-selected="false" id="queueTextTab">Текстовая версия</button>
        <span class="small-muted" id="queuePreviewHint">Проверьте вложение и обе версии письма (multipart/alternative).</span>
      </div>
      <iframe id="queuePreviewFrame" title="Предпросмотр письма" class="pdf-preview-frame"></iframe>
      <pre id="queueTextPreview" class="queue-text-preview" hidden></pre>
      <div class="queue-edit-grid">
        <div class="modal-field"><label for="queueTo">Кому (основной адрес)</label><textarea id="queueTo" rows="2" placeholder="name@example.org"></textarea></div>
        <div class="modal-field"><label for="queueCc">Копия (дополнительные/старые email)</label><textarea id="queueCc" rows="2" placeholder="copy@example.org"></textarea><span id="queueEmailSource" class="small-muted"></span></div>
        <div class="modal-field span2"><label for="queueSubject">Тема</label><input id="queueSubject" type="text"></div>
        <div class="modal-field span2"><label for="queueBody">Текстовая версия письма (multipart/alternative)</label><textarea id="queueBody" rows="5"></textarea><span class="queue-text-hint warn" id="queueTextHint" hidden>Для письма с HTML-дизайном текстовая версия обязательна. Без неё отправка и автоотправка блокируются — заполните её здесь или в блоке «Шаблон письма».</span></div>
      </div>
      <div class="review-confirm"><label><input id="queueReviewedCheck" type="checkbox"> Я проверил(а) PDF, адресата, CC, тему, HTML-дизайн и текстовую версию этого письма.</label><span class="small-muted">Отправка выполнится только для этого одного письма.</span></div>
    </div>
    <div class="modal-actions"><span class="small-muted" id="queueActionFeedback">Письмо ещё не отправлено.</span><div class="right"><button type="button" class="btn-ghost" id="queueModalCancel">Закрыть</button><button type="button" class="btn-ghost" id="saveQueueItemBtn">Сохранить правки</button><button type="button" class="btn-primary" id="sendQueueItemBtn" disabled>Отправить это письмо</button></div></div>
  </section>
</div>
<footer class="footer">Локальное тестовое приложение · XLSX, PHPWord, Morphos, PHPMailer и LibreOffice · письма проверяются пачками; отправка по умолчанию ручная, авто — только после явного разрешения.</footer>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>
'use strict';
const SAMPLE_TEMPLATE_INFO = <?= json_encode($sampleInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const CSRF_TOKEN = <?= json_encode(docgenCsrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PAGE_SIZE = 30;
let dataset = {records: [], sheets: [], demoOverrides: {}, managerChecks: {}};
let templateInfo = SAMPLE_TEMPLATE_INFO;
let templateMode = 'saved';
let templateId = 'sample-docx';
let templateFile = null;
let templateLibrary = [];
let editingHtmlTemplateId = '';
let queueItems = [];
let reviewBatches = [];
let activeQueueItemId = '';
let activeQueuePreview = 'pdf';
let smtpPasswordConfigured = false;
let smtpAccounts = [];
let smtpAccountId = '';
let letterSettings = null;
let letterOwn = false;
let letterTemplateKey = '';
let letterActiveField = '';
let letterDesignMode = 'main';
let letterSampleData = null;
const LETTER_FIELDS = {
  letterFilenamePattern: 'filenamePattern', letterToPattern: 'toPattern', letterCcPattern: 'ccPattern',
  letterSubjectPattern: 'subjectPattern', letterBodyPattern: 'bodyPattern',
  letterHtmlPattern: 'htmlPattern', letterHtmlTextPattern: 'htmlTextPattern'
};
const LETTER_HTML_IDS = ['letterHtmlPattern','letterBodyPattern','letterHtmlTextPattern'];
let dadataConfigured = false;
let managerChecks = {};
let managerCheckRunning = false;
let managerCheckCancelRequested = false;
let dadataRefreshRunning = false;
let dadataRefreshCancelRequested = false;
let dadataRefreshData = null;
let dadataPreviewEdits = null;
let dadataPreviewNewFields = [];
let pendingExcelImportId = '';
let pendingBackupImportId = '';
let selectedIds = new Set();
let overrides = {};
let pageNumber = 1;
let toastTimer = null;
let activeModalRecordId = '';
let latestInflections = null;
let inflectTimer = null;
let inflectRequestId = 0;
let derivedManual = {nameDative:false, jobDative:false, initials:false, nameParts:false, salutation:false};
let modalSourceEdited = {name:false,job:false};
const MODAL_CORE_GROUPS = [
  {title:'Адресат и обращение', fields:['Регион','Наименование (ЕГРЮЛ/лист)','Полное наименование','ФИО','ФИО ДП','Фамилия И.О.','Имя Отчество','Обращение']},
  {title:'Должность', fields:['Должность','Должность руководителя','Должность руководителя дательный']},
  {title:'Реквизиты и связь', fields:['ИНН','КПП','ОГРН','Юридический адрес','Основной email','Доп./старые email','Официальный сайт']},
];
const CASE_LABELS_CHECK = [
  ['nominative','Именительный'],['genitive','Родительный'],
  ['accusative','Винительный'],['ablative','Творительный'],['prepositional','Предложный']
];
const CASE_LABELS_DATIVE = [['dative','Дательный']];
const $ = (id) => document.getElementById(id);
const sourceSelect = $('sourceSelect');
const regionSelect = $('regionSelect');
const tableBody = $('tableBody');
const templateInput = $('templateInput');
const templateSelect = $('templateSelect');
function csrfHeaders() { return {'X-CSRF-Token': CSRF_TOKEN}; }
async function requestJson(url, options, timeoutMs) {
  const controller=new AbortController();
  const timer=setTimeout(()=>controller.abort(),timeoutMs);
  try {
    const response=await fetch(url,{...options,signal:controller.signal});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось выполнить запрос.');
    return payload;
  } catch(e) {
    if(e.name==='AbortError')throw new Error(`Сервер не ответил за ${Math.round(timeoutMs/1000)} с. Проверьте доступность приложения и SMTP-сервера.`);
    throw e;
  } finally { clearTimeout(timer); }
}

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}
const toastQueue=[];
function showToast(text, kind='') {
  const el=$('toast');
  toastQueue.push({text:String(text||''),kind:kind||''});
  // A save can produce two messages (result + notice): show them in turn
  // instead of the second one silently replacing the first.
  if(el.className.includes('show'))return;
  const next=()=>{const item=toastQueue.shift();if(!item)return;el.textContent=item.text;el.className='toast show '+item.kind;clearTimeout(toastTimer);toastTimer=setTimeout(()=>{el.className='toast';setTimeout(next,220);},item.kind==='info'?7000:5500);};
  next();
}
function renderExcelImportPreview(payload) {
  const target=$('excelImportPreviewContent');
  $('excelImportSubtitle').textContent=`${payload.filename||'Книга Excel'} · ${Number(payload.totalRows||0)} строк данных · ${payload.sheets.length} листов`;
  const sectionOptions=payload.sections.map(section=>`<option value="${esc(section.key)}">В существующий раздел: ${esc(section.label)}</option>`).join('');
  target.innerHTML=payload.sheets.map(sheet=>{
    const headers=sheet.headers||[];const sampleHeaders=headers.slice(0,8);
    const sampleRows=(sheet.sample||[]).map(row=>`<tr>${sampleHeaders.map(header=>`<td title="${esc(row[header]||'')}">${esc(row[header]||'')}</td>`).join('')}</tr>`).join('');
    const plan=sheet.plan||{};
    const options=`<option value="new">Создать новый раздел «${esc(sheet.name)}»</option>${sectionOptions}`;
    return `<article class="excel-sheet-preview"><div class="excel-sheet-preview-head"><div><h3>Лист: ${esc(sheet.name)}</h3><div class="excel-headers">Колонки: ${headers.length?headers.map(esc).join(' · '):'не найдены'}</div></div><select data-excel-target="${esc(sheet.name)}" aria-label="Раздел для листа ${esc(sheet.name)}">${options}</select></div><div class="excel-import-counts"><span>${Number(sheet.rowCount||0)} строк</span><span class="update">Обновить: ${Number(plan.update||0)}</span><span class="insert">Добавить: ${Number(plan.insert||0)}</span><span class="skip">Неоднозначно: ${Number(plan.ambiguous||0)}</span><span class="skip">Дубликаты: ${Number(plan.duplicate||0)}</span><span>Пустые: ${Number(plan.empty||0)}</span>${sheet.formulaCount?`<span>Формулы: ${Number(sheet.formulaCount)} · импортируются сохранённые значения</span>`:''}</div>${sampleHeaders.length&&sampleRows?`<div class="excel-sample-wrap"><table class="excel-sample"><thead><tr>${sampleHeaders.map(header=>`<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${sampleRows}</tbody></table></div>`:''}</article>`;
  }).join('');
  target.querySelectorAll('[data-excel-target]').forEach(select=>{
    const sheet=payload.sheets.find(item=>item.name===select.dataset.excelTarget);
    select.value=sheet?.suggestedTarget||'new';
  });
  $('excelImportModal').hidden=false;document.body.classList.add('modal-open');
}
function closeExcelImportModal() {
  $('excelImportModal').hidden=true;document.body.classList.remove('modal-open');pendingExcelImportId='';
}
async function previewExcelImport() {
  const file=$('excelImportFile').files?.[0];
  if(!file){showToast('Выберите книгу Excel в формате .xlsx.','error');return;}
  const button=$('previewExcelImportBtn');button.disabled=true;button.textContent='Проверяем книгу…';
  $('excelImportFeedback').textContent='Файл передаётся в приватное хранилище для предварительной проверки.';
  const form=new FormData();form.append('excel_file',file);
  try{
    const response=await fetch('?action=preview_excel_import',{method:'POST',headers:csrfHeaders(),body:form});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось прочитать книгу Excel.');
    pendingExcelImportId=payload.importId;renderExcelImportPreview(payload);
    $('excelImportFeedback').textContent='Предварительный просмотр готов. Ничего не сохранено до подтверждения.';
  }catch(error){$('excelImportFeedback').textContent=error.message||'Ошибка проверки книги.';showToast(error.message||'Ошибка проверки книги.','error');}
  finally{button.disabled=false;button.textContent='Загрузить и проверить';}
}
async function confirmExcelImport() {
  if(!pendingExcelImportId)return;
  const targetMap={};document.querySelectorAll('[data-excel-target]').forEach(select=>{targetMap[select.dataset.excelTarget]=select.value;});
  const clearEmpty=$('excelClearEmpty').checked;
  if(!window.confirm(`Подтвердить импорт? Совпавшие записи будут обновлены, новые строки добавлены. Пустые ячейки ${clearEmpty?'очистят':'не изменят'} существующие значения. Неоднозначные строки будут пропущены.`))return;
  const button=$('confirmExcelImportBtn');button.disabled=true;button.textContent='Импортируем…';
  const form=new FormData();form.append('import_id',pendingExcelImportId);form.append('target_map',JSON.stringify(targetMap));form.append('clear_empty',clearEmpty?'1':'0');
  try{
    const response=await fetch('?action=commit_excel_import',{method:'POST',headers:csrfHeaders(),body:form});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Импорт Excel не выполнен.');
    closeExcelImportModal();$('excelImportFile').value='';$('excelClearEmpty').checked=false;
    const skipped=Object.values(payload.skipped||{}).reduce((sum,value)=>sum+Number(value||0),0);
    $('excelImportFeedback').textContent=`Добавлено ${payload.created}, обновлено ${payload.updated}, пропущено ${skipped}. Резервная копия: ${payload.backupId||'не требовалась'}.`;
    const preferred=sourceSelect.value;
    await reloadDatasetAfterTableChange(preferred);
    showToast(`Excel импортирован: добавлено ${payload.created}, обновлено ${payload.updated}, пропущено ${skipped}.`,'success');
    $('tableStructureFeedback').textContent=payload.message||'Импорт завершён.';
  }catch(error){$('excelImportModalFeedback').textContent=error.message||'Ошибка импорта.';showToast(error.message||'Ошибка импорта Excel.','error');}
  finally{button.disabled=false;button.textContent='Подтвердить импорт';}
}
async function exportExcel() {
  const scope=$('excelExportScope').value;
  const ids=scope==='all'?[]:(scope==='filtered'?getFilteredRecords().map(record=>record.id):[...selectedIds]);
  if(scope!=='all'&&!ids.length){showToast(scope==='selected'?'Отметьте строки для экспорта.':'По текущему фильтру нет записей.','error');return;}
  const button=$('exportExcelBtn');button.disabled=true;button.textContent='Готовим XLSX…';
  const form=new FormData();form.append('scope',scope==='all'?'all':'subset');if(scope!=='all')form.append('record_ids',JSON.stringify(ids));
  try{
    const response=await fetch('?action=export_excel',{method:'POST',headers:csrfHeaders(),body:form});
    if(!response.ok){let message='Не удалось сформировать Excel.';try{const body=await response.json();message=body.error||message;}catch{}throw new Error(message);}
    const blob=await response.blob();const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download='Выгрузка_таблиц.xlsx';document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    showToast(`Книга XLSX сформирована: ${ids.length||dataset.records.length} записей.`,'success');
  }catch(error){showToast(error.message||'Ошибка экспорта Excel.','error');}
  finally{button.disabled=false;button.textContent='Скачать книгу XLSX';}
}
function formatBackupSize(bytes) {
  const value=Number(bytes||0);
  if(value<1024)return `${value} Б`;
  if(value<1024*1024)return `${(value/1024).toFixed(1)} КБ`;
  return `${(value/1024/1024).toFixed(1)} МБ`;
}
function closeBackupImportModal() {
  $('backupImportModal').hidden=true;document.body.classList.remove('modal-open');pendingBackupImportId='';
}
async function exportConfigBackup() {
  const button=$('backupExportBtn'),oldText=button.textContent;
  button.disabled=true;button.textContent='Собираем архив…';
  const fd=new FormData();if($('backupIncludeSecrets').checked)fd.append('include_secrets','1');
  try{
    const response=await fetch('?action=backup_export',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось собрать архив.');
    $('backupExportFeedback').innerHTML=`Готово: ${payload.fileCount} файлов. <a href="${esc(payload.downloadUrl)}" download="${esc(payload.filename||'docgen-backup.zip')}">Скачать ZIP</a> (ссылка действует 24 часа).`;
    showToast('Архив конфигурации и базы готов.','success');
  }catch(e){$('backupExportFeedback').textContent=e.message||'Ошибка выгрузки.';showToast(e.message||'Ошибка выгрузки.','error');}
  finally{button.disabled=false;button.textContent=oldText;}
}
async function previewConfigBackup() {
  const input=$('backupImportFile'),file=input.files?.[0];
  if(!file){showToast('Выберите ZIP-файл резервной копии.','error');return;}
  const button=$('backupPreviewBtn'),oldText=button.textContent;
  button.disabled=true;button.textContent='Проверяем архив…';
  const fd=new FormData();fd.append('backup_file',file);
  try{
    const response=await fetch('?action=backup_preview',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось проверить архив.');
    pendingBackupImportId=payload.importId;
    $('backupImportSubtitle').textContent=`Архив от ${payload.createdAt||'неизвестной даты'} · файлов: ${(payload.entries||[]).length}${payload.includeSecrets?' · с секретами':''}`;
    $('backupImportList').innerHTML=(payload.entries||[]).map(entry=>`<div class="dadata-preview-change"><b>${esc(entry.label||entry.name)}</b><div class="old">${esc(entry.name)} · ${formatBackupSize(entry.size)}${entry.replace?' · будет заменён':' · будет создан'}</div>${entry.secret?'<div class="new">Секрет: будет восстановлен с правами 0600</div>':''}</div>`).join('')||'<span class="small-muted">В архиве нет файлов для восстановления.</span>';
    $('backupImportModalFeedback').textContent='Ничего не изменено до подтверждения.';
    $('backupImportModal').hidden=false;document.body.classList.add('modal-open');
  }catch(e){$('backupImportFeedback').textContent=e.message||'Ошибка проверки архива.';showToast(e.message||'Ошибка проверки архива.','error');}
  finally{button.disabled=false;button.textContent=oldText;input.value='';}
}
async function confirmConfigBackupImport() {
  if(!pendingBackupImportId)return;
  if(!window.confirm('Заменить конфигурацию и базу данными из архива? Перед заменой будет создан ZIP текущего состояния.'))return;
  const button=$('confirmBackupImportBtn'),oldText=button.textContent;
  button.disabled=true;button.textContent='Восстанавливаем…';
  const fd=new FormData();fd.append('import_id',pendingBackupImportId);fd.append('confirm','1');
  try{
    const response=await fetch('?action=backup_import',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось восстановить архив.');
    closeBackupImportModal();
    $('backupImportFeedback').innerHTML=`Восстановлено файлов: ${payload.restored}. <a href="${esc(payload.safetyDownloadUrl)}" download="${esc(payload.safetyFilename||'backup.zip')}">Резервная копия перед заменой</a>. Обновите страницу.`;
    showToast('Конфигурация и база восстановлены. Обновите страницу.','success');
  }catch(e){$('backupImportModalFeedback').textContent=e.message||'Ошибка восстановления.';showToast(e.message||'Ошибка восстановления.','error');}
  finally{button.disabled=false;button.textContent=oldText;}
}

async function tableToolPost(action,values) {
  const form=new FormData();Object.entries(values).forEach(([key,value])=>form.append(key,String(value)));
  const response=await fetch(`?action=${action}`,{method:'POST',headers:csrfHeaders(),body:form});
  const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.error||'Действие не выполнено.');return payload;
}
async function reloadDatasetAfterTableChange(preferredSource='all') {
  const response=await fetch('?action=data',{cache:'no-store'});if(!response.ok)throw new Error('Не удалось обновить таблицу после изменения структуры.');
  dataset=await response.json();managerChecks=dataset.managerChecks||{};selectedIds.clear();overrides={};populateSources();
  if(preferredSource&&[...sourceSelect.options].some(option=>option.value===preferredSource))sourceSelect.value=preferredSource;
  updateRegionOptions(false);updateMapping();renderTable();
  $('dbBadge').textContent=`${dataset.records.length.toLocaleString('ru-RU')} записей · демо-копия`;
}
async function addTableColumn() {
  const input=$('newTableColumn'),name=input.value.trim();if(!name){input.focus();showToast('Введите название новой колонки.','error');return;}
  try{const payload=await tableToolPost('add_table_column',{name});input.value='';await reloadDatasetAfterTableChange(sourceSelect.value);$('tableStructureFeedback').textContent=payload.message;showToast(`Добавлена общая колонка «${payload.column}».`,'success');}
  catch(error){showToast(error.message||'Не удалось добавить колонку.','error');}
}
async function addTableSection() {
  const input=$('newTableSection'),name=input.value.trim();if(!name){input.focus();showToast('Введите название нового раздела.','error');return;}
  try{const payload=await tableToolPost('add_table_section',{name});input.value='';await reloadDatasetAfterTableChange(payload.section.key);$('tableStructureFeedback').textContent=payload.message;showToast(`Добавлен раздел «${payload.section.label}».`,'success');}
  catch(error){showToast(error.message||'Не удалось добавить раздел.','error');}
}
function fieldValue(record, name) {
  return record && record.fields && record.fields[name] !== undefined ? String(record.fields[name] ?? '') : '';
}
function normalizeManagerValue(value) {
  return String(value??'').normalize('NFKC').toLocaleLowerCase('ru-RU').replace(/ё/g,'е').replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
}
function localManagerValues(record) {
  const name=fieldValue(record,'Руководитель').trim()||fieldValue(record,'ФИО').trim();
  const position=fieldValue(record,'Должность руководителя').trim()||fieldValue(record,'Должность').trim();
  const rawKpp=fieldValue(record,'КПП').replace(/\D/g,'');
  return {name,position,inn:fieldValue(record,'ИНН').replace(/\D/g,''),kpp:/^\d{9}$/.test(rawKpp)?rawKpp:''};
}
function getManagerCheck(record) {
  const saved=managerChecks?.[record?.id];
  if(!saved)return null;
  const current=localManagerValues(record);
  const savedKpp=String(saved.kpp||'');
  if(current.inn!==String(saved.inn||'') || current.kpp!==savedKpp || normalizeManagerValue(current.name)!==normalizeManagerValue(saved.localName||'') || normalizeManagerValue(current.position)!==normalizeManagerValue(saved.localPosition||'')) {
    return {...saved,status:'stale',message:'Вы изменили карточку после сверки — это нормально. Старый результат показан для сравнения; чтобы получить актуальный, отметьте запись и снова нажмите «Сверить руководителей по ИНН».'};
  }
  return saved;
}
function managerCheckMeta(check) {
  const states={
    match:['ФИО совпадает','match'],name_mismatch:['ФИО отличается','mismatch'],both_mismatch:['ФИО и должность отличаются','mismatch'],
    position_mismatch:['Должность отличается','position'],local_missing:['Нет ФИО в базе','neutral'],no_manager:['DaData без руководителя','neutral'],
    not_found:['ИНН/КПП не найдено','neutral'],ambiguous:['Неоднозначно · нужен КПП','neutral'],invalid_inn:['Некорректный ИНН','neutral'],error:['Ошибка DaData','neutral'],stale:['Нужна повторная сверка','stale']
  };
  return check ? (states[check.status]||['Неизвестный статус','neutral']) : ['Не проверялось','neutral'];
}
function managerCheckBadgeMarkup(check) {
  const [label,kind]=managerCheckMeta(check);
  const title=check?.message||'Сверка DaData ещё не запускалась.';
  return `<span class="manager-check-badge ${kind}" title="${esc(title)}">${esc(label)}</span>`;
}
function renderManagerCheckDetail(record) {
  const target=$('managerCheckDetail');
  const check=getManagerCheck(record);
  if(!check){
    target.className='manager-check-detail';
    target.innerHTML='<span class="small-muted">Сверка ещё не запускалась. Автоматических запросов к DaData нет.</span>';
    return;
  }
  const statusClass=['match','name_mismatch','both_mismatch','position_mismatch'].includes(check.status)?check.status:'';
  let checkedAt=String(check.checkedAt||'');
  if(checkedAt){const date=new Date(checkedAt);if(!Number.isNaN(date.getTime()))checkedAt=date.toLocaleString('ru-RU');}
  target.className=`manager-check-detail ${statusClass}`;
  const startDate=check.managerStartDate?`<div class="manager-check-value"><b>Дата назначения на должность · DaData</b>${esc(check.managerStartDate)}</div>`:'';
  const canApplyName=['name_mismatch','both_mismatch','local_missing'].includes(check.status)&&check.managerName;
  const applyName=canApplyName?`<div class="manager-check-value"><b>Сменился руководитель</b><button type="button" class="auto-button btn-small" data-apply-manager-name="${esc(check.managerName)}">Подставить ФИО из DaData</button><span class="small-muted" style="display:block;margin-top:4px">Подставит именительный падеж в карточку; после проверки сохраните её вручную.</span></div>`:'';
  target.innerHTML=`<div>${managerCheckBadgeMarkup(check)} <span class="small-muted">Проверено: ${esc(checkedAt||'—')}</span></div><div class="manager-check-values"><div class="manager-check-value"><b>ФИО руководителя · в книге</b>${esc(check.localName||'—')}</div><div class="manager-check-value"><b>ФИО руководителя · DaData</b>${esc(check.managerName||'—')}</div><div class="manager-check-value"><b>Должность · в книге</b>${esc(check.localPosition||'—')}</div><div class="manager-check-value"><b>Должность · DaData</b>${esc(check.managerPost||'—')}</div>${startDate}<div class="manager-check-value"><b>Организация DaData · ИНН/КПП</b>${esc(check.organizationName||'—')} · ${esc(check.inn||'—')}${check.kpp?` / ${esc(check.kpp)}`:''}</div>${applyName}</div><div class="small-muted" style="display:block;margin-top:8px">${esc(check.message||'')}</div>`;
}
function modalFieldLabel(name) {
  const labels={
    'Регион':'Регион',
    'Наименование (ЕГРЮЛ/лист)':'Наименование по книге / ЕГРЮЛ',
    'Полное наименование':'Полное наименование для письма',
    'ФИО':'ФИО адресата — именительный падеж',
    'ФИО ДП':'ФИО адресата — дательный падеж (ФИО ДП)',
    'Фамилия И.О.':'Фамилия и инициалы',
    'Имя Отчество':'Имя и отчество для обращения',
    'Обращение':'Обращение',
    'Должность':'Должность для обращения',
    'Должность руководителя':'Полная должность руководителя из книги',
    'Должность руководителя дательный':'Должность для письма — дательный падеж',
    'Юридический адрес':'Юридический адрес',
    'Основной email':'Основной email (получатель)',
    'Доп./старые email':'Дополнительные/старые email (копия CC)',
    'Официальный сайт':'Официальный сайт'
  };
  return labels[name]||name;
}
function modalFieldMarkup(name, value) {
  const longField=['Полное наименование','Наименование (ЕГРЮЛ/лист)','Должность руководителя','Юридический адрес','Источник и примечания','Доп./старые email'].includes(name) || String(value??'').length>110;
  const field=longField
    ? `<textarea data-modal-field="${esc(name)}" aria-label="${esc(modalFieldLabel(name))}">${esc(value)}</textarea>`
    : `<input type="text" data-modal-field="${esc(name)}" aria-label="${esc(modalFieldLabel(name))}" value="${esc(value)}" placeholder="Введите значение">`;
  return `<div class="modal-field${longField?' wide':''}"><label title="${esc(modalFieldLabel(name))}">${esc(modalFieldLabel(name))}</label>${field}</div>`;
}
function renderRecordFields(values) {
  const coreNames=new Set(MODAL_CORE_GROUPS.flatMap(group=>group.fields));
  $('modalCoreFields').innerHTML=MODAL_CORE_GROUPS.map(group=>`<section class="modal-section"><h3>${esc(group.title)}</h3><div class="modal-fields">${group.fields.map(name=>modalFieldMarkup(name,values[name]??'')).join('')}</div></section>`).join('');
  const extraNames=[...new Set([...(templateInfo.fields||[]),...Object.keys(values)])].filter(name=>!coreNames.has(name));
  $('modalExtraFields').innerHTML=extraNames.length
    ? `<div class="modal-fields">${extraNames.map(name=>modalFieldMarkup(name,values[name]??'')).join('')}</div>`
    : '<span class="small-muted">Дополнительных полей нет.</span>';
}
function modalInput(name) {
  return [...document.querySelectorAll('[data-modal-field]')].find(input=>input.dataset.modalField===name)||null;
}
function readModalFields() {
  const values={};
  document.querySelectorAll('[data-modal-field]').forEach(input=>{values[input.dataset.modalField]=input.value;});
  return values;
}
function setModalField(name, value) {
  const input=modalInput(name);
  if (input) input.value=String(value??'');
}
function renderCaseGrid(targetId, forms, emptyText, labels=CASE_LABELS_CHECK) {
  const target=$(targetId);
  if (!forms || !Object.keys(forms).length) {
    target.innerHTML=`<span class="small-muted">${esc(emptyText)}</span>`;
    return;
  }
  target.innerHTML=labels.map(([key,label])=>`<div class="case-item"><b>${esc(label)}</b><span>${esc(forms[key]||'—')}</span></div>`).join('');
}
function initialsFromDative(fullName, dativeName) {
  const source=String(fullName||'').trim().split(/\s+/u).filter(Boolean);
  const dative=String(dativeName||'').trim().split(/\s+/u).filter(Boolean);
  if (!dative.length) return '';
  const initials=source.slice(1).map(part=>`${Array.from(part)[0]||''}.`).join('');
  return dative[0]+(initials?' '+initials:'');
}
function renderRecipientInflections(result, applyChangedFields=false) {
  latestInflections=result;
  renderCaseGrid('nameForms',result.nameCases,'Введите ФИО (именительный падеж), чтобы увидеть формы.',CASE_LABELS_CHECK);
  renderCaseGrid('jobForms',result.jobCases,'Введите должность, чтобы увидеть формы.',CASE_LABELS_DATIVE);
  const genderNote=result.gender==='female'?'Пол определён как женский · Уважаемая,':(result.gender==='male'?'Пол определён как мужской · Уважаемый,':'Пол по ФИО не определён — задайте «Обращение» вручную.');
  $('nameInflectNote').textContent=result.nameError|| genderNote;
  $('jobInflectNote').textContent=result.jobRecognized?'Форма должности предложена автоматически; проверьте сложное наименование.':'Автоматически распознать должность не удалось — заполните дательный падеж вручную.';
  $('applyNameDative').disabled=!result.nameCases?.dative;
  $('applyJobDative').disabled=!result.jobRecognized||!result.jobCases?.dative;
  if (!derivedManual.nameDative && result.nameCases?.dative && !modalInput('ФИО ДП')?.value.trim()) setModalField('ФИО ДП',result.nameCases.dative);
  if (!derivedManual.jobDative && result.jobRecognized && !modalInput('Должность руководителя дательный')?.value.trim()) setModalField('Должность руководителя дательный',result.jobCases.dative);
  if (!derivedManual.initials && result.nameCases?.dative && !modalInput('Фамилия И.О.')?.value.trim()) {
    const fullName=modalInput('ФИО')?.value.trim()||modalInput('Руководитель')?.value.trim()||'';
    setModalField('Фамилия И.О.',initialsFromDative(fullName,result.nameCases.dative));
  }
  if (!derivedManual.nameParts && !modalInput('Имя Отчество')?.value.trim()) {
    const fullName=modalInput('ФИО')?.value.trim()||modalInput('Руководитель')?.value.trim()||'';
    const parts=fullName.split(/\s+/u).filter(Boolean);
    if(parts.length>=2) setModalField('Имя Отчество',parts.slice(1).join(' '));
  }
  if (!derivedManual.salutation && result.salutation) setModalField('Обращение',result.salutation);
  if (applyChangedFields) {
    const fullName=modalInput('ФИО')?.value.trim()||modalInput('Руководитель')?.value.trim()||'';
    if (modalSourceEdited.name && !derivedManual.nameDative && result.nameCases?.dative) setModalField('ФИО ДП',result.nameCases.dative);
    if (modalSourceEdited.name && !derivedManual.initials && result.nameCases?.dative) setModalField('Фамилия И.О.',initialsFromDative(fullName,result.nameCases.dative));
    if (modalSourceEdited.name && !derivedManual.nameParts) {
      const parts=fullName.split(/\s+/u).filter(Boolean);
      if (parts.length>=2) setModalField('Имя Отчество',parts.slice(1).join(' '));
    }
    if (modalSourceEdited.name && !derivedManual.salutation && result.salutation) setModalField('Обращение',result.salutation);
    if (modalSourceEdited.job && !derivedManual.jobDative && result.jobRecognized) setModalField('Должность руководителя дательный',result.jobCases.dative);
  }
}
function updateInflections(sourceKind='') {
  if (!activeModalRecordId) return;
  if (sourceKind) modalSourceEdited[sourceKind]=true;
  clearTimeout(inflectTimer);
  const requestId=++inflectRequestId;
  const fullName=modalInput('ФИО')?.value.trim()||modalInput('Руководитель')?.value.trim()||'';
  const jobTitle=modalInput('Должность')?.value.trim()||modalInput('Должность руководителя')?.value.trim()||'';
  if (!fullName && !jobTitle) {
    latestInflections=null;
    renderCaseGrid('nameForms',null,'Введите ФИО (именительный падеж), чтобы увидеть формы.',CASE_LABELS_CHECK);
    renderCaseGrid('jobForms',null,'Введите должность, чтобы увидеть формы.',CASE_LABELS_DATIVE);
    $('nameInflectNote').textContent=''; $('jobInflectNote').textContent='';
    $('applyNameDative').disabled=true; $('applyJobDative').disabled=true;
    return;
  }
  inflectTimer=setTimeout(async()=>{
    const fd=new FormData(); fd.append('full_name',fullName); fd.append('job_title',jobTitle);
    try {
      const response=await fetch('?action=inflect',{method:'POST',headers:csrfHeaders(),body:fd});
      const result=await response.json();
      if (!response.ok||!result.ok||requestId!==inflectRequestId) return;
      renderRecipientInflections(result,Boolean(sourceKind||modalSourceEdited.name||modalSourceEdited.job));
    } catch(e) {
      if(requestId===inflectRequestId){$('nameInflectNote').textContent='Не удалось получить автоматические формы.';$('jobInflectNote').textContent='Проверьте поля вручную.';}
    }
  },sourceKind?220:0);
}
function openRecordCard(id) {
  const record=dataset.records.find(item=>item.id===id);
  if(!record) return;
  activeModalRecordId=id;
  const values={...(record.fields||{}),...(overrides[id]||{})};
  for(const group of MODAL_CORE_GROUPS) for(const name of group.fields) if(values[name]===undefined) values[name]='';
  for(const name of (templateInfo.fields||[])) if(values[name]===undefined) values[name]=(dataset.demoOverrides?.[id]||{})[name]||'';
  renderRecordFields(values);
  renderManagerCheckDetail(record);
  $('modalTitle').textContent='Карточка адресата';
  $('modalRecordLabel').textContent=[fieldValue(record,'Регион'),fieldValue(record,'ФИО')||fieldValue(record,'Руководитель'),record.source].filter(Boolean).join(' · ');
  $('modalMoreDetails').open=false;
  derivedManual={nameDative:false,jobDative:false,initials:false,nameParts:false,salutation:false};
  modalSourceEdited={name:false,job:false};
  latestInflections=null;
  $('recordModal').hidden=false;
  document.body.classList.add('modal-open');
  $('modalCloseBtn').focus();
  document.querySelectorAll('[data-modal-field]').forEach(input=>input.addEventListener('input',()=>{
    const name=input.dataset.modalField;
    if(name==='ФИО' || (name==='Руководитель'&&!modalInput('ФИО')?.value.trim())) updateInflections('name');
    else if(name==='Должность' || (name==='Должность руководителя'&&!modalInput('Должность')?.value.trim())) updateInflections('job');
    else if(name==='ФИО ДП') derivedManual.nameDative=true;
    else if(name==='Должность руководителя дательный') derivedManual.jobDative=true;
    else if(name==='Фамилия И.О.') derivedManual.initials=true;
    else if(name==='Имя Отчество') derivedManual.nameParts=true;
    else if(name==='Обращение') derivedManual.salutation=true;
  }));
  updateInflections();
}
function closeRecordCard() {
  $('recordModal').hidden=true;
  document.body.classList.remove('modal-open');
  activeModalRecordId='';
  inflectRequestId++; clearTimeout(inflectTimer);
}
async function saveRecordCard() {
  if(!activeModalRecordId) return;
  const id=activeModalRecordId;
  const values=readModalFields();
  const button=$('saveCardBtn'),old=button.textContent;
  button.disabled=true;button.textContent='Сохраняем…';
  const fd=new FormData();fd.append('id',id);fd.append('fields',JSON.stringify(values));
  try {
    const response=await fetch('?action=save_record',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось сохранить карточку.');
    const index=dataset.records.findIndex(item=>item.id===id);
    if(index>=0) dataset.records[index]=payload.record;
    delete overrides[id];
    closeRecordCard();updateMapping();renderTable();
    showToast('Карточка сохранена. Эти данные будут использованы при формировании PDF и DOCX.','success');
  } catch(e) {
    showToast(e.message||'Ошибка сохранения карточки.','error');
  } finally {
    button.disabled=false;button.textContent=old;
  }
}
function activeSourceRecords() {
  const key=sourceSelect.value;
  return dataset.records.filter(r => key === 'all' || r.source_key === key);
}
function updateRegionOptions(keepCurrent=true) {
  const old=keepCurrent ? regionSelect.value : '';
  const records=activeSourceRecords();
  const regions=[...new Set(records.map(r=>fieldValue(r,'Регион')).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru'));
  regionSelect.innerHTML='<option value="all">Все регионы</option>'+regions.map(r=>`<option value="${esc(r)}">${esc(r)}</option>`).join('');
  if (regions.includes(old)) regionSelect.value=old;
  else if (sourceSelect.value==='guber' && regions.includes('Астраханская область')) regionSelect.value='Астраханская область';
  else regionSelect.value='all';
}
function getFilteredRecords() {
  const source=sourceSelect.value;
  const region=regionSelect.value;
  const query=$('searchInput').value.trim().toLocaleLowerCase('ru');
  return dataset.records.filter(r=>{
    if (source!=='all' && r.source_key!==source) return false;
    if (region!=='all' && fieldValue(r,'Регион')!==region) return false;
    if (query) {
      const f=r.fields||{};
      const hay=[r.source, ...Object.values(f)].join(' ').toLocaleLowerCase('ru');
      if (!hay.includes(query)) return false;
    }
    return true;
  });
}
function getUnmappedFields() {
  const records=activeSourceRecords();
  if (!records.length) return [];
  return (templateInfo.fields||[]).filter(name=>records.some(record=>!fieldValue(record,name).trim()));
}
function updateMapping() {
  const fields=templateInfo.fields||[];
  renderLetterVariables();
  $('syntaxLabel').textContent=templateInfo.syntaxLabel ? '· '+templateInfo.syntaxLabel : '';
  if (!fields.length) {
    $('mappingBody').innerHTML='<span class="pill bad">Переменные в шаблоне не найдены</span>';
    return;
  }
  const records=activeSourceRecords();
  const counts=fields.map(name=>records.filter(record=>fieldValue(record,name).trim()).length);
  $('mappingBody').innerHTML=fields.map((name,index)=>{
    const count=counts[index], complete=records.length>0&&count===records.length;
    const state=complete?'колонка книги':(count?`частично · ${count}/${records.length}`:'ручное поле');
    return `<span class="map-chip ${complete?'':'manual'}"><b>${esc(name)}</b><span class="state">${state}</span></span>`;
  }).join('')+`<span class="small-muted" style="align-self:center">${fields.length} полей · ${counts.filter((count,index)=>records.length>0&&count===records.length).length} полностью сопоставлено</span>`;
  if (counts.some(count=>count<records.length)) {
    $('mappingBody').insertAdjacentHTML('beforeend','<span class="small-muted" style="width:100%;color:#8b5b00">Незаполненные значения нужно проверить в карточке записи или задать в янтарных ячейках. Неизвестные данные автоматически не угадываются.</span>');
  }
}
function statusBadge(record) {
  if (record.kind==='Архив') return '<span class="badge archive">Архив</span>';
  if (String(record.kind||'').toLocaleLowerCase('ru').includes('пользовательский')) return '<span class="badge custom-record">Добавлена вручную</span>';
  const s=fieldValue(record,'Статус ЕГРЮЛ').toLocaleLowerCase('ru');
  if (s.includes('не действ')) return '<span class="badge inactive">Не действует</span>';
  return '<span class="badge active">Действует</span>';
}
function updateManagerCheckButton() {
  const button=$('checkManagersBtn');
  if(!button)return;
  button.disabled=selectedIds.size===0||!dadataConfigured||managerCheckRunning;
  button.textContent=managerCheckRunning?'Сверка DaData…':`Сверить руководителей по ИНН${selectedIds.size?` (${selectedIds.size})`:''}`;
  button.title=!dadataConfigured?'Сначала сохраните API-ключ DaData в настройках.':'Проверка выполняется только вручную для отмеченных записей.';
  updateDadataRefreshButton();
}
function renderTable() {
  if (!dataset.records.length) return;
  const filtered=getFilteredRecords();
  const pageCount=Math.max(1,Math.ceil(filtered.length/PAGE_SIZE));
  pageNumber=Math.min(pageNumber,pageCount);
  const start=(pageNumber-1)*PAGE_SIZE;
  const pageRows=filtered.slice(start,start+PAGE_SIZE);
  const unknownFields=getUnmappedFields();
  const customColumns=(dataset.customColumns||[]).filter(name=>!unknownFields.includes(name));
  const headers=`<tr><th><input class="check" type="checkbox" id="checkPage" title="Выбрать ${pageRows.length} записей только на этой странице; всего в выборке ${filtered.length}"></th><th>Регион</th><th>Адресат / ФИО руководителя</th><th>Должность</th><th>ИНН</th><th>Сверка DaData</th><th>Статус</th><th>Карточка</th>${customColumns.map(name=>`<th>${esc(name)}</th>`).join('')}${unknownFields.map(f=>`<th>Ручное поле: ${esc(f)}</th>`).join('')}</tr>`;
  $('tableHead').innerHTML=headers;
  tableBody.innerHTML=pageRows.map(r=>{
    const f=r.fields||{}; const id=r.id;
    const person=fieldValue(r,'Руководитель')||fieldValue(r,'ФИО')||'—';
    const position=fieldValue(r,'Должность руководителя')||fieldValue(r,'Должность')||'—';
    const region=fieldValue(r,'Регион')||'—';
    const inn=fieldValue(r,'ИНН')||'—';
    const managerCheck=getManagerCheck(r);
    const nameMismatch=['name_mismatch','both_mismatch'].includes(managerCheck?.status);
    const positionMismatch=['position_mismatch','both_mismatch'].includes(managerCheck?.status);
    const rowClasses=[selectedIds.has(id)?'selected':'',nameMismatch?'manager-name-mismatch':'',positionMismatch?'manager-position-mismatch':''].filter(Boolean).join(' ');
    const check=selectedIds.has(id)?'checked':'';
    const nameTitle=nameMismatch?`DaData: ${managerCheck.managerName||'ФИО не получено'}`:'';
    const nameNote=nameMismatch?`<span class="manager-name-note">DaData: ${esc(managerCheck.managerName||'не указано')}</span>`:'';
    const positionNote=positionMismatch?`<span class="manager-position-note">DaData: ${esc(managerCheck.managerPost||'не указана')}</span>`:'';
    const extraColumns=customColumns.map(name=>`<td title="${esc(fieldValue(r,name))}">${esc(fieldValue(r,name)||'—')}</td>`).join('');
    const manual=unknownFields.map(name=>{
      const preset=(dataset.demoOverrides?.[id]||{})[name]??'';
      const current=(overrides[id]||{})[name]??(fieldValue(r,name)||preset);
      const bookName=fieldValue(r,'Наименование (ЕГРЮЛ/лист)');
      const fallback=name==='Полное наименование'&&bookName?` · пустое значение → ${bookName}`:'';
      const sourceNote=id==='guber-0037' && name==='Полное наименование' ? ' · из PDF-примера' : '';
      const placeholder=name==='Полное наименование'&&bookName?bookName:'Введите значение';
      return `<td><input class="manual-input" type="text" data-record-id="${esc(id)}" data-manual-field="${esc(name)}" value="${esc(current)}" placeholder="${esc(placeholder)}" title="Нет одноимённой колонки; задайте значение явно${esc(fallback)}${esc(sourceNote)}"></td>`;
    }).join('');
    return `<tr class="${rowClasses}" data-id="${esc(id)}"><td><input class="check row-check" type="checkbox" data-id="${esc(id)}" ${check} aria-label="Выбрать ${esc(person)}"></td><td><div class="cell-main">${esc(region)}</div><div class="cell-sub">${esc(r.source)}</div></td><td><div class="cell-main"${nameTitle?` title="${esc(nameTitle)}"`:''}>${esc(person)}</div>${nameNote}<div class="cell-sub">${esc(fieldValue(r,'Фамилия И.О.'))}</div></td><td>${esc(position)}${positionNote}</td><td>${esc(inn)}</td><td>${managerCheckBadgeMarkup(managerCheck)}</td><td>${statusBadge(r)}</td><td><button type="button" class="btn-ghost btn-small edit-record" data-edit-id="${esc(id)}">Карточка</button></td>${extraColumns}${manual}</tr>`;
  }).join('');
  $('tableWrap').hidden=pageRows.length===0;
  $('emptyState').hidden=pageRows.length>0;
  $('tableFoot').hidden=pageRows.length===0;
  if (pageRows.length===0) $('emptyState').innerHTML='<div class="big">По заданным условиям ничего не найдено</div><div>Измените источник, регион или поисковую строку.</div>';
  const shownStart=filtered.length?start+1:0;
  const shownEnd=Math.min(start+PAGE_SIZE,filtered.length);
  $('footShown').textContent=`Показано ${shownStart}–${shownEnd} из ${filtered.length}`;
  $('pageLabel').textContent=`Страница ${pageNumber} из ${pageCount}`;
  $('prevPage').disabled=pageNumber<=1;
  $('nextPage').disabled=pageNumber>=pageCount;
  $('filteredCount').textContent=String(filtered.length);
  $('selectedCount').textContent=String(selectedIds.size);
  $('sourceCount').textContent=`${activeSourceRecords().length.toLocaleString('ru-RU')} записей в текущем наборе`;
  $('pageSelectionHint').textContent=`Галочка в шапке — только эта страница (${pageRows.length}).`;
  $('selectFilteredBtn').textContent=`Выбрать все по фильтру (${filtered.length})`;
  $('generateBtn').disabled=selectedIds.size===0 || (templateInfo.fields||[]).length===0;
  $('generateBtn').textContent=selectedIds.size===1?'Сформировать PDF и поставить письмо в очередь':`Сформировать PDF для ${selectedIds.size} отмеченных записей и поставить в очередь`;
  updateManagerCheckButton();
  const pageAll=pageRows.length>0 && pageRows.every(r=>selectedIds.has(r.id));
  const checkPage=$('checkPage'); if(checkPage) checkPage.checked=pageAll;
  $('tableBody').querySelectorAll('.row-check').forEach(box=>box.addEventListener('change',()=>{
    const id=box.dataset.id;
    if(box.checked) selectedIds.add(id); else selectedIds.delete(id);
    renderTable();
  }));
  $('tableBody').querySelectorAll('.edit-record').forEach(button=>button.addEventListener('click',()=>openRecordCard(button.dataset.editId)));
  $('tableBody').querySelectorAll('.manual-input').forEach(input=>input.addEventListener('input',()=>{
    const id=input.dataset.recordId, field=input.dataset.manualField;
    if(!overrides[id]) overrides[id]={};
    overrides[id][field]=input.value;
    input.closest('tr')?.classList.toggle('selected',selectedIds.has(id));
  }));
  if(checkPage) checkPage.addEventListener('change',()=>{
    pageRows.forEach(r=>checkPage.checked?selectedIds.add(r.id):selectedIds.delete(r.id));
    renderTable();
    showToast(`${checkPage.checked?'Выбраны':'Сняты'} ${pageRows.length} записей на текущей странице. Всего в выборке ${filtered.length}; для всех нажмите «Выбрать все по фильтру».`);
  });
}
function populateSources() {
  const options=['<option value="all">Все наборы</option>'];
  for (const sheet of dataset.sheets) {
    const archive=sheet.kind==='Архив'?' · архив':'';
    options.push(`<option value="${esc(sheet.key)}">${esc(sheet.label+archive)} (${sheet.count})</option>`);
  }
  sourceSelect.innerHTML=options.join('');
  sourceSelect.value='all';
  updateRegionOptions(false);
}
function applyTemplateLibrary(templates) {
  if(!Array.isArray(templates))return;
  templateLibrary=templates;
  if(!templateLibrary.some(item=>item.id==='sample-docx'))templateLibrary.unshift({id:'sample-docx',name:'Письмо-пример.docx',type:'docx'});
  renderTemplateOptions();
  if(templateId)templateSelect.value=templateId;
  syncTemplateNameInput();
}
function renderTemplateOptions() {
  templateSelect.innerHTML=templateLibrary.map(item=>`<option value="${esc(item.id)}">${esc(item.name)} · ${item.type==='html'?'HTML → PDF':'DOCX → PDF'}</option>`).join('');
}
function setTemplateInfo(info, mode='saved', file=null, id='') {
  templateInfo=info; templateMode=mode; templateFile=file;
  if (mode==='upload') {
    templateId='__upload__';
    let option=templateSelect.querySelector('option[value="__upload__"]');
    if(!option){option=document.createElement('option');option.value='__upload__';templateSelect.appendChild(option);}
    option.textContent=`Загружен на этот запуск · ${file?.name||'DOCX'}`;
    templateSelect.value='__upload__';
    $('templateStatus').className='pill ok';
    $('templateStatus').innerHTML=`<span class="file-name">${esc(file?.name||'Загруженный DOCX')}</span>`;
  } else {
    templateId=id||info.templateId||'sample-docx';
    templateSelect.querySelector('option[value="__upload__"]')?.remove();
    templateSelect.value=templateId;
    const selected=templateLibrary.find(item=>item.id===templateId);
    $('templateStatus').className='pill ok';
    $('templateStatus').innerHTML=`<span class="file-name">${esc(selected?.name||info.templateName||'Письмо-пример.docx')}</span>`;
  }
  $('dropTemplate').classList.add('loaded'); $('dropTemplate').classList.remove('error');
  $('saveUploadedTemplate').disabled=mode!=='upload';
  $('editHtmlTemplate').disabled=info.type!=='html' || mode==='upload';
  $('createHtmlTemplate').disabled=false;
  syncTemplateNameInput(true);
  $('templateNameFeedback').textContent='';
  // Each saved template keeps its own letter; switching templates must reload it.
  loadLetterTemplate()
    .then(()=>updateLetterNotice())
    .catch(e=>{ $('letterFeedback').textContent=e.message||'Не удалось загрузить письмо шаблона.'; });
  updateMapping(); renderTable();
}
async function loadTemplates() {
  const response=await fetch('?action=templates',{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось загрузить библиотеку шаблонов.');
  templateLibrary=payload.templates||[];
  if(!templateLibrary.some(item=>item.id==='sample-docx')) templateLibrary.unshift({id:'sample-docx',name:'Письмо-пример.docx',type:'docx'});
  renderTemplateOptions();
  setTemplateInfo(SAMPLE_TEMPLATE_INFO,'saved',null,'sample-docx');
}
async function selectTemplate(id) {
  if(id==='__upload__') return;
  if(id==='sample-docx') {
    templateInput.value='';
    setTemplateInfo(SAMPLE_TEMPLATE_INFO,'saved',null,'sample-docx');
    return;
  }
  const fd=new FormData();fd.append('template_id',id);fd.append('source_key',sourceSelect.value==='all'?'':sourceSelect.value);
  try {
    const response=await fetch('?action=analyze',{method:'POST',headers:csrfHeaders(),body:fd});
    const info=await response.json();
    if(!response.ok||!info.ok) throw new Error(info.error||'Не удалось открыть шаблон.');
    templateInput.value='';
    setTemplateInfo(info,'saved',null,id);
  } catch(e) {
    showToast(e.message||'Ошибка чтения шаблона.','error');
    templateSelect.value=templateId;
  }
}
async function analyzeUpload(file) {
  if (!file) return;
  if (!file.name.toLowerCase().endsWith('.docx')) { showToast('Выберите файл с расширением .docx.','error'); return; }
  $('templateStatus').className='pill'; $('templateStatus').textContent='Проверяем шаблон…';
  $('dropTemplate').classList.remove('loaded','error');
  const fd=new FormData(); fd.append('template',file); fd.append('source_key',sourceSelect.value==='all'?'':sourceSelect.value);
  try {
    const response=await fetch('?action=analyze',{method:'POST',headers:csrfHeaders(),body:fd});
    const info=await response.json();
    if (!response.ok || !info.ok) throw new Error(info.error||'Не удалось разобрать DOCX.');
    setTemplateInfo(info,'upload',file);
    if (!info.fields.length) showToast('В DOCX не найдены переменные для подстановки.','error');
    else showToast(`Шаблон проверен: найдено переменных ${info.fields.length}.`,'success');
  } catch(e) {
    $('templateStatus').className='pill err'; $('templateStatus').textContent='Ошибка шаблона';
    $('dropTemplate').classList.add('error');
    $('saveUploadedTemplate').disabled=true;
    showToast(e.message||'Ошибка анализа DOCX.','error');
  }
}
function resetToSample() {
  templateInput.value='';
  setTemplateInfo(SAMPLE_TEMPLATE_INFO,'saved',null,'sample-docx');
  showToast('Выбран шаблон-пример DOCX.');
}
async function saveUploadToLibrary(quiet) {
  const fd=new FormData();fd.append('type','docx');fd.append('name',templateFile.name.replace(/\.docx$/i,'')||'Шаблон DOCX');fd.append('template',templateFile);
  const response=await fetch('?action=save_template',{method:'POST',headers:csrfHeaders(),body:fd});
  const payload=await response.json();
  if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось сохранить шаблон.');
  applyTemplateLibrary(payload.templates||templateLibrary);
  templateInput.value='';
  if(!quiet) setTemplateInfo(payload.info,'saved',null,payload.template.id);
  return {id:payload.template.id,info:payload.info};
}
async function saveUploadedTemplate() {
  if(templateMode!=='upload'||!templateFile) return;
  const button=$('saveUploadedTemplate');button.disabled=true;button.textContent='Сохраняем…';
  try {
    // Carry an unsaved letter (HTML design, recipients, text) into the new
    // library entry: the document and its letter are one template.
    const pending=letterFormHasContent();
    const saved=await saveUploadToLibrary(true);
    if(pending) await saveLetterFor(saved.id);
    setTemplateInfo(saved.info,'saved',null,saved.id);
    showToast(pending?'DOCX-шаблон сохранён вместе с письмом и HTML-дизайном.':'DOCX-шаблон сохранён в библиотеку приложения.','success');
  } catch(e) { showToast(e.message||'Ошибка сохранения шаблона.','error'); }
  finally {button.textContent='Сохранить DOCX в библиотеку';button.disabled=templateMode!=='upload';}
}
const HTML_STARTER=`<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
@page { size: A4; margin: 22mm 20mm; }
body { font-family: "Liberation Serif", "Times New Roman", serif; font-size: 12pt; line-height: 1.45; color: #111; }
.recipient { text-align: right; margin-bottom: 28mm; }
.sign { margin-top: 28mm; }
</style>
</head>
<body>
<div class="recipient">{Регион}</div>
<p>{Обращение} {Имя Отчество}!</p>
<p>Текст письма. Замените этот текст и настройте оформление под свой документ.</p>
<p class="sign">{Должность руководителя дательный}<br>{ФИО ДП}</p>
</body>
</html>`;
const LETTER_DESIGN_STARTER=`<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#eef2f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.45;color:#1f2d3d;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{Регион}. Документ во вложении: краткое содержание письма для превью в почте.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f5"><tr><td align="center" style="padding:16px 12px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;"><tr><td bgcolor="#ffffff" style="border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(30,50,70,0.06);">
<div style="padding:18px 24px 12px;">
<a href="https://example.org/" style="display:inline-block;background:#e6f2f3;color:#1a6b70;font-size:12px;font-weight:600;padding:5px 12px;border-radius:8px;text-decoration:none;margin-bottom:8px;">📅 11 декабря · 10:00 МСК</a>
<h1 style="margin:0 0 8px;font-size:21px;line-height:1.25;font-weight:600;color:#0f2c3d;">Ваш заголовок — {Регион}</h1>
<p style="margin:0 0 10px;color:#1f2d3d;">Добрый день, {Имя Отчество}!</p>
<p style="margin:0 0 8px;color:#1f2d3d;">Направляем документ для «{Наименование (ЕГРЮЛ/лист)}» во вложении. Ниже — главное, что стоит посмотреть.</p>
</div>
<div style="padding:14px 24px;background:#f7fafb;">
<h2 style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#1a6b70;">Что внутри</h2>
<ul style="margin:0;padding:0 0 0 18px;color:#1f2d3d;">
<li style="margin-bottom:4px;">Инвестиционные программы и целевая амортизация</li>
<li style="margin-bottom:4px;">Требования к тарифному делу на 2028 год</li>
<li>Контрольные сроки и типичные ошибки</li>
</ul>
</div>
<div style="padding:14px 24px;text-align:center;">
<a href="https://example.org/" style="display:inline-block;background:#1a6b70;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:8px;font-weight:600;font-size:14px;">Открыть подробнее →</a>
<p style="margin:10px 0 0;font-size:12px;color:#5a6b7c;">Документ также приложен к этому письму в формате PDF.</p>
</div>
<div style="padding:12px 24px;background:#f7fafb;font-size:12px;color:#5a6b7c;text-align:center;">
Координатор: Ирина Рашидовна — <a href="mailto:kir@example.org" style="color:#1a6b70;">kir@example.org</a>
</div>
</td></tr>
<tr><td style="padding:14px 12px;font-size:11px;color:#8896a4;text-align:center;line-height:1.45;">
Вы получили письмо, так как подписаны на рассылку.<br><a href="{{UnsubscribeUrl}}" style="color:#8896a4;">Отписаться</a>
</td></tr>
</table>
</td></tr></table>
</body>
</html>`;
function previewHtml(html) {
  return String(html||'').replace(/<\s*(script|iframe|object|embed|form)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi,'').replace(/\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*')/gi,'').replace(/(src|href)\s*=\s*("|')\s*(https?:|file:|javascript:|data:|\/\/)[^"']*\2/gi,'');
}
function updateHtmlPreview() { $('htmlPreviewFrame').srcdoc=previewHtml($('htmlTemplateContent').value); }
function openHtmlEditor(name='',content=HTML_STARTER,id='') {
  editingHtmlTemplateId=id;
  $('htmlEditorTitle').textContent=id?'Редактирование HTML-шаблона':'Новый HTML-шаблон';
  $('htmlTemplateName').value=name;
  $('htmlTemplateContent').value=content;
  $('htmlTemplateFeedback').textContent='';
  $('saveHtmlTemplateBtn').textContent=id?'Сохранить изменения':'Сохранить шаблон';
  $('htmlTemplateModal').hidden=false;document.body.classList.add('modal-open');updateHtmlPreview();$('htmlTemplateName').focus();
}
async function editHtmlTemplate() {
  if(templateInfo.type!=='html'||templateMode==='upload') return;
  try {
    const response=await fetch(`?action=template_content&id=${encodeURIComponent(templateId)}`,{cache:'no-store'});
    const payload=await response.json();
    if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось загрузить HTML-шаблон.');
    openHtmlEditor(payload.name,payload.content,payload.id);
  } catch(e) { showToast(e.message||'Ошибка загрузки HTML-шаблона.','error'); }
}
function closeHtmlEditor() { $('htmlTemplateModal').hidden=true;document.body.classList.remove('modal-open'); }
async function saveHtmlTemplate() {
  const name=$('htmlTemplateName').value.trim(),content=$('htmlTemplateContent').value;
  if(!name||!content.trim()){showToast('Введите название и HTML-код шаблона.','error');return;}
  const fd=new FormData();fd.append('type','html');fd.append('name',name);fd.append('content',content);if(editingHtmlTemplateId)fd.append('id',editingHtmlTemplateId);
  const button=$('saveHtmlTemplateBtn');button.disabled=true;button.textContent='Сохраняем…';
  try {
    const response=await fetch('?action=save_template',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось сохранить HTML-шаблон.');
    applyTemplateLibrary(payload.templates||templateLibrary);
    setTemplateInfo(payload.info,'saved',null,payload.template.id);closeHtmlEditor();showToast('HTML-шаблон сохранён.','success');
  } catch(e) { $('htmlTemplateFeedback').textContent=e.message||'Ошибка сохранения.';showToast(e.message||'Ошибка сохранения шаблона.','error'); }
  finally {button.disabled=false;button.textContent=editingHtmlTemplateId?'Сохранить изменения':'Сохранить шаблон';}
}
function showDownloadResult(result) {
  const box=$('downloadResult');box.replaceChildren();
  const message=document.createElement('span');
  message.textContent=`Подготовлены PDF и очередь (${result.created||0} писем). `;
  box.append(message);
  if(result.downloadUrl){
    const link=document.createElement('a');link.href=result.downloadUrl;link.download=result.filename||'Пакет_документов.zip';link.textContent='Скачать ZIP с PDF и DOCX';link.title='Ссылка действует 24 часа';box.append(link);
  }
  const queueLink=document.createElement('a');queueLink.href='#emailQueue';queueLink.textContent='Перейти к очереди';box.append(queueLink);
  box.hidden=false;
}
function makeProgressToken() {
  const bytes=new Uint8Array(16);
  if(window.crypto?.getRandomValues)window.crypto.getRandomValues(bytes);
  else for(let i=0;i<bytes.length;i++)bytes[i]=Math.floor(Math.random()*256);
  return [...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
}
async function pollGenerateProgress(token) {
  try {
    const response=await fetch(`?action=generate_progress&token=${encodeURIComponent(token)}`,{cache:'no-store'});
    const payload=await response.json();
    const state=payload.progress||{};
    if(state.message)$('progressText').textContent=state.message;
    if(Array.isArray(state.lines)&&state.lines.length){
      const log=$('progressLog');log.textContent=state.lines.slice(-8).join('\n');log.scrollTop=log.scrollHeight;
    }
  } catch(e) { /* The mini log is advisory; generation must continue. */ }
}

async function generate() {
  if (!selectedIds.size) { showToast('Отметьте хотя бы одну запись.','error'); return; }
  const ids=[...selectedIds];
  const fd=new FormData();
  fd.append('selected_ids',JSON.stringify(ids));
  fd.append('overrides',JSON.stringify(overrides));
  if (templateMode==='upload' && templateFile) fd.append('template',templateFile);
  else fd.append('template_id',templateId);
  // A one-off DOCX keeps the letter typed in this window, design included.
  if(templateMode==='upload' && letterFormHasContent()) fd.append('letterDraft',JSON.stringify(Object.fromEntries(Object.entries(LETTER_FIELDS).map(([id,key])=>[key,$(id).value]))));
  const btn=$('generateBtn'), oldText=btn.textContent;
  const progressToken=makeProgressToken();
  fd.append('progress_token',progressToken);
  btn.disabled=true; btn.textContent='Формируем PDF…'; $('progressWrap').classList.add('show'); $('downloadResult').hidden=true;
  $('progressLog').textContent='';
  $('progressText').textContent=`Готовим PDF${templateInfo.type==='docx'?' и DOCX':''} для ${ids.length.toLocaleString('ru-RU')} выбранных записей…`;
  await pollGenerateProgress(progressToken);
  const progressTimer=setInterval(()=>pollGenerateProgress(progressToken),600);
  try {
    const response=await fetch('?action=generate',{method:'POST',headers:csrfHeaders(),body:fd});
    let payload={};try{payload=await response.json();}catch{}
    if (!response.ok || !payload.ok) {
      const details=Array.isArray(payload.details)?'\n'+payload.details.slice(0,8).join('\n'):'';
      throw new Error((payload.error||'Не удалось сформировать документы.')+details);
    }
    showDownloadResult(payload);
    await refreshQueue();
    const skipped=Number(payload.skipped||0);
    showToast(`Готово: ${payload.created||0} PDF добавлено в очередь${skipped?`; ошибок/пропусков — ${skipped}`:''}. Отправки не было.`,'success');
    if(Number(payload.htmlTextFallback||0)>0){
      showToast(`В ${payload.htmlTextFallback} письмах текстовая версия собрана из HTML-дизайна. Проверьте её в карточке письма: без текста письмо не отправить.`,'info');
    }
    $('emailQueue').scrollIntoView({behavior:'smooth',block:'start'});
  } catch(e) {
    showToast(e.message||'Ошибка формирования.','error');
  } finally {
    clearInterval(progressTimer);
    $('progressWrap').classList.remove('show'); btn.textContent=oldText; renderTable();
  }
}
function queueStatus(status) {
  const map={queued:['В ручной очереди','queued'],scheduled:['В расписании','scheduled'],paused:['На паузе','scheduled'],sent:['Отправлено','sent'],failed:['Ошибка / не отправлено','failed'],sending:['Отправка прервана или неясна','sending'],cancelled:['Отменено','cancelled']};
  return map[status]||['Статус неизвестен',''];
}
function renderQueue() {
  const list=$('queueList');
  if(!reviewBatches.length){list.innerHTML='<div class="queue-empty">Пачек на ручную проверку нет. Сформируйте документы для выбранных адресатов — новая пачка появится здесь.</div>';return;}
  list.innerHTML=reviewBatches.map(batch=>{
    const rows=queueItems.filter(item=>batch.id==='legacy'?!item.batchId:item.batchId===batch.id);
    const items=rows.map(item=>{
      const [statusLabel,css]=queueStatus(item.status);
      const letterMarks=[queueItemHasHtml(item)?'HTML':''].filter(Boolean).map(mark=>`<span class="letter-badge">${mark}</span>`).join('');
      return `<div class="batch-review-item"><span class="status-label ${css}">${statusLabel}</span><b>${esc(item.recipientName||item.recordId||'Адресат')}</b><span class="small-muted">${esc(item.to||'получатель не указан')}</span><span class="small-muted">${esc(item.subject||'')}</span>${letterMarks}<button type="button" class="btn-ghost btn-small" data-queue-open="${esc(item.id)}">Проверить PDF и письмо</button></div>`;
    }).join('');
    return `<details class="batch-review"><summary>Очередь — ${esc(batch.name||'Без названия')} — ${Number(batch.itemCount||rows.length)} писем · Тема: ${esc(batch.subject||'—')}</summary><div class="batch-review-body"><div class="small-muted">Создано: ${esc(batch.createdAt||'')} · писем в пачке: ${rows.length}${batch.smtpAccountLabel?` · ящик: ${esc(batch.smtpAccountLabel)}`:''}</div><div class="batch-account-row"><label class="small-muted" for="batch-account-${esc(batch.id)}">Из какого ящика отправлять</label><select id="batch-account-${esc(batch.id)}" data-batch-account="${esc(batch.id)}">${smtpAccounts.map(a=>`<option value="${esc(a.id)}"${a.id===batch.smtpAccountId?' selected':''}>${esc(a.label||a.fromEmail||a.id)}${a.fromEmail?' · '+esc(a.fromEmail):''}</option>`).join('')}</select><span class="small-muted">Отправитель подставляется в письма пачки в момент передачи в очередь. Так ящик можно выбрать, уже зная число писем и адресатов.</span></div><div style="margin-top:9px">${items||'<span class="small-muted">Писем нет.</span>'}</div><div class="batch-review-actions"><label><input type="checkbox" data-batch-confirm="${esc(batch.id)}"> Я проверил(а) все письма пачки</label><button type="button" class="btn-ghost btn-small" data-batch-mode="manual" data-batch-id="${esc(batch.id)}">Поставить в ручную очередь</button><button type="button" class="btn-primary btn-small" data-batch-mode="auto" data-batch-id="${esc(batch.id)}">В расписание (автоотправка)</button><span class="small-muted" data-batch-feedback="${esc(batch.id)}">Автоотправка — только после явного выбора; по умолчанию письма остаются на ручной отправке.</span></div></div></details>`;
  }).join('');
  list.querySelectorAll('[data-queue-open]').forEach(button=>button.addEventListener('click',()=>openQueueItem(button.dataset.queueOpen)));
  list.querySelectorAll('[data-batch-mode]').forEach(button=>button.addEventListener('click',()=>reviewBatch(button.dataset.batchId,button.dataset.batchMode)));
}
async function reviewBatch(batchId, mode) {
  const confirm=$('queueList').querySelector(`[data-batch-confirm="${CSS.escape(batchId)}"]`);
  const feedback=$('queueList').querySelector(`[data-batch-feedback="${CSS.escape(batchId)}"]`);
  if(!confirm?.checked){if(feedback)feedback.textContent='Сначала отметьте, что проверили все письма пачки.';showToast('Подтвердите проверку пачки.','error');return;}
  if(mode==='auto'&&!window.confirm('Разрешить автоматическую отправку этой пачки по расписанию? Письма уйдут без дополнительного подтверждения.'))return;
  if(feedback)feedback.textContent='Передаём пачку…';
  const accountSelect=$('queueList').querySelector(`[data-batch-account="${CSS.escape(batchId)}"]`);
  const accountId=accountSelect?.value||'';
  if(!accountId){if(feedback)feedback.textContent='Выберите ящик, из которого отправлять пачку.';showToast('Выберите ящик отправки.','error');return;}
  const fd=new FormData();fd.append('batch_id',batchId);fd.append('mode',mode);fd.append('confirmed','1');fd.append('smtp_account_id',accountId);
  try {
    const payload=await requestJson('?action=review_batch',{method:'POST',headers:csrfHeaders(),body:fd},15000);
    showToast((mode==='auto'?'Пачка поставлена в расписание.':'Пачка передана в ручную очередь.')+(payload.account?.label?` Ящик: ${payload.account.label}.`:''),'success');
    await refreshQueue();
  } catch(e) {
    if(feedback)feedback.textContent=e.message||'Не удалось передать пачку.';
    showToast(e.message||'Не удалось передать пачку.','error');
  }
}
async function refreshQueue() {
  const response=await fetch('?action=queue',{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось загрузить очередь.');
  queueItems=payload.items||[];
  reviewBatches=(payload.batches||[]).filter(batch=>batch.status==='review');
  const letters=reviewBatches.reduce((sum,batch)=>sum+Number(batch.itemCount||0),0);
  $('queueCount').textContent=`${reviewBatches.length} пачек на проверку · ${letters} писем`;
  $('queueCount').className='pill '+(reviewBatches.length?'warn':'ok');
  renderQueue();
}
function currentQueueItem() { return queueItems.find(item=>item.id===activeQueueItemId)||null; }
function queueItemHasHtml(item) { return Boolean(item&&String(item.bodyHtml||'').trim()); }
function queuePreviewTab(tab) {
  const item=currentQueueItem();if(!item)return;
  activeQueuePreview=tab;
  document.querySelectorAll('[data-queue-preview]').forEach(button=>{
    const active=button.dataset.queuePreview===tab;
    button.classList.toggle('active',active);button.setAttribute('aria-selected',active?'true':'false');
  });
  const frame=$('queuePreviewFrame'),text=$('queueTextPreview');
  if(tab==='text'){
    frame.hidden=true;text.hidden=false;
    text.textContent=String(item.body||'').trim()||'Текстовая версия пуста. Письмо с HTML-дизайном нельзя отправить без неё — заполните поле «Текстовая версия» ниже.';
    text.classList.toggle('empty',!String(item.body||'').trim());
  } else if(tab==='design' && queueItemHasHtml(item)) {
    frame.hidden=false;text.hidden=true;
    frame.src=`?action=queue_file&id=${encodeURIComponent(item.id)}&format=pdf&design=1&inline=1`;
  } else {
    frame.hidden=false;text.hidden=true;
    frame.src=`?action=queue_file&id=${encodeURIComponent(item.id)}&format=pdf&inline=1`;
  }
}
function updateQueueTextHint() {
  const item=currentQueueItem();if(!item)return;
  const hasHtml=queueItemHasHtml(item);
  const hasText=String(item.body||'').trim()!=='';
  const hint=$('queueTextHint');
  hint.hidden=!(hasHtml&&!hasText);
  $('queueDesignTab').hidden=!hasHtml;
  $('queueTextTab').textContent=hasHtml?'Текстовая версия'+(hasText?'':' · пусто'):'Текст письма';
  $('queuePreviewHint').textContent=hasHtml
    ? 'HTML-дизайн и текстовая версия уйдут вместе (multipart/alternative). Проверьте обе.'
    : 'Письмо отправляется простым текстом; PDF/DOCX — вложение.';
  if(!hasHtml && activeQueuePreview==='design')activeQueuePreview='pdf';
}
function syncQueueReviewControls() {
  const item=currentQueueItem();
  if(!item)return;
  const blocked=['sent','sending'].includes(item.status);
  const missingText=queueItemHasHtml(item)&&String(item.body||'').trim()==='';
  $('sendQueueItemBtn').disabled=!$('queueReviewedCheck').checked||blocked||missingText;
  $('saveQueueItemBtn').disabled=blocked;
  $('sendQueueItemBtn').title=missingText?'Заполните текстовую версию письма: HTML-письмо уходит как multipart/alternative.':'';
  $('sendQueueItemBtn').textContent=item.status==='failed'?'Повторить отправку':'Отправить это письмо';
  updateQueueTextHint();
}
function openQueueItem(id) {
  const item=queueItems.find(row=>row.id===id);if(!item)return;
  activeQueueItemId=id;
  $('queueItemLabel').textContent=[item.region,item.recipientName,item.recordId].filter(Boolean).join(' · ');
  $('queueTo').value=item.to||'';$('queueCc').value=item.cc||'';$('queueSubject').value=item.subject||'';$('queueBody').value=item.body||'';
  $('queueEmailSource').textContent=`Исходные поля книги — основной: ${item.sourcePrimaryEmail||'пусто'}; доп./старые: ${item.sourceAdditionalEmails||'пусто'}. Проверьте пометки об устаревших адресах.`;
  activeQueuePreview='pdf';
  updateQueueTextHint();
  queuePreviewTab('pdf');
  $('queuePdfDownload').href=`?action=queue_file&id=${encodeURIComponent(id)}&format=pdf`;
  $('queueDocxDownload').hidden=!item.hasDocx;
  $('queueDocxDownload').href=`?action=queue_file&id=${encodeURIComponent(id)}&format=docx`;
  const [statusText]=queueStatus(item.status);
  $('queueItemStatus').textContent=`${statusText}${item.lastError?' · '+item.lastError:''}`;
  $('queueActionFeedback').textContent=item.status==='failed'?'Перед повтором проверьте, не было ли письмо принято SMTP-сервером.':(item.status==='sent'?'Письмо уже отправлено.':'Сначала проверьте PDF, адреса и текст; отправка доступна только этому письму.');
  $('queueReviewedCheck').checked=false;
  $('queueModal').hidden=false;document.body.classList.add('modal-open');syncQueueReviewControls();$('queueModalClose').focus();
}
function closeQueueModal() {
  $('queueModal').hidden=true;$('queuePreviewFrame').src='about:blank';$('queueTextPreview').textContent='';document.body.classList.remove('modal-open');activeQueueItemId='';
}
function openQueueManager() {
  const frame=$('queueManagerFrame');
  if(!frame.src || frame.src==='about:blank') frame.src='queue.php';
  $('queueManagerModal').hidden=false;document.body.classList.add('modal-open');$('queueManagerModalClose').focus();
}
function closeQueueManager() {
  $('queueManagerModal').hidden=true;$('queueManagerFrame').src='about:blank';document.body.classList.remove('modal-open');
  refreshQueue().catch(()=>{});
}
async function saveQueueItem(showMessage=true) {
  if(!activeQueueItemId)return false;
  const fd=new FormData();fd.append('id',activeQueueItemId);fd.append('to',$('queueTo').value);fd.append('cc',$('queueCc').value);fd.append('subject',$('queueSubject').value);fd.append('body',$('queueBody').value);
  const response=await fetch('?action=update_queue_item',{method:'POST',headers:csrfHeaders(),body:fd});
  const payload=await response.json();
  if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось сохранить правки письма.');
  const index=queueItems.findIndex(item=>item.id===activeQueueItemId);if(index>=0)queueItems[index]=payload.item;
  renderQueue();syncQueueReviewControls();
  if(showMessage)$('queueActionFeedback').textContent='Правки сохранены; отправки не было.';
  return true;
}
async function sendQueueItem() {
  const item=currentQueueItem();if(!item)return;
  if(!$('queueReviewedCheck').checked){showToast('Подтвердите проверку PDF, получателей и текста.','error');return;}
  if(item.status==='failed'&&!window.confirm('Предыдущая попытка завершилась ошибкой. Возможно, SMTP-сервер уже принял письмо, но ответ не дошёл. Повтор может создать дубль. Продолжить?'))return;
  const button=$('sendQueueItemBtn'),old=button.textContent;button.disabled=true;button.textContent='Отправляем…';
  try {
    await saveQueueItem(false);
    const fd=new FormData();fd.append('id',activeQueueItemId);fd.append('confirmed','1');
    const response=await fetch('?action=send_queue_item',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok) throw new Error(payload.error||'Не удалось отправить письмо.');
    closeQueueModal();await refreshQueue();showToast('Одно письмо отправлено. Остальные элементы очереди не затронуты.','success');
  } catch(e) {
    $('queueActionFeedback').textContent=e.message||'Ошибка отправки.';
    showToast(e.message||'Ошибка отправки письма.','error');
    try{await refreshQueue();}catch{}
  } finally {button.textContent=old;syncQueueReviewControls();}
}
function updateSmtpState() {
  const ready=$('smtpHost').value.trim()!==''&&$('smtpFromEmail').value.trim()!=='';
  $('smtpState').textContent=ready?'Параметры заполнены':'Не настроено';
  $('smtpState').className='settings-state '+(ready?'ok':'bad');
}
function renderSmtpAccountOptions(payload, selectedId) {
  smtpAccounts=Array.isArray(payload.accounts)?payload.accounts:[];
  const options=smtpAccounts.map(a=>`<option value="${esc(a.id)}">${esc(a.label||a.fromEmail||'Ящик')}</option>`).join('');
  $('smtpAccountSelect').innerHTML=options;
  smtpAccountId=selectedId||payload.accountId||(smtpAccounts[0]?.id||'');
  $('smtpAccountSelect').value=smtpAccountId;
  $('deleteSmtpAccountBtn').disabled=smtpAccounts.length<=1;
  $('smtpAccountsHint').textContent=smtpAccounts.length>1
    ? `Ящиков: ${smtpAccounts.length}. Очередь формируется без отправителя: ящик выбирается при проверке пачки.`
    : 'Очередь формируется без отправителя: ящик выбирается при проверке пачки.';
}
function applySmtpAccountToForm(id) {
  const account=smtpAccounts.find(a=>a.id===id)||smtpAccounts[0];
  if(!account)return;
  smtpAccountId=account.id;
  $('smtpAccountLabel').value=account.label||'';
  $('smtpHost').value=account.host||'';$('smtpPort').value=account.port||587;$('smtpEncryption').value=account.encryption||'tls';$('smtpUsername').value=account.username||'';
  $('smtpFromEmail').value=account.fromEmail||'';$('smtpFromName').value=account.fromName||'';$('smtpReplyTo').value=account.replyTo||'';
  smtpPasswordConfigured=Boolean(account.passwordConfigured);
  $('smtpPasswordSaved').textContent=smtpPasswordConfigured?'· сохранён, скрыт':'';$('smtpPassword').value='';$('clearSmtpPassword').checked=false;
  updateSmtpState();
}
function applySmtpPayload(payload, selectedId) {
  renderSmtpAccountOptions(payload,selectedId);
  renderLetterAccountOptions();
  applySmtpAccountToForm(smtpAccountId);
  if(payload.settings&&!smtpAccounts.length){
    const s=payload.settings;
    $('smtpHost').value=s.host||'';$('smtpPort').value=s.port||587;$('smtpEncryption').value=s.encryption||'tls';$('smtpUsername').value=s.username||'';
    $('smtpFromEmail').value=s.fromEmail||'';$('smtpFromName').value=s.fromName||'';$('smtpReplyTo').value=s.replyTo||'';
    smtpPasswordConfigured=Boolean(payload.passwordConfigured);
    $('smtpPasswordSaved').textContent=smtpPasswordConfigured?'· сохранён, скрыт':'';$('smtpPassword').value='';$('clearSmtpPassword').checked=false;
    updateSmtpState();
  }
}
async function loadSmtpSettings() {
  const response=await fetch('?action=smtp_settings',{cache:'no-store'});
  const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось прочитать SMTP-настройки.');
  applySmtpPayload(payload,payload.accountId);
}
function availableLetterVariables() {
  const names=new Set((templateInfo.fields||[]).map(String));
  (dataset?.records||[]).forEach(record=>Object.keys(record.fields||{}).forEach(name=>names.add(name)));
  names.add('Наименование файла');
  names.add('наименование файла');
  return [...names].filter(name=>name&&!name.includes('{')).sort((a,b)=>a.localeCompare(b,'ru'));
}
function renderLetterVariables() {
  const target=$('letterVariableList');if(!target)return;
  const active=letterActiveField||'letterBodyPattern';
  target.innerHTML=availableLetterVariables().map(name=>`<button type="button" class="var-chip" data-var="${esc(name)}" title="Вставить в ${esc(active)}">{${esc(name)}}</button>`).join('');
}
function insertLetterVariable(name) {
  const id=letterActiveField||'letterBodyPattern';
  const input=$(id);if(!input)return;
  const token='{'+name+'}';
  const start=typeof input.selectionStart==='number'?input.selectionStart:input.value.length;
  const end=typeof input.selectionEnd==='number'?input.selectionEnd:start;
  input.value=input.value.slice(0,start)+token+input.value.slice(end);
  letterActiveField=id;
  input.focus();
  if(typeof input.setSelectionRange==='function')input.setSelectionRange(start+token.length,start+token.length);
}
function renderLetterAccountOptions() {
  const select=$('letterAccountSelect');if(!select)return;
  select.innerHTML=smtpAccounts.map(account=>`<option value="${esc(account.id)}">${esc(account.label||account.fromEmail||account.id)}</option>`).join('');
  const wanted=letterSettings?.smtpAccountId||'default';
  select.value=smtpAccounts.some(account=>account.id===wanted)?wanted:(smtpAccounts[0]?.id||'');
}
function applyLetterAccountDefault() {
}
function selectedTemplateLabel() {
  const selected=templateLibrary.find(item=>item.id===templateId);
  return selected?.name || templateInfo?.templateName || 'Письмо-пример.docx';
}
function currentLetterTemplateId() {
  // Only saved templates can keep their own letter copy; uploads and the
  // sample edit the common values.
  return templateId && templateId!=='__upload__' && templateId!=='sample-docx' ? templateId : '';
}
function renderLetterDesignPreview(fillWithData) {
  const frame=$('letterDesignPreview');if(!frame)return;
  let html=$('letterHtmlPattern').value;
  if(!html.trim()){frame.srcdoc='<body style="font-family:system-ui,sans-serif;color:#8896a4;font-size:13px;padding:18px">HTML-дизайн не задан. Пока письмо уходит обычным текстом.</body>';return;}
  if(fillWithData && letterSampleData) html=letterPreviewWithData(html, letterSampleData);
  frame.srcdoc=letterPreviewSanitize(html);
}
function letterPreviewWithData(html, values) {
  return html.replace(/\$\{([^{}]+)\}|\{([^{}:;\n]{1,120})\}/g,(match, dollarName, plainName)=>{
    const key=String(dollarName||plainName||'').trim();
    return key && values[key]!==undefined ? values[key] : match;
  }).replace(/\{nl\}/g,'<br>');
}
function letterPreviewSanitize(html) {
  return String(html||'').replace(/<\s*(script|iframe|object|embed|form)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi,'').replace(/\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*')/gi,'').replace(/(src|href|poster|background)\s*=\s*("|')\s*(https?:|file:|javascript:|data:|\/\/)[^"']*\2/gi,'');
}
function letterTab(tab) {
  letterDesignMode=tab;
  document.querySelectorAll('[data-letter-tab]').forEach(button=>{
    const active=button.dataset.letterTab===tab;
    button.classList.toggle('active',active);
    button.setAttribute('aria-selected',active?'true':'false');
  });
  document.querySelectorAll('[data-letter-pane]').forEach(pane=>{pane.hidden=pane.dataset.letterPane!==tab;});
  if(tab==='design')renderLetterDesignPreview($('letterDesignPreviewFill').checked);
  else renderLetterVariables();
}
function updateLetterNotice() {
  const html=$('letterHtmlPattern').value.trim();
  const plain=$('letterBodyPattern').value.trim();
  const ownText=$('letterHtmlTextPattern').value.trim();
  $('letterDesignMark').hidden=!html;
  $('letterDesignMark').title=html?'Для этого шаблона задан HTML-дизайн письма':'';
  const notice=$('letterPlainNotice');
  if(notice){
    notice.hidden=!html;
    notice.textContent=html&&!plain&&!ownText
      ? 'HTML-дизайн задан, а текстовая версия пуста: она будет собрана из HTML. Проверьте её в карточке письма; при автоотправке письма без текстовой версии блокируются.'
      : 'HTML-дизайн задан и текстовая версия заполнена — письмо уйдёт как multipart/alternative.';
    notice.className='letter-plain-notice '+(html&&!plain&&!ownText?'warn':'ok');
  }
}
function applyLetterSettings(payload={}) {
  const settings=payload.settings||letterSettings||{};
  Object.entries(LETTER_FIELDS).forEach(([id,key])=>{
    const input=$(id);
    if(input)input.value=settings[key]??'';
  });
  letterOwn=Boolean(settings.own);
  const scopeIsTemplate=Boolean(currentLetterTemplateId());
  const unsavedUpload=!scopeIsTemplate&&templateMode==='upload';
  $('letterState').textContent=scopeIsTemplate?(letterOwn?'Своё письмо шаблона':'Письмо выбранного шаблона'):(unsavedUpload?'Письмо разового DOCX':'Общее письмо · шаблон-пример');
  $('letterState').className='settings-state '+((letterOwn||unsavedUpload)?'ok':'');
  $('letterTemplateName').textContent=unsavedUpload?(templateFile?.name||'Загруженный DOCX'):selectedTemplateLabel();
  $('letterSourceBadge').className='pill '+(letterOwn?'ok':'');
  $('letterSourceBadge').textContent=letterOwn?'свои настройки шаблона':(unsavedUpload?'будет сохранено вместе с DOCX':'общие настройки (по умолчанию)');
  $('letterContextHint').textContent=!scopeIsTemplate
    ? (unsavedUpload?'Заполните письмо и нажмите «Сохранить письмо для этого шаблона»: DOCX попадёт в библиотеку вместе со своим HTML-дизайном, получателями и текстом.'
      :'Письмо-пример настраивается общими значениями. Сохраните DOCX в библиотеку — и у шаблона появится своё письмо.')
    : (letterOwn?'Изменения сохранятся только для этого шаблона.':'Изменения сохранятся копией для этого шаблона, общие значения не изменятся.');
  $('copyLetterDefaultsBtn').hidden=!letterOwn;
  // Codes and the data-filled preview depend on the selected template.
  letterSampleData=letterSampleValues();
  renderLetterAccountOptions();
  applyLetterAccountDefault();
  renderLetterVariables();
  renderLetterDesignPreview($('letterDesignPreviewFill')?.checked);
  updateLetterNotice();
}
function letterSampleValues() {
  const id=[...selectedIds][0];
  const record=id?dataset.records.find(row=>row.id===id):null;
  if(!record)return null;
  const values={...(record.fields||{}),...((overrides&&overrides[record.id])||{})};
  values['Наименование файла']=String(templateInfo?.templateName||'Документ').replace(/\.(docx|html)$/i,'');
  return values;
}
async function loadLetterTemplate(forTemplateId) {
  const wanted=forTemplateId===undefined?(currentLetterTemplateId()):(forTemplateId||'');
  letterTemplateKey=wanted;
  const query=wanted?`&template_id=${encodeURIComponent(wanted)}`:'';
  const response=await fetch(`?action=letter_template${query}`,{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось прочитать шаблон письма.');
  if(letterTemplateKey!==wanted)return; // A newer template selection already won.
  letterSettings=payload.settings||{};
  applyLetterSettings(payload);
  const design=$('letterHtmlPattern').value.trim();
  if(design)letterTab(letterDesignMode||'main');
  else if(letterDesignMode==='design')letterTab('main');
}
function letterFormData(templateKey) {
  const fd=new FormData();
  Object.entries(LETTER_FIELDS).forEach(([id,key])=>fd.append(key,$(id).value));
  fd.append('smtpAccountId',$('letterAccountSelect').value||'default');
  fd.append('templateId',templateKey||'');
  return fd;
}
function letterFormHasContent() {
  return Boolean($('letterHtmlPattern').value.trim()||$('letterBodyPattern').value.trim()||$('letterHtmlTextPattern').value.trim());
}
async function saveLetterFor(templateKey) {
  const payload=await requestJson('?action=save_letter_template',{method:'POST',headers:csrfHeaders(),body:letterFormData(templateKey)},15000);
  const previousAccountId=letterSettings?.smtpAccountId||'';
  letterSettings=payload.settings||letterSettings;
  letterOwn=Boolean(letterSettings.own);
  applyLetterSettings(payload);
  return payload;
}
async function saveLetterTemplate() {
  const button=$('saveLetterTemplateBtn');
  if(button.disabled)return;
  const html=$('letterHtmlPattern').value.trim();
  const plain=$('letterBodyPattern').value.trim();
  const ownText=$('letterHtmlTextPattern').value.trim();
  if(!plain&&!ownText&&!html){$('letterFeedback').textContent='Заполните текст письма или вставьте HTML-дизайн.';showToast('Письмо не может быть пустым.','error');letterTab('main');return;}
  if(!$('letterSubjectPattern').value.trim()){$('letterFeedback').textContent='Укажите тему письма.';showToast('Укажите тему письма.','error');letterTab('main');return;}
  if(html&&!plain&&!ownText){
    const proceed=window.confirm('Текстовая версия пуста. Она будет собрана из HTML, но multipart/alternative надёжнее с явным текстом. Сохранить и проверить позже?');
    if(!proceed){letterTab('main');$('letterBodyPattern').focus();$('letterFeedback').textContent='Заполните текстовую версию письма.';return;}
  }
  button.disabled=true;button.textContent='Сохраняем…';
  $('letterFeedback').textContent='Сохраняем письмо шаблона…';
  try {
    let targetId=currentLetterTemplateId(), created=null;
    // One template = document + its letter: an uploaded DOCX joins the library
    // first, so the design is stored in that same template entry.
    if(!targetId && templateMode==='upload' && templateFile) {
      created=await saveUploadToLibrary(true);
      targetId=created.id;
    }
    const payload=await saveLetterFor(targetId);
    if(created) setTemplateInfo(created.info,'saved',null,created.id);
    $('letterFeedback').textContent=letterOwn?'Письмо сохранено для этого шаблона.':'Общие настройки письма сохранены.';
    showToast(letterOwn?(created?'DOCX-шаблон сохранён вместе с письмом и HTML-дизайном.':'Письмо шаблона сохранено.'):'Общие настройки письма сохранены.','success');
    if(payload.notice)showToast(payload.notice,'info');
  } catch(e) {
    $('letterFeedback').textContent=e.message||'Ошибка сохранения шаблона письма.';
    showToast(e.message||'Ошибка сохранения шаблона письма.','error');
  } finally {button.disabled=false;button.textContent='Сохранить письмо для этого шаблона';}
}
// --- Template manager: name, copy, replace file, delete -------------------
const templateManagerState = {replaceId:'', pendingUpload:null};
function templateKindLabel(item) {
  return item.type==='html' ? 'HTML-шаблон → PDF' : 'DOCX → PDF';
}
function templateLetterBadges(item) {
  if(item.sample) return '<span class="pill">встроенный пример · общее письмо</span>';
  const parts=[];
  parts.push(item.ownLetter?'<span class="pill ok">своё письмо</span>':'<span class="pill">общее письмо</span>');
  if(item.hasHtmlDesign) parts.push('<span class="pill ok">HTML-дизайн</span>');
  if(item.size) parts.push(`<span class="pill">${Math.max(1,Math.round(Number(item.size)/1024))} КБ</span>`);
  if(item.updatedAt) parts.push(`<span class="pill" title="Изменён">${esc(String(item.updatedAt).slice(0,10))}</span>`);
  return parts.join('');
}
function renderTemplateManager() {
  const list=$('templateManagerList');if(!list)return;
  list.innerHTML=templateLibrary.map(item=>{
    const current=item.id===templateId;
    const editable=!item.sample;
    return `<article class="template-row${current?' current':''}" data-template-row="${esc(item.id)}">
      <div class="template-row-head"><div><div class="template-row-title">${esc(item.name)}</div><div class="small-muted">${esc(templateKindLabel(item))}${current?' · выбран сейчас':''}</div></div><div class="template-row-meta">${templateLetterBadges(item)}</div></div>
      ${editable?`<input type="text" maxlength="180" value="${esc(item.name)}" data-template-name-input="${esc(item.id)}" aria-label="Новое имя шаблона ${esc(item.name)}">
      <div class="template-row-actions">
        <button type="button" class="btn-primary" data-template-action="rename" data-id="${esc(item.id)}">Сохранить имя</button>
        <button type="button" class="btn-ghost" data-template-action="copy" data-id="${esc(item.id)}">Сохранить как копию…</button>
        ${item.type==='html'?`<button type="button" class="btn-ghost" data-template-action="edit" data-id="${esc(item.id)}">Редактировать HTML</button>`:`<button type="button" class="btn-ghost" data-template-action="replace" data-id="${esc(item.id)}">Заменить DOCX…</button>`}
        <button type="button" class="btn-ghost" data-template-action="select" data-id="${esc(item.id)}">Выбрать</button>
        <button type="button" class="btn danger" data-template-action="delete" data-id="${esc(item.id)}">Удалить</button>
      </div>`:`<div class="template-row-actions"><button type="button" class="btn-ghost" data-template-action="select" data-id="${esc(item.id)}">Выбрать пример</button></div>`}
    </article>`;
  }).join('');
  list.querySelectorAll('[data-template-action]').forEach(button=>{
    button.addEventListener('click',()=>runTemplateAction(button.dataset.templateAction,button.dataset.id));
  });
}
function templateManagerMessage(text,error) {
  const feedback=$('templateManagerFeedback');
  if(feedback){feedback.textContent=text;feedback.style.color=error?'var(--bad)':'';}
  if(text) showToast(text,error?'error':'success');
}
function refreshTemplateRow() {
  renderTemplateManager();
  syncTemplateNameInput(true);
}
function templateNameInputValue() { return $('templateNameInput')?.value.trim()||''; }
function selectedTemplateName() {
  const selected=templateLibrary.find(item=>item.id===templateId);
  return selected?.name||'Письмо-пример.docx';
}
function syncTemplateNameInput(force) {
  const input=$('templateNameInput');if(!input)return;
  if(force||document.activeElement!==input) input.value=selectedTemplateName();
  const isSample=templateId==='sample-docx';
  input.disabled=templateId==='__upload__'||isSample;
  $('templateRenameBtn').disabled=templateId==='__upload__'||isSample;
  $('templateSaveAsBtn').disabled=templateId==='__upload__';
}
async function saveTemplateName() {
  if(!templateId||templateId==='__upload__'){ $('templateNameFeedback').textContent='Сначала сохраните DOCX в библиотеку — у разового файла нет имени в списке.'; return; }
  if(templateId==='sample-docx'){ $('templateNameFeedback').textContent='У встроенного примера имя не меняется. Используйте «Сохранить как копию…».'; return; }
  const name=templateNameInputValue();
  if(!name){ $('templateNameFeedback').textContent='Введите имя шаблона.'; return; }
  const button=$('templateRenameBtn');button.disabled=true;
  try {
    const fd=new FormData();fd.append('id',templateId);fd.append('name',name);
    const payload=await requestJson('?action=rename_template',{method:'POST',headers:csrfHeaders(),body:fd},15000);
    applyTemplateLibrary(payload.templates);
    $('templateNameFeedback').textContent='Имя сохранено: '+name;
    if(!$('templateManagerModal').hidden) refreshTemplateRow();
    showToast('Имя шаблона сохранено.','success');
  } catch(e) {
    $('templateNameFeedback').textContent=e.message||'Не удалось сохранить имя.';
    showToast(e.message||'Не удалось сохранить имя.','error');
  } finally {button.disabled=false;syncTemplateNameInput(true);}
}
async function saveTemplateAsCopy(sourceId, suggestedName) {
  const baseName=suggestedName||templateNameInputValue()||selectedTemplateName();
  const name=window.prompt('Новое имя копии шаблона:',baseName+' · копия');
  if(name===null)return null;
  if(!name.trim()){showToast('Имя копии не может быть пустым.','error');return null;}
  try {
    const fd=new FormData();fd.append('source_id',sourceId);fd.append('name',name.trim());
    const payload=await requestJson('?action=copy_template',{method:'POST',headers:csrfHeaders(),body:fd},30000);
    applyTemplateLibrary(payload.templates);
    setTemplateInfo(payload.info,'saved',null,payload.template.id);
    syncTemplateNameInput(true);
    if(!$('templateManagerModal').hidden) renderTemplateManager();
    showToast('Копия создана: '+payload.template.name,'success');
    if(payload.notice)showToast(payload.notice,'info');
    return payload.template;
  } catch(e) { showToast(e.message||'Не удалось создать копию.','error'); return null; }
}
async function runTemplateAction(action,id) {
  if(action==='select'){ await selectTemplate(id); if(!$('templateManagerModal').hidden) renderTemplateManager(); return; }
  if(action==='edit'){ try { await selectTemplate(id); await editHtmlTemplate(); } catch(e){ showToast(e.message||'Не удалось открыть редактор.','error'); } return; }
  if(action==='rename'){
    const input=document.querySelector(`[data-template-name-input="${id}"]`);
    const name=input?.value.trim()||'';
    if(!name){ templateManagerMessage('Введите имя шаблона.',true); return; }
    try {
      const fd=new FormData();fd.append('id',id);fd.append('name',name);
      const payload=await requestJson('?action=rename_template',{method:'POST',headers:csrfHeaders(),body:fd},15000);
      applyTemplateLibrary(payload.templates);
      if(id===templateId)syncTemplateNameInput(true);
      renderTemplateManager();
      templateManagerMessage('Имя сохранено: '+name);
    } catch(e) { templateManagerMessage(e.message||'Не удалось переименовать шаблон.',true); }
    return;
  }
  if(action==='copy'){ await saveTemplateAsCopy(id); return; }
  if(action==='replace'){
    templateManagerState.replaceId=id;
    $('templateManagerReplaceInput').value='';
    $('templateManagerReplaceInput').click();
    return;
  }
  if(action==='delete'){
    const item=templateLibrary.find(row=>row.id===id);
    const label=item?.name||'шаблон';
    if(!window.confirm(`Удалить шаблон «${label}» вместе с его письмом и HTML-дизайном? Документ и настройки будут потеряны.`))return;
    if(!window.confirm('Подтвердите удаление: отменить его будет нельзя.'))return;
    try {
      const fd=new FormData();fd.append('id',id);
      const payload=await requestJson('?action=delete_template',{method:'POST',headers:csrfHeaders(),body:fd},15000);
      applyTemplateLibrary(payload.templates);
      if(id===templateId){ await selectTemplate('sample-docx'); }
      renderTemplateManager();
      templateManagerMessage('Шаблон «'+label+'» удалён.');
    } catch(e) { templateManagerMessage(e.message||'Не удалось удалить шаблон.',true); }
  }
}
async function replaceTemplateFile(file) {
  const id=templateManagerState.replaceId;
  if(!id||!file)return;
  if(!window.confirm(`Заменить DOCX-файл шаблона «${selectedTemplateNameFor(id)}»? Имя, письмо и HTML-дизайн сохранятся, файл станет новым.`))return;
  try {
    const fd=new FormData();fd.append('id',id);fd.append('template',file);
    const payload=await requestJson('?action=replace_template_file',{method:'POST',headers:csrfHeaders(),body:fd},60000);
    applyTemplateLibrary(payload.templates);
    if(id===templateId) setTemplateInfo(payload.info,'saved',null,id);
    renderTemplateManager();
    templateManagerMessage(payload.notice||'Файл шаблона заменён.');
  } catch(e) { templateManagerMessage(e.message||'Не удалось заменить файл шаблона.',true); }
  finally { templateManagerState.replaceId=''; }
}
function selectedTemplateNameFor(id) {
  return templateLibrary.find(item=>item.id===id)?.name||'шаблон';
}
function openTemplateManager() {
  renderTemplateManager();
  $('templateManagerFeedback').textContent='Действия применяются только к выбранной строке.';
  $('templateManagerModal').hidden=false;document.body.classList.add('modal-open');
  $('templateManagerClose').focus();
}
function closeTemplateManager() {
  $('templateManagerModal').hidden=true;document.body.classList.remove('modal-open');
  syncTemplateNameInput(true);
}
async function uploadTemplateFromManager(file) {
  if(!file)return;
  const name=($('templateManagerNewName').value.trim()||file.name.replace(/\.docx$/i,'')).slice(0,180);
  const button=$('templateManagerUploadBtn');button.disabled=true;button.textContent='Сохраняем…';
  try {
    const fd=new FormData();fd.append('type','docx');fd.append('name',name);fd.append('template',file);
    const payload=await requestJson('?action=save_template',{method:'POST',headers:csrfHeaders(),body:fd},60000);
    applyTemplateLibrary(payload.templates);
    setTemplateInfo(payload.info,'saved',null,payload.template.id);
    $('templateManagerNewName').value='';
    syncTemplateNameInput(true);
    renderTemplateManager();
    templateManagerMessage('Шаблон «'+payload.template.name+'» добавлен в библиотеку.');
  } catch(e) { templateManagerMessage(e.message||'Не удалось сохранить DOCX.',true); }
  finally {button.disabled=false;button.textContent='Загрузить DOCX и сохранить';$('templateManagerUploadInput').value='';}
}
async function copyLetterDefaults() {
  if(!window.confirm('Заменить поля общими настройками? Несохранённые изменения этого шаблона будут потеряны.'))return;
  try { await loadLetterTemplate(); $('letterFeedback').textContent='Показаны значения по умолчанию (без сохранения).'; }
  catch(e) { showToast(e.message||'Не удалось прочитать общие настройки.','error'); }
}
function letterDesignSample() {
  if($('letterHtmlPattern').value.trim()&&!window.confirm('Заменить текущий HTML-дизайн примером письма?'))return;
  $('letterHtmlPattern').value=LETTER_DESIGN_STARTER;
  letterTab('design');renderLetterDesignPreview($('letterDesignPreviewFill').checked);updateLetterNotice();
  $('letterDesignFeedback').textContent='Пример вставлен. Замените текст, дату и ссылки на свои.';
}
function letterDesignClear() {
  if(!$('letterHtmlPattern').value.trim())return;
  if(!window.confirm('Очистить HTML-дизайн для этого шаблона? Письмо вернётся к обычному тексту.'))return;
  $('letterHtmlPattern').value='';$('letterDesignFeedback').textContent='HTML-дизайн очищен; не забудьте сохранить.';
  renderLetterDesignPreview(false);updateLetterNotice();$('letterDesignMark').hidden=true;
}
function smtpFormData(includeTemplates) {
  const fd=new FormData();
  fd.append('account_id',smtpAccountId||'default');
  if(includeTemplates)fd.append('label',$('smtpAccountLabel').value.trim());
  const fields={host:'smtpHost',port:'smtpPort',encryption:'smtpEncryption',username:'smtpUsername',fromEmail:'smtpFromEmail',fromName:'smtpFromName',replyTo:'smtpReplyTo'};
  for(const [key,id] of Object.entries(fields))fd.append(key,$(id).value);
  if($('smtpPassword').value)fd.append('password',$('smtpPassword').value);
  if($('clearSmtpPassword').checked)fd.append('clear_password','1');
  return fd;
}
async function saveSmtpSettings() {
  const button=$('saveSmtpBtn');
  if(button.disabled)return;
  button.disabled=true;button.textContent='Сохраняем…';
  $('smtpFeedback').textContent='Сохраняем настройки…';
  try {
    const payload=await requestJson('?action=save_smtp_settings',{method:'POST',headers:csrfHeaders(),body:smtpFormData(true)},15000);
    applySmtpPayload(payload,payload.accountId||smtpAccountId);
    $('smtpFeedback').textContent='Настройки ящика сохранены в приватном хранилище.';showToast('SMTP-настройки сохранены. Пароль скрыт.','success');
  } catch(e) {$('smtpFeedback').textContent=e.message||'Ошибка сохранения.';showToast(e.message||'Ошибка сохранения SMTP-настроек.','error');}
  finally {button.disabled=false;button.textContent='Сохранить настройки';}
}
async function addSmtpAccount() {
  try {
    const payload=await requestJson('?action=add_smtp_account',{method:'POST',headers:csrfHeaders(),body:new FormData()},15000);
    applySmtpPayload(payload,payload.accountId);
    $('smtpFeedback').textContent='Добавлен новый SMTP-ящик. Заполните параметры и сохраните.';
    $('smtpDetails').open=true;$('smtpHost').focus();
    showToast('Новый SMTP-ящик добавлен.','success');
  } catch(e) {$('smtpFeedback').textContent=e.message||'Не удалось добавить ящик.';showToast(e.message||'Не удалось добавить SMTP-ящик.','error');}
}
async function deleteSmtpAccount() {
  if(smtpAccounts.length<=1){showToast('Нельзя удалить единственный SMTP-ящик.','error');return;}
  const current=smtpAccounts.find(a=>a.id===smtpAccountId);
  if(!window.confirm(`Удалить SMTP-ящик «${current?.label||smtpAccountId}»? Сохранённый пароль будет удалён.`))return;
  const fd=new FormData();fd.append('account_id',smtpAccountId);
  try {
    const payload=await requestJson('?action=delete_smtp_account',{method:'POST',headers:csrfHeaders(),body:fd},15000);
    applySmtpPayload(payload,payload.accountId);
    $('smtpFeedback').textContent='SMTP-ящик удалён.';showToast('SMTP-ящик удалён.','success');
  } catch(e) {$('smtpFeedback').textContent=e.message||'Не удалось удалить ящик.';showToast(e.message||'Не удалось удалить SMTP-ящик.','error');}
}
function openSmtpLog(diagnostics) {
  const log=diagnostics||{};
  const ok=Boolean(log.ok);
  const bad=$('smtpLogBadge');
  bad.textContent=ok?'Успешно':'Ошибка';
  bad.className='smtp-log-badge '+(ok?'ok':'bad');
  $('smtpLogSubtitle').textContent=[log.server||'', log.context?.['шифрование']||'', log.elapsedMs?`${log.elapsedMs} мс`:''].filter(Boolean).join(' · ');
  $('smtpLogHeadline').textContent=log.summary||(ok?'Соединение проверено.':'Проверка не прошла.');
  $('smtpLogHint').textContent=ok?'Пароль и диалог с сервером скрыты. Сервер принял параметры авторизации.':'Ниже — сетевые шаги и полный диалог: по ним видно, на каком этапе всё остановилось.';
  const steps=Array.isArray(log.steps)?log.steps:[];
  $('smtpLogSteps').innerHTML=steps.length?steps.map(step=>`<li class="${step.ok?'good':'bad'}"><span class="step-name">${esc(step.step||'')}</span><span>${step.ok?'✓':'✕'} ${esc(step.detail||'')}</span></li>`).join(''):'<li><span class="step-name">Сеть</span><span>Сетевые шаги недоступны.</span></li>';
  const ports=Array.isArray(log.ports)?log.ports:[];
  const portsBox=$('smtpLogPortsBox');
  portsBox.hidden=ports.length===0;
  $('smtpLogPorts').innerHTML=ports.map(item=>{
    const open=Boolean(item.ok);
    const usable=open&&/приветствие/.test(String(item.detail||''));
    return `<li class="${open?'good':'bad'}"><span class="step-name">${item.port}</span><span>${open?'✓ открыт':'✕ '+esc(String(item.detail||'').replace(/^\d+\s*—\s*/,''))}${open?': '+esc(item.detail):''}${usable?` · <button type="button" class="link-button" data-smtp-use="${item.port}" data-smtp-enc="${esc(item.encryption)}">применить ${item.port} + ${item.encryption==='tls'?'STARTTLS':(item.encryption==='ssl'?'SSL/TLS':'без шифрования')}</button>`:''}</span></li>`;
  }).join('');
  $('smtpLogPorts').querySelectorAll('[data-smtp-use]').forEach(button=>button.addEventListener('click',()=>{
    $('smtpPort').value=button.dataset.smtpUse;
    $('smtpEncryption').value=button.dataset.smtpEnc;
    updateSmtpState();
    $('smtpLogFeedback').textContent=`Порт ${button.dataset.smtpUse} и режим шифрования перенесены в форму. Нажмите «Проверить SMTP» ещё раз, затем сохраните настройки.`;
  }));
  const smtpError=log.smtpError||{};
  const errorLine=[smtpError.error,smtpError.detail,smtpError.code&&`код ${smtpError.code}`].filter(Boolean).join(' · ');
  $('smtpLogTranscript').textContent=[log.transcript||'PHPMailer не оставил журнала.',errorLine?`\nSMTP: ${errorLine}`:''].join('');
  const context=Object.entries(log.context||{});
  $('smtpLogContext').innerHTML=context.map(([key,value])=>`<dt>${esc(key)}</dt><dd>${esc(value)}</dd>`).join('');
  $('smtpLogFeedback').textContent=ok?'Проверка ничего не сохранила.':'Журнал можно скопировать целиком.';
  $('smtpLogModal').hidden=false;document.body.classList.add('modal-open');
  $('smtpLogCloseBtn').focus();
}
function closeSmtpLog() {
  $('smtpLogModal').hidden=true;document.body.classList.remove('modal-open');
}
async function testSmtp() {
  const button=$('testSmtpBtn');
  if(button.disabled)return;
  if(!$('smtpHost').value.trim()||!$('smtpFromEmail').value.trim()){
    $('smtpFeedback').textContent='Сначала заполните SMTP-сервер и email отправителя. Проверка использует значения из формы и ничего не сохраняет.';
    showToast('Заполните SMTP-сервер и email отправителя.','error');return;
  }
  button.disabled=true;button.textContent='Проверяем…';
  $('smtpFeedback').textContent='Подключаемся к SMTP-серверу…';
  try {
    // Ошибка 502 всё равно несёт журнал, поэтому читаем тело до проверки ok.
    const response=await fetchSmtpTest();
    const payload=await response.json().catch(()=>({}));
    if(payload.ok){
      $('smtpFeedback').textContent=payload.summary||payload.message||'Соединение проверено.';
      showToast(payload.summary||'SMTP доступен.','success');
      openSmtpLog(payload);
      return;
    }
    const summary=payload.summary||payload.error||'Проверка SMTP не прошла.';
    $('smtpFeedback').textContent=summary;
    showToast(summary+' Открыт журнал проверки.','error');
    openSmtpLog(payload);
  } catch(e) {
    $('smtpFeedback').textContent=e.message||'Ошибка SMTP.';
    showToast(e.message||'Ошибка SMTP.','error');
    openSmtpLog({ok:false,summary:e.message||'Проверка не дошла до сервера.',steps:[],transcript:'',context:{}});
  } finally {button.disabled=false;button.textContent='Проверить SMTP';}
}
async function fetchSmtpTest() {
  const controller=new AbortController();
  const timer=setTimeout(()=>controller.abort(),30000);
  try {
    return await fetch('?action=test_smtp',{method:'POST',headers:csrfHeaders(),body:smtpFormData(false),signal:controller.signal});
  } catch(e) {
    if(e.name==='AbortError')throw new Error('Сервер не ответил за 30 с: SMTP-сервер недоступен или сеть контейнера блокирует соединение.');
    throw e;
  } finally {clearTimeout(timer);}
}
function updateDadataState() {
  const count=Object.keys(managerChecks||{}).length;
  $('dadataState').textContent=dadataConfigured?`API-ключ сохранён${count?` · сверено ${count}`:''}`:'API-ключ не задан';
  $('dadataState').className='settings-state '+(dadataConfigured?'ok':'bad');
  $('dadataTokenSaved').textContent=dadataConfigured?'· сохранён, скрыт':'';
  updateManagerCheckButton();
}
async function loadDadataSettings() {
  const response=await fetch('?action=dadata_settings',{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось прочитать настройки DaData.');
  dadataConfigured=Boolean(payload.configured);
  $('dadataToken').value='';
  $('clearDadataToken').checked=false;
  updateDadataState();
}
async function saveDadataSettings() {
  const token=$('dadataToken').value.trim();
  const clear=$('clearDadataToken').checked;
  if(!token&&!clear){$('dadataFeedback').textContent='Введите новый API-ключ или отметьте удаление сохранённого ключа.';return;}
  const fd=new FormData();
  if(token&&!clear)fd.append('token',token);
  if(clear)fd.append('clear_token','1');
  const button=$('saveDadataBtn');button.disabled=true;button.textContent='Сохраняем…';
  try {
    const response=await fetch('?action=save_dadata_settings',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось сохранить API-ключ DaData.');
    dadataConfigured=Boolean(payload.configured);
    $('dadataToken').value='';$('clearDadataToken').checked=false;
    $('dadataFeedback').textContent=dadataConfigured?'Ключ сохранён в приватном хранилище и скрыт.':'Сохранённый ключ удалён.';
    updateDadataState();
    showToast(dadataConfigured?'API-ключ DaData сохранён и скрыт.':'API-ключ DaData удалён.','success');
  } catch(e) {$('dadataFeedback').textContent=e.message||'Ошибка сохранения ключа.';showToast(e.message||'Ошибка настроек DaData.','error');}
  finally {button.disabled=false;button.textContent='Сохранить API-ключ';}
}
async function runManagerCheckIds(ids, askConfirmation=true) {
  const unique=[...new Set(ids.map(String).filter(Boolean))];
  if(!unique.length){showToast('Отметьте одну или несколько записей для сверки.','error');return;}
  if(!dadataConfigured){$('dadataDetails').open=true;$('dadataToken').focus();showToast('Сначала сохраните API-ключ DaData в настройках.','error');return;}
  if(askConfirmation&&!window.confirm(`Будет выполнен ручной запрос DaData для ${unique.length} отмеченных записей (по одному запросу на корректный ИНН). Это может расходовать лимит или оплату вашего тарифа. Продолжить?`))return;
  managerCheckRunning=true;managerCheckCancelRequested=false;updateManagerCheckButton();
  $('managerCheckProgress').classList.add('show');
  $('managerCheckProgressText').textContent=`DaData: проверено 0 из ${unique.length}…`;
  const updatedIds=new Set();let completed=0;let stopReason='';let cancelled=false;
  try {
    for(let offset=0;offset<unique.length;offset+=20){
      if(managerCheckCancelRequested){cancelled=true;break;}
      const batch=unique.slice(offset,offset+20);
      const fd=new FormData();fd.append('selected_ids',JSON.stringify(batch));
      const response=await fetch('?action=check_managers',{method:'POST',headers:csrfHeaders(),body:fd});
      const payload=await response.json();
      if(!response.ok||!payload.ok)throw new Error(payload.error||'Сверка DaData не выполнена.');
      Object.assign(managerChecks,payload.checks||{});dataset.managerChecks=managerChecks;
      (payload.updatedIds||[]).forEach(id=>updatedIds.add(String(id)));
      completed+=Number(payload.processed||0);
      $('managerCheckProgressText').textContent=`DaData: обработано ${completed} из ${unique.length}. Запросы выполняются вручную, по пакетам.`;
      renderTable();
      if(activeModalRecordId){const activeRecord=dataset.records.find(row=>row.id===activeModalRecordId);if(activeRecord)renderManagerCheckDetail(activeRecord);}
      if(payload.stop){stopReason=String(payload.stopReason||'DaData остановила пакет.');break;}
    }
    const checked=[...updatedIds].map(id=>managerChecks[id]).filter(Boolean);
    const nameDiff=checked.filter(item=>['name_mismatch','both_mismatch'].includes(item.status)).length;
    const positionDiff=checked.filter(item=>['position_mismatch','both_mismatch'].includes(item.status)).length;
    const match=checked.filter(item=>item.status==='match').length;
    const summary=`Готово: обработано ${checked.length} записей; ФИО не совпало — ${nameDiff}; должность отличается — ${positionDiff}; ФИО совпало — ${match}.`;
    $('dadataFeedback').textContent=stopReason||summary;
    if(stopReason)showToast(`${summary} Остановлено: ${stopReason}`,'error');
    else if(cancelled||managerCheckCancelRequested)showToast(`${summary} Сверка остановлена после завершения текущего пакета.`,'success');
    else showToast(summary,'success');
    updateDadataState();
  } catch(e) {
    $('dadataFeedback').textContent=e.message||'Ошибка сверки DaData.';
    showToast(e.message||'Ошибка сверки DaData.','error');
  } finally {
    managerCheckRunning=false;$('managerCheckProgress').classList.remove('show');updateManagerCheckButton();renderTable();
    if(activeModalRecordId){const activeRecord=dataset.records.find(row=>row.id===activeModalRecordId);if(activeRecord)renderManagerCheckDetail(activeRecord);}
  }
}
function checkSelectedManagers() {
  return runManagerCheckIds([...selectedIds],true);
}
function updateDadataRefreshButton() {
  const button=$('refreshFromDadataBtn');
  if(!button)return;
  button.disabled=selectedIds.size===0||!dadataConfigured||dadataRefreshRunning;
  button.textContent=dadataRefreshRunning?'Загрузка DaData…':`Обновить через DaData${selectedIds.size?` (${selectedIds.size})`:''}`;
  button.title=!dadataConfigured?'Сначала сохраните API-ключ DaData в настройках.':'Данные загружаются в диалог и сохраняются только после подтверждения.';
}
function closeDadataRefreshModal() {
  $('dadataRefreshModal').hidden=true;
  document.body.classList.remove('modal-open');
  dadataRefreshData=null;dadataPreviewEdits=null;dadataPreviewNewFields=[];
}
function allEditableFieldNames() {
  const names=new Set(dataset.customColumns||[]);
  (dataset.records||[]).forEach(record=>Object.keys(record.fields||{}).forEach(name=>{if(name)names.add(name);}));
  return [...names].sort((a,b)=>a.localeCompare(b,'ru'));
}
function dadataStatusLabel(status) {
  return ({ok:'Найдено',ambiguous:'Неоднозначно',not_found:'Не найдено',error:'Ошибка'})[status]||'Не проверено';
}
function renderDadataSources() {
  if(!dadataRefreshData)return;
  const labels=new Map((dadataRefreshData.fields||[]).map(field=>[field.key,field.label]));
  const rows=Object.values(dadataRefreshData.rows||{});
  $('dadataRefreshSummary').textContent=`Загружено ${rows.length} из ${dadataRefreshData.ids.length}: найдено ${rows.filter(row=>row.status==='ok').length}, с ошибками ${rows.filter(row=>row.status!=='ok').length}. Данные не сохранены.`;
  $('dadataSourceList').innerHTML=rows.map(row=>{
    const values=Object.entries(row.values||{});
    const list=values.length?`<details><summary>Все поля DaData (${values.length})</summary><div class="dadata-preview-grid" style="margin-top:8px">${values.map(([key,value])=>`<div class="dadata-preview-change"><b>${esc(labels.get(key)||key)}</b><div>${esc(value)}</div></div>`).join('')}</div></details>`:'';
    return `<div class="dadata-preview-card"><div class="dadata-preview-head"><b>${esc(row.label||row.id)}</b><span class="pill ${row.status==='ok'?'ok':'warn'}">${esc(dadataStatusLabel(row.status))}</span></div><div class="small-muted">ИНН ${esc(row.inn||'—')}${row.kpp?` / ${esc(row.kpp)}`:''} · ${esc(row.message||'')}</div>${list}</div>`;
  }).join('');
}
function renderDadataMapping() {
  if(!dadataRefreshData)return;
  const sample=Object.values(dadataRefreshData.rows||{}).find(row=>row.status==='ok'&&Object.keys(row.values||{}).length);
  const sampleValues=sample?.values||{};
  const options=allEditableFieldNames().map(name=>`<option value="${esc(name)}">${esc(name)}</option>`).join('')+'<option value="__new__">+ Создать своё поле</option>';
  $('dadataMappingList').innerHTML=(dadataRefreshData.fields||[]).map(field=>{
    const sampleValue=String(sampleValues[field.key]||'');
    return `<div class="dadata-map-row" data-dadata-key="${esc(field.key)}"><div class="dadata-map-label"><b>${esc(field.label)}</b><span title="${esc(sampleValue)}">${sampleValue?esc(sampleValue):'Значений в ответе нет'}</span></div><select data-role="target"><option value="">Не обновлять</option>${options}</select><input type="text" data-role="new-name" maxlength="120" placeholder="Название нового поля" hidden></div>`;
  }).join('');
  $('dadataRefreshStatus').textContent='Выберите сопоставление. Пока ничего не сохранено.';
  $('saveDadataCardsBtn').disabled=true;
  $('dadataPreviewList').innerHTML='<span class="small-muted">После сопоставления здесь появятся конкретные значения «было → стало».</span>';
}
function markDadataMappingChanged() {
  dadataPreviewEdits=null;dadataPreviewNewFields=[];
  $('saveDadataCardsBtn').disabled=true;
  $('dadataRefreshStatus').textContent='Сопоставление изменилось. Нажмите «Сопоставить и показать изменения».';
  $('dadataPreviewList').innerHTML='<span class="small-muted">Предпросмотр устарел. Повторите сопоставление.</span>';
}
function readDadataMapping() {
  const pairs=[],newFields=[],used=new Set();
  document.querySelectorAll('#dadataMappingList .dadata-map-row').forEach(row=>{
    const source=row.dataset.dadataKey;
    const select=row.querySelector('[data-role="target"]');
    const target=select?.value||'';
    if(!target)return;
    let name=target;
    if(target==='__new__'){
      name=row.querySelector('[data-role="new-name"]')?.value.trim()||'';
      if(!name)throw new Error(`Укажите название нового поля для «${source}».`);
      newFields.push(name);
    }
    if(used.has(name))throw new Error(`Колонка «${name}» выбрана для двух полей DaData. Выберите разные колонки.`);
    used.add(name);
    pairs.push({source,target:name});
  });
  if(!pairs.length)throw new Error('Выберите хотя бы одно поле DaData и колонку книги.');
  return {pairs,newFields};
}
function renderDadataPreview(edits,pairs) {
  const rows=Object.values(dadataRefreshData.rows||{}).filter(row=>row.status==='ok');
  if(!rows.length){$('dadataPreviewList').innerHTML='<span class="small-muted">Нет организаций с загруженными данными.</span>';return;}
  $('dadataPreviewList').innerHTML=rows.map(row=>{
    const record=dataset.records.find(item=>item.id===row.id);
    const fields=edits[row.id]||{};
    const changes=pairs.map(pair=>{
      const newValue=String(row.values?.[pair.source]??'');
      if(newValue==='')return '';
      const oldValue=fieldValue(record,pair.target);
      const changed=oldValue!==newValue;
      return `<div class="dadata-preview-change"><b>${esc(pair.target)}</b><div class="old">Было: ${esc(oldValue||'—')}</div><div class="new">${changed?'Стало: ':'Уже совпадает: '}${esc(newValue)}</div></div>`;
    }).join('');
    return `<div class="dadata-preview-card"><div class="dadata-preview-head"><b>${esc(row.label||row.id)}</b><span class="pill ${Object.keys(fields).length?'warn':'ok'}">${Object.keys(fields).length?`Изменений: ${Object.keys(fields).length}`:'Без изменений'}</span></div><div class="dadata-preview-grid">${changes||'<span class="small-muted">Для этой записи нет значений по выбранным полям.</span>'}</div></div>`;
  }).join('');
}
function buildDadataPreview() {
  if(!dadataRefreshData){showToast('Сначала загрузите данные DaData.','error');return;}
  let mapping;
  try{mapping=readDadataMapping();}catch(e){showToast(e.message,'error');return;}
  const edits={};let changed=0;
  for(const [id,row] of Object.entries(dadataRefreshData.rows||{})){
    if(row.status!=='ok')continue;
    const fields={};
    mapping.pairs.forEach(pair=>{
      const value=String(row.values?.[pair.source]??'');
      if(value!==''&&value!==fieldValue(dataset.records.find(item=>item.id===id),pair.target))fields[pair.target]=value;
    });
    if(Object.keys(fields).length){edits[id]=fields;changed+=Object.keys(fields).length;}
  }
  dadataPreviewEdits=edits;dadataPreviewNewFields=mapping.newFields;
  renderDadataPreview(edits,mapping.pairs);
  $('saveDadataCardsBtn').disabled=changed===0;
  $('dadataRefreshStatus').textContent=changed
    ? `К сохранению готово ${Object.keys(edits).length} карточек и ${changed} значений. Изменения ещё не записаны.`
    : 'Изменений нет: выбранные значения уже совпадают с книгой.';
  if(changed)showToast(`Сопоставлено ${changed} значений. Проверьте список и нажмите «Сохранить карточки».`,'success');
}
async function fetchDadataForUpdate() {
  const ids=[...selectedIds];
  if(!ids.length){showToast('Отметьте организации в списке.','error');return;}
  if(!dadataConfigured){$('dadataDetails').open=true;$('dadataToken').focus();showToast('Сначала сохраните API-ключ DaData.','error');return;}
  if(!window.confirm(`Будут запрошены данные DaData для ${ids.length} отмеченных записей. Это может расходовать лимит тарифа. Ничего не сохранится до нажатия «Сохранить карточки». Продолжить?`))return;
  dadataRefreshRunning=true;dadataRefreshCancelRequested=false;updateDadataRefreshButton();updateManagerCheckButton();
  $('dadataRefreshProgress').classList.add('show');
  const rows={};let fields=[];let processed=0;let stopReason='';let cancelled=false;let errorText='';
  try{
    for(let offset=0;offset<ids.length;offset+=20){
      if(dadataRefreshCancelRequested){cancelled=true;break;}
      const batch=ids.slice(offset,offset+20);
      const fd=new FormData();fd.append('selected_ids',JSON.stringify(batch));
      const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),120000);
      try{
        const response=await fetch('?action=dadata_fetch',{method:'POST',headers:csrfHeaders(),body:fd,signal:controller.signal});
        const payload=await response.json();
        if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось загрузить данные DaData.');
        Object.assign(rows,payload.rows||{});
        if(!fields.length)fields=payload.fields||[];
        processed+=Number(payload.processed||0);
        $('dadataRefreshProgressText').textContent=`DaData: обработано ${processed} из ${ids.length}…`;
        if(payload.stop){stopReason=String(payload.stopReason||'DaData остановила пакет.');break;}
      }catch(e){
        errorText=e.name==='AbortError'?'DaData не ответила за 120 секунд.':(e.message||'Ошибка загрузки DaData.');
        break;
      }finally{clearTimeout(timer);}
    }
    if(errorText){$('dadataFeedback').textContent=errorText;showToast(errorText,'error');return;}
    if(!Object.keys(rows).length){showToast(stopReason||'DaData не вернула данных по отмеченным записям.','error');return;}
    dadataRefreshData={ids,rows,fields};
    $('dadataRefreshSubtitle').textContent=`Отмечено записей: ${ids.length}. Загружено: ${processed}.`;
    renderDadataSources();renderDadataMapping();
    $('dadataRefreshModal').hidden=false;document.body.classList.add('modal-open');
    if(stopReason||cancelled){
      const note=(stopReason?`Остановлено: ${stopReason}`:'Загрузка остановлена по вашему запросу.')+' Показан загруженный список.';
      $('dadataFeedback').textContent=note;showToast(note,'error');
    }
  }finally{
    dadataRefreshRunning=false;$('dadataRefreshProgress').classList.remove('show');
    updateDadataRefreshButton();updateManagerCheckButton();
  }
}
async function saveDadataCards() {
  if(!dadataPreviewEdits||!Object.keys(dadataPreviewEdits).length){showToast('Нет сопоставленных изменений для сохранения.','error');return;}
  const ids=Object.keys(dadataPreviewEdits);
  const button=$('saveDadataCardsBtn'),old=button.textContent;
  button.disabled=true;button.textContent='Сохраняем карточки…';
  const fd=new FormData();
  fd.append('ids',JSON.stringify(ids));
  fd.append('edits',JSON.stringify(dadataPreviewEdits));
  fd.append('new_fields',JSON.stringify(dadataPreviewNewFields||[]));
  try{
    const response=await fetch('?action=save_records_bulk',{method:'POST',headers:csrfHeaders(),body:fd});
    const payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось сохранить карточки.');
    (payload.records||[]).forEach(record=>{
      const index=dataset.records.findIndex(item=>item.id===record.id);
      if(index>=0)dataset.records[index]=record;
      delete overrides[record.id];
    });
    if((payload.newColumns||[]).length)dataset.customColumns=[...new Set([...(dataset.customColumns||[]),...payload.newColumns])];
    const saved=Number(payload.savedFields||0);
    closeDadataRefreshModal();updateMapping();renderTable();
    showToast(`Сохранено ${ids.length} карточек · ${saved} значений.`,'success');
  }catch(e){
    showToast(e.message||'Ошибка сохранения карточек.','error');
  }finally{button.disabled=false;button.textContent=old;}
}

async function init() {
  try {
    const response=await fetch('?action=data',{cache:'no-store'});
    if (!response.ok) throw new Error('Не удалось загрузить демо-набор.');
    dataset=await response.json();
    managerChecks=dataset.managerChecks||{};
    overrides={};
    populateSources();
    $('dbBadge').textContent=`${dataset.records.length.toLocaleString('ru-RU')} записей · демо-копия`;
    $('dbBadge').className='pill ok';
    $('emptyState').innerHTML='<div class="big">Выберите адресатов для формирования</div><div>По умолчанию показаны все наборы и регионы; начальный выбор пуст, чтобы случайно не сформировать или не отправить всю базу.</div>';
    try { await loadTemplates(); } catch(e) { showToast(e.message||'Не удалось загрузить шаблоны.','error'); }
    try { await loadSmtpSettings(); } catch(e) { $('smtpFeedback').textContent=e.message||'Не удалось загрузить SMTP-настройки.'; }
    try { await loadLetterTemplate(); } catch(e) { $('letterFeedback').textContent=e.message||'Не удалось загрузить шаблон письма.'; }
    try { await loadDadataSettings(); } catch(e) { $('dadataFeedback').textContent=e.message||'Не удалось загрузить настройки DaData.'; }
    try { await refreshQueue(); } catch(e) { $('queueCount').textContent='Ошибка загрузки'; }
    const reviewId=new URLSearchParams(location.search).get('review');
    if(reviewId&&queueItems.some(item=>item.id===reviewId))openQueueItem(reviewId);
    updateMapping(); renderTable();
  } catch(e) {
    $('dbBadge').className='pill bad'; $('dbBadge').textContent='Ошибка данных';
    $('emptyState').innerHTML=`<div class="big">Не удалось загрузить демо-данные</div><div>${esc(e.message||'Проверьте ответ сервера.')}</div>`;
    showToast(e.message||'Ошибка загрузки данных.','error');
  }
}
sourceSelect.addEventListener('change',()=>{pageNumber=1;updateRegionOptions(false);updateMapping();renderTable();});
regionSelect.addEventListener('change',()=>{pageNumber=1;renderTable();});
$('searchInput').addEventListener('input',()=>{pageNumber=1;renderTable();});
$('selectFilteredBtn').addEventListener('click',()=>{getFilteredRecords().forEach(r=>selectedIds.add(r.id));renderTable();showToast(`Выбраны все записи по фильтру (${getFilteredRecords().length}).`);});
$('clearSelectionBtn').addEventListener('click',()=>{selectedIds.clear();renderTable();});
$('prevPage').addEventListener('click',()=>{if(pageNumber>1){pageNumber--;renderTable();}});
$('nextPage').addEventListener('click',()=>{pageNumber++;renderTable();});
$('generateBtn').addEventListener('click',generate);
$('checkManagersBtn').addEventListener('click',checkSelectedManagers);
$('cancelManagerCheckBtn').addEventListener('click',()=>{managerCheckCancelRequested=true;$('managerCheckProgressText').textContent='Завершаем текущий пакет DaData; новые запросы больше не запускаются…';});
$('refreshFromDadataBtn').addEventListener('click',fetchDadataForUpdate);
$('dadataRefreshClose').addEventListener('click',closeDadataRefreshModal);
$('dadataRefreshCancelBtn').addEventListener('click',closeDadataRefreshModal);
$('cancelDadataRefreshBtn').addEventListener('click',()=>{dadataRefreshCancelRequested=true;$('dadataRefreshProgressText').textContent='Завершаем текущий пакет DaData; новые запросы больше не запускаются…';});
$('dadataMappingList').addEventListener('change',event=>{
  if(event.target.dataset.role!=='target')return;
  const input=event.target.closest('.dadata-map-row')?.querySelector('[data-role="new-name"]');
  if(input){input.hidden=event.target.value!=='__new__';if(!input.hidden)input.focus();}
  markDadataMappingChanged();
});
$('dadataMappingList').addEventListener('input',event=>{if(event.target.dataset.role==='new-name')markDadataMappingChanged();});
$('applyDadataMappingBtn').addEventListener('click',buildDadataPreview);
$('saveDadataCardsBtn').addEventListener('click',saveDadataCards);
$('saveDadataBtn').addEventListener('click',saveDadataSettings);
$('templateSelect').addEventListener('change',()=>selectTemplate(templateSelect.value));
$('saveUploadedTemplate').addEventListener('click',saveUploadedTemplate);
$('createHtmlTemplate').addEventListener('click',()=>openHtmlEditor());
$('editHtmlTemplate').addEventListener('click',editHtmlTemplate);
$('saveHtmlTemplateBtn').addEventListener('click',saveHtmlTemplate);
$('htmlTemplateContent').addEventListener('input',updateHtmlPreview);
$('htmlEditorClose').addEventListener('click',closeHtmlEditor);
$('htmlEditorCancel').addEventListener('click',closeHtmlEditor);
$('htmlTemplateModal').addEventListener('click',event=>{if(event.target===$('htmlTemplateModal'))closeHtmlEditor();});
$('applyNameDative').addEventListener('click',()=>{const value=latestInflections?.nameCases?.dative;if(value){setModalField('ФИО ДП',value);derivedManual.nameDative=false;}});
$('applyJobDative').addEventListener('click',()=>{const value=latestInflections?.jobCases?.dative;if(value){setModalField('Должность руководителя дательный',value);derivedManual.jobDative=false;}});
$('saveCardBtn').addEventListener('click',saveRecordCard);
$('modalCloseBtn').addEventListener('click',closeRecordCard);
$('modalCancelBtn').addEventListener('click',closeRecordCard);
$('recordModal').addEventListener('click',event=>{if(event.target===$('recordModal'))closeRecordCard();});
$('queueModalClose').addEventListener('click',closeQueueModal);
$('queueModalCancel').addEventListener('click',closeQueueModal);
$('queueModal').addEventListener('click',event=>{if(event.target===$('queueModal'))closeQueueModal();});
$('queueManagerModalClose').addEventListener('click',closeQueueManager);
$('queueManagerModal').addEventListener('click',event=>{if(event.target===$('queueManagerModal'))closeQueueManager();});
document.querySelectorAll('[data-open-manager]').forEach(element=>element.addEventListener('click',openQueueManager));
window.addEventListener('message',event=>{if(event.data==='docgen-close-queue-manager')closeQueueManager();});
document.querySelectorAll('[data-queue-preview]').forEach(button=>button.addEventListener('click',()=>queuePreviewTab(button.dataset.queuePreview)));
$('queueReviewedCheck').addEventListener('change',syncQueueReviewControls);
['queueTo','queueCc','queueSubject','queueBody'].forEach(id=>$(id).addEventListener('input',()=>{if($('queueReviewedCheck').checked)$('queueReviewedCheck').checked=false;const item=currentQueueItem();if(item&&id==='queueBody')item.body=$('queueBody').value;syncQueueReviewControls();}));
$('saveQueueItemBtn').addEventListener('click',async()=>{try{await saveQueueItem(true);showToast('Правки письма сохранены.','success');}catch(e){showToast(e.message||'Не удалось сохранить правки.','error');}});
$('sendQueueItemBtn').addEventListener('click',sendQueueItem);
$('refreshQueueBtn').addEventListener('click',async()=>{try{await refreshQueue();showToast('Очередь обновлена.','success');}catch(e){showToast(e.message||'Не удалось обновить очередь.','error');}});
$('previewExcelImportBtn').addEventListener('click',previewExcelImport);
$('confirmExcelImportBtn').addEventListener('click',confirmExcelImport);
$('excelImportClose').addEventListener('click',closeExcelImportModal);
$('excelImportCancel').addEventListener('click',closeExcelImportModal);
$('excelImportModal').addEventListener('click',event=>{if(event.target===$('excelImportModal'))closeExcelImportModal();});
$('exportExcelBtn').addEventListener('click',exportExcel);
$('addTableColumnBtn').addEventListener('click',addTableColumn);
$('backupExportBtn').addEventListener('click',exportConfigBackup);
$('backupPreviewBtn').addEventListener('click',previewConfigBackup);
$('backupImportClose').addEventListener('click',closeBackupImportModal);
$('backupImportCancelBtn').addEventListener('click',closeBackupImportModal);
$('confirmBackupImportBtn').addEventListener('click',confirmConfigBackupImport);
$('backupImportModal').addEventListener('click',event=>{if(event.target===$('backupImportModal'))closeBackupImportModal();});
$('addTableSectionBtn').addEventListener('click',addTableSection);
$('templateRenameBtn').addEventListener('click',saveTemplateName);
$('templateNameInput').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();saveTemplateName();}});
$('templateSaveAsBtn').addEventListener('click',()=>saveTemplateAsCopy(templateId));
$('openTemplateManager').addEventListener('click',openTemplateManager);
$('templateManagerClose').addEventListener('click',closeTemplateManager);
$('templateManagerDone').addEventListener('click',closeTemplateManager);
$('templateManagerModal').addEventListener('click',event=>{if(event.target===$('templateManagerModal'))closeTemplateManager();});
$('templateManagerUploadBtn').addEventListener('click',()=>{$('templateManagerUploadInput').value='';$('templateManagerUploadInput').click();});
$('templateManagerUploadInput').addEventListener('change',()=>uploadTemplateFromManager($('templateManagerUploadInput').files?.[0]));
$('templateManagerReplaceInput').addEventListener('change',()=>replaceTemplateFile($('templateManagerReplaceInput').files?.[0]));
$('templateManagerHtmlBtn').addEventListener('click',()=>{closeTemplateManager();openHtmlEditor();});
$('saveLetterTemplateBtn').addEventListener('click',saveLetterTemplate);
$('letterVariableList').addEventListener('click',event=>{
  const button=event.target.closest('[data-var]');
  if(button)insertLetterVariable(button.dataset.var);
});
Object.keys(LETTER_FIELDS).forEach(id=>$(id)?.addEventListener('focus',()=>{letterActiveField=id;renderLetterVariables();}));
Object.keys(LETTER_FIELDS).forEach(id=>$(id)?.addEventListener('input',()=>{updateLetterNotice();if(id==='letterHtmlPattern')renderLetterDesignPreview($('letterDesignPreviewFill')?.checked);}));
document.querySelectorAll('[data-letter-tab]').forEach(button=>button.addEventListener('click',()=>letterTab(button.dataset.letterTab)));
$('copyLetterDefaultsBtn').addEventListener('click',copyLetterDefaults);
$('letterDesignSampleBtn').addEventListener('click',letterDesignSample);
$('letterDesignClearBtn').addEventListener('click',letterDesignClear);
$('letterDesignPreviewFill').addEventListener('change',()=>{renderLetterDesignPreview($('letterDesignPreviewFill').checked);});
$('letterDesignFileInput').addEventListener('change',()=>{
  const file=$('letterDesignFileInput').files?.[0];
  if(!file)return;
  if(file.size>1000000){showToast('HTML-дизайн больше 1 МБ — упростите файл.','error');$('letterDesignFileInput').value='';return;}
  const reader=new FileReader();
  reader.onload=()=>{
    if($('letterHtmlPattern').value.trim()&&!window.confirm('Заменить текущий HTML-дизайн содержимым файла?')){$('letterDesignFileInput').value='';return;}
    $('letterHtmlPattern').value=String(reader.result||'');
    letterTab('design');renderLetterDesignPreview($('letterDesignPreviewFill').checked);updateLetterNotice();
    $('letterDesignFeedback').textContent='Файл загружен: '+file.name+'. Проверьте коды и сохраните.';
  };
  reader.onerror=()=>showToast('Не удалось прочитать файл.','error');
  reader.readAsText(file,'utf-8');
  $('letterDesignFileInput').value='';
});
$('saveSmtpBtn').addEventListener('click',saveSmtpSettings);
$('testSmtpBtn').addEventListener('click',testSmtp);
$('smtpLogClose').addEventListener('click',closeSmtpLog);
$('smtpLogCloseBtn').addEventListener('click',closeSmtpLog);
$('smtpLogModal').addEventListener('click',event=>{if(event.target===$('smtpLogModal'))closeSmtpLog();});
$('smtpLogCopyBtn').addEventListener('click',async()=>{
  const portsText=$('smtpLogPortsBox').hidden?'':('Порты:\n'+$('smtpLogPorts').innerText);
  const text=[$('smtpLogTitle').textContent,$('smtpLogHeadline').textContent,$('smtpLogSteps').innerText,portsText,$('smtpLogTranscript').textContent,$('smtpLogContext').innerText].filter(Boolean).join('\n');
  try { await navigator.clipboard.writeText(text); $('smtpLogFeedback').textContent='Журнал скопирован.'; }
  catch(e) {
    const area=document.createElement('textarea');area.value=text;document.body.appendChild(area);area.select();
    try{document.execCommand('copy');$('smtpLogFeedback').textContent='Журнал скопирован.';}catch{ $('smtpLogFeedback').textContent='Браузер не дал скопировать: выделите текст вручную.'; }
    area.remove();
  }
});
$('addSmtpAccountBtn').addEventListener('click',addSmtpAccount);
$('deleteSmtpAccountBtn').addEventListener('click',deleteSmtpAccount);
$('smtpAccountSelect').addEventListener('change',()=>applySmtpAccountToForm($('smtpAccountSelect').value));
$('managerCheckDetail').addEventListener('click',event=>{
  const button=event.target.closest('[data-apply-manager-name]');
  if(!button||!button.dataset.applyManagerName)return;
  const name=button.dataset.applyManagerName;
  ['ФИО','Руководитель'].forEach(field=>{if(modalInput(field))setModalField(field,name);});
  derivedManual.nameDative=false;derivedManual.initials=false;derivedManual.nameParts=false;derivedManual.salutation=false;
  modalSourceEdited.name=true;
  updateInflections('name');
  showToast('ФИО из DaData подставлено. Проверьте карточку и нажмите «Сохранить карточку».','success');
});
['smtpHost','smtpFromEmail'].forEach(id=>$(id).addEventListener('input',updateSmtpState));
$('smtpPort').addEventListener('change',()=>{
  const port=$('smtpPort').value.trim();
  if(port==='465')$('smtpEncryption').value='ssl';
  else if(port==='587')$('smtpEncryption').value='tls';
  else if(port==='25')$('smtpEncryption').value='none';
});
$('smtpEncryption').addEventListener('change',()=>{
  const encryption=$('smtpEncryption').value, port=$('smtpPort').value.trim();
  if(encryption==='ssl'&&port==='587')$('smtpPort').value='465';
  if(encryption==='tls'&&port==='465')$('smtpPort').value='587';
});
document.addEventListener('keydown',event=>{if(event.key==='Escape'){if(!$('smtpLogModal').hidden)closeSmtpLog();else if(!$('queueManagerModal').hidden)closeQueueManager();else if(!$('backupImportModal').hidden)closeBackupImportModal();else if(!$('queueModal').hidden)closeQueueModal();else if(!$('excelImportModal').hidden)closeExcelImportModal();else if(!$('htmlTemplateModal').hidden)closeHtmlEditor();else if(!$('recordModal').hidden)closeRecordCard();}});
$('dropTemplate').addEventListener('click',()=>templateInput.click());
$('dropTemplate').addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();templateInput.click();}});
templateInput.addEventListener('change',()=>analyzeUpload(templateInput.files?.[0]));
$('dropTemplate').addEventListener('dragover',e=>{e.preventDefault();$('dropTemplate').classList.add('dragover');});
$('dropTemplate').addEventListener('dragleave',()=> $('dropTemplate').classList.remove('dragover'));
$('dropTemplate').addEventListener('drop',e=>{e.preventDefault();$('dropTemplate').classList.remove('dragover');const file=e.dataTransfer?.files?.[0];if(file)analyzeUpload(file);});
init();
</script>
</body>
</html>
