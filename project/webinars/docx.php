<?php
declare(strict_types=1);

/**
 * Заполнение копии утверждённого бланка программы ЦМК.
 *
 * Перенос cmk_docx.py: правится только текст внутри существующих run'ов, клонируется
 * абзац-прототип ведущего/темы, лишние абзацы удаляются. Форматирование, шапка,
 * реквизиты, анкета и картинка остаются из бланка — генератор их не рисует.
 */

const WEBINARS_ACRONYMS = ['ТКО', 'ЖКХ', 'МКД', 'ВС', 'РФ', 'ГОЗ', 'СНТ', 'НДС', 'УК', 'СЗЗ', 'ВКХ', 'ФАС', 'МСУ', 'РОП', 'БПЛА'];
const WEBINARS_NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const WEBINARS_NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

/** Заголовок карточки: первая буква заглавная, аббревиатуры сохраняются заглавными. */
function webinarsPlainTitle(string $value): string
{
    if (mb_strtoupper($value, 'UTF-8') === $value) {
        $first = mb_substr($value, 0, 1, 'UTF-8');
        $rest = mb_substr($value, 1, null, 'UTF-8');
        $value = mb_strtoupper($first, 'UTF-8') . mb_strtolower($rest, 'UTF-8');
    }
    $pattern = '/\b(' . implode('|', WEBINARS_ACRONYMS) . ')\b/iu';
    return (string) preg_replace_callback($pattern, static fn(array $m): string => mb_strtoupper($m[1], 'UTF-8'), $value);
}

function webinarsMonthName(int $month): string
{
    return WEBINARS_MONTHS[$month - 1] ?? '';
}

/** «10 декабря», «жёсткий пробел» между числом и месяцем — как в бланке. */
function webinarsDayMonth(string $day): string
{
    [$year, $month, $date] = array_map('intval', explode('-', $day));
    return $date . "\u{00A0}" . webinarsMonthName($month);
}

/** «10 декабря» с обычным пробелом — для абзаца «Условия участия». */
function webinarsDayMonthPlain(string $day): string
{
    [, $month, $date] = array_map('intval', explode('-', $day));
    return $date . ' ' . webinarsMonthName($month);
}

function webinarsDateRu(string $day): string
{
    [$year] = array_map('intval', explode('-', $day));
    return webinarsDayMonth($day) . "\u{00A0}" . $year . "\u{00A0}года";
}

function webinarsHours(array $event): string
{
    return webinarsShortTime((string) $event['start']) . '–' . webinarsShortTime((string) $event['end']);
}

/** «10:00:00» → «10:00»: время из снимка расписания хранится с секундами. */
function webinarsShortTime(string $value): string
{
    return preg_match('/^(\d{1,2}):(\d{2})/', $value, $match) === 1
        ? sprintf('%02d:%02d', (int) $match[1], (int) $match[2])
        : $value;
}

function webinarsSlug(string $url): string
{
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $segments = explode('/', $path);
    return (string) end($segments);
}

function webinarsEventKey(array $event): string
{
    return $event['day'] . '/' . webinarsSlug((string) $event['url']);
}

// ---------------------------------------------------------------------------
// Работа с XML бланка
// ---------------------------------------------------------------------------

/** Абзацы верхнего уровня тела документа (как doc.paragraphs в python-docx). */
function webinarsBodyParagraphs(DOMXPath $xp): array
{
    $result = [];
    $body = $xp->query('/w:document/w:body')->item(0);
    if ($body === null) {
        return $result;
    }
    foreach ($body->childNodes as $node) {
        if ($node instanceof DOMElement && $node->localName === 'p') {
            $result[] = $node;
        }
    }
    return $result;
}

/** @return list<DOMElement> */
function webinarsRuns(DOMElement $paragraph): array
{
    $result = [];
    foreach ($paragraph->childNodes as $node) {
        if ($node instanceof DOMElement && $node->localName === 'r') {
            $result[] = $node;
        }
    }
    return $result;
}

function webinarsRunText(DOMElement $run): string
{
    $text = '';
    foreach ($run->childNodes as $node) {
        if ($node instanceof DOMElement && $node->localName === 't') {
            $text .= $node->textContent;
        }
    }
    return $text;
}

