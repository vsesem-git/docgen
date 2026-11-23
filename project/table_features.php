<?php
/**
 * XLSX import/export and user-managed table columns/sections.
 * XLSX files are read/written with the platform's DOM and ZipArchive extensions;
 * no workbook is extracted to the web root and no external spreadsheet service is used.
 */

function docgenTableData(): array
{
    $data = docgenReadJson(DOCGEN_TABLE_DATA_FILE, []);
    $rawColumns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
    $rawSections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
    $rawRecords = is_array($data['records'] ?? null) ? $data['records'] : [];
    $columns = [];
    foreach ($rawColumns as $column) {
        if (is_string($column) && trim($column) !== '') {
            $columns[] = trim($column);
        }
    }
    $sections = array_values(array_filter($rawSections, static fn($item): bool => is_array($item)));
    $records = array_values(array_filter($rawRecords, static fn($item): bool => is_array($item) && isset($item['id'])));
    return ['version' => 1, 'columns' => array_values(array_unique($columns)), 'sections' => $sections, 'records' => $records];
}

function docgenSaveTableData(array $data): void
{
    $data['version'] = 1;
    $data['columns'] = array_values(array_unique(array_filter($data['columns'] ?? [], static fn($name): bool => is_string($name) && trim($name) !== '')));
    $data['sections'] = array_values(array_filter($data['sections'] ?? [], static fn($item): bool => is_array($item)));
    $data['records'] = array_values(array_filter($data['records'] ?? [], static fn($item): bool => is_array($item) && isset($item['id'])));
    docgenWriteJson(DOCGEN_TABLE_DATA_FILE, $data);
}

function docgenMergeUserTableData(array $dataset): array
{
    $custom = docgenTableData();
    $records = array_values(array_filter($dataset['records'] ?? [], static fn($item): bool => is_array($item) && isset($item['id'])));
    $ids = [];
    foreach ($records as $record) {
        $ids[(string) $record['id']] = true;
    }
    foreach ($custom['records'] as $record) {
        $id = (string) ($record['id'] ?? '');
        if ($id === '' || isset($ids[$id])) {
            continue;
        }
        $records[] = $record;
        $ids[$id] = true;
    }

    $sheets = array_values(array_filter($dataset['sheets'] ?? [], static fn($item): bool => is_array($item) && isset($item['key'])));
    $sheetIndex = [];
    foreach ($sheets as $index => $sheet) {
        $sheetIndex[(string) $sheet['key']] = $index;
    }
    foreach ($custom['sections'] as $section) {
        $key = (string) ($section['key'] ?? '');
        if ($key === '' || isset($sheetIndex[$key])) {
            continue;
        }
        $sheetIndex[$key] = count($sheets);
        $sheets[] = [
            'key' => $key,
            'label' => (string) ($section['label'] ?? $key),
            'kind' => (string) ($section['kind'] ?? 'Пользовательский раздел'),
            'count' => 0,
            'custom' => true,
        ];
    }

    $counts = [];
    foreach ($records as $record) {
        $key = (string) ($record['source_key'] ?? '');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    foreach ($sheets as &$sheet) {
        $sheet['count'] = (int) ($counts[(string) $sheet['key']] ?? 0);
    }
    unset($sheet);
    $dataset['records'] = $records;
    $dataset['sheets'] = $sheets;
    $dataset['customColumns'] = $custom['columns'];
    return $dataset;
}

function docgenExcelReservedHeader(string $header): ?string
{
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $header) ?? $header), 'UTF-8');
    $key = str_replace(['_', '-'], ' ', $key);
    $key = trim(preg_replace('/\s+/u', ' ', $key) ?? $key);
    if (in_array($key, ['id записи', 'id записи приложения', 'record id', '__record id', 'record_id'], true)) {
        return 'id';
    }
    if (in_array($key, ['раздел', 'набор', 'section', 'source section'], true)) {
        return 'section';
    }
    if (in_array($key, ['тип записи', 'record type'], true)) {
        return 'kind';
    }
    return null;
}

function docgenExcelCleanFieldName(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
    return trim($name);
}

