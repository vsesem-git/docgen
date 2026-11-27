<?php
declare(strict_types=1);

/**
 * Задачи рассылки для больших баз (4 000–20 000 писем).
 *
 * Зачем отдельный слой. Разовая генерация из браузера упирается в лимит
 * MAX_ROWS_PER_RUN = 300 писем и в один запрос с таймаутом 300 секунд.
 * На 4 000 письмах это 14 ручных запусков, а на 20 000 — 67.
 *
 * Задача рассылки решает это иначе:
 *   1. Создание — только план: запоминаем отбор записей и настройки письма.
 *      Ничего не генерируется, поэтому ответ приходит мгновенно.
 *   2. Генерация — фоновая, по чанкам, из того же cron-таймера, что и отправка.
 *      Каждый тик берёт следующую порцию, генерирует PDF и кладёт письма
 *      в очередь. Прогресс виден в интерфейсе.
 *   3. Отправка — прежняя: почтовый cron берёт готовые письма из очереди.
 *
 * Почему не «генерировать в момент отправки»: генерация примерно в 60 раз
 * быстрее отправки при 1 письме в минуту (LibreOffice ~1 с на документ,
 * отправка — 60 с на письмо). Держать LibreOffice в цикле отправки значило бы
 * смешать две разные надёжности: падение конвертации остановило бы отправку
 * уже готовых писем.
 */

/** Сколько документов генерировать за один тик воркера. */
const DOCGEN_MAILING_CHUNK = 40;

/** Сколько документов держать готовыми впереди отправки. */
const DOCGEN_MAILING_LOOKAHEAD = 120;

/** Секунд на один тик воркера: LibreOffice получает пакет, а не по одному. */
const DOCGEN_MAILING_BUDGET_SECONDS = 150;

function docgenMailingDir(): string
{
    return DOCGEN_BATCH_DIR . '/mailing';
}

function docgenMailingTaskPath(string $id): string
{
    return docgenMailingDir() . '/' . $id;
}

function docgenMailingTaskFile(string $id): string
{
    return docgenMailingTaskPath($id) . '/task.json';
}

function docgenMailingTaskPlanFile(string $id): string
{
    return docgenMailingTaskPath($id) . '/plan.json';
}

function docgenMailingJobDir(string $id, string $jobId): string
{
    return docgenMailingTaskPath($id) . '/jobs/' . $jobId;
}

function docgenMailingValidId(string $id): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
}

function docgenMailingTask(string $id): array
{
    if (!docgenMailingValidId($id)) {
        return [];
    }
    $task = docgenReadJson(docgenMailingTaskFile($id), []);
    return (string) ($task['id'] ?? '') === $id ? $task : [];
}

function docgenMailingSaveTask(array $task): void
{
    $id = (string) ($task['id'] ?? '');
    if (!docgenMailingValidId($id)) {
        throw new RuntimeException('Некорректный идентификатор задачи рассылки.');
    }
    docgenEnsureDir(docgenMailingTaskPath($id));
    $task['updatedAt'] = date(DATE_ATOM);
    docgenWriteJson(docgenMailingTaskFile($id), $task);
}

function docgenMailingSavePlan(string $id, array $plan): void
{
    docgenEnsureDir(docgenMailingTaskPath($id));
    docgenWriteJson(docgenMailingTaskPlanFile($id), $plan);
}

function docgenMailingPlan(string $id): array
{
    return docgenReadJson(docgenMailingTaskPlanFile($id), []);
}

/**
 * Все задачи, новые сверху. Читаются только meta-файлы, без планов:
 * план на 20 000 записей весит мегабайты, и грузить его в списке незачем.
 */