function webinarsParagraphText(DOMElement $paragraph): string
{
    // Текст абзаца целиком: в 25-м абзаце бланка email стоит внутри w:hyperlink,
    // поэтому собственный текст собирается по всем вложенным w:t, а замена
    // (webinarsSetSegments) по-прежнему работает только с прямыми run'ами.
    $text = '';
    foreach (webinarsXPath($paragraph)->query('.//w:t', $paragraph) as $node) {
        $text .= $node->textContent;
    }
    return $text;
}

/** Записать текст в run, сохранив его оформление (rPr) как есть. */
function webinarsSetRunText(DOMElement $run, string $value): void
{
    $doc = $run->ownerDocument;
    $targets = [];
    foreach ($run->childNodes as $node) {
        if ($node instanceof DOMElement && $node->localName === 't') {
            $targets[] = $node;
        }
    }
    if ($targets === []) {
        $t = $doc->createElementNS(WEBINARS_NS_W, 'w:t');
        $run->appendChild($t);
        $targets[] = $t;
    }
    $first = $targets[0];
    foreach (array_slice($targets, 1) as $extra) {
        $run->removeChild($extra);
    }
    while ($first->firstChild !== null) {
        $first->removeChild($first->firstChild);
    }
    if ($value !== '') {
        $first->appendChild($doc->createTextNode($value));
    }
    $first->setAttribute('xml:space', 'preserve');
}

/**
 * Заменить текст абзаца сегментами — по одному на каждый существующий run.
 * Число сегментов обязано совпадать: иначе бланк отличается от утверждённого.
 *
 * @param list<string> $segments
 */
function webinarsSetSegments(DOMElement $paragraph, array $segments): void
{
    $runs = webinarsRuns($paragraph);
    if (count($runs) !== count($segments)) {
        throw new WebinarsSourceError('Формат абзаца в шаблоне отличается от утверждённого: ' . mb_substr(webinarsParagraphText($paragraph), 0, 65));
    }
    foreach ($runs as $index => $run) {
        webinarsSetRunText($run, $segments[$index]);
    }
}

/** Клон абзаца-прототипа без служебных идентификаторов Word. */
function webinarsCloneParagraph(DOMElement $prototype, DOMElement $before): DOMElement
{
    $clone = $prototype->cloneNode(true);
    foreach (['paraId', 'textId'] as $attribute) {
        foreach (iterator_to_array($clone->attributes ?? []) as $attr) {
            if ($attr->localName === $attribute) {
                $clone->removeAttributeNode($attr);
            }
        }
    }
    $before->parentNode?->insertBefore($clone, $before);
    return $clone;
}

function webinarsRemoveNode(DOMElement $node): void
{
    $node->parentNode?->removeChild($node);
}

/** Сокращения должностей, принятые в бланке ЦМК. */
function webinarsSpeakerRole(string $role, array $edit): string
{
    $words = [
        'жилищно-коммунального хозяйства Федеральной антимонопольной службы' => 'ЖКХ ФАС России',
        'Института экономики и права жилищно-коммунального хозяйства' => 'Института экономики и права ЖКХ',
        'Института экономики жилищно-коммунального хозяйства' => 'Института экономики ЖКХ',
        'Группы компаний «Росводоканал»' => 'ГК «Росводоканал»',
        'жилищно-коммунальной сфере' => 'сфере ЖКХ',
        'ранее – Заместитель' => 'ранее — заместитель',
    ];
    foreach ((array) ($edit['role_shortenings'] ?? []) as $old => $short) {
        $words[(string) $old] = (string) $short;
    }
    return strtr($role, $words);
}

/**
 * «Условия участия» — 5 сегментов бланка: вводная, день, «(по московскому времени).»,
 * сумма и остальное.
 *
 * @return list<string>
 */