function docgenExcelColumnName(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function docgenExcelXmlEscape(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function docgenExcelCellXml(string $reference, $value): string
{
    if (is_bool($value)) {
        $value = $value ? 'TRUE' : 'FALSE';
    } elseif (is_scalar($value) || $value === null) {
        $value = (string) ($value ?? '');
    } else {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
    $value = mb_substr($value, 0, 32767, 'UTF-8');
    return '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . docgenExcelXmlEscape($value) . '</t></is></c>';
}

function docgenExcelUniqueSheetNames(array $labels): array
{
    $used = [];
    $result = [];
    foreach ($labels as $label) {
        $base = preg_replace('/[\\[\\]:*?\/\\\\]/u', ' ', (string) $label) ?? 'Данные';
        $base = trim($base, " '\t\r\n");
        if ($base === '') {
            $base = 'Данные';
        }
        $base = mb_substr($base, 0, 31, 'UTF-8');
        $candidate = $base;
        $counter = 2;
        while (isset($used[mb_strtolower($candidate, 'UTF-8')])) {
            $suffix = ' (' . $counter++ . ')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
        }
        $used[mb_strtolower($candidate, 'UTF-8')] = true;
        $result[] = $candidate;
    }
    return $result;
}

function docgenWriteXlsx(array $sheets, string $path): void
{
    if (!$sheets) {
        throw new RuntimeException('Нет таблиц для экспорта в Excel.');
    }
    $labels = array_map(static fn(array $sheet): string => (string) ($sheet['name'] ?? 'Данные'), $sheets);
    $sheetNames = docgenExcelUniqueSheetNames($labels);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать XLSX-файл.');
    }
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $workbookSheets = '';
    $relationships = '';
    foreach ($sheets as $index => $sheet) {
        $sheetId = $index + 1;
        $sheetName = $sheetNames[$index];
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $headers = array_values($sheet['headers'] ?? []);
        if (count($headers) > 500) {
            $zip->close();
            throw new RuntimeException('Для экспорта доступно не более 500 колонок на лист.');
        }
        $rowNumber = 1;
        $sheetXml .= '<row r="1">';
        foreach ($headers as $column => $header) {
            $sheetXml .= docgenExcelCellXml(docgenExcelColumnName($column + 1) . '1', $header);
        }
        $sheetXml .= '</row>';
        foreach (($sheet['rows'] ?? []) as $row) {
            $rowNumber++;
            $sheetXml .= '<row r="' . $rowNumber . '">';
            foreach ($headers as $column => $header) {
                $sheetXml .= docgenExcelCellXml(docgenExcelColumnName($column + 1) . $rowNumber, $row[$column] ?? '');
            }
            $sheetXml .= '</row>';
            if ($rowNumber > 20001) {
                $zip->close();
                throw new RuntimeException('За один раз можно экспортировать не более 20 000 строк на лист.');
            }
        }
        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $sheetId . '.xml', $sheetXml);
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . docgenExcelXmlEscape($sheetName) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        $relationships .= '<Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
    }
    $contentTypes .= '</Types>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . $workbookSheets . '</sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    if (!$zip->close()) {
        throw new RuntimeException('Не удалось завершить сборку XLSX-файла.');
    }
}

function docgenExportExcel(): never
{
    $scope = (string) ($_POST['scope'] ?? 'all');
    $dataset = loadDataset();
    $records = $dataset['records'] ?? [];
    if ($scope !== 'all') {
        $ids = json_decode((string) ($_POST['record_ids'] ?? '[]'), true);
        if (!is_array($ids)) {
            sendJson(['ok' => false, 'error' => 'Не удалось прочитать список записей для экспорта.'], 400);
        }
        $selected = [];
        foreach ($ids as $id) {
            if (is_scalar($id)) {
                $selected[trim((string) $id)] = true;
            }
        }
        if (!$selected) {
            sendJson(['ok' => false, 'error' => 'В выбранной области нет записей для экспорта.'], 422);
        }
        $records = array_values(array_filter($records, static fn(array $record): bool => isset($selected[(string) ($record['id'] ?? '')])));
    }
    if (!$records) {
        sendJson(['ok' => false, 'error' => 'Нет записей для экспорта в Excel.'], 422);
    }

    $sectionMap = [];
    foreach (($dataset['sheets'] ?? []) as $section) {
        $sectionMap[(string) ($section['key'] ?? '')] = $section;
    }
    $groups = [];
    foreach ($records as $record) {
        $key = (string) ($record['source_key'] ?? 'other');
        if (!isset($groups[$key])) {
            $groups[$key] = ['name' => (string) ($sectionMap[$key]['label'] ?? $record['source'] ?? $key), 'records' => []];
        }
        $groups[$key]['records'][] = $record;
    }
    if ($scope === 'all') {
        foreach (($dataset['sheets'] ?? []) as $section) {
            $key = (string) ($section['key'] ?? '');
            if ($key !== '' && !isset($groups[$key])) {
                $groups[$key] = ['name' => (string) ($section['label'] ?? $key), 'records' => []];
            }
        }
    }

    $columns = [];
    foreach ($records as $record) {
        foreach (array_keys(baseFields($record)) as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
    }
    foreach (docgenTableData()['columns'] as $column) {
        if (!in_array($column, $columns, true)) {
            $columns[] = $column;
        }
    }
    $headers = array_merge(['ID записи', 'Раздел', 'Тип записи'], $columns);
    if (count($headers) > 500) {
        sendJson(['ok' => false, 'error' => 'В выборке более 497 колонок; сократите структуру перед экспортом.'], 422);
    }
    $workbookSheets = [];
    foreach ($groups as $group) {
        $rows = [];
        foreach ($group['records'] as $record) {
            $fields = baseFields($record);
            $rows[] = array_merge(
                [(string) ($record['id'] ?? ''), (string) $group['name'], (string) ($record['kind'] ?? '')],
                array_map(static fn(string $column) => $fields[$column] ?? '', $columns)
            );
        }
        $workbookSheets[] = ['name' => $group['name'], 'headers' => $headers, 'rows' => $rows];
    }

    $temporary = '';
    try {
        docgenEnsureDir(DOWNLOADS_DIR);
        $temporary = DOWNLOADS_DIR . '/excel-export-' . bin2hex(random_bytes(8)) . '.xlsx';
        docgenWriteXlsx($workbookSheets, $temporary);
        $contents = file_get_contents($temporary);
        @unlink($temporary);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('Не удалось прочитать готовый XLSX-файл.');
        }
    } catch (Throwable $e) {
        if ($temporary !== '') {
            @unlink($temporary);
        }
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $filename = 'Выгрузка_таблиц_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . strlen($contents));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $contents;
    exit;
}

function docgenExcelLoadXml(ZipArchive $zip, string $path): DOMDocument
{
    $xml = $zip->getFromName($path);
    if (!is_string($xml) || $xml === '' || strlen($xml) > 30_000_000) {
        throw new RuntimeException('Файл Excel не содержит корректный XML: ' . $path);
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        throw new RuntimeException('В книге Excel обнаружено запрещённое XML-объявление.');
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->resolveExternals = false;
    $document->substituteEntities = false;
    $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$ok) {
        throw new RuntimeException('Не удалось прочитать структуру книги Excel.');
    }
    return $document;
}

function docgenExcelTextNodes(DOMNode $node, string $localName): array
{
    $result = [];
    if ($node instanceof DOMElement && $node->localName === $localName) {
        $result[] = $node;
    }
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            array_push($result, ...docgenExcelTextNodes($child, $localName));
        }
    }
    return $result;
}