function docgenMailingTasks(): array
{
    $tasks = [];
    foreach (scandir(docgenMailingDir()) ?: [] as $id) {
        if (!docgenMailingValidId($id)) {
            continue;
        }
        $task = docgenMailingTask($id);
        if ($task) {
            $tasks[] = $task;
        }
    }
    usort($tasks, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
    return $tasks;
}

/** Счётчики писем задачи по статусам очереди. */
function docgenMailingMetrics(string $id): array
{
    $task = docgenMailingTask($id);
    $counts = ['planned' => (int) ($task['plannedCount'] ?? 0), 'queued' => 0, 'scheduled' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'ready' => 0];
    foreach (docgenQueueList() as $item) {
        if ((string) ($item['mailingTaskId'] ?? '') !== $id) {
            continue;
        }
        $status = (string) ($item['status'] ?? '');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    // «Готово к отправке» — письма, у которых уже есть PDF и получатель.
    // Именно их воркер держит впереди отправки; остальное ещё генерируется.
    $counts['ready'] = $counts['queued'] + $counts['scheduled'];
    $counts['unresolved'] = max(0, $counts['planned'] - $counts['ready'] - $counts['sent'] - $counts['failed'] - $counts['cancelled']);
    return $counts;
}

/**
 * Создаёт задачу рассылки: проверяет шаблон и отбор, но ничего не
 * генерирует. Возвращает задачу; генерацию запустит воркер.
 */
function docgenMailingCreate(array $request): array
{
    $ids = $request['ids'] ?? [];
    if (!is_array($ids) || !$ids) {
        throw new InvalidArgumentException('Сначала выберите записи для рассылки.');
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
    if (count($ids) > 50000) {
        throw new InvalidArgumentException('За один раз можно создать рассылку не более чем на 50 000 писем.');
    }
    $templateId = trim((string) ($request['templateId'] ?? ''));
    if ($templateId === '') {
        throw new InvalidArgumentException('Выберите шаблон документа.');
    }
    $template = docgenResolveTemplateFromId($templateId);
    $info = docgenScanTemplate($template);
    if (($info['syntax'] ?? '') === 'mixed') {
        throw new InvalidArgumentException('В DOCX смешаны переменные {поле} и ${поле}. Оставьте один формат.');
    }
    if (empty($info['fields'])) {
        throw new InvalidArgumentException('В шаблоне не найдены коды полей.');
    }
    $id = bin2hex(random_bytes(16));
    $task = [
        'id' => $id,
        'name' => mb_substr(trim((string) ($request['name'] ?? '')), 0, 160, 'UTF-8'),
        'templateId' => $templateId,
        'templateName' => (string) ($template['name'] ?? ''),
        'excludedFields' => array_values(array_map('strval', (array) ($request['excludedFields'] ?? []))),
        'letter' => (array) ($request['letter'] ?? []),
        'plannedCount' => count($ids),
        'generatedCount' => 0,
        'errorCount' => 0,
        'status' => 'generating',
        'lastError' => '',
        'createdAt' => date(DATE_ATOM),
        'updatedAt' => date(DATE_ATOM),
        'generationStartedAt' => null,
        'generationFinishedAt' => null,
        'lastTickAt' => null,
    ];
    if ($task['name'] === '') {
        $task['name'] = 'Рассылка от ' . date('d.m.Y H:i');
    }
    docgenMailingSaveTask($task);
    docgenMailingSavePlan($id, [
        'ids' => $ids,
        'overrides' => (array) ($request['overrides'] ?? []),
    ]);
    return $task;
}

/** Удаляет задачу вместе с планом; письма в очереди остаются как есть. */
function docgenMailingDelete(string $id): void
{
    $task = docgenMailingTask($id);
    if (!$task) {
        throw new RuntimeException('Задача не найдена.');
    }
    cleanupTree(docgenMailingTaskPath($id));
}

/**
 * Генерирует следующую порцию документов задачи.
 *
 * Работает без браузера: шаблон и настройки письма восстанавливаются из
 * задачи, поэтому функцию можно звать из cron. Возвращает отчёт о тике,
 * чтобы вызывающий код (cron) мог записать его в журнал.
 */
function docgenMailingGenerateTick(string $id, ?int $chunkSize = null, ?int $budgetSeconds = null): array
{
    $task = docgenMailingTask($id);
    if (!$task) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if (!in_array((string) $task['status'], ['generating', 'paused'], true)) {
        return ['ok' => true, 'reason' => 'not_generating', 'generated' => 0];
    }
    if ((string) $task['status'] === 'paused') {
        return ['ok' => true, 'reason' => 'paused', 'generated' => 0];
    }
    $lockPath = docgenMailingTaskPath($id) . '/worker.lock';
    docgenEnsureDir(dirname($lockPath));
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        // Второй тик одновременно не нужен: LibreOffice уже занят этим.
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['ok' => true, 'reason' => 'already_running', 'generated' => 0];
    }
    try {
        return docgenMailingGenerateLocked($task, $chunkSize ?? DOCGEN_MAILING_CHUNK, $budgetSeconds ?? DOCGEN_MAILING_BUDGET_SECONDS);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function docgenMailingGenerateLocked(array $task, int $chunkSize, int $budgetSeconds): array
{
    $id = (string) $task['id'];
    $plan = docgenMailingPlan($id);
    $ids = (array) ($plan['ids'] ?? []);
    $done = (int) ($task['generatedCount'] ?? 0) + (int) ($task['errorCount'] ?? 0);
    if ($done >= count($ids)) {
        $task['status'] = 'ready';
        $task['generationFinishedAt'] = $task['generationFinishedAt'] ?? date(DATE_ATOM);
        docgenMailingSaveTask($task);
        return ['ok' => true, 'reason' => 'finished', 'generated' => 0];
    }
    $slice = array_slice($ids, $done, $chunkSize);
    $overrides = (array) ($plan['overrides'] ?? []);
    $startedAt = microtime(true);

    // Шаблон и оформление письма восстанавливаем из задачи, а не из POST.
    $template = docgenResolveTemplateFromId((string) $task['templateId']);
    $info = docgenScanTemplate($template);
    $taskExcluded = array_values(array_intersect((array) ($task['excludedFields'] ?? []), (array) $info['fields']));
    $required = array_values(array_diff((array) $info['fields'], $taskExcluded));
    $info['excluded'] = $taskExcluded;
    [$letter, $letterTemplateId] = docgenLetterForTemplate($template);

    $jobId = bin2hex(random_bytes(8));
    $jobDir = docgenMailingJobDir($id, $jobId);
    $sourceDir = $jobDir . '/sources';
    $pdfDir = $jobDir . '/pdf';
    docgenEnsureDir($sourceDir);
    docgenEnsureDir($pdfDir);

    $dataset = loadDataset();
    $byId = [];
    foreach ((array) ($dataset['records'] ?? []) as $record) {
        if (isset($record['id'])) {
            $byId[(string) $record['id']] = $record;
        }
    }

    $prepared = [];
    $errors = [];
    $number = 0;
    foreach ($slice as $recordId) {
        if (!isset($byId[$recordId])) {
            $errors[] = ['id' => $recordId, 'reason' => 'Запись не найдена в наборе.'];
            continue;
        }
        $number++;
        $extension = $template['type'] === 'docx' ? 'docx' : 'html';
        $sourcePath = $sourceDir . '/doc_' . str_pad((string) $number, 4, '0', STR_PAD_LEFT) . '.' . $extension;
        try {
            $fields = docgenPrepareDocument($byId[$recordId], $template, $info, $required, $overrides, $sourcePath);
            $prepared[] = ['id' => $recordId, 'record' => $byId[$recordId], 'fields' => $fields, 'sourcePath' => $sourcePath];
        } catch (InvalidArgumentException $e) {
            $errors[] = ['id' => $recordId, 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            $errors[] = ['id' => $recordId, 'reason' => 'Ошибка генерации документа: ' . $e->getMessage()];
        }
    }

    $generated = 0;
    if ($prepared) {
        $conversion = docgenConvertToPdf(array_column($prepared, 'sourcePath'), $pdfDir, $jobDir . '/lo-profile');
        $missing = array_fill_keys((array) ($conversion['missing'] ?? []), true);
        foreach ($prepared as $item) {
            $pdfPath = $pdfDir . '/' . pathinfo((string) $item['sourcePath'], PATHINFO_FILENAME) . '.pdf';
            if (isset($missing[basename((string) $item['sourcePath'])]) || !is_file($pdfPath)) {
                $errors[] = ['id' => $item['id'], 'reason' => (string) ($conversion['error'] ?? 'Не удалось сформировать PDF.')];
                continue;
            }
            try {
                $queued = docgenQueueLetter($item, $template, $letter, $letterTemplateId, $pdfPath, '');
                // Связываем письмо с задачей: по этому полю считаются
                // прогресс и «сколько осталось отправить».
                $meta = docgenReadQueueItem((string) $queued['id']);
                $meta['mailingTaskId'] = $id;
                $meta['templateName'] = (string) ($task['templateName'] ?? '');
                docgenSaveQueueItem($meta);
                $generated++;
            } catch (Throwable $e) {
                $errors[] = ['id' => $item['id'], 'reason' => $e->getMessage()];
            }
        }
    }
    cleanupTree($jobDir);

    $task['generatedCount'] = (int) ($task['generatedCount'] ?? 0) + $generated;
    $task['errorCount'] = (int) ($task['errorCount'] ?? 0) + count($errors);
    $task['lastError'] = $errors ? mb_substr((string) ($errors[0]['reason'] ?? ''), 0, 300, 'UTF-8') : '';
    $task['lastTickAt'] = date(DATE_ATOM);
    $task['generationStartedAt'] = $task['generationStartedAt'] ?? date(DATE_ATOM);
    if ($generated === 0 && $errors && count($errors) >= count($slice)) {
        // Вся порция провалилась: не крутим бесконечные тики на одном месте.
        $task['status'] = 'failed';
        $task['lastError'] = 'Ни одно письмо из порции не сгенерировалось: ' . $task['lastError'];
    } elseif ((int) $task['generatedCount'] + (int) $task['errorCount'] >= count($ids)) {
        $task['status'] = $task['generatedCount'] > 0 ? 'ready' : 'failed';
        $task['generationFinishedAt'] = date(DATE_ATOM);
    }
    docgenMailingSaveTask($task);
    return [
        'ok' => true,
        'generated' => $generated,
        'errors' => count($errors),
        'seconds' => round(microtime(true) - $startedAt, 1),
        'remaining' => max(0, count($ids) - (int) $task['generatedCount'] - (int) $task['errorCount']),
        'status' => (string) $task['status'],
    ];
}

/**
 * Один тик по всем активным задачам. Вызывается из cron_send.php до отправки:
 * письма должны появляться в очереди быстрее, чем уходят.
 *
 * Ограничение по времени общее: cron тикает раз в минуту, и следующий тик не
 * должен начаться раньше, чем закончится этот.
 */
function docgenMailingTick(?int $totalBudgetSeconds = null): array
{
    $budget = $totalBudgetSeconds ?? 600;
    $started = microtime(true);
    $report = ['tasks' => 0, 'generated' => 0, 'skipped' => 0];
    foreach (docgenMailingTasks() as $task) {
        if (microtime(true) - $started > $budget) {
            break;
        }
        $status = (string) ($task['status'] ?? '');
        if ($status !== 'generating') {
            continue;
        }
        // Впереди отправки достаточно готовых: нет смысла жечь CPU и диск.
        $metrics = docgenMailingMetrics((string) $task['id']);
        if ($metrics['unresolved'] <= 0) {
            continue;
        }
        if ($metrics['ready'] >= DOCGEN_MAILING_LOOKAHEAD) {
            $report['skipped']++;
            continue;
        }
        $tick = docgenMailingGenerateTick((string) $task['id'], DOCGEN_MAILING_CHUNK, DOCGEN_MAILING_BUDGET_SECONDS);
        if (!empty($tick['generated'])) {
            $report['generated'] += (int) $tick['generated'];
            $report['tasks']++;
        }
    }
    $report['seconds'] = round(microtime(true) - $started, 1);
    return $report;
}

/**
 * GET ?action=mailing_tasks — список задач с прогрессом.
 * Читаются только task.json, поэтому ответ лёгкий даже на 20 000 записей.
 */
function docgenMailingTasksAction(): never
{
    $tasks = [];
    foreach (docgenMailingTasks() as $task) {
        $metrics = docgenMailingMetrics((string) $task['id']);
        $task['metrics'] = $metrics;
        $tasks[] = $task;
    }
    sendJson(['ok' => true, 'tasks' => $tasks]);
}

/** GET ?action=mailing_task&id=… — одна задача с деталями. */
function docgenMailingTaskAction(): never
{
    $id = trim((string) ($_GET['id'] ?? ''));
    $task = docgenMailingTask($id);
    if (!$task) {
        sendJson(['ok' => false, 'error' => 'Задача рассылки не найдена.'], 404);
    }
    $task['metrics'] = docgenMailingMetrics($id);
    sendJson(['ok' => true, 'task' => $task]);
}

/**
 * POST ?action=mailing_create — план рассылки без генерации.
 * Принимает те же поля, что и разовая генерация, поэтому интерфейс может
 * использовать уже выбранные записи, шаблон и оформление письма.
 */
function docgenMailingCreateAction(): never
{
    try {
        $request = [
            'name' => (string) ($_POST['name'] ?? ''),
            'templateId' => (string) ($_POST['template_id'] ?? ''),
            'ids' => json_decode((string) ($_POST['selected_ids'] ?? '[]'), true),
            'overrides' => json_decode((string) ($_POST['overrides'] ?? '{}'), true),
            'excludedFields' => json_decode((string) ($_POST['excluded_fields'] ?? '[]'), true),
            'letter' => json_decode((string) ($_POST['letter'] ?? '{}'), true),
        ];
        if (!is_array($request['overrides'])) {
            $request['overrides'] = [];
        }
        if (!is_array($request['excludedFields'])) {
            $request['excludedFields'] = [];
        }
        if (!is_array($request['letter'])) {
            $request['letter'] = [];
        }
        // Оформление письма берём из тех же полей, что редактирует оператор.
        $draft = json_decode((string) ($_POST['letterDraft'] ?? '{}'), true);
        if (is_array($draft)) {
            $request['letter'] = array_merge($request['letter'], $draft);
        }
        $task = docgenMailingCreate($request);
        // Первую порцию генерируем сразу: оператор должен увидеть PDF на проверку
        // в ближайшую минуту, а не гадать, работает ли задача.
        $first = docgenMailingGenerateTick((string) $task['id'], 12, 60);
        sendJson(['ok' => true, 'task' => docgenMailingTask((string) $task['id']) ?: $task, 'first' => $first]);
    } catch (InvalidArgumentException $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * POST ?action=mailing_action — пауза, возобновление, отмена, удаление,
 * «сгенерировать сейчас». Действия над отдельными письмами остаются
 * за существующей пачкой: задача их не отменяет.
 */
function docgenMailingAction(): never
{
    $id = trim((string) ($_POST['id'] ?? ''));
    $op = (string) ($_POST['op'] ?? '');
    $task = docgenMailingTask($id);
    if (!$task) {
        sendJson(['ok' => false, 'error' => 'Задача рассылки не найдена.'], 404);
    }
    try {
        switch ($op) {
            case 'pause':
                if ((string) $task['status'] === 'generating') {
                    $task['status'] = 'paused';
                    docgenMailingSaveTask($task);
                }
                break;
            case 'resume':
                if (in_array((string) $task['status'], ['paused', 'failed'], true)) {
                    $task['status'] = 'generating';
                    docgenMailingSaveTask($task);
                }
                break;
            case 'tick':
                // Ручной запуск: удобно, когда cron ещё не сработал.
                $tick = docgenMailingGenerateTick($id, 24, 90);
                sendJson(['ok' => true, 'task' => docgenMailingTask($id), 'tick' => $tick]);
            case 'cancel':
                $task['status'] = 'cancelled';
                docgenMailingSaveTask($task);
                break;
            case 'delete':
                docgenMailingDelete($id);
                sendJson(['ok' => true]);
            default:
                sendJson(['ok' => false, 'error' => 'Неизвестное действие.'], 422);
        }
        sendJson(['ok' => true, 'task' => docgenMailingTask($id)]);
    } catch (Throwable $e) {
        sendJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
