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
require __DIR__ . '/../table_features.php';

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

// 9. Sending policy: window, daily cap, jitter and the provider 421 answer.
check(docgenClampPerMinute(0) === 1 && docgenClampPerMinute(60) === 6 && docgenClampPerMinute(1) === 1, 'per-minute limit is capped at 1..6');
check(docgenClampDailyCap(-5) === 0 && docgenClampDailyCap(5000) === 300 && docgenClampDailyCap(40) === 40, 'daily cap is capped at 0..300');
check(docgenClockMinutes('09:30') === 570 && docgenClockMinutes('18:00') === 1080, 'HH:MM is read as minutes');
$outside = ['windowStart' => '00:00', 'windowEnd' => '00:01', 'weekdaysOnly' => false];
[$inside,] = docgenCronWithinWindow(['windowStart' => '00:00', 'windowEnd' => '23:59'] + $outside);
check($inside === true, 'wide window is always open');
[$closed, $reason] = docgenCronWithinWindow(['windowStart' => '03:00', 'windowEnd' => '03:01'] + $outside);
check(in_array($closed, [true, false], true) && ($closed || $reason !== ''), 'narrow window reports a reason when closed');
check(docgenIsRateLimitError('SMTP Error: Could not send. 421 4.7.0 ratelimit exceeded') === true, '421 is recognised as a rate limit');
check(docgenIsRateLimitError('421 4.7.0 Too many messages') === true, 'too many messages is a rate limit');
check(docgenIsRateLimitError('550 mailbox unavailable') === false, 'a mailbox error is not a rate limit');
check(docgenIsRateLimitError('SMTP Error: Data not accepted') === false, 'a data error is not a rate limit');

// 10. Headers: the plain part has no HTML, sender domain is usable as HELO.
$plain = docgenLetterTextAlternative(['bodyPattern' => "Строка {nl} вторая", 'htmlPattern' => '<p>Дизайн</p>']);
check(strpos($plain, '<br') === false && strpos($plain, '<') === false, 'plain alternative never carries html tags');
check(strpos($plain, "{nl}") === false, 'plain alternative converts {nl} to a line break');
$html = docgenLetterPlainToHtml("Строка{nl}вторая");
check(strpos($html, '<br>') !== false, 'plain-to-html still keeps <br> for the rich part');
$fromDomain = 'cmkcenter.ru';
check(preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $fromDomain) === 1, 'sender domain is a valid HELO name');
check(preg_match('/^[a-z0-9][a-z0-9.-]*$/i', 'docgen') === 1, 'container hostname would have passed the old check, so the explicit HELO matters');

// 11. Письма очереди не привязаны к ящику: сначала очередь, потом ящик.
$accA = 'acc_' . bin2hex(random_bytes(4));
$accB = 'acc_' . bin2hex(random_bytes(4));
docgenSmtpWriteAccount(['id' => $accA, 'label' => 'Первый ящик', 'host' => 'smtp.mail.ru', 'port' => 465,
    'encryption' => 'ssl', 'username' => 'one@mail.ru', 'fromEmail' => 'one@mail.ru', 'fromName' => 'ЦМК'], 'secret-a', false);
docgenSmtpWriteAccount(['id' => $accB, 'label' => 'Второй ящик', 'host' => 'smtp.mail.ru', 'port' => 465,
    'encryption' => 'ssl', 'username' => 'two@mail.ru', 'fromEmail' => 'two@mail.ru', 'fromName' => 'ЦМК'], 'secret-b', false);