function docgenExcelZipTarget(string $target): string
{
    $target = str_replace('\\', '/', $target);
    if (str_starts_with($target, '/')) {
        $target = ltrim($target, '/');
    } else {
        $target = 'xl/' . $target;
    }
    $parts = [];
    foreach (explode('/', $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if (!$parts) {
                throw new RuntimeException('Книга Excel содержит недопустимую ссылку на лист.');
            }
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    $path = implode('/', $parts);
    if (!str_starts_with($path, 'xl/worksheets/') || !str_ends_with(strtolower($path), '.xml')) {
        throw new RuntimeException('Поддерживаются только обычные листы данных .xlsx.');
    }
    return $path;
}

function docgenExcelCellValue(DOMElement $cell, array $sharedStrings, int &$formulaCount): string
{
    $type = $cell->getAttribute('t');
    $values = docgenExcelTextNodes($cell, 'v');
    $value = $values ? (string) $values[0]->textContent : '';
    if (docgenExcelTextNodes($cell, 'f')) {
        $formulaCount++;
    }
    if ($type === 'inlineStr') {
        $inline = docgenExcelTextNodes($cell, 'is');
        if ($inline) {
            $texts = docgenExcelTextNodes($inline[0], 't');
            return implode('', array_map(static fn(DOMNode $text): string => (string) $text->textContent, $texts));
        }
        return '';
    }
    if ($type === 's') {
        $index = ctype_digit($value) ? (int) $value : -1;
        return $sharedStrings[$index] ?? '';
    }
    if ($type === 'b') {
        return $value === '1' ? 'TRUE' : ($value === '0' ? 'FALSE' : $value);
    }
    return $value;
}

function docgenExcelColumnIndex(string $reference, int $fallback): int
{
    if (!preg_match('/^([A-Z]+)[0-9]+$/i', $reference, $matches)) {
        return $fallback;
    }
    $column = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $column = $column * 26 + ord($letter) - 64;
    }
    return $column - 1;
}

function docgenReadXlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Не удалось открыть файл .xlsx. Проверьте, что это книга Excel формата XLSX.');
    }
    $unpackedSize = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $unpackedSize += (int) ($stat['size'] ?? 0);
        if ($unpackedSize > 100_000_000) {
            $zip->close();
            throw new RuntimeException('Распакованный размер книги превышает ограничение 100 МБ.');
        }
    }
    if ($zip->locateName('xl/vbaProject.bin') !== false) {
        $zip->close();
        throw new RuntimeException('Книги с макросами не поддерживаются. Сохраните файл как .xlsx без макросов.');
    }
    if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('xl/_rels/workbook.xml.rels') === false) {
        $zip->close();
        throw new RuntimeException('В файле отсутствуют основные части книги Excel.');
    }
    try {
        $workbook = docgenExcelLoadXml($zip, 'xl/workbook.xml');
        $relations = docgenExcelLoadXml($zip, 'xl/_rels/workbook.xml.rels');
        $relationshipMap = [];
        foreach (docgenExcelTextNodes($relations, 'Relationship') as $relation) {
            if (strtolower($relation->getAttribute('targetmode')) === 'external') {
                continue;
            }
            $relationshipMap[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
        }
        $sharedStrings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $sharedDocument = docgenExcelLoadXml($zip, 'xl/sharedStrings.xml');
            foreach (docgenExcelTextNodes($sharedDocument, 'si') as $item) {
                $texts = docgenExcelTextNodes($item, 't');
                $sharedStrings[] = implode('', array_map(static fn(DOMNode $text): string => (string) $text->textContent, $texts));
            }
        }
        $sheetElements = docgenExcelTextNodes($workbook, 'sheet');
        if (!$sheetElements || count($sheetElements) > 50) {
            throw new RuntimeException('В книге должно быть от 1 до 50 листов.');
        }
        $sheets = [];
        $totalRows = 0;
        foreach ($sheetElements as $sheetElement) {
            $name = trim($sheetElement->getAttribute('name'));
            $relationshipId = $sheetElement->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($relationshipId === '') {
                $relationshipId = $sheetElement->getAttribute('r:id');
            }
            if ($name === '' || !isset($relationshipMap[$relationshipId])) {
                continue;
            }
            $sheetPath = docgenExcelZipTarget($relationshipMap[$relationshipId]);
            $sheetDocument = docgenExcelLoadXml($zip, $sheetPath);
            $sheetDataNodes = docgenExcelTextNodes($sheetDocument, 'sheetData');
            $sheetData = $sheetDataNodes[0] ?? null;
            $headersByIndex = [];
            $headersSeen = [];
            $rows = [];
            $headerDone = false;
            $formulaCount = 0;
            if ($sheetData instanceof DOMElement) {
                $fallbackRow = 0;
                foreach ($sheetData->childNodes as $rowNode) {
                    if (!$rowNode instanceof DOMElement || $rowNode->localName !== 'row') {
                        continue;
                    }
                    $fallbackRow++;
                    $rowNumber = (int) ($rowNode->getAttribute('r') ?: $fallbackRow);
                    $cells = [];
                    $fallbackColumn = 0;
                    foreach ($rowNode->childNodes as $cellNode) {
                        if (!$cellNode instanceof DOMElement || $cellNode->localName !== 'c') {
                            continue;
                        }
                        $columnIndex = docgenExcelColumnIndex($cellNode->getAttribute('r'), $fallbackColumn);
                        $fallbackColumn = $columnIndex + 1;
                        if ($columnIndex >= 500) {
                            throw new RuntimeException('В листе «' . $name . '» больше 500 колонок.');
                        }
                        $cells[$columnIndex] = docgenExcelCellValue($cellNode, $sharedStrings, $formulaCount);
                    }
                    if (!$cells || !array_filter($cells, static fn($value): bool => trim((string) $value) !== '')) {
                        continue;
                    }
                    if (!$headerDone) {
                        foreach ($cells as $columnIndex => $headerValue) {
                            $header = docgenExcelCleanFieldName((string) $headerValue);
                            if ($header === '') {
                                continue;
                            }
                            if (mb_strlen($header, 'UTF-8') > 120) {
                                throw new RuntimeException('В листе «' . $name . '» заголовок колонки длиннее 120 символов.');
                            }
                            $role = docgenExcelReservedHeader($header);
                            $normalized = $role !== null ? '@' . $role : mb_strtolower($header, 'UTF-8');
                            if (isset($headersSeen[$normalized])) {
                                throw new RuntimeException('В листе «' . $name . '» повторяется заголовок «' . $header . '».');
                            }
                            $headersSeen[$normalized] = true;
                            $headersByIndex[(int) $columnIndex] = $header;
                        }
                        if (!$headersByIndex) {
                            throw new RuntimeException('В листе «' . $name . '» не найдена строка заголовков.');
                        }
                        $headerDone = true;
                        continue;
                    }
                    $values = [];
                    $hasFieldValue = false;
                    foreach ($headersByIndex as $columnIndex => $header) {
                        $value = trim((string) ($cells[$columnIndex] ?? ''));
                        if (mb_strlen($value, 'UTF-8') > 5000) {
                            throw new RuntimeException('В листе «' . $name . '», строке ' . $rowNumber . ' есть значение длиннее 5 000 символов.');
                        }
                        $values[$header] = $value;
                        if ($value !== '' && docgenExcelReservedHeader($header) === null) {
                            $hasFieldValue = true;
                        }
                    }
                    if (!$values) {
                        continue;
                    }
                    $rows[] = ['rowNumber' => $rowNumber, 'values' => $values, 'hasFieldValue' => $hasFieldValue];
                    $totalRows++;
                    if ($totalRows > 20_000) {
                        throw new RuntimeException('Книга содержит более 20 000 строк данных. Разделите её на несколько импортов.');
                    }
                }
            }
            $headers = array_values($headersByIndex);
            if (count($headers) > 250) {
                throw new RuntimeException('В листе «' . $name . '» больше 250 колонок.');
            }
            $sheets[] = [
                'name' => mb_substr($name, 0, 120, 'UTF-8'),
                'headers' => $headers,
                'rows' => $rows,
                'rowCount' => count($rows),
                'formulaCount' => $formulaCount,
            ];
        }
        if (!$sheets) {
            throw new RuntimeException('В книге не найдено листов с таблицами.');
        }
        return ['sheets' => $sheets, 'totalRows' => $totalRows];
    } finally {
        $zip->close();
    }
}

