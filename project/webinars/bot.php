<?php
declare(strict_types=1);

/**
 * Команды Telegram-бота: меню, список вебинаров, журнал проверок, ручная
 * проверка и расписание.
 *
 * Бот читает сообщения через getUpdates и отвечает только тому чату, который
 * привязан в панели (или задан окружением службы). Чужие сообщения игнорируются,
 * поэтому бота нельзя использовать как открытый справочник.
 *
 * Команды работают и текстом, и кнопками: подпись кнопки — это та же команда.
 */

/** Предел длины сообщения Telegram. */
const WEBINARS_BOT_MAX = 3900;

/** Смещение getUpdates: хранится отдельно, чтобы не терять сообщения между тиками. */
function webinarsBotStateFile(): string
{
    return WEBINARS_STORAGE_DIR . '/bot-state.json';
}

/** @return array{offset: int, answered: array<string, int>, last_update: int} */
function webinarsBotState(): array
{
    $state = docgenReadJson(webinarsBotStateFile(), []);
    $answered = [];
    foreach ((array) ($state['answered'] ?? []) as $id => $at) {
        if ((int) $at > time() - 600) {
            $answered[(string) $id] = (int) $at;
        }
    }
    return [
        'offset' => (int) ($state['offset'] ?? 0),
        'answered' => $answered,
        'last_update' => (int) ($state['last_update'] ?? 0),
    ];
}

/** @param array{offset?: int, answered?: array<string, int>, last_update?: int} $state */
function webinarsBotSaveState(array $state): void
{
    webinarsBoot();
    docgenWriteJson(webinarsBotStateFile(), $state);
    @chmod(webinarsBotStateFile(), 0600);
}

/** Главное меню: заголовок и кнопки в два столбца. */
function webinarsBotMenu(): array
{
    $status = webinarsMonitorStatus();
    $time = $status['last_at'] !== '' ? webinarsBotDate($status['last_at']) : 'ещё не было';
    $lines = [
        'ЦМК · Управление ботом',
        '',
        'Расписание: ' . ($status['enabled'] ? 'включено' : 'приостановлено'),
        'Проверки: ' . implode(', ', $status['times']) . ' МСК',
        $status['next_check'] !== null ? 'Следующая: ' . webinarsBotDate($status['next_check']) : '',
        'Последняя проверка: ' . $time,
        'Новых карточек в очереди: ' . $status['pending'],
        '',
        'Выберите раздел или отправьте команду.',
    ];
    return [
        'text' => implode("\n", array_filter($lines, static fn(string $line): bool => $line !== '')),
        'keyboard' => [
            [['text' => '📋 Список вебинаров', 'callback_data' => 'list'], ['text' => '🔎 Проверить сейчас', 'callback_data' => 'check']],
            [['text' => '🗓 Расписание', 'callback_data' => 'schedule'], ['text' => '📜 Логи проверки', 'callback_data' => 'logs']],
            [['text' => '📊 Статус', 'callback_data' => 'status'], ['text' => '❓ Помощь', 'callback_data' => 'help']],
        ],
    ];
}

/** «2026-11-24T13:30:00+03:00» или «2026-11-24 13:30:05» → «24.11.2026, 13:30». */
function webinarsBotDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $at = new DateTimeImmutable($value);
    } catch (Exception) {
        return $value;
    }
    return $at->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y, H:i') . ' МСК';
}

/**
 * Текст команды: убираем адресацию бота (/list@my_bot), эмодзи из подписи
 * кнопки и регистр, чтобы «Список вебинаров» и «/list» работали одинаково.
 */
function webinarsBotNormalize(string $text): string
{
    $text = trim(str_replace("\u{00A0}", ' ', $text));
    if ($text === '') {
        return '';
    }
    $text = (string) preg_replace('/@[\w_]+$/u', '', $text);
    $text = (string) preg_replace('/^[\p{So}\p{Sk}\s]+/u', '', $text);
    return mb_strtolower(trim($text), 'UTF-8');
}