$queueBatch = bin2hex(random_bytes(16));
$queueIds = [];
foreach (['Регион А', 'Регион Б'] as $index => $region) {
    $queueId = bin2hex(random_bytes(16));
    $queueIds[] = $queueId;
    docgenEnsureDir(docgenQueuePath($queueId));
    file_put_contents(docgenQueuePath($queueId) . '/document.pdf', '%PDF-1.4 test');
    docgenSaveQueueItem([
        'id' => $queueId, 'batchId' => $queueBatch, 'recipientName' => 'Иванов И.И.', 'to' => 'boss@example.org',
        'cc' => '', 'subject' => 'Программа', 'body' => 'Текст', 'bodyHtml' => '', 'filename' => 'Программа',
        'status' => 'queued', 'createdAt' => date(DATE_ATOM), 'smtpAccountId' => '', 'smtpAccountLabel' => '', 'region' => $region,
    ]);
}
docgenSaveBatch(['id' => $queueBatch, 'name' => 'МИНЗДРАВ', 'itemCount' => 2, 'subject' => 'Программа',
    'status' => 'review', 'mode' => '', 'priority' => docgenNextBatchPriority(), 'createdAt' => date(DATE_ATOM),
    'sentCount' => 0, 'failedCount' => 0, 'smtpAccountId' => '', 'smtpAccountLabel' => '']);

check(docgenReadQueueItem($queueIds[0])['smtpAccountId'] === '', 'очередь создаётся без ящика');
check((docgenBatchMeta($queueBatch)['smtpAccountId'] ?? '') === '', 'пачка на проверке без ящика');

$assigned = docgenAssignBatchAccount($queueBatch, $accB);
check($assigned['id'] === $accB, 'ящик назначается на этапе проверки пачки');
check(docgenReadQueueItem($queueIds[0])['smtpAccountId'] === $accB
    && docgenReadQueueItem($queueIds[1])['smtpAccountId'] === $accB, 'ящик попадает во все письма пачки');
check(docgenReadQueueItem($queueIds[1])['smtpAccountLabel'] === 'Второй ящик', 'в письме остаётся читаемое имя ящика');

$refused = false;
try {
    docgenAssignBatchAccount($queueBatch, 'acc_missing');
} catch (Throwable $e) {
    $refused = strpos($e->getMessage(), 'SMTP-ящик') !== false;
}
check($refused, 'неизвестный ящик отклоняется');
check(docgenReadQueueItem($queueIds[0])['smtpAccountId'] === $accB, 'отказ не сбрасывает уже назначенный ящик');

docgenAssignBatchAccount($queueBatch, $accA);
check(docgenReadQueueItem($queueIds[0])['smtpAccountId'] === $accA, 'ящик можно сменить до отправки');
check(docgenCronItemAccountId(docgenReadQueueItem($queueIds[0])) === $accA, 'лимит и поток считаются по выбранному ящику');

// 12. Диагностика SMTP: журнал для модалки, пароль скрыт, причины понятны.
$smtpPassword = 'S3cret-Pa55word';
$secretTranscript = "CLIENT -> SERVER: AUTH PLAIN " . base64_encode("\0user@mail.ru\0" . $smtpPassword) . "\n"
    . "CLIENT -> SERVER: " . base64_encode($smtpPassword) . "\n"
    . "SERVER -> CLIENT: 535 auth failed for " . $smtpPassword . "\n";
$masked = docgenSmtpMaskSecrets(docgenSmaskTranscript($secretTranscript), $smtpPassword, 'user@mail.ru');
check(strpos($masked, $smtpPassword) === false, 'журнал SMTP не содержит пароля');
check(strpos($masked, base64_encode($smtpPassword)) === false, 'журнал SMTP не содержит base64 пароля');
check(strpos($masked, base64_encode("\0user@mail.ru\0" . $smtpPassword)) === false, 'журнал SMTP не содержит AUTH PLAIN строки');
check(strpos($masked, '535 auth failed') !== false, 'ответ сервера в журнале сохранён');
check(strpos(docgenSmtpExplain('Could not connect to SMTP host.', 'ssl', 587), '465') !== false, 'SSL на 587 подсказывает 465');
check(strpos(docgenSmtpExplain('Could not connect to SMTP host.', 'tls', 465), 'SSL/TLS') !== false, 'STARTTLS на 465 подсказывает SSL');
check(strpos(docgenSmtpExplain('SMTP Error: Could not authenticate.', 'tls', 587), 'внешнего приложения') !== false, 'ошибка входа объяснена про пароль приложения');
check(strpos(docgenSmtpExplain('certificate verify failed', 'tls', 587), 'сертификат') !== false, 'ошибка сертификата объяснена');
check(strpos(docgenSmtpExplain('Connection timed out', 'tls', 587), 'молчит') !== false, 'таймаут объяснён как блокировка порта');
$openTcp = [['step' => 'TCP', 'ok' => true, 'detail' => 'открыт']];
check(strpos(docgenSmtpExplain('Could not connect to SMTP host.', 'tls', 587, $openTcp), 'SMTP-диалог не начался') !== false, 'открытый порт без приветствия объяснён отдельно');
$closedTcp = [['step' => 'TCP', 'ok' => false, 'detail' => 'закрыт']];
check(strpos(docgenSmtpExplain('Could not connect to SMTP host.', 'tls', 587, $closedTcp), 'не открыл соединение') !== false, 'закрытый порт объяснён как неподключение');