function webinarsConditionsParts(array $event): array
{
    // В «Условиях участия» пробелы обычные: неразрывные остаются только в шапке и анкете.
    $day = webinarsDayMonthPlain($event['day']) . ' с ' . webinarsShortTime((string) $event['start'])
        . ' до ' . webinarsShortTime((string) $event['end']);
    $prefix = " (по московскому времени).";
    $amount = '';
    $detail = '';
    $fee = (string) ($event['fee'] ?? '');
    if (preg_match('/(Стоимость участия в вебинаре\s*[–—-]\s*)([\d ]+ рубл\w*)(.*)/u', $fee, $price) === 1) {
        $prefix .= ' Стоимость участия в вебинаре — ';
        $amount = str_replace(' ', "\u{00A0}", $price[2]);
        $detail = $price[3];
    } elseif ($fee !== '') {
        $detail = ' ' . $fee;
    }
    $extra = (string) ($event['access_and_discounts'] ?? $event['access'] ?? '');
    $extra = str_replace(
        'возможность получить индивидуальные рекомендации экспертов и коллег в общем чате',
        'консультации экспертов в общем чате',
        $extra
    );
    $extra = str_replace(
        'комплект справочно-методических материалов, сертификат об обучении, полную видеозапись вебинара и '
        . 'консультации экспертов в общем чате.',
        'консультации экспертов в общем чате, полная видеозапись вебинара, сертификат об обучении '
        . 'и комплект справочно-методических материалов.',
        $extra
    );
    if ($extra !== '') {
        $detail .= ' ' . $extra;
    }
    return ['Интернет-трансляция будет проходить ', $day, $prefix, $amount, $detail];
}

/**
 * Аннотация: подсветить первый смысловой фрагмент, не меняя текст.
 * Если в редактуре заданы abstract_runs — они используются как есть.
 */
function webinarsFormattedAbstract(DOMElement $paragraph, string $source, array $edit): void
{
    if (isset($edit['abstract_runs']) && is_array($edit['abstract_runs']) && $edit['abstract_runs'] !== []) {
        webinarsSetSegments($paragraph, array_map('strval', $edit['abstract_runs']));
        return;
    }
    if (!empty($edit['abstract']) && is_string($edit['abstract'])) {
        $source = $edit['abstract'];
    }
    $spans = webinarsRuns($paragraph);
    if (count($spans) < 3) {
        throw new WebinarsSourceError('Не найдено оформление аннотации в шаблоне.');
    }
    $matched = preg_match('/^(К \d{4} году|В \d{4} году|В течение \d{4} года|С \d{2}\.\d{2}\.\d{4})\b/u', $source, $match) === 1;
    if ($matched) {
        webinarsSetRunText($spans[0], '');
        webinarsSetRunText($spans[1], $match[0]);
        webinarsSetRunText($spans[2], mb_substr($source, mb_strlen($match[0], 'UTF-8'), null, 'UTF-8'));
        $remove = array_slice($spans, 3);
    } else {
        webinarsSetRunText($spans[0], $source);
        $remove = array_slice($spans, 1);
    }
    foreach ($remove as $run) {
        webinarsRemoveNode($run);
    }
}

// ---------------------------------------------------------------------------
// Пакет DOCX
// ---------------------------------------------------------------------------

/**
 * Убрать из пакета старый внешний .dotx и вложения, на которые больше нет ссылок.
 * Python-версия брала чистый app.xml из пустого документа; здесь достаточно
 * снять attachedTemplate и неиспользуемые image/hyperlink-связи.
 */