/** Разбор команды: возвращает ключ раздела или null. */
function webinarsBotCommand(string $text): ?string
{
    $key = webinarsBotNormalize($text);
    if ($key === '') {
        return null;
    }
    $aliases = [
        'start' => 'menu', 'menu' => 'menu', 'меню' => 'menu', 'главное меню' => 'menu', '❓ помощь' => 'help',
        'list' => 'list', 'список вебинаров' => 'list', 'вебинары' => 'list', 'список' => 'list',
        'check' => 'check', 'проверить сейчас' => 'check', 'проверить' => 'check', 'обновить' => 'check',
        'schedule' => 'schedule', 'расписание' => 'schedule', 'настроить расписание' => 'schedule',
        'logs' => 'logs', 'логи проверки' => 'logs', 'логи' => 'logs', 'журнал' => 'logs',
        'status' => 'status', 'статус' => 'status', 'состояние' => 'status',
        'help' => 'help', 'помощь' => 'help', 'команды' => 'help',
        'on' => 'schedule_on', 'включить' => 'schedule_on', 'включить расписание' => 'schedule_on',
        'off' => 'schedule_off', 'выключить' => 'schedule_off', 'выключить расписание' => 'schedule_off',
        'new' => 'list_new', 'новые' => 'list_new', 'новые вебинары' => 'list_new',
        'today' => 'list_today', 'сегодня' => 'list_today', 'ближайшие' => 'list_upcoming',
        'ping' => 'ping',
    ];
    $key = ltrim($key, '/');
    return $aliases[$key] ?? null;
}

/** Подпись команды для ответа: «/list — список вебинаров». */
function webinarsBotHelp(): string
{
    return implode("\n", [
        'ЦМК · Команды',
        '',
        '/menu — главное меню с кнопками',
        '/list — список текущих вебинаров',
        '/new — только новые карточки (без сформированных)',
        '/today — вебинары на сегодня и завтра',
        '/check — проверить сайт сейчас',
        '/schedule — расписание и переключатель',
        '/on · /off — включить или приостановить расписание',
        '/logs — журнал последних проверок',
        '/status — состояние: расписание, очередь, бланк',
        '/help — эта подсказка',
        '',
        'Кнопки и текст работают одинаково; регистр и эмодзи не важны.',
    ]);
}

/**
 * Список вебинаров в чате: ближайшие по дате, с пометками.
 *
 * @param 'all'|'new'|'today'|'upcoming' $scope
 * @return array{text: string, keyboard?: list<list<array{text: string, callback_data: string}>>}
 */
function webinarsBotList(string $scope = 'all'): array
{
    $state = webinarsState();
    $events = array_values((array) $state['events']);
    $status = webinarsMonitorStatus();
    $today = webinarsNow()->format('Y-m-d');
    $tomorrow = webinarsNow()->modify('+1 day')->format('Y-m-d');

    if ($scope === 'new') {
        $events = array_values(array_filter($events, static fn(array $event): bool => !empty($event['is_new'])));
    } elseif ($scope === 'today') {
        $events = array_values(array_filter($events, static fn(array $event): bool => in_array((string) ($event['day'] ?? ''), [$today, $tomorrow], true)));
    }
    usort($events, static fn(array $a, array $b): int => [(string) ($a['day'] ?? ''), (string) ($a['menu_title'] ?? '')] <=> [(string) ($b['day'] ?? ''), (string) ($b['menu_title'] ?? '')]);
    if ($scope === 'upcoming') {
        $events = array_values(array_filter($events, static fn(array $event): bool => (string) ($event['day'] ?? '') >= $today));
    }

    $title = match ($scope) {
        'new' => 'ЦМК · Новые карточки',
        'today' => 'ЦМК · Сегодня и завтра',
        'upcoming' => 'ЦМК · Ближайшие вебинары',
        default => 'ЦМК · Текущие вебинары',
    };
    if ($events === []) {
        $empty = match ($scope) {
            'new' => 'Новых карточек нет: всё сформировано или ещё не найдено.',
            'today' => 'На сегодня и завтра вебинаров нет.',
            default => 'Список пуст. Запустите проверку: /check.',
        };
        return ['text' => $title . "\n\n" . $empty . "\n\nВсего в списке: " . count((array) $state['events'])];
    }

    $lines = [$title . ' · ' . count($events), ''];
    foreach ($events as $index => $event) {
        $day = isset($event['day']) && is_string($event['day']) && $event['day'] !== '' ? webinarsDayMonthPlain($event['day']) : 'дата уточняется';
        $marks = [];
        if (!empty($event['is_new'])) {
            $marks[] = 'новое';
        }
        $key = webinarsEventKey($event);
        if (isset($state['generated'][$key])) {
            $marks[] = 'сформировано';
        }
        $name = (string) ($event['menu_title'] ?? '') !== '' ? (string) $event['menu_title'] : (string) ($event['title'] ?? '');
        $lines[] = ($index + 1) . '. ' . $day . ' · ' . webinarsHours($event) . ' МСК' . ($marks === [] ? '' : ' · ' . implode(', ', $marks)) . "\n   " . $name . "\n   " . (string) ($event['url'] ?? '');
        if (mb_strlen(implode("\n", $lines), 'UTF-8') > WEBINARS_BOT_MAX - 500) {
            $lines[] = '…и ещё ' . (count($events) - $index - 1) . '. Полный список — в панели на сервере.';
            break;
        }
    }
    $lines[] = '';
    $lines[] = 'Очередь уведомлений: ' . $status['pending'] . '. Формирование Word — в панели.';
    return [
        'text' => implode("\n", $lines),
        'keyboard' => [
            [['text' => '🔎 Проверить сейчас', 'callback_data' => 'check'], ['text' => '🏠 Меню', 'callback_data' => 'menu']],
        ],
    ];
}