function docgenExcelSectionMatch(array $sections, string $value): ?array
{
    $needle = mb_strtolower(trim($value), 'UTF-8');
    if ($needle === '') {
        return null;
    }
    foreach ($sections as $section) {
        if ($needle === mb_strtolower((string) ($section['label'] ?? ''), 'UTF-8') || $needle === mb_strtolower((string) ($section['key'] ?? ''), 'UTF-8')) {
            return $section;
        }
    }
    return null;
}

function docgenExcelRowPayload(array $row, array $columnNameMap): array
{
    $id = '';
    $sectionName = '';
    $fields = [];
    foreach (($row['values'] ?? []) as $header => $value) {
        $role = docgenExcelReservedHeader((string) $header);
        if ($role === 'id') {
            $id = trim((string) $value);
            continue;
        }
        if ($role === 'section') {
            $sectionName = trim((string) $value);
            continue;
        }
        if ($role === 'kind') {
            continue;
        }
        $name = $columnNameMap[mb_strtolower((string) $header, 'UTF-8')] ?? docgenExcelCleanFieldName((string) $header);
        if ($name !== '') {
            $fields[$name] = trim((string) $value);
        }
    }
    if (isset($fields['ИНН'])) {
        $inn = preg_replace('/\D+/u', '', $fields['ИНН']) ?? '';
        if (strlen($inn) === 9) {
            $inn = '0' . $inn;
        }
        $fields['ИНН'] = $inn;
    }
    if (isset($fields['КПП'])) {
        $kpp = preg_replace('/\D+/u', '', $fields['КПП']) ?? '';
        if (strlen($kpp) === 8) {
            $kpp = '0' . $kpp;
        }
        $fields['КПП'] = $kpp;
    }
    return ['id' => $id, 'sectionName' => $sectionName, 'fields' => $fields, 'rowNumber' => (int) ($row['rowNumber'] ?? 0), 'hasFieldValue' => (bool) ($row['hasFieldValue'] ?? false)];
}