function webinarsCleanPackage(ZipArchive $zip, DOMDocument $document): void
{
    $settings = $zip->getFromName('word/settings.xml');
    if (is_string($settings) && str_contains($settings, 'attachedTemplate')) {
        $settingsDom = new DOMDocument();
        if (@$settingsDom->loadXML($settings, LIBXML_NONET)) {
            $xp = new DOMXPath($settingsDom);
            $xp->registerNamespace('w', WEBINARS_NS_W);
            $xp->registerNamespace('r', WEBINARS_NS_R);
            $removedIds = [];
            foreach (iterator_to_array($xp->query('//w:attachedTemplate')) as $node) {
                $removedIds[] = $node->getAttributeNS(WEBINARS_NS_R, 'id') ?: $node->getAttribute('r:id');
                $node->parentNode?->removeChild($node);
            }
            if ($removedIds !== []) {
                $rels = $zip->getFromName('word/_rels/settings.xml.rels');
                foreach ($removedIds as $id) {
                    if (is_string($rels) && $id !== '') {
                        $rels = preg_replace('/<Relationship[^>]*Id="' . preg_quote($id, '/') . '"[^>]*\/>/', '', $rels) ?? $rels;
                    }
                }
                if (is_string($rels)) {
                    $zip->addFromString('word/_rels/settings.xml.rels', $rels);
                }
            }
            $zip->addFromString('word/settings.xml', $settingsDom->saveXML());
        }
    }

    $rels = $zip->getFromName('word/_rels/document.xml.rels');
    if (!is_string($rels)) {
        return;
    }
    $xml = $document->saveXML();
    $relsDom = new DOMDocument();
    if (!@$relsDom->loadXML($rels, LIBXML_NONET)) {
        return;
    }
    $xp = new DOMXPath($relsDom);
    $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $changed = false;
    foreach (iterator_to_array($xp->query('/p:Relationships/p:Relationship')) as $relationship) {
        $type = (string) $relationship->getAttribute('Type');
        $id = (string) $relationship->getAttribute('Id');
        $isAttachment = str_ends_with($type, '/image') || str_ends_with($type, '/hyperlink');
        if ($isAttachment && $id !== ''
            && !str_contains((string) $xml, 'r:id="' . $id . '"')
            && !str_contains((string) $xml, 'r:embed="' . $id . '"')
            && !str_contains((string) $xml, 'r:link="' . $id . '"')) {
            $relationship->parentNode?->removeChild($relationship);
            $changed = true;
        }
    }
    if ($changed) {
        $zip->addFromString('word/_rels/document.xml.rels', $relsDom->saveXML());
    }
}

function webinarsUpdateCoreProperties(ZipArchive $zip, string $title): void
{
    $core = $zip->getFromName('docProps/core.xml');
    $dom = new DOMDocument();
    if (!is_string($core) || !@$dom->loadXML($core, LIBXML_NONET)) {
        return;
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');
    $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    $xp->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
    $stamp = gmdate('Y-m-d\TH:i:s\Z');
    $set = static function (string $query, string $value) use ($xp, $dom): void {
        $node = $xp->query($query)->item(0);
        if ($node === null) {
            return;
        }
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }
        if ($value !== '') {
            $node->appendChild($dom->createTextNode($value));
        }
    };
    $set('/cp:coreProperties/dc:title', $title);
    $set('/cp:coreProperties/dc:subject', 'Программа вебинара Центра Межрегиональных Коммуникаций');
    $set('/cp:coreProperties/dc:creator', 'Центр Межрегиональных Коммуникаций');
    $set('/cp:coreProperties/cp:lastModifiedBy', '');
    $set('/cp:coreProperties/cp:comments', '');
    $set('/cp:coreProperties/cp:revision', '1');
    $set('/cp:coreProperties/dcterms:created', $stamp);
    $set('/cp:coreProperties/dcterms:modified', $stamp);
    $zip->addFromString('docProps/core.xml', $dom->saveXML());
}

/**
 * Сформировать DOCX по карточке. $edit — запись редактуры для ключа «ГГГГ-ММ-ДД/slug».
 *
 * @param array<string, mixed> $event
 * @throws WebinarsSourceError если бланк не тот или его структура изменилась
 */