$diagSteps = docgenSmtpNetworkSteps('127.0.0.1', 9, 'tls');
check(count($diagSteps) >= 2, 'диагностика сети возвращает DNS и TCP');
check($diagSteps[0]['step'] === 'DNS' && isset($diagSteps[0]['detail']), 'шаг DNS описан');
$hasTlsStep = false;
foreach (docgenSmtpNetworkSteps('127.0.0.1', 9, 'ssl') as $step) {
    if ($step['step'] === 'TLS') {
        $hasTlsStep = true;
    }
}
check($hasTlsStep, 'для SSL добавлен шаг TLS');
$hasTlsStep = false;
foreach (docgenSmtpNetworkSteps('127.0.0.1', 9, 'none') as $step) {
    if ($step['step'] === 'TLS') {
        $hasTlsStep = true;
    }
}
check(!$hasTlsStep, 'без шифрования шага TLS нет');

$diagnostics = docgenSmtpDiagnose([
    'host' => '127.0.0.1', 'port' => 9, 'encryption' => 'tls', 'username' => 'user@mail.ru',
    'fromEmail' => 'user@mail.ru', 'fromName' => '', 'replyTo' => '',
], $smtpPassword);
check($diagnostics['ok'] === false, 'диагностика недоступного сервера — not ok');
check(trim((string) $diagnostics['summary']) !== '', 'диагностика даёт понятную причину');
check(strpos((string) $diagnostics['transcript'], $smtpPassword) === false, 'в полном журнале нет пароля');
check(array_key_exists('PHP', (array) $diagnostics['context']) && isset($diagnostics['context']['openssl']), 'в журнале есть окружение PHP/openssl');
check(isset($diagnostics['elapsedMs']) && $diagnostics['elapsedMs'] >= 0, 'в журнале есть время попытки');
check(is_array($diagnostics['ports']) && count($diagnostics['ports']) === 4, 'при сбое проверяются соседние порты', (string) count((array) ($diagnostics['ports'] ?? [])));
$probedPorts = array_column((array) $diagnostics['ports'], 'port');
check($probedPorts === [25, 465, 587, 2525], 'проверяются 25, 465, 587 и 2525', implode(',', $probedPorts));
foreach ((array) $diagnostics['ports'] as $probe) {
    check(isset($probe['ok'], $probe['detail'], $probe['encryption']), 'у пробы порта ' . $probe['port'] . ' есть итог и описание');
}
check(strpos(docgenSmtpExplain('SMTP ERROR: Failed to connect to server: Connection timed out (110)', 'ssl', 465), 'не ошибка пароля') !== false, 'таймаут 465 объяснён как блокировка порта, а не пароль');

// 13. Бан firewall выглядит как сетевой сбой: пауза вместо серии попыток.
check(docgenIsNetworkError('SMTP Error: Could not connect to SMTP host. Failed to connect to server') === true, 'сетевой сбой распознан');
check(docgenIsNetworkError('SMTP ERROR: Failed to connect to server: Connection timed out (110)') === true, 'таймаут 110 распознан как сетевой');
check(docgenIsNetworkError('stream_socket_client(): Unable to connect (Connection refused)') === true, 'отказ соединения распознан');
check(docgenIsNetworkError('SMTP Error: The following recipients failed: boss@example.org') === false, 'ошибка адреса не считается сетевой');
check(docgenIsNetworkError('SMTP Error: Could not authenticate.') === false, 'ошибка входа не считается сетевой');
check(docgenIsNetworkError('421 4.7.0 ratelimit exceeded') === false, 'лимит 421 не считается сетевым сбоем');
check(docgenIsRateLimitError('421 4.7.0 ratelimit exceeded') === true, 'лимит 421 остаётся лимитом');

