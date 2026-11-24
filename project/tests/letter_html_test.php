<?php

/**
 * Lightweight checks for the per-template letter layout and the HTML design.
 * Run: php tests/letter_html_test.php (PHP 8.1+, no web server needed).
 * Storage is redirected to a temp folder, so the real data/queue is untouched.
 */

$storage = sys_get_temp_dir() . '/docgen-letter-test-' . bin2hex(random_bytes(4));
putenv('DOCGEN_STORAGE_DIR=' . $storage);
register_shutdown_function(static function () use ($storage): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path)) {
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

$failures = 0;
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

// 1. Common defaults stay as before; per-template copies are independent.
$defaults = docgenLetterTemplateDefaults();
check($defaults['htmlPattern'] === '', 'htmlPattern defaults to empty');
check($defaults['toPattern'] === '{Основной email}', 'recipient default kept');
check(!docgenIsHtmlTemplateId('sample-docx'), 'sample is not an html template');
check(!docgenIsHtmlTemplateId('__upload__'), 'upload is not an html template');
check(!docgenIsHtmlTemplateId('0123456789abcdef0123456789abcdef'), 'unknown id is not an html template');

// 2. Text alternative is never empty and follows plain text > own text > html.
check(docgenLetterTextAlternative(['bodyPattern' => "Строка 1\nСтрока 2"]) === "Строка 1\nСтрока 2", 'plain text is the alternative');
check(docgenLetterTextAlternative(['bodyPattern' => '', 'htmlTextPattern' => 'Только текст']) === 'Только текст', 'own text variant is used');
check(docgenLetterTextAlternative(['bodyPattern' => '', 'htmlTextPattern' => '', 'htmlPattern' => '<h1>Заголовок</h1><p>Абзац</p>']) === "Заголовок\n\nАбзац", 'text derived from html');
check(docgenLetterTextAlternative([]) !== '', 'alternative never empty');
check(docgenLetterHtmlToText('<style>@page{size:A4}body{color:red}</style><p>Текст</p>') === "Текст", 'styles stripped, text kept');

// 3. Html design validation: codes must be filled, empty design is rejected.
$values = ['Регион' => 'Рига', 'Имя Отчество' => 'Иван Иванович'];
try {
    docgenValidateLetterHtml('<p>{Регион}: {Имя Отчество}</p>', $values);
    check(true, 'valid html passes');
} catch (Throwable $e) {
    check(false, 'valid html passes: ' . $e->getMessage());
}
try {
    docgenValidateLetterHtml('<p>{Регион}: {НеизвестноеПоле}</p>', $values);
    check(false, 'unknown code rejected');
} catch (Throwable $e) {
    check(strpos($e->getMessage(), '{НеизвестноеПоле}') !== false, 'unknown code named in error');
}
try {
    docgenValidateLetterHtml('   ', $values);
    check(false, 'empty design rejected');
} catch (Throwable $e) {
    check(true, 'empty design rejected');
}
try {
    docgenValidateLetterHtml('<div>{{UnsubscribeUrl}}</div>', $values);
    check(true, 'handlebars placeholders are not codes');
} catch (Throwable $e) {
    check(false, 'handlebars placeholders are not codes: ' . $e->getMessage());
}

// 4. HTML is cleaned: scripts/remote resources go, print rules stay.
$dirty = '<html><head><title>x</title></head><body onclick="alert(1)"><script>alert(1)</script><img src="https://evil.example/x.png"><a href="https://example.org">Ссылка</a></body></html>';
$clean = docgenNormalizeLetterHtml($dirty, false);
check(stripos($clean, '<script') === false, 'script removed');
check(stripos($clean, 'onclick') === false, 'inline handler removed');
check(strpos($clean, 'https://evil.example') === false, 'remote image removed');
check(strpos($clean, 'https://example.org') !== false, 'plain link kept');
$print = docgenNormalizeLetterHtml('<p>Текст</p>', true);
check(strpos($print, '@page') !== false, 'print rules added for documents');
check(stripos($print, '<!doctype html>') === 0, 'document shell added');

// 5. Plain text becomes safe mail markup with clickable links and {nl}.
$mail = docgenLetterPlainToHtml("Добрый день.\n\nСайт: https://example.org/x?a=1&b=2{nl}Пока");
check(strpos($mail, "Добрый день.<br>") === 0, 'line breaks rendered');
check(strpos($mail, '<a href="https://example.org/x?a=1&amp;b=2">') !== false, 'link clickable and escaped');
check(strpos($mail, '{nl}') === false, '{nl} converted');
$mailXss = docgenLetterPlainToHtml('<script>alert(1)</script>');
check(strpos($mailXss, '<script>') === false, 'plain text stays escaped');

// 6. Per-template settings: own copy wins, others inherit common defaults.
$templateId = 'a' . bin2hex(random_bytes(15)); // 31 hex chars => invalid, must not match
check(docgenTemplateLetterSettings($templateId)['own'] === false, 'invalid id inherits defaults');
$realId = bin2hex(random_bytes(16));
docgenSaveTemplateLetterSettings($realId, [
    'filenamePattern' => '{ИНН}',
    'toPattern' => '{Основной email}',
    'ccPattern' => '',
    'subjectPattern' => 'Своя тема',
    'bodyPattern' => "Текст\nписьма",
    'htmlPattern' => '<p>{Регион}</p>',
    'htmlTextPattern' => '',
    'htmlDocument' => false,
], 'default');
$own = docgenTemplateLetterSettings($realId);
check($own['own'] === true, 'own copy stored');
check($own['subjectPattern'] === 'Своя тема', 'own subject used');
$other = docgenTemplateLetterSettings(bin2hex(random_bytes(16)));
check($other['own'] === false && $other['subjectPattern'] !== 'Своя тема', 'other template keeps global values');
check(docgenTemplateLetterSettings('')['own'] === false, 'sample/upload use global values');
docgenCleanupTemplateLetters($realId);
check(docgenTemplateLetterSettings($realId)['own'] === false, 'deleted template drops its copy');

// 7. The shipped example design validates against the book's codes.
$design = (string) file_get_contents(__DIR__ . '/../docs/letter-design-template.html');
check(trim($design) !== '', 'example design file exists');
try {
    docgenValidateLetterHtml($design, [], true);
    check(true, 'example design validates');
} catch (Throwable $e) {
    check(false, 'example design validates: ' . $e->getMessage());
}
$preview = docgenLetterHtmlPreview($design);
check(strpos($preview, 'calendar.google.com') !== false, 'design preview keeps links');
check(strpos($preview, 'preheader') === false, 'preheader hidden in preview');
check(stripos((string) docgenLetterTextAlternative(['htmlPattern' => $design]), 'Новые требования тарифного регулирования') !== false, 'text alternative from example design');

// 8. Outgoing parts: text is mandatory next to a design, plain mail stays plain.
$parts = docgenLetterMailParts(['body' => 'Текст', 'bodyHtml' => '<p>Текст</p>']);
check($parts['text'] === 'Текст' && $parts['html'] === '<p>Текст</p>', 'html letter keeps both parts');
$parts = docgenLetterMailParts(['body' => 'Текст']);
check($parts['text'] === 'Текст' && $parts['html'] === '', 'plain letter has no html part');
try {
    docgenLetterMailParts(['body' => '', 'bodyHtml' => '<p>Текст</p>']);
    check(false, 'html without text refused');
} catch (Throwable $e) {
    check(strpos($e->getMessage(), 'multipart/alternative') !== false, 'html without text explained');
}
try {
    docgenLetterMailParts(['body' => '', 'bodyHtml' => '']);
    check(false, 'empty letter refused');
} catch (Throwable $e) {
    check(true, 'empty letter refused');
}

// 8. Template manager: names, copies and the letter that travels with them.
$managerId = bin2hex(random_bytes(16));
$copyId = bin2hex(random_bytes(16));
docgenWriteJson(DOCGEN_TEMPLATE_INDEX, [
    ['id' => $managerId, 'name' => 'Вебинар 11.12.2028', 'type' => 'html', 'createdAt' => '', 'updatedAt' => ''],
]);
file_put_contents(DOCGEN_TEMPLATES_DIR . '/' . $managerId . '.html', '<p>{Регион}</p>');
$list = docgenTemplateList();
$own = null;
foreach ($list as $entry) {
    if ($entry['id'] === $managerId) {
        $own = $entry;
    }
}
check($own !== null && $own['name'] === 'Вебинар 11.12.2028', 'template list shows the saved name');
check($own !== null && $own['ownLetter'] === false, 'template without its own letter is marked');
docgenSaveTemplateLetterSettings($managerId, [
    'filenamePattern' => 'x', 'toPattern' => 'a@b.c', 'ccPattern' => '', 'subjectPattern' => 'Своя тема',
    'bodyPattern' => 'Текст', 'htmlPattern' => '<p>Дизайн</p>', 'htmlTextPattern' => '',
], 'default');
$marked = null;
foreach (docgenTemplateList() as $entry) {
    if ($entry['id'] === $managerId) {
        $marked = $entry;
    }
}
check($marked !== null && $marked['ownLetter'] === true && $marked['hasHtmlDesign'] === true, 'own letter and design are marked in the list');
check(docgenCopyTemplateLetterSettings($managerId, $copyId) === true, 'letter copied to another template');
$copied = docgenTemplateLetterSettings($copyId);
check($copied['own'] === true && $copied['subjectPattern'] === 'Своя тема' && $copied['htmlPattern'] === '<p>Дизайн</p>', 'copy keeps subject, text and design');
check(docgenCopyTemplateLetterSettings($managerId, $managerId) === false, 'copying to itself is refused');
check(docgenCopyTemplateLetterSettings($copyId, bin2hex(random_bytes(16))) === true, 'copy works from any template with own letter');
$fresh = docgenTemplateLetterSettings(bin2hex(random_bytes(16)));
check($fresh['own'] === false, 'template without a copy still inherits defaults');
docgenCleanupTemplateLetters($copyId);
docgenCleanupTemplateLetters($managerId);

// 7. Backup includes the per-template letter config.
check(isset(docgenBackupFileMap(false)['templates/letters.json']), 'letters.json is in the backup');

echo $failures === 0 ? "OK: {$checks} checks passed\n" : "FAILED: {$failures} of {$checks}\n";
exit($failures === 0 ? 0 : 1);