function webinarsRenderDocx(array $event, string $layoutPath, array $edit, string $destination): void
{
    // Бланк — эталон: он только читается. Записывается копия в $destination,
    // иначе первое же формирование испортило бы утверждённый файл.
    if (is_file($layoutPath) && realpath($layoutPath) === realpath($destination)) {
        throw new WebinarsSourceError('Формирование не должно перезаписывать сам бланк ЦМК.');
    }
    if (!@copy($layoutPath, $destination)) {
        throw new WebinarsSourceError('Не удалось создать копию бланка ЦМК для заполнения.');
    }
    $zip = new ZipArchive();
    if ($zip->open($destination) !== true) {
        @unlink($destination);
        throw new WebinarsSourceError('Не удалось открыть бланк ЦМК. Загрузите утверждённый «ШАБЛОН — программа вебинара.docx».');
    }
    try {
        $xml = $zip->getFromName('word/document.xml');
        if (!is_string($xml) || $xml === '') {
            throw new WebinarsSourceError('В бланке нет основного текста документа.');
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET)) {
            throw new WebinarsSourceError('Бланк повреждён: не удалось прочитать основной текст.');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', WEBINARS_NS_W);

        $paras = webinarsBodyParagraphs($xp);
        $tables = $xp->query('/w:document/w:body/w:tbl');
        $valid = count($tables) === 3 && count($paras) === 28
            && webinarsParagraphText($paras[1]) === 'Уважаемые коллеги!'
            && webinarsParagraphText($paras[6]) === 'ВЕДУЩИЕ ВЕБИНАРА'
            && webinarsParagraphText($paras[11]) === 'ПРОГРАММА ВЕБИНАРА'
            && webinarsParagraphText($paras[23]) === 'УСЛОВИЯ УЧАСТИЯ'
            && str_contains(webinarsParagraphText($paras[25]), 'kir@vsesem.ru')
            && webinarsParagraphText($paras[26]) === 'Анкета для регистрации на вебинар';
        if (!$valid) {
            throw new WebinarsSourceError('Ожидается утверждённый «ШАБЛОН — программа вебинара.docx» ЦМК.');
        }

        $info = $tables->item(1);
        $form = $tables->item(2);
        foreach (iterator_to_array($xp->query('./w:tblPr/w:tblpPr', $info)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = webinarsPlainTitle((string) $event['title']);
        $dateShort = webinarsDayMonth($event['day']) . "\u{00A0}" . (int) substr($event['day'], 0, 4) . "\u{00A0}г.";
        $formTitle = $dateShort . ' «' . $title . '»';

        // Таблица «О проведении…»: левая ячейка — тема, правая — аудитория.
        $infoRow = $xp->query('./w:tr[1]/w:tc', $info);
        $audience = (string) ($edit['audience'] ?? '');
        if ($audience === '') {
            $audience = rtrim((string) $event['audience'], '.');
        }
        webinarsSetSegments(
            $xp->query('./w:p', $infoRow->item(0))->item(0),
            ['О проведении онлайн-трансляции ' . $formTitle]
        );
        webinarsSetSegments($xp->query('./w:p', $infoRow->item(1))->item(0), [$audience]);

        webinarsFormattedAbstract($paras[2], (string) $event['abstract'], $edit);

        $kinds = [
            'всероссийский практический вебинар' => 'всероссийского практического вебинара',
            'практический вебинар' => 'практического вебинара',
            'консультационный вебинар' => 'консультационного вебинара',
        ];
        $kind = $kinds[mb_strtolower((string) $event['kind'], 'UTF-8')] ?? 'вебинара';
        $who = (string) ($edit['with_whom'] ?? $event['with_whom']);
        $introSuffix = (string) ($edit['intro_suffix'] ?? ':');
        $dateRu = str_replace(' ', "\u{00A0}", webinarsDateRu($event['day']));
        webinarsSetSegments($paras[3], [
            webinarsRunText(webinarsRuns($paras[3])[0] ?? $paras[3]->appendChild($doc->createElementNS(WEBINARS_NS_W, 'w:r'))),
            $dateRu,
            ' интернет-трансляции ' . $kind . ($who !== '' ? ' с участием ' : ''),
            $who,
            $introSuffix,
        ]);
        webinarsSetSegments($paras[4], [(string) $event['title']]);

        $benefit = (string) ($edit['benefit'] ?? '');
        if ($benefit === '') {
            $topics = [];
            foreach (array_slice((array) $event['topics'], 0, 4) as $topic) {
                $name = webinarsPlainTitle((string) $topic['title']);
                $topics[] = preg_match('/^\p{Lu}+$/u', (string) $topic['title']) === 1
                    ? mb_strtolower($name, 'UTF-8')
                    : mb_strtolower(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
            }
            $benefit = 'разобраться в следующих вопросах: ' . implode('; ', $topics) . '.';
        }
        $benefitLead = webinarsRunText(webinarsRuns($paras[5])[0] ?? $paras[5]->appendChild($doc->createElementNS(WEBINARS_NS_W, 'w:r')));
        webinarsSetSegments($paras[5], [$benefitLead, $benefit]);

        // Ведущие: первые четыре абзаца готовы, при большем числе — клон прототипа.
        $speakers = array_values((array) $event['speakers']);
        foreach ($speakers as $index => $speaker) {
            [$name, $role] = webinarsSpeakerPair($speaker);
            $item = $index < 4 ? $paras[7 + $index] : webinarsCloneParagraph($paras[7], $paras[11]);
            webinarsSetSegments($item, [$name, ' — ' . webinarsSpeakerRole($role, $edit)]);
        }
        if (count($speakers) < 4) {
            for ($index = 7 + count($speakers); $index < 11; $index++) {
                webinarsRemoveNode($paras[$index]);
            }
        }

        // Программа: темы заполняют до десяти готовых абзацев (12..21), при большем
        // числе — клон абзаца-прототипа перед закрывающей строкой. Закрывающая
        // строка «Ответы на вопросы…» всегда пишется в абзац 22 (у него один run).
        $topics = array_values((array) $event['topics']);
        $renames = (array) ($edit['rename_topics'] ?? []);
        $detailsOverride = (array) ($edit['topic_details'] ?? []);
        foreach ($topics as $index => $topic) {
            $number = $index + 1;
            $item = $number <= 10 ? $paras[11 + $number] : webinarsCloneParagraph($paras[12], $paras[22]);
            $name = rtrim((string) ($renames[(string) $number] ?? $topic['title']), ' .');
            $details = (string) ($detailsOverride[(string) $number] ?? $topic['details']);
            webinarsSetSegments($item, [$number . '. ' . $name . '. ', $details]);
        }
        for ($index = 12 + count($topics); $index <= 21; $index++) {
            webinarsRemoveNode($paras[$index]);
        }
        $closing = (string) ($event['closing'] ?? '');
        if ($closing !== '') {
            $closing = webinarsSentenceCase($closing);
            if ($closing === 'Ответы на вопросы участников и практические рекомендации экспертов') {
                $closing = 'Ответы на вопросы участников, консультации экспертов и практические рекомендации';
            }
            webinarsSetSegments($paras[22], [(count($topics) + 1) . '. ' . $closing]);
        } else {
            webinarsRemoveNode($paras[22]);
        }
        webinarsSetSegments($paras[24], webinarsConditionsParts($event));

        // Анкета: название и дата со временем; строка названия не отрывается от даты.
        $formRow0 = $xp->query('./w:tr[1]/w:tc', $form);
        $formRow1 = $xp->query('./w:tr[2]/w:tc', $form);
        webinarsSetSegments($xp->query('./w:p', $formRow0->item(1))->item(0), [$formTitle]);
        webinarsSetSegments($xp->query('./w:p', $formRow1->item(1))->item(0), [$dateShort . ', ' . webinarsHours($event) . ' (МСК)']);
        foreach ($xp->query('./w:tr[1]/w:tc/w:p', $form) as $node) {
            $keep = $xp->query('./w:pPr/w:keepNext', $node)->item(0);
            if ($keep === null) {
                $pPr = $xp->query('./w:pPr', $node)->item(0);
                if ($pPr === null) {
                    $pPr = $doc->createElementNS(WEBINARS_NS_W, 'w:pPr');
                    $node->insertBefore($pPr, $node->firstChild);
                }
                $pPr->appendChild($doc->createElementNS(WEBINARS_NS_W, 'w:keepNext'));
            }
        }

        $zip->addFromString('word/document.xml', $doc->saveXML());
        webinarsCleanPackage($zip, $doc);
        webinarsUpdateCoreProperties($zip, $title);
    } finally {
        $zip->close();
    }
    if (!is_file($destination) || filesize($destination) < 1000) {
        throw new WebinarsSourceError('Не удалось записать сформированный DOCX.');
    }
}

function webinarsSentenceCase(string $value): string
{
    if ($value === '') {
        return $value;
    }
    return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
}

/**
 * Ведущий в любом поддерживаемом виде: ['name' => …, 'role' => …] или пара [имя, должность]
 * (так их хранит Python-версия и снимок расписания).
 *
 * @return array{0: string, 1: string}
 */
function webinarsSpeakerPair(mixed $speaker): array
{
    if (is_array($speaker) && array_key_exists('name', $speaker)) {
        return [(string) $speaker['name'], (string) ($speaker['role'] ?? '')];
    }
    if (is_array($speaker) && array_key_exists(0, $speaker)) {
        return [(string) $speaker[0], (string) ($speaker[1] ?? '')];
    }
    return ['', ''];
}