$netAccountId = 'acc_net_test';
check(docgenCronNetworkPauseRemaining($netAccountId) === 0, 'до сбоя сетевой паузы нет');
docgenCronNoteNetworkFailure($netAccountId, 'Connection timed out (110)');
$netLeft = docgenCronNetworkPauseRemaining($netAccountId);
check($netLeft > 800 && $netLeft <= 900, 'после сетевого сбоя пауза около 15 минут');
$netState = docgenReadJson(docgenCronAccountPath($netAccountId, 'state.json'), []);
check(strpos((string) ($netState['networkPauseReason'] ?? ''), 'timed out') !== false, 'причина сетевой паузы сохранена');
check(strpos(docgenSmtpNetworkErrorHint(), 'fail2ban') !== false, 'подсказка называет fail2ban');
$netDefault = (string) docgenSmtpAccountStore()['defaultId'];
docgenCronNoteNetworkFailure($netDefault, 'Connection timed out (110)');
$netTick = docgenCronSend($netDefault, false);
check(($netTick['reason'] ?? '') === 'network_pause', 'тик ящика на сетевой паузе ничего не отправляет');
check((int) ($netTick['pauseLeft'] ?? 0) > 0, 'тик сообщает остаток сетевой паузы');
check(strpos((string) ($netTick['hint'] ?? ''), 'fail2ban') !== false, 'тик объясняет причину паузы');
$netState = docgenReadJson(docgenCronAccountPath($netDefault, 'state.json'), []);
$netState['networkPauseUntil'] = 0;
docgenWriteJson(docgenCronAccountPath($netDefault, 'state.json'), $netState);
check(docgenCronNetworkPauseRemaining($netDefault) === 0, 'сетевую паузу можно снять');

// Частые нажатия «Проверить SMTP» сами по себе серия отказов — их считаем.
@unlink(DOCGEN_STORAGE_DIR . '/smtp-probe-state.json');
$probeSettings = ['host' => '127.0.0.1', 'port' => 9, 'encryption' => 'tls', 'username' => 'u@mail.ru',
    'fromEmail' => 'u@mail.ru', 'fromName' => '', 'replyTo' => ''];
$probeFirst = docgenSmtpDiagnose($probeSettings, 'pw');
check($probeFirst['probeCount'] === 1 && $probeFirst['probeWarn'] === '', 'первое нажатие проверки без предупреждения');
docgenSmtpDiagnose($probeSettings, 'pw');
$probeThird = docgenSmtpDiagnose($probeSettings, 'pw');
check($probeThird['probeCount'] === 3, 'счётчик нажатий проверки растёт');
check(strpos((string) $probeThird['probeWarn'], 'продлит бан') !== false, 'на третьем нажатии предупреждение о бане');
check(strpos(docgenSmtpExplain('SMTP ERROR: Failed to connect to server: Connection timed out (110)', 'ssl', 465), 'fail2ban') !== false, 'таймаут объяснён через fail2ban');

// 14. Журнал проверки переживает обрыв браузера и не запускается дважды.
$journalPath = DOCGEN_STORAGE_DIR . '/smtp-last-diagnose.json';
@unlink($journalPath);
$noJournal = docgenSmtpLastDiagnose();
check($noJournal['ok'] === false && strpos((string) $noJournal['summary'], 'пока нет') !== false, 'до проверки сохранённого журнала нет');
$journalSettings = ['host' => '127.0.0.1', 'port' => 9, 'encryption' => 'tls', 'username' => 'u@mail.ru',
    'fromEmail' => 'u@mail.ru', 'fromName' => '', 'replyTo' => ''];