/** Журнал проверок: последние записи с итогом и заметкой. */
function webinarsBotLogs(): array
{
    $state = webinarsState();
    $checks = array_slice(array_reverse((array) $state['checks']), 0, 10);
    if ($checks === []) {
        return ['text' => 'ЦМК · Логи проверки' . "\n\nЗаписей пока нет. Запустите проверку: /check."];
    }
    $lines = ['ЦМК · Логи проверки (последние ' . count($checks) . ')', ''];
    foreach ($checks as $check) {
        $ok = !empty($check['ok']) && !empty($check['site_ok']);
        $lines[] = ($ok ? '✅ ' : '⚠️ ') . webinarsBotDate((string) ($check['at'] ?? ''))
            . ' · +' . (int) ($check['added'] ?? 0) . ' новых'
            . ' · отправлено ' . (int) ($check['sent'] ?? 0);
        $note = trim((string) ($check['note'] ?? ''));
        if ($note !== '') {
            $lines[] = '   ' . $note;
        }
        $lines[] = '';
    }
    $status = webinarsMonitorStatus();
    $lines[] = 'Всего проверок в журнале: ' . count((array) $state['checks'])
        . ' · следующая: ' . ($status['next_check'] !== null ? webinarsBotDate($status['next_check']) : 'расписание выключено');
    return [
        'text' => implode("\n", $lines),
        'keyboard' => [
            [['text' => '🔎 Проверить сейчас', 'callback_data' => 'check'], ['text' => '🏠 Меню', 'callback_data' => 'menu']],
        ],
    ];
}

/** Расписание: состояние и кнопка-переключатель. */
function webinarsBotSchedule(): array
{
    $status = webinarsMonitorStatus();
    $lines = [
        'ЦМК · Расписание проверок',
        '',
        'Статус: ' . ($status['enabled'] ? 'включено' : 'приостановлено'),
        'Время (МСК): ' . implode(', ', $status['times']),
        $status['next_check'] !== null ? 'Следующая проверка: ' . webinarsBotDate($status['next_check']) : '',
        '',
        $status['enabled']
            ? 'Проверки выполняет служба на сервере; после включения менять часы нужно в панели.'
            : 'Автоматические проверки не идут. Включите их кнопкой ниже или командой /on.',
    ];
    return [
        'text' => implode("\n", array_filter($lines, static fn(string $line): bool => $line !== '')),
        'keyboard' => [[
            ['text' => $status['enabled'] ? '⏸ Приостановить' : '▶️ Включить', 'callback_data' => $status['enabled'] ? 'schedule_off' : 'schedule_on'],
            ['text' => '🏠 Меню', 'callback_data' => 'menu'],
        ]],
    ];
}