function docgenExcelKeyDigits(string $value): string
{
    return preg_replace('/\D+/u', '', $value) ?? '';
}

function docgenPlanExcelImport(array $workbook, array $dataset, array $columnNameMap): array
{
    $byId = [];
    $idCounts = [];
    $byPair = [];
    foreach (($dataset['records'] ?? []) as $record) {
        if (!is_array($record) || !isset($record['id'])) {
            continue;
        }
        $id = (string) $record['id'];
        $byId[$id] = $record;
        $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
        $fields = baseFields($record);
        $inn = docgenExcelKeyDigits((string) ($fields['ИНН'] ?? ''));
        $kpp = docgenExcelKeyDigits((string) ($fields['КПП'] ?? ''));
        if (in_array(strlen($inn), [10, 12], true) && strlen($kpp) === 9) {
            $byPair[$inn . '/' . $kpp][] = $id;
        }
    }
    $ambiguousIds = [];
    foreach ($idCounts as $id => $count) {
        if ($count > 1) {
            $ambiguousIds[$id] = true;
            unset($byId[$id]);
        }
    }
    $flat = [];
    $inputIdentityCounts = [];
    foreach (($workbook['sheets'] ?? []) as $sheetIndex => $sheet) {
        foreach (($sheet['rows'] ?? []) as $row) {
            $payload = docgenExcelRowPayload($row, $columnNameMap);
            $identities = [];
            $idIsKnown = $payload['id'] !== '' && isset($byId[$payload['id']]);
            if ($payload['id'] !== '') {
                $identities[] = 'id:' . $payload['id'];
            }
            if (!$idIsKnown) {
                $inn = docgenExcelKeyDigits((string) ($payload['fields']['ИНН'] ?? ''));
                $kpp = docgenExcelKeyDigits((string) ($payload['fields']['КПП'] ?? ''));
                if (in_array(strlen($inn), [10, 12], true) && strlen($kpp) === 9) {
                    $identities[] = 'pair:' . $inn . '/' . $kpp;
                }
            }
            foreach ($identities as $identity) {
                $inputIdentityCounts[$identity] = ($inputIdentityCounts[$identity] ?? 0) + 1;
            }
            $flat[] = ['sheetIndex' => $sheetIndex, 'payload' => $payload, 'identities' => $identities];
        }
    }
    $plan = [];
    $assignedTargets = [];
    $insertedPairs = [];
    foreach ($flat as $item) {
        $payload = $item['payload'];
        $action = 'insert';
        $recordId = '';
        $reason = '';
        $duplicateInput = false;
        foreach ($item['identities'] as $identity) {
            if (($inputIdentityCounts[$identity] ?? 0) > 1) {
                $duplicateInput = true;
                break;
            }
        }
        if ($duplicateInput) {
            $action = 'duplicate';
            $reason = 'Одинаковый ID или ИНН/КПП повторяется в импортируемой книге; строки пропущены.';
        } elseif (!$payload['hasFieldValue']) {
            $action = 'empty';
            $reason = 'В строке нет значений колонок данных.';
        } elseif ($payload['id'] !== '' && isset($byId[$payload['id']])) {
            $action = 'update';
            $recordId = $payload['id'];
        } else {
            $inn = docgenExcelKeyDigits((string) ($payload['fields']['ИНН'] ?? ''));
            $kpp = docgenExcelKeyDigits((string) ($payload['fields']['КПП'] ?? ''));
            $pairIsValid = in_array(strlen($inn), [10, 12], true) && strlen($kpp) === 9;
            if ($pairIsValid) {
                $pair = $inn . '/' . $kpp;
                $matches = $byPair[$pair] ?? [];
                if (count($matches) > 1) {
                    $action = 'ambiguous';
                    $reason = 'В базе несколько записей с таким ИНН/КПП; автоматическое обновление отменено.';
                } elseif (count($matches) === 1 && isset($ambiguousIds[$matches[0]])) {
                    $action = 'ambiguous';
                    $reason = 'Совпадение ИНН/КПП ведёт к повторяющемуся внутреннему ID; автоматическое обновление отменено.';
                } elseif (count($matches) === 1) {
                    $action = 'update';
                    $recordId = $matches[0];
                } elseif (isset($insertedPairs[$pair])) {
                    $action = 'duplicate';
                    $reason = 'ИНН/КПП повторяется в импортируемой книге.';
                } elseif (isset($ambiguousIds[$payload['id']])) {
                    $action = 'ambiguous';
                    $reason = 'В базе повторяется внутренний ID; по переданным реквизитам нет единственного совпадения.';
                } else {
                    $insertedPairs[$pair] = true;
                }
            } elseif (isset($ambiguousIds[$payload['id']])) {
                $action = 'ambiguous';
                $reason = 'В базе повторяется внутренний ID; автоматическое обновление отменено.';
            }
        }
        if ($recordId !== '' && isset($assignedTargets[$recordId])) {
            $action = 'duplicate';
            $recordId = '';
            $reason = 'Несколько строк претендуют на обновление одной записи; повторная строка пропущена.';
        } elseif ($recordId !== '') {
            $assignedTargets[$recordId] = true;
        }
        $plan[] = ['sheetIndex' => $item['sheetIndex'], 'payload' => $payload, 'action' => $action, 'recordId' => $recordId, 'reason' => $reason];
    }
    return $plan;
}