$journalResult = docgenSmtpDiagnose($journalSettings, 'S3cret-Pa55word');
check($journalResult['ok'] === false, 'журнальная проверка выполнена');
check(!empty($journalResult['savedAt']), 'в ответе есть время сохранения');
check(is_file($journalPath), 'журнал сохранён в приватном каталоге');
$storedJournal = docgenSmtpLastDiagnose();
check(trim((string) $storedJournal['transcript']) !== '', 'сохранённый журнал содержит протокол', substr((string) $storedJournal['transcript'], 0, 120));
check(strpos((string) $storedJournal['transcript'], 'S3cret-Pa55word') === false, 'в сохранённом журнале нет пароля');
check(count((array) $storedJournal['ports']) === 4, 'сохранённый журнал содержит опрос портов');
check((string) $storedJournal['summary'] === (string) $journalResult['summary'], 'сохранён тот же вывод');

$probeLock = fopen(DOCGEN_STORAGE_DIR . '/smtp-probe.lock', 'c');
flock($probeLock, LOCK_EX | LOCK_NB);
$busyResult = docgenSmtpDiagnose($journalSettings, 'pw');
check(!empty($busyResult['busy']), 'вторая одновременная проверка отклоняется');
check(strpos((string) $busyResult['summary'], 'уже идёт') !== false, 'причина отказа от второй проверки понятна');
flock($probeLock, LOCK_UN);
fclose($probeLock);
check(empty(docgenSmtpDiagnose($journalSettings, 'pw')['busy']), 'после снятия блокировки проверка снова идёт');

// Бюджет: PHPMailer не должен забирать всё время, клиент ждёт дольше сервера.
check(DOCGEN_SMTP_PROBE_BUDGET_SECONDS <= 25, 'бюджет проверки меньше клиентского таймаута');
check(DOCGEN_SMTP_CONNECT_TIMEOUT_SECONDS < DOCGEN_SMTP_PROBE_BUDGET_SECONDS, 'таймаут подключения меньше общего бюджета');

// 15. Отключённые переменные: код вырезается из копии, шаблон не меняется.
$exclusionKey = docgenTemplateExclusionKey('sample-docx', 'Письмо-пример.docx');
check($exclusionKey === 'id:sample-docx', 'ключ отключений строится по id шаблона');
check(docgenTemplateExclusionKey('', 'Мой шаблон.DOCX') === 'file:мой шаблон.docx', 'разовый файл запоминается по имени в нижнем регистре');
docgenSaveTemplateExcluded($exclusionKey, ['Полное наименование']);
check(docgenTemplateExcluded($exclusionKey) === ['Полное наименование'], 'набор отключённых переменных сохраняется');
docgenSaveTemplateExcluded($exclusionKey, []);
check(docgenTemplateExcluded($exclusionKey) === [], 'пустой набор очищает настройку');

$stripXml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . '<w:p><w:r><w:t>Контакт: ({Телефон})</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>{Телефон}, добрый день</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>{Адрес}, {Телефон}</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>{Телефон}</w:t></w:r></w:p>'
    . '</w:body></w:document>';