/** Состояние: расписание, очередь, бланк, последняя проверка. */
function webinarsBotStatus(): array
{
    $status = webinarsMonitorStatus();
    $state = webinarsState();
    $layout = is_file(WEBINARS_LAYOUT_FILE) ? 'загружен в настройках' : 'утверждённый из поставки';
    $lines = [
        'ЦМК · Состояние',
        '',
        'Расписание: ' . ($status['enabled'] ? 'включено' : 'приостановлено'),
        'Следующая проверка: ' . ($status['next_check'] !== null ? webinarsBotDate($status['next_check']) : '—'),
        'Последняя проверка: ' . ($status['last_at'] !== '' ? webinarsBotDate($status['last_at']) : 'ещё не было'),
        'Карточек в списке: ' . count((array) $state['events']),
        'Сформировано программ: ' . count((array) $state['generated']),
        'Очередь уведомлений: ' . $status['pending'],
        'Бланк: ' . $layout,
        'Telegram: ' . ($status['configured'] ? 'чат привязан' : 'чат не привязан'),
    ];
    if ($status['last_note'] !== '') {
        $lines[] = '';
        $lines[] = 'Последняя заметка: ' . $status['last_note'];
    }
    return [
        'text' => implode("\n", $lines),
        'keyboard' => [[['text' => '🏠 Меню', 'callback_data' => 'menu']]],
    ];
}

/**
 * Ответ на команду. Возвращает текст и, если нужно, клавиатуру.
 *
 * @return array{text: string, keyboard?: list<list<array{text: string, callback_data: string}>>}
 */
function webinarsBotAnswer(string $command, bool $allowCheck = true): array
{
    switch ($command) {
        case 'menu':
            return webinarsBotMenu();
        case 'help':
            return ['text' => webinarsBotHelp(), 'keyboard' => [[['text' => '🏠 Меню', 'callback_data' => 'menu']]]];
        case 'list':
            return webinarsBotList('all');
        case 'list_new':
            return webinarsBotList('new');
        case 'list_today':
            return webinarsBotList('today');
        case 'list_upcoming':
            return webinarsBotList('upcoming');
        case 'logs':
            return webinarsBotLogs();
        case 'schedule':
            return webinarsBotSchedule();
        case 'schedule_on':
            webinarsMonitorToggle(true);
            return ['text' => "Расписание включено.\nПроверки: " . implode(', ', WEBINARS_CHECK_TIMES) . " МСК.\n\n" . webinarsBotSchedule()['text'], 'keyboard' => webinarsBotSchedule()['keyboard']];
        case 'schedule_off':
            webinarsMonitorToggle(false);
            return ['text' => "Расписание приостановлено. Сохранённый список не изменится.\n\n" . webinarsBotSchedule()['text'], 'keyboard' => webinarsBotSchedule()['keyboard']];
        case 'status':
            return webinarsBotStatus();
        case 'ping':
            return ['text' => 'Пинг: бот на связи, ' . webinarsBotDate(webinarsNow()->format('c'))];
        case 'check':
            if (!$allowCheck) {
                return ['text' => 'Проверка сейчас недоступна: она уже выполняется. Попробуйте через минуту.'];
            }
            return webinarsBotRunCheck();
        default:
            return [
                'text' => "Не понял команду.\n\n" . webinarsBotHelp(),
                'keyboard' => [[['text' => '🏠 Меню', 'callback_data' => 'menu']]],
            ];
    }
}

/** Ручная проверка из чата: обход сайта, сохранение списка, отправка уведомлений. */
function webinarsBotRunCheck(): array
{
    try {
        $result = webinarsMonitorCheckNow();
    } catch (Throwable $error) {
        return [
            'text' => 'Проверка не запустилась: ' . mb_substr($error->getMessage(), 0, 500, 'UTF-8'),
            'keyboard' => [[['text' => '🏠 Меню', 'callback_data' => 'menu']]],
        ];
    }
    $ok = !empty($result['site_ok']);
    $lines = [
        $ok ? '✅ Проверка выполнена' : '⚠️ Проверка не удалась',
        '',
        'Новых карточек: ' . (int) ($result['added'] ?? 0),
        'Отправлено уведомлений: ' . (int) ($result['sent'] ?? 0),
    ];
    $note = trim((string) ($result['note'] ?? ''));
    if ($note !== '') {
        $lines[] = '';
        $lines[] = $note;
    }
    if ((int) ($result['added'] ?? 0) > 0) {
        $lines[] = '';
        $lines[] = 'Подробнее: /new';
    }
    return [
        'text' => implode("\n", $lines),
        'keyboard' => [[['text' => '📋 Список', 'callback_data' => 'list'], ['text' => '🏠 Меню', 'callback_data' => 'menu']]],
    ];
}