function docgenExcelCanonicalColumnMap(array $dataset, array $table): array
{
    $existing = [];
    foreach ($table['columns'] ?? [] as $column) {
        $existing[mb_strtolower((string) $column, 'UTF-8')] = (string) $column;
    }
    foreach (($dataset['records'] ?? []) as $record) {
        foreach (array_keys(baseFields($record)) as $column) {
            $existing[mb_strtolower((string) $column, 'UTF-8')] = (string) $column;
        }
    }
    $map = [];
    foreach ($existing as $normalized => $canonical) {
        $map[$normalized] = $canonical;
    }
    return $map;
}

function docgenExcelImportPreview(): never
{
    docgenCleanupStagedExcelImports();
    $file = $_FILES['excel_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        sendJson(['ok' => false, 'error' => 'Выберите книгу Excel в формате .xlsx.'], 422);
    }
    $originalName = basename((string) ($file['name'] ?? ''));
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
        sendJson(['ok' => false, 'error' => 'Поддерживается формат .xlsx. Сохраните книгу Excel в современном формате XLSX.'], 422);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 20_000_000) {
        sendJson(['ok' => false, 'error' => 'Размер книги должен быть не более 20 МБ.'], 422);
    }
    docgenEnsureDir(DOCGEN_EXCEL_IMPORT_DIR);
    $importId = bin2hex(random_bytes(16));
    $stagedPath = DOCGEN_EXCEL_IMPORT_DIR . '/' . $importId . '.xlsx';
    if (!copy((string) $file['tmp_name'], $stagedPath)) {
        sendJson(['ok' => false, 'error' => 'Не удалось временно сохранить книгу в приватном каталоге.'], 500);
    }
    @chmod($stagedPath, 0600);
    try {
        $workbook = docgenReadXlsx($stagedPath);
        $dataset = loadDataset();
        $table = docgenTableData();
        $columnNameMap = docgenExcelCanonicalColumnMap($dataset, $table);
        $plan = docgenPlanExcelImport($workbook, $dataset, $columnNameMap);
        $planBySheet = [];
        foreach ($plan as $item) {
            $planBySheet[$item['sheetIndex']][$item['action']] = ($planBySheet[$item['sheetIndex']][$item['action']] ?? 0) + 1;
        }
        $sections = array_map(static fn(array $section): array => ['key' => (string) $section['key'], 'label' => (string) $section['label'], 'kind' => (string) ($section['kind'] ?? '')], $dataset['sheets'] ?? []);
        foreach ($workbook['sheets'] as $index => &$sheet) {
            $suggestion = docgenExcelSectionMatch($dataset['sheets'] ?? [], (string) $sheet['name']);
            if ($suggestion === null) {
                foreach ($sheet['rows'] as $row) {
                    foreach ($row['values'] as $header => $value) {
                        if (docgenExcelReservedHeader((string) $header) === 'section' && trim((string) $value) !== '') {
                            $suggestion = docgenExcelSectionMatch($dataset['sheets'] ?? [], (string) $value);
                            if ($suggestion !== null) {
                                break 2;
                            }
                        }
                    }
                }
            }
            $sheet['suggestedTarget'] = $suggestion ? (string) $suggestion['key'] : 'new';
            $sheet['plan'] = [
                'update' => (int) ($planBySheet[$index]['update'] ?? 0),
                'insert' => (int) ($planBySheet[$index]['insert'] ?? 0),
                'ambiguous' => (int) ($planBySheet[$index]['ambiguous'] ?? 0),
                'duplicate' => (int) ($planBySheet[$index]['duplicate'] ?? 0),
                'empty' => (int) ($planBySheet[$index]['empty'] ?? 0),
            ];
            $sheet['sample'] = [];
            foreach (array_slice($sheet['rows'], 0, 3) as $row) {
                $sample = [];
                foreach (array_slice($sheet['headers'], 0, 8) as $header) {
                    $sample[$header] = mb_substr((string) ($row['values'][$header] ?? ''), 0, 160, 'UTF-8');
                }
                $sheet['sample'][] = $sample;
            }
            unset($sheet['rows']);
        }
        unset($sheet);
        sendJson(['ok' => true, 'importId' => $importId, 'filename' => $originalName, 'totalRows' => $workbook['totalRows'], 'sheets' => $workbook['sheets'], 'sections' => $sections, 'message' => 'Файл проверен. До подтверждения изменения в таблицах не применяются.']);
    } catch (Throwable $e) {
        @unlink($stagedPath);
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function docgenExcelStagePath(string $importId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $importId)) {
        throw new RuntimeException('Идентификатор импорта некорректен.');
    }
    return DOCGEN_EXCEL_IMPORT_DIR . '/' . $importId . '.xlsx';
}

