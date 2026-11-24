<?php

/**
 * Сверка интерфейса генератора вебинаров: элементы, к которым обращается
 * public/assets/webinar.js, должны существовать в разметке webinar.php.
 *
 * Запуск: php tests/webinar_ui_test.php
 *
 * Ошибка «Cannot read properties of undefined» означает, что скрипт взял
 * `el['...']` для id, которого нет в списке инициализации или в HTML.
 * Проверка статическая, сеть и браузер не нужны.
 */

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/webinar.js');
$html = (string) file_get_contents($root . '/webinar.php');

$failures = 0;
$checks = 0;
function check(bool $ok, string $label, string $extra = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok: $label\n";
        return;
    }
    $failures++;
    echo "  FAIL: $label" . ($extra === '' ? '' : ' — ' . $extra) . "\n";
}

// Список инициализации: массив строк перед .forEach(id => ...).
preg_match('/const el = \{\};\s*\[(.*?)\]\.forEach/s', $js, $listMatch);
$declared = $listMatch === [] ? [] : (preg_match_all("/'([a-zA-Z0-9_-]+)'/", $listMatch[1], $m) ? $m[1] : []);
check(count($declared) > 90, 'список инициализации найден', (string) count($declared));

// Идентификаторы, которые скрипт реально запрашивает через el['...'].
$used = [];
if (preg_match_all("/el\['([a-zA-Z0-9_-]+)'\]/", $js, $m)) {
    $used = array_values(array_unique($m[1]));
}
$missingInit = array_values(array_diff($used, $declared));
check($missingInit === [], 'все используемые id есть в списке инициализации', implode(', ', $missingInit));

// Идентификаторы, которые есть в разметке страницы.
$inHtml = [];
if (preg_match_all('/id="([a-zA-Z0-9_-]+)"/', $html, $m)) {
    $inHtml = array_values(array_unique($m[1]));
}
$missingHtml = array_values(array_diff($declared, $inHtml));
check($missingHtml === [], 'у каждого id из списка есть элемент в webinar.php', implode(', ', $missingHtml));

// $webinar... переменные шаблона не должны ломать разметку.
check(str_contains($html, 'id="monitor-times"'), 'в панели мониторинга есть контейнер времени проверок');

// Действия API, которые зовёт скрипт, должны быть в обработчике страницы.
$php = (string) file_get_contents($root . '/webinar.php');
preg_match_all('/(?:request|read)\(\s*[\'"]([a-z_]+)[\'"]/', $js, $m);
$called = array_values(array_unique($m[1]));
preg_match_all("/case '([a-z_]+)'/", $php, $m);
$handled = array_values(array_unique($m[1]));
$unknown = array_values(array_diff($called, $handled));
check($unknown === [], 'каждое действие из скрипта обрабатывается на сервере', implode(', ', $unknown));

if ($failures > 0) {
    echo "\nFAILED: $failures of $checks checks\n";
    exit(1);
}
echo "\nOK: $checks checks passed\n";