/** @return array{text: string, keyboard?: array<int, array<int, array{text: string, callback_data: string}>>} */
function webinarsBotKeyboard(array $answer): array
{
    return isset($answer['keyboard']) ? ['inline_keyboard' => $answer['keyboard']] : [];
}

/**
 * Один шаг чтения сообщений бота.
 *
 * Вызывается из планировщика (раз в минуту) и из кнопки в панели. Отвечает
 * только привязанному чату: чужие сообщения получают отказ один раз, чтобы
 * бота нельзя было использовать как открытый справочник.
 *
 * @return array<string, mixed>
 */
function webinarsBotPoll(?DateTimeImmutable $now = null): array
{
    $config = webinarsTelegramConfig();
    if ($config === null) {
        return ['ok' => true, 'skipped' => 'not_configured'];
    }
    $token = $config['token'];
    $chat = $config['chat_id'];
    $state = webinarsBotState();
    $updates = webinarsTelegramCall($token, 'getUpdates', [
        'offset' => $state['offset'],
        'timeout' => 0,
        'limit' => 20,
        'allowed_updates' => ['message', 'callback_query'],
    ]);
    if (!is_array($updates)) {
        throw new WebinarsTelegramError('Telegram вернул неверный список сообщений.');
    }
    $handled = 0;
    $ignored = 0;
    $maxUpdate = $state['offset'] > 0 ? $state['offset'] - 1 : 0;
    foreach ($updates as $update) {
        if (!is_array($update) || !isset($update['update_id'])) {
            continue;
        }
        $updateId = (int) $update['update_id'];
        $maxUpdate = max($maxUpdate, $updateId);
        // Повтор после сбоя не должен отвечать дважды.
        if (isset($state['answered'][(string) $updateId])) {
            continue;
        }

        $callback = $update['callback_query'] ?? null;
        $message = $update['message'] ?? null;
        $fromChat = is_array($callback) ? (string) ($callback['message']['chat']['id'] ?? '') : (string) ($message['chat']['id'] ?? '');
        $text = is_array($callback) ? (string) ($callback['data'] ?? '') : (string) ($message['text'] ?? '');
        $callbackId = is_array($callback) ? (string) ($callback['id'] ?? '') : '';

        if ($fromChat !== $chat) {
            // Чужой чат: одно вежливое объяснение, дальше молчим.
            $ignored++;
            if ($callbackId !== '') {
                webinarsTelegramCall($token, 'answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Этот бот отвечает только своему чату.', 'show_alert' => false]);
            } elseif ($message !== null) {
                webinarsTelegramSend($token, $fromChat, 'Этот бот отвечает только привязанному чату ЦМК. Попросите администратора открыть панель генератора вебинаров.');
            }
            $state['answered'][(string) $updateId] = time();
            continue;
        }

        if ($callbackId !== '') {
            webinarsTelegramCall($token, 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
        }

        $command = is_array($callback) ? (webinarsBotCommand($text) ?? 'menu') : webinarsBotCommand($text);
        if ($command === null) {
            // Произвольный текст: показываем меню, чтобы не оставлять без ответа.
            $command = 'help';
        }
        try {
            $answer = webinarsBotAnswer($command);
        } catch (Throwable $error) {
            error_log('webinars bot: ' . $error->getMessage());
            $answer = ['text' => 'Не удалось выполнить команду. Подробности в журнале службы.'];
        }
        $payload = ['chat_id' => $chat, 'text' => mb_substr($answer['text'], 0, WEBINARS_BOT_MAX, 'UTF-8'), 'disable_web_page_preview' => true];
        $keyboard = webinarsBotKeyboard($answer);
        if ($keyboard !== []) {
            $payload['reply_markup'] = $keyboard;
        }
        webinarsTelegramCall($token, 'sendMessage', $payload);
        $state['answered'][(string) $updateId] = time();
        $handled++;
    }
    $state['offset'] = $maxUpdate + 1;
    $state['last_update'] = time();
    // Память о отвеченных обновлениях нужна только на короткое время.
    $state['answered'] = array_filter($state['answered'], static fn(int $at): bool => $at > time() - 900);
    webinarsBotSaveState($state);
    return ['ok' => true, 'handled' => $handled, 'ignored' => $ignored, 'checked_at' => ($now ?? webinarsNow())->format('c')];
}