function docgenCleanupStagedExcelImports(): void
{
    if (!is_dir(DOCGEN_EXCEL_IMPORT_DIR)) {
        return;
    }
    $cutoff = time() - 3600;
    foreach (scandir(DOCGEN_EXCEL_IMPORT_DIR) ?: [] as $entry) {
        if (!preg_match('/^[a-f0-9]{32}\.xlsx$/', $entry)) {
            continue;
        }
        $path = DOCGEN_EXCEL_IMPORT_DIR . '/' . $entry;
        if (is_file($path) && filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

function docgenExcelUniqueSectionLabel(string $label, array $dataset, array $table): string
{
    $base = trim($label);
    if ($base === '') {
        $base = 'Импорт из Excel';
    }
    $used = [];
    foreach (($dataset['sheets'] ?? []) as $section) {
        $used[mb_strtolower((string) ($section['label'] ?? ''), 'UTF-8')] = true;
    }
    foreach (($table['sections'] ?? []) as $section) {
        $used[mb_strtolower((string) ($section['label'] ?? ''), 'UTF-8')] = true;
    }
    if (!isset($used[mb_strtolower($base, 'UTF-8')])) {
        return mb_substr($base, 0, 100, 'UTF-8');
    }
    for ($number = 2; $number < 1000; $number++) {
        $suffix = ' (' . $number . ')';
        $candidate = mb_substr($base, 0, 100 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
        if (!isset($used[mb_strtolower($candidate, 'UTF-8')])) {
            return $candidate;
        }
    }
    throw new RuntimeException('Не удалось подобрать уникальное название раздела.');
}

function docgenExcelSectionByChoice(string $choice, string $sheetName, array $dataset, array &$table, array &$createdSections): array
{
    if ($choice !== '' && $choice !== 'new') {
        foreach (($dataset['sheets'] ?? []) as $section) {
            if ((string) ($section['key'] ?? '') === $choice) {
                return ['key' => (string) $section['key'], 'label' => (string) $section['label'], 'kind' => (string) ($section['kind'] ?? 'Основной список')];
            }
        }
        foreach (($table['sections'] ?? []) as $section) {
            if ((string) ($section['key'] ?? '') === $choice) {
                return ['key' => (string) $section['key'], 'label' => (string) $section['label'], 'kind' => (string) ($section['kind'] ?? 'Пользовательский раздел')];
            }
        }
        throw new RuntimeException('Выбранный раздел импорта не существует. Обновите список и повторите.');
    }
    foreach ($createdSections as $section) {
        if ($section['sheetName'] === $sheetName) {
            return $section['section'];
        }
    }
    $label = docgenExcelUniqueSectionLabel($sheetName, $dataset, $table);
    $section = ['key' => 'custom-' . bin2hex(random_bytes(6)), 'label' => $label, 'kind' => 'Пользовательский раздел', 'createdAt' => date(DATE_ATOM)];
    $table['sections'][] = $section;
    $createdSections[] = ['sheetName' => $sheetName, 'section' => $section];
    return $section;
}

function docgenSaveExcelImportBackup(array $recordEdits, array $tableData, string $importId): string
{
    $directory = DOCGEN_STORAGE_DIR . '/excel-backups';
    docgenEnsureDir($directory);
    $backupId = date('Ymd_His') . '_' . $importId;
    $path = $directory . '/' . $backupId . '.json';
    docgenWriteJson($path, [
        'version' => 1,
        'importId' => $importId,
        'createdAt' => date(DATE_ATOM),
        'recordEdits' => $recordEdits,
        'tableData' => $tableData,
    ]);
    $backups = glob($directory . '/*.json') ?: [];
    usort($backups, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    foreach (array_slice($backups, 10) as $oldBackup) {
        @unlink($oldBackup);
    }
    return $backupId;
}

function docgenCommitExcelImport(): never
{
    docgenCleanupStagedExcelImports();
    $importId = trim((string) ($_POST['import_id'] ?? ''));
    try {
        $stagedPath = docgenExcelStagePath($importId);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 400);
    }
    if (!is_file($stagedPath) || filemtime($stagedPath) < time() - 3600) {
        @unlink($stagedPath);
        sendJson(['ok' => false, 'error' => 'Файл предварительного просмотра устарел. Загрузите книгу ещё раз.'], 410);
    }
    $targetMap = json_decode((string) ($_POST['target_map'] ?? '{}'), true);
    if (!is_array($targetMap)) {
        sendJson(['ok' => false, 'error' => 'Не удалось прочитать назначения листов для разделов.'], 400);
    }
    $clearEmpty = (string) ($_POST['clear_empty'] ?? '') === '1';
    try {
        $workbook = docgenReadXlsx($stagedPath);
        $dataset = loadDataset();
        $table = docgenTableData();
        $oldTable = $table;
        $oldEdits = loadRecordEdits();
        $edits = $oldEdits;
        $columnNameMap = docgenExcelCanonicalColumnMap($dataset, $table);
        $plan = docgenPlanExcelImport($workbook, $dataset, $columnNameMap);
        foreach ($workbook['sheets'] as $sheet) {
            foreach ($sheet['headers'] as $header) {
                if (docgenExcelReservedHeader((string) $header) !== null) {
                    continue;
                }
                $name = docgenExcelCleanFieldName((string) $header);
                if ($name === '') {
                    continue;
                }
                $normalized = mb_strtolower($name, 'UTF-8');
                if (!isset($columnNameMap[$normalized])) {
                    $columnNameMap[$normalized] = $name;
                    $table['columns'][] = $name;
                }
            }
        }
        $newRecordCount = 0;
        $updatedCount = 0;
        $skipped = ['ambiguous' => 0, 'duplicate' => 0, 'empty' => 0];
        $details = [];
        $createdSections = [];
        $targetChoices = [];
        foreach ($workbook['sheets'] as $index => $sheet) {
            $choice = (string) ($targetMap[(string) $sheet['name']] ?? '');
            if ($choice === '') {
                $suggested = docgenExcelSectionMatch($dataset['sheets'] ?? [], (string) $sheet['name']);
                $choice = $suggested ? (string) $suggested['key'] : 'new';
            }
            $targetChoices[$index] = $choice;
        }
        foreach ($plan as $item) {
            $payload = $item['payload'];
            if ($item['action'] === 'ambiguous' || $item['action'] === 'duplicate' || $item['action'] === 'empty') {
                $skipped[$item['action']]++;
                if (count($details) < 20) {
                    $details[] = 'Лист «' . $workbook['sheets'][$item['sheetIndex']]['name'] . '», строка ' . $payload['rowNumber'] . ': ' . $item['reason'];
                }
                continue;
            }
            $rowFields = $payload['fields'];
            if ($item['action'] === 'update') {
                $id = $item['recordId'];
                $changed = [];
                foreach ($rowFields as $name => $value) {
                    if ($value === '' && !$clearEmpty) {
                        continue;
                    }
                    $changed[$name] = $value;
                }
                if ($changed) {
                    $existing = is_array($edits[$id] ?? null) ? $edits[$id] : [];
                    $edits[$id] = array_merge($existing, $changed);
                    $updatedCount++;
                } else {
                    $skipped['empty']++;
                }
                continue;
            }
            $target = docgenExcelSectionByChoice($targetChoices[$item['sheetIndex']], (string) $workbook['sheets'][$item['sheetIndex']]['name'], $dataset, $table, $createdSections);
            $recordId = 'custom-' . bin2hex(random_bytes(10));
            $table['records'][] = [
                'id' => $recordId,
                'source_key' => $target['key'],
                'source' => $target['label'],
                'kind' => $target['kind'],
                'excel_row' => $payload['rowNumber'],
                'fields' => $rowFields,
            ];
            $newRecordCount++;
        }
        $table['columns'] = array_values(array_unique($table['columns']));
        $backupId = '';
        if ($edits !== $oldEdits || $table !== $oldTable) {
            $backupId = docgenSaveExcelImportBackup($oldEdits, $oldTable, $importId);
        }
        try {
            if ($edits !== $oldEdits) {
                saveRecordEdits($edits);
            }
            if ($table !== $oldTable) {
                docgenSaveTableData($table);
            }
        } catch (Throwable $e) {
            try {
                if ($edits !== $oldEdits) {
                    saveRecordEdits($oldEdits);
                }
                if ($table !== $oldTable) {
                    docgenSaveTableData($oldTable);
                }
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
        @unlink($stagedPath);
        sendJson([
            'ok' => true,
            'created' => $newRecordCount,
            'updated' => $updatedCount,
            'skipped' => $skipped,
            'total' => $workbook['totalRows'],
            'backupId' => $backupId,
            'details' => $details,
            'message' => 'Импорт завершён. Перед изменениями создана приватная резервная копия ' . ($backupId !== '' ? $backupId : 'не требовалась') . '. Пустые ячейки ' . ($clearEmpty ? 'очищали' : 'не заменяли') . ' существующие значения.',
        ]);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

/** Create new shared columns for values mapped from an external source. */
function docgenEnsureColumns(array $names): array
{
    $table = docgenTableData();
    $dataset = loadDataset();
    $columnMap = docgenExcelCanonicalColumnMap($dataset, $table);
    $added = [];
    foreach ($names as $name) {
        $name = docgenExcelCleanFieldName((string) $name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new RuntimeException('Название нового поля должно содержать от 1 до 120 символов.');
        }
        if (docgenExcelReservedHeader($name) !== null) {
            throw new RuntimeException('Это название поля зарезервировано.');
        }
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($columnMap[$key])) {
            continue;
        }
        $table['columns'][] = $name;
        $columnMap[$key] = $name;
        $added[] = $name;
    }
    if ($added !== []) {
        docgenSaveTableData($table);
    }
    return $added;
}

function docgenAddTableColumn(): never
{
    $name = docgenExcelCleanFieldName((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        sendJson(['ok' => false, 'error' => 'Введите название колонки длиной от 1 до 120 символов.'], 422);
    }
    if (docgenExcelReservedHeader($name) !== null) {
        sendJson(['ok' => false, 'error' => 'Это название зарезервировано для служебных колонок Excel.'], 422);
    }
    $table = docgenTableData();
    $dataset = loadDataset();
    $columnMap = docgenExcelCanonicalColumnMap($dataset, $table);
    if (isset($columnMap[mb_strtolower($name, 'UTF-8')])) {
        sendJson(['ok' => false, 'error' => 'Колонка с таким названием уже существует.'], 409);
    }
    $table['columns'][] = $name;
    try {
        docgenSaveTableData($table);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(['ok' => true, 'column' => $name, 'message' => 'Колонка добавлена во все разделы. Значения можно заполнить в карточках записей.']);
}

function docgenAddTableSection(): never
{
    $label = trim((string) ($_POST['name'] ?? ''));
    if ($label === '' || mb_strlen($label, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
        sendJson(['ok' => false, 'error' => 'Введите название раздела длиной от 1 до 100 символов.'], 422);
    }
    $dataset = loadDataset();
    foreach (($dataset['sheets'] ?? []) as $section) {
        if (mb_strtolower((string) ($section['label'] ?? ''), 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
            sendJson(['ok' => false, 'error' => 'Раздел с таким названием уже существует.'], 409);
        }
    }
    $table = docgenTableData();
    $section = [
        'key' => 'custom-' . bin2hex(random_bytes(6)),
        'label' => $label,
        'kind' => 'Пользовательский раздел',
        'createdAt' => date(DATE_ATOM),
    ];
    $table['sections'][] = $section;
    try {
        docgenSaveTableData($table);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    sendJson(['ok' => true, 'section' => $section, 'message' => 'Раздел добавлен. Чтобы добавить строки, импортируйте XLSX-лист с этим названием.']);
}