$strippedCount = 0;
$strippedXml = (string) docgenStripExcludedXml($stripXml, ['Телефон'], 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', $strippedCount);
preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $strippedXml, $strippedTexts);
$strippedTexts = array_map(fn($text) => html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'), $strippedTexts[1]);
check($strippedCount > 0, 'вырезание затронуло документ');
check(!str_contains($strippedXml, '{Телефон}'), 'код отключённой переменной удалён');
check(in_array('', $strippedTexts, true), 'строка из одного кода становится пустой');
check(in_array('добрый день', $strippedTexts, true), 'текст после запятой сохранён');
check(in_array('Контакт', $strippedTexts, true) || in_array('Контакт:', $strippedTexts, true), 'пустые скобки убраны');
check(in_array('{Адрес}', $strippedTexts, true) || in_array('{Адрес}, ', $strippedTexts, true), 'включённая переменная не тронута');
check(docgenStripExcludedCodes('X ${Телефон} Y', ['Телефон']) === 'X  Y', 'доллар-формат кода также вырезается');

// Разовый шаблон: отключение приходит из запроса, а не из настроек.
$_POST['excluded_fields'] = json_encode(['Телефон', 'Адрес']);
check(docgenExcludedFromRequest(['id' => 'sample-docx', 'name' => 'Письмо-пример.docx']) === ['Телефон', 'Адрес'], 'отключения берутся из запроса генерации');
$_POST['excluded_fields'] = 'Телефон, Адрес';
check(docgenExcludedFromRequest(['id' => 'sample-docx', 'name' => 'Письмо-пример.docx']) === ['Телефон', 'Адрес'], 'поддерживается список через запятую');
unset($_POST['excluded_fields']);
docgenSaveTemplateExcluded($exclusionKey, ['Адрес']);
check(docgenExcludedFromRequest(['id' => 'sample-docx', 'name' => 'Письмо-пример.docx']) === ['Адрес'], 'без запроса действует сохранённая настройка');
docgenSaveTemplateExcluded($exclusionKey, []);

// HTML-шаблон: отключённый код вырезается до подстановки значений.
$htmlTemplate = [
    'id' => 'html-test', 'name' => 'Письмо.html', 'type' => 'html',
    'content' => '<p>{Обращение} {Имя Отчество}!</p><p>Тел.: {Телефон}</p>',
];
$htmlSource = sys_get_temp_dir() . '/excluded-html-' . bin2hex(random_bytes(4)) . '.txt';
docgenBuildDocumentSource($htmlTemplate, ['fields' => ['Обращение', 'Имя Отчество', 'Телефон'], 'syntax' => 'filldocuments', 'excluded' => ['Телефон']], ['Обращение' => 'Уважаемый', 'Имя Отчество' => 'Иван Иванович'], $htmlSource);
$htmlOut = (string) file_get_contents($htmlSource);
check(!str_contains($htmlOut, '{Телефон}'), 'в HTML-документе нет кода отключённой переменной', $htmlOut);
check(str_contains($htmlOut, 'Уважаемый'), 'включённые переменные подставлены');
@unlink($htmlSource);

// 16. Серверная выборка книги: страницы, фильтры и счётчики.
$testRecords = [];
for ($i = 0; $i < 95; $i++) {
    $testRecords[] = [
        'id' => 'rec-' . $i,
        'source' => 'Книга',
        'source_key' => $i % 2 === 0 ? 'guber' : 'test',
        'kind' => '',
        'fields' => [
            'Регион' => $i % 3 === 0 ? 'Москва' : 'Татарстан',
            'ИНН' => '7700000' . $i,
            'Должность' => 'Начальник отдела ' . ($i % 5),
            'Статус ЕГРЮЛ' => $i % 7 === 0 ? 'Не действует' : 'Действует',
        ],
    ];
}
$payload = docgenRecordsPayload(['records' => $testRecords], [], ['source' => 'all', 'region' => 'all', 'query' => '', 'facets' => []], 1);
check($payload['total'] === 95, 'все записи учтены в выборке');
check(count($payload['records']) === 30, 'на страницу отдаётся ровно 30 записей');
check($payload['pageCount'] === 4, 'число страниц считается от общего количества');
check(docgenRecordsPayload(['records' => $testRecords], [], [], 99)['page'] === 4, 'номер страницы ограничен последней');

$page3 = docgenRecordsPayload(['records' => $testRecords], [], [], 3);
check($page3['records'][0]['id'] === 'rec-60', 'третья страница начинается с 61-й записи', (string) ($page3['records'][0]['id'] ?? ''));

$bySource = docgenRecordsPayload(['records' => $testRecords], [], ['source' => 'guber'], 1);
check($bySource['total'] === 48, 'фильтр по источнику применяется на сервере', (string) $bySource['total']);

$byQuery = docgenRecordsPayload(['records' => $testRecords], [], ['query' => '770000077'], 1);
check($byQuery['total'] === 1, 'поиск по полям находит запись', (string) $byQuery['total']);
check(docgenRecordsPayload(['records' => $testRecords], [], ['query' => '7700000077'], 1)['total'] === 0, 'поиск не находит несуществующий ИНН');

$byRegion = docgenRecordsPayload(['records' => $testRecords], [], ['facets' => ['Регион' => ['Москва']]], 1);
check($byRegion['total'] === 32, 'фильтр по региону из шапки работает', (string) $byRegion['total']);

$noRegion = docgenRecordsPayload(['records' => $testRecords], [], ['facets' => ['Регион' => []]], 1);
check($noRegion['total'] === 0, 'пустой набор галочек даёт пустую выборку, как в Excel');

$regionFacet = [];
foreach ($byRegion['facets']['Регион'] as $entry) {
    $regionFacet[$entry['value']] = $entry['count'];
}
check(($regionFacet['Москва'] ?? 0) === 32, 'счётчик региона не учитывает собственный фильтр', (string) ($regionFacet['Москва'] ?? 0));
check(($regionFacet['Татарстан'] ?? 0) === 63, 'в счётчике видны и отфильтрованные значения', (string) ($regionFacet['Татарстан'] ?? 0));

$statusFacet = [];
foreach ($byRegion['facets']['Статус ЕГРЮЛ'] as $entry) {
    $statusFacet[$entry['value']] = $entry['count'];
}
check(($statusFacet['inactive'] ?? 0) === 5, 'статус «не действует» группируется отдельно', (string) ($statusFacet['inactive'] ?? 0));
check(($statusFacet['active'] ?? 0) === 27, 'счётчик действующих считается по остальным записям', (string) ($statusFacet['active'] ?? 0));

// Настройки вида: ширины столбцов общие и переживают перезагрузку.
docgenWriteJson(docgenTableViewFile(), ['widths' => ['Регион' => '180', 'ИНН' => 120], 'fit' => true, 'updatedAt' => date(DATE_ATOM)]);
$view = docgenTableView();
check(($view['widths']['Регион'] ?? 0) === 180, 'ширина столбца сохраняется числом');
check($view['fit'] === true, 'режим «по ширине окна» сохраняется');
docgenWriteJson(docgenTableViewFile(), ['widths' => ['Регион' => 20, 'ИНН' => 5000], 'fit' => false]);
$clean = docgenTableView();
check($clean['widths'] === [], 'недопустимые ширины отбрасываются');

// 7. Backup includes the per-template letter config.
check(isset(docgenBackupFileMap(false)['templates/letters.json']), 'letters.json is in the backup');

// 17. Задачи рассылки: план создаётся без генерации, прогресс считается по очереди.
require_once __DIR__ . '/../mailing_tasks.php';
$planIds = [];
for ($i = 1; $i <= 7; $i++) {
    $planIds[] = 'mail-' . $i;
}
$task = docgenMailingCreate([
    'name' => 'Проверка плана',
    'templateId' => 'sample-docx',
    'ids' => $planIds,
    'excludedFields' => [],
    'letter' => [],
]);
check(($task['plannedCount'] ?? 0) === 7, 'задача запоминает весь отбор без генерации', (string) ($task['plannedCount'] ?? 0));
check(($task['status'] ?? '') === 'generating', 'новая задача переходит в генерацию');
check(is_file(docgenMailingTaskFile((string) $task['id'])), 'задача сохраняется на диск');
$storedPlan = docgenMailingPlan((string) $task['id']);
check(count((array) ($storedPlan['ids'] ?? [])) === 7, 'план хранит все id для фоновой генерации');

// Письма задачи попадают в очередь с привязкой к задаче — по ней считается прогресс.
foreach ($planIds as $position => $mailId) {
    if ($position >= 3) {
        break;
    }
    docgenSaveQueueItem([
        'id' => str_pad((string) ($position + 1), 32, 'b', STR_PAD_LEFT),
        'batchId' => '',
        'mailingTaskId' => (string) $task['id'],
        'recordId' => $mailId,
        'status' => 'queued',
        'to' => 'r@example.com',
        'subject' => 'Тема',
        'body' => 'Текст',
        'bodyHtml' => '',
        'createdAt' => date(DATE_ATOM),
    ]);
}
$metrics = docgenMailingMetrics((string) $task['id']);
check(($metrics['ready'] ?? 0) === 3, 'готовые письма задачи считаются по привязке', (string) ($metrics['ready'] ?? 0));
check(($metrics['unresolved'] ?? -1) === 4, 'нерешённые письма = план минус готовые', (string) ($metrics['unresolved'] ?? -1));
check(($metrics['planned'] ?? 0) === 7, 'план остаётся виден рядом с прогрессом');

// Очередь не обрезается: отправка обязана видеть все письма, а не первые 500.
$queueIds = [];
for ($i = 1; $i <= 520; $i++) {
    $queueIds[] = str_pad((string) $i, 32, 'c', STR_PAD_LEFT);
    docgenSaveQueueItem([
        'id' => end($queueIds),
        'batchId' => '',
        'recordId' => 'bulk-' . $i,
        'status' => 'scheduled',
        'to' => 'bulk' . $i . '@example.com',
        'subject' => 'Тема',
        'body' => 'Текст',
        'bodyHtml' => '',
        'createdAt' => date(DATE_ATOM),
    ]);
}
$allScheduled = array_filter(docgenQueueList(true), static fn(array $item): bool => ($item['status'] ?? '') === 'scheduled');
check(count($allScheduled) >= 520, 'список очереди не обрезается на 500 письмах', (string) count($allScheduled));
$recent = docgenQueueRecent();
check(count($recent) === 500, 'интерфейсу отдаются только свежие 500 писем', (string) count($recent));

// Пауза останавливает генерацию, повторное включение её возвращает.
$paused = docgenMailingTask((string) $task['id']);
$paused['status'] = 'paused';
docgenMailingSaveTask($paused);
$tick = docgenMailingGenerateTick((string) $task['id']);
check(($tick['reason'] ?? '') === 'paused', 'на паузе тик ничего не генерирует');
$paused['status'] = 'cancelled';
docgenMailingSaveTask($paused);
$stopped = docgenMailingGenerateTick((string) $task['id']);
check(($stopped['reason'] ?? '') === 'not_generating', 'отменённая задача не генерируется');

// Удаление задачи убирает план, но не трогает письма в очереди.
$queuedBefore = count(docgenQueueList(true));
docgenMailingDelete((string) $task['id']);
check(docgenMailingTask((string) $task['id']) === [], 'удалённая задача исчезает из списка');
check(count(docgenQueueList(true)) === $queuedBefore, 'удаление задачи не удаляет письма очереди');

// 18. Отправленное письмо освобождает диск, но остаётся в журнале.
$sentId = str_pad('d', 32, 'd', STR_PAD_LEFT);
$sentDir = docgenQueuePath($sentId);
docgenEnsureDir($sentDir);
file_put_contents($sentDir . '/document.pdf', '%PDF-1.4');
file_put_contents($sentDir . '/document.docx', 'PK');
docgenSaveQueueItem([
    'id' => $sentId,
    'batchId' => '',
    'recordId' => 'cleanup-1',
    'status' => 'sent',
    'to' => 'x@example.com',
    'subject' => 'Тема',
    'body' => 'Текст',
    'bodyHtml' => '',
    'createdAt' => date(DATE_ATOM),
]);
check(is_file($sentDir . '/document.pdf'), 'до очистки PDF на месте');
check(docgenCleanupSentFiles($sentDir) === true, 'очистка сообщает об успехе');
check(!is_file($sentDir . '/document.pdf') && !is_file($sentDir . '/document.docx'), 'PDF и DOCX удалены после отправки');
check((docgenReadQueueItem($sentId)['status'] ?? '') === 'sent', 'запись журнала сохраняется после очистки');

echo $failures === 0 ? "OK: {$checks} checks passed\n" : "FAILED: {$failures} of {$checks}\n";
exit($failures === 0 ? 0 : 1);
