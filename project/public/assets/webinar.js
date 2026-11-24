'use strict';

/*
 * Генератор программ вебинаров ЦМК — интерфейс страницы webinar.php.
 * Логика перенесена из панели Python-версии: каталог с поиском, периодами и
 * вкладками, выбор карточек, экспорт DOCX/ZIP, редактура и мониторинг Telegram.
 */

const boot = window.DOCGEN_WEBINARS || {};
const el = {};
[
  'scan-form', 'start-url', 'scan-status', 'scan-button', 'event-count', 'schedule-range',
  'catalog-status', 'catalog-status-text', 'event-search', 'date-toggle', 'date-controls',
  'date-from', 'date-to', 'date-indicator', 'date-reset', 'date-error', 'show-all',
  'show-todo', 'todo-count', 'show-done', 'done-count', 'table-scroll', 'select-visible',
  'events-body', 'table-empty', 'shown-count', 'clear-selection', 'selected-count',
  'selected-label', 'selection-hint', 'make-docs', 'export-result', 'export-note', 'result-text',
  'download-word', 'download-zip', 'batch-words', 'word-links', 'remake-docs', 'preview-empty',
  'preview-content', 'preview-title', 'preview-date', 'speaker-count', 'topic-count',
  'open-program', 'open-editorial', 'source-link', 'saved-word', 'program-dialog',
  'program-dialog-heading', 'program-dialog-date', 'program-dialog-body', 'program-source',
  'help-dialog', 'open-help', 'editorial-dialog', 'editorial-heading', 'editorial-date',
  'editorial-feedback', 'editorial-save', 'editorial-clear', 'ed-file_title', 'ed-benefit',
  'ed-audience', 'ed-with_whom', 'ed-intro_suffix', 'ed-topics', 'monitor-dialog', 'open-monitor',
  'monitor-state', 'monitor-last', 'monitor-next', 'monitor-note', 'monitor-telegram',
  'monitor-toggle', 'monitor-check-now', 'monitor-feedback', 'telegram-form', 'telegram-token',
  'telegram-connect', 'telegram-test', 'telegram-disconnect', 'telegram-env-note', 'pair-box',
  'pair-bot', 'pair-code', 'pair-remaining', 'layout-dialog', 'open-layout', 'layout-name',
  'layout-size', 'layout-source', 'layout-drop', 'layout-file', 'layout-feedback', 'layout-reset',
  'toast', 'toast-icon', 'toast-text', 'toast-close'
].forEach(id => { el[id] = document.getElementById(id); });

const state = {
  catalog: boot.catalog || null,
  visible: [],
  selected: new Set(),
  active: null,
  scope: 'all',
  initializing: !boot.catalog,
  scanning: false,
  exporting: false,
  result: null,
  loadError: '',
  detail: null,
  editorial: {},
};

const ns = 'http://www.w3.org/2000/svg';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let toastTimer;
const checkedTime = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function make(tag, className = '', text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text !== undefined) element.textContent = text;
  return element;
}

function icon(name) {
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'icon');
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS(ns, 'use');
  use.setAttribute('href', '#i-' + name);
  svg.append(use);
  return svg;
}

function plural(n, one, few, many) {
  const mod = n % 100;
  if (mod >= 11 && mod <= 14) return many;
  return n % 10 === 1 ? one : [2, 3, 4].includes(n % 10) ? few : many;
}

function plainSearch(text) {
  return String(text).toLocaleLowerCase('ru-RU').replaceAll('ё', 'е').trim();
}

function readableDate(iso) {
  const [y, m, d] = String(iso).slice(0, 10).split('-');
  return `${d}.${m}.${y}`;
}

/* Один обработчик CSRF на все изменяющие запросы: сервер проверяет тот же токен. */
async function request(action, body, options = {}) {
  const init = { method: 'POST', headers: { 'X-CSRF-Token': boot.csrf || '' } };
  if (body instanceof FormData) {
    init.body = body;
  } else if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const response = await fetch('webinar.php?action=' + encodeURIComponent(action), init);
  let data;
  try { data = await response.json(); } catch { throw new Error('Сервер не вернул данные. Повторите попытку.'); }
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось выполнить запрос.');
  return data;
}

async function read(action, params = {}) {
  const query = new URLSearchParams({ action, ...params });
  const response = await fetch('webinar.php?' + query.toString(), { cache: 'no-store' });
  let data;
  try { data = await response.json(); } catch { throw new Error('Сервер не вернул данные. Повторите попытку.'); }
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось выполнить запрос.');
  return data;
}

function form(fields) {
  const body = new FormData();
  for (const [name, value] of Object.entries(fields)) body.append(name, value);
  return body;
}

function toast(text, error = false) {
  clearTimeout(toastTimer);
  el.toast.classList.toggle('is-error', error);
  el['toast-icon'].setAttribute('href', error ? '#i-alert' : '#i-check');
  el['toast-text'].textContent = text;
  el.toast.hidden = false;
  toastTimer = setTimeout(() => { el.toast.hidden = true; }, error ? 9000 : 4500);
}

function feedback(id, message = '', error = false) {
  const box = el[id];
  if (!box) return;
  box.textContent = message;
  box.hidden = !message;
  box.classList.toggle('is-error', error);
}

function activeEvent() {
  return state.catalog?.events.find(e => e.id === state.active) || null;
}

function useCatalog(catalog, autoSelect = false, keepResult = false) {
  const oldCount = state.selected.size;
  const previous = new Map((state.catalog?.events ?? []).map(e => [e.id, e.date]));
  state.catalog = catalog;
  state.loadError = '';
  const dates = new Map(catalog.events.map(e => [e.id, e.date]));
  state.selected = autoSelect
    ? new Set(catalog.events.slice(0, 1).map(e => e.id))
    : new Set([...state.selected].filter(id => dates.has(id) && dates.get(id) === previous.get(id)));
  if (!dates.has(state.active) || autoSelect) state.active = catalog.events[0]?.id || null;
  if (!keepResult || oldCount !== state.selected.size) state.result = null;
  refreshSchedule();
}

const scopeButtons = { all: 'show-all', todo: 'show-todo', done: 'show-done' };

function refreshSchedule() {
  const entries = state.catalog?.events ?? [];
  const done = entries.filter(e => e.generated_at).length;
  for (const [scope, id] of Object.entries(scopeButtons)) el[id].setAttribute('aria-pressed', String(state.scope === scope));
  el['done-count'].textContent = done;
  el['todo-count'].textContent = entries.length - done;
  el['event-count'].textContent = state.initializing ? '—' : entries.length;
  el['event-count'].setAttribute('aria-label', `${entries.length} ${plural(entries.length, 'вебинар', 'вебинара', 'вебинаров')}`);

  const ordered = entries.slice().sort((a, b) => a.date.localeCompare(b.date));
  const start = ordered[0], end = ordered.at(-1);
  let range = 'Московское время (МСК)';
  if (start && end) {
    if (start.date === end.date) range = `${start.date_ru} · Время МСК`;
    else {
      const early = start.date.slice(0, 4) === end.date.slice(0, 4) ? start.day_month : start.date_ru;
      range = `${early} — ${end.date_ru} · Время МСК`;
    }
  } else if (state.initializing) range = 'Загружаем список…';
  el['schedule-range'].textContent = range;

  const checked = state.catalog?.checked_at;
  el['catalog-status'].hidden = !checked;
  if (checked) {
    const live = state.catalog.origin === 'live';
    el['catalog-status'].classList.toggle('live', live);
    el['catalog-status-text'].textContent = live ? 'Обновлено с сайта' : `Снимок от ${readableDate(checked)}`;
    el['catalog-status'].title = live ? `${checkedTime.format(new Date(checked))} МСК`
      : 'Сохранённое расписание. Чтобы проверить актуальность, обновите его с сайта.';
  }

  const from = el['date-from'].value, to = el['date-to'].value;
  const badRange = Boolean(from && to && from > to);
  el['date-error'].hidden = !badRange;
  el['date-indicator'].hidden = !(from || to);
  const terms = plainSearch(el['event-search'].value).split(/\s+/u).filter(Boolean);
  state.visible = badRange ? [] : entries.filter(e => {
    if (state.scope === 'todo' && e.generated_at) return false;
    if (state.scope === 'done' && !e.generated_at) return false;
    if ((from && from > e.date) || (to && e.date > to)) return false;
    const searchable = plainSearch(`${e.title} ${e.full_title} ${e.date_ru} ${e.date} ${readableDate(e.date)} ${e.hours}`);
    return terms.every(term => searchable.includes(term));
  });
  if (state.visible.length && !state.visible.some(e => e.id === state.active)) state.active = state.visible[0].id;
  renderRows();
  syncSelection();
  renderPreview();
}

function renderRows() {
  const list = document.createDocumentFragment();
  for (const e of state.visible) {
    const tr = make('tr');
    tr.dataset.event = e.id;
    tr.classList.toggle('new-event', Boolean(e.is_new && !e.generated_at));
    const select = make('td');
    const checkbox = make('input');
    checkbox.type = 'checkbox';
    checkbox.setAttribute('aria-label', `Выбрать: ${e.title}, ${e.day_month}`);
    checkbox.checked = state.selected.has(e.id);
    checkbox.addEventListener('change', () => {
      if (checkbox.checked) {
        state.selected.add(e.id);
        state.active = e.id;
      } else state.selected.delete(e.id);
      selectionChanged();
    });
    const checkArea = make('label', 'check-area');
    checkArea.append(checkbox);
    select.append(checkArea);
    const date = make('td');
    const time = make('span', 'row-time', e.hours);
    time.append(make('span', 'row-zone', ' МСК'));
    date.append(make('span', 'row-date', e.day_month), time);
    const title = make('td');
    title.append(make('div', 'row-title', e.title));
    const meta = make('div', 'row-meta');
    if (e.generated_at) {
      const badge = make('span', 'row-badge badge-done');
      badge.append(icon('check'), document.createTextNode('Сформировано'));
      badge.title = `${checkedTime.format(new Date(e.generated_at))} МСК`;
      meta.append(badge);
    } else if (e.is_new) meta.append(make('span', 'row-badge badge-new', 'Новое'));
    if (meta.children.length) title.append(meta);
    const action = make('td');
    const open = make('button', 'button row-open');
    open.type = 'button';
    open.title = 'Открыть карточку';
    open.setAttribute('aria-label', 'Карточка: ' + e.title);
    open.append(icon('chevron'));
    open.addEventListener('click', () => {
      selectPreview(e.id);
      if (matchMedia('(max-width: 730px)').matches) openProgram();
    });
    action.append(open);
    tr.append(select, date, title, action);
    tr.addEventListener('click', event => {
      if (!event.target.closest('button, label, a')) selectPreview(e.id);
    });
    list.append(tr);
  }
  el['events-body'].replaceChildren(list);
  const empty = el['table-empty'];
  empty.hidden = Boolean(state.visible.length);
  if (!empty.hidden) {
    if (state.initializing) {
      empty.replaceChildren(make('span', 'spinner'), make('p', '', 'Загружаем расписание…'));
    } else if (state.loadError) {
      empty.replaceChildren(icon('alert'), make('p', '', 'Не удалось загрузить список. Обновите его с сайта.'));
    } else if (!state.catalog?.events.length) {
      empty.replaceChildren(icon('calendar'), make('p', '', 'Здесь появятся вебинары после обновления с сайта.'));
    } else {
      const reset = make('button', 'button button-quiet', 'Очистить фильтры');
      reset.type = 'button';
      reset.addEventListener('click', resetFilters);
      const statusOnly = !el['event-search'].value.trim() && !el['date-from'].value && !el['date-to'].value;
      const message = statusOnly && state.scope === 'done' ? 'Здесь будут вебинары с сохранённым Word.'
        : statusOnly && state.scope === 'todo' ? 'Все вебинары в этом списке уже сформированы.'
        : 'По этим фильтрам вебинары не найдены.';
      empty.replaceChildren(icon(state.scope === 'done' ? 'archive' : 'search'), make('p', '', message), reset);
    }
  }
}

function resetFilters() {
  el['event-search'].value = '';
  el['date-from'].value = '';
  el['date-to'].value = '';
  state.scope = 'all';
  refreshSchedule();
  el['event-search'].focus();
}

function selectPreview(id) {
  state.active = id;
  syncRows();
  renderPreview();
}

function syncRows() {
  for (const tr of el['events-body'].rows) {
    const id = tr.dataset.event;
    tr.classList.toggle('active', id === state.active);
    const box = tr.querySelector('input');
    box.checked = state.selected.has(id);
    box.disabled = state.exporting;
  }
}

function selectionChanged() {
  state.result = null;
  syncSelection();
  renderPreview();
}

function downloadLink(link, { url, filename }) {
  link.href = url;
  link.download = filename;
  link.title = filename;
}

function syncSelection() {
  const n = state.selected.size;
  const visibleSelected = state.visible.filter(e => state.selected.has(e.id)).length;
  const all = el['select-visible'];
  all.checked = visibleSelected > 0 && visibleSelected === state.visible.length;
  all.indeterminate = visibleSelected > 0 && !all.checked;
  all.disabled = state.visible.length === 0 || state.exporting;
  el['clear-selection'].hidden = n === 0;
  el['clear-selection'].disabled = state.exporting;
  el['shown-count'].textContent = `Показано ${state.visible.length} из ${state.catalog?.events.length ?? 0}`;
  el['selected-count'].textContent = n;
  const noun = plural(n, 'вебинар', 'вебинара', 'вебинаров');
  el['selected-label'].textContent = `${n % 10 === 1 && n % 100 !== 11 ? 'Выбран' : 'Выбрано'} ${noun}`;
  const format = !n ? 'Отметьте карточки в списке' : n === 1 ? 'Один Word и ZIP' : 'Word по одному или общий ZIP';
  const hidden = n - visibleSelected;
  el['selection-hint'].textContent = hidden ? `${format} · скрыто фильтрами: ${hidden}` : format;
  el['make-docs'].disabled = !n || state.exporting || state.scanning;
  el['remake-docs'].disabled = state.exporting || state.scanning;
  el['make-docs'].hidden = Boolean(state.result);
  el['make-docs'].setAttribute('aria-busy', String(state.exporting));
  el['make-docs'].querySelector('span').textContent = state.exporting ? 'Формируем…' : 'Сформировать Word';
  el['make-docs'].querySelector('use').setAttribute('href', state.exporting ? '#i-refresh' : '#i-file');
  el['make-docs'].classList.toggle('is-loading', state.exporting);
  el['export-result'].hidden = !state.result;
  const result = state.result;
  el['export-note'].textContent = result ? 'Последний Word сохранён в истории.'
    : state.exporting ? 'Заполняем бланк ЦМК' : 'Ссылки появятся после формирования';
  if (result) {
    const single = result.count === 1;
    el['result-text'].textContent = single ? 'Документ готов' : `Готово: ${result.count} ${plural(result.count, 'документ', 'документа', 'документов')}`;
    el['download-zip'].classList.toggle('button-primary', !single);
    el['download-zip'].classList.toggle('button-outline', single);
    downloadLink(el['download-zip'], result.zip);
    el['download-word'].hidden = !single;
    if (single) downloadLink(el['download-word'], result.documents[0]);
    el['batch-words'].hidden = single;
    if (!single && el['word-links'].dataset.result !== result.zip.url) {
      const links = result.documents.map(doc => {
        const li = make('li');
        const a = make('a', '', doc.filename.replace(/\.docx$/u, ''));
        downloadLink(a, doc);
        li.append(a);
        return li;
      });
      el['word-links'].replaceChildren(...links);
      el['word-links'].dataset.result = result.zip.url;
      el['batch-words'].open = false;
    }
  } else {
    el['download-word'].removeAttribute('href');
    el['download-zip'].removeAttribute('href');
  }
  syncRows();
  el['scan-button'].disabled = state.scanning || state.exporting;
}

function renderPreview() {
  const e = activeEvent();
  el['preview-content'].hidden = !e;
  el['preview-empty'].hidden = Boolean(e);
  if (!e) return;
  el['preview-date'].textContent = `${e.day_month} · ${e.hours} МСК`;
  el['preview-title'].textContent = e.title;
  el['speaker-count'].textContent = `${e.speaker_count} ${plural(e.speaker_count, 'ведущий', 'ведущих', 'ведущих')}`;
  el['topic-count'].textContent = `${e.topic_count} ${plural(e.topic_count, 'тема', 'темы', 'тем')}`;
  el['source-link'].href = e.url;
  el['saved-word'].hidden = !e.generated_at;
  if (e.generated_at) {
    downloadLink(el['saved-word'], { url: 'webinar.php?action=saved&event=' + encodeURIComponent(e.id), filename: e.saved_name });
  } else el['saved-word'].removeAttribute('href');
}

// ---------------------------------------------------------------------------
// Обновление расписания
// ---------------------------------------------------------------------------

function scanMode() {
  return el['scan-form'].querySelector('[name="scan-mode"]:checked').value;
}

function scanFeedback(message, error = false) {
  el['scan-status'].textContent = message;
  el['scan-status'].hidden = !message;
  el['scan-status'].classList.toggle('is-error', error);
}

function scanBusy(active) {
  state.scanning = active;
  el['scan-button'].querySelector('span').textContent = active ? 'Обновляем…' : 'Обновить с сайта';
  el['scan-button'].classList.toggle('is-loading', active);
  el['scan-button'].setAttribute('aria-busy', String(active));
  el['start-url'].readOnly = active;
  for (const n of el['scan-form'].querySelectorAll('[type=radio]')) n.disabled = active;
  syncSelection();
}

async function scan(event) {
  event.preventDefault();
  if (state.scanning || state.exporting) return;
  const mode = scanMode(), start = el['start-url'].value.trim();
  if (mode === 'single' && !start) {
    scanFeedback('Для одного вебинара укажите ссылку или код карточки.', true);
    el['start-url'].setAttribute('aria-invalid', 'true');
    el['start-url'].focus();
    return;
  }
  el['start-url'].removeAttribute('aria-invalid');
  scanBusy(true);
  scanFeedback(mode === 'chain' ? 'Проверяем переходы «Следующий вебинар»…' : 'Открываем карточку вебинара…');
  try {
    const job = await request('scan', form({ mode, start }));
    for (let i = 0; i < 60; i++) {
      await delay(i === 0 ? 600 : 900);
      const progress = await read('progress', { token: job.progress });
      const status = progress.progress || {};
      if (status.state === 'error') throw new Error(status.message || 'Не удалось обновить расписание.');
      if (status.state === 'ready') {
        if (job.catalog) {
          useCatalog(job.catalog, false);
        } else if (Array.isArray(job.single) && job.single.length) {
          toast(`Прочитано карточек: ${job.single.length}. Общий список не изменён.`);
          openSingle(job.single[0]);
        }
        scanFeedback('');
        toast(status.message || 'Расписание обновлено.');
        el['table-scroll'].scrollTop = 0;
        return;
      }
      if (status.count) scanFeedback(`Проверено карточек: ${status.count}. Читаем расписание…`);
    }
  } catch (error) {
    scanFeedback(error.message || 'Не удалось обновить расписание.', true);
    if (state.catalog?.events.length) toast('Обновление не завершилось. Предыдущий список сохранён.', true);
  } finally {
    scanBusy(false);
  }
}

/* Одиночный срез не заменяет полный список: карточка только показывается в диалоге. */
function openSingle(row) {
  el['program-dialog-heading'].textContent = row.title;
  el['program-dialog-date'].textContent = `${row.date_ru} · ${row.hours} МСК`;
  el['program-source'].href = row.url;
  el['program-dialog-body'].replaceChildren(make('p', '', 'Карточка прочитана с сайта. Чтобы открыть её в списке, выполните полное обновление «По цепочке».'));
  el['program-dialog'].showModal();
}

async function makeDocs() {
  if (!state.selected.size || state.exporting || state.scanning) return;
  state.exporting = true;
  state.result = null;
  syncSelection();
  try {
    state.result = await request('export', form({ event_ids: JSON.stringify([...state.selected]) }));
    const names = new Map(state.result.documents.map(doc => [doc.event_id, doc.filename]));
    for (const e of state.catalog.events) if (names.has(e.id)) {
      e.generated_at = state.result.generated_at;
      e.saved_name = names.get(e.id);
    }
    toast(state.result.count === 1 ? 'Word и ZIP готовы к скачиванию.' : 'ZIP и отдельные Word готовы к скачиванию.');
  } catch (error) {
    toast(error.message || 'Не удалось сформировать файлы.', true);
  } finally {
    state.exporting = false;
    refreshSchedule();
    if (state.result) el[state.result.count === 1 ? 'download-word' : 'download-zip'].focus({ preventScroll: true });
  }
}

// ---------------------------------------------------------------------------
// Карточка вебинара
// ---------------------------------------------------------------------------

function dialogBody(event) {
  const out = document.createDocumentFragment();
  out.append(make('p', '', event.abstract), make('p', '', event.audience));
  if (event.speakers.length) {
    out.append(make('h3', '', 'Ведущие'));
    const ul = make('ul');
    for (const s of event.speakers) {
      const name = Array.isArray(s) ? s[0] : s.name;
      const role = Array.isArray(s) ? s[1] : s.role;
      const li = make('li');
      li.append(make('strong', '', name), document.createTextNode(' — ' + role));
      ul.append(li);
    }
    out.append(ul);
  }
  out.append(make('h3', '', 'Темы'));
  const topics = make('ol');
  for (const t of event.topics) {
    const li = make('li');
    const title = String(t.title).trim();
    li.append(make('strong', '', /[.!?…]$/u.test(title) ? title : title + '.'), document.createTextNode(' ' + (t.details || '')));
    topics.append(li);
  }
  if (event.closing) topics.append(make('li', '', event.closing[0].toLocaleUpperCase('ru-RU') + event.closing.slice(1) + '.'));
  out.append(topics);
  if (event.fee || event.access_and_discounts) {
    out.append(make('h3', '', 'Условия участия'), make('p', '', [event.fee, event.access_and_discounts].filter(Boolean).join(' ')));
  }
  for (const warning of event.warnings || []) out.append(make('p', 'help-note', warning));
  return out;
}

let detailVersion = 0;
async function openProgram() {
  const event = activeEvent();
  if (!event) return;
  const version = ++detailVersion;
  const dialog = el['program-dialog'];
  el['program-dialog-heading'].textContent = event.title;
  el['program-dialog-date'].textContent = `${event.date_ru} · ${event.hours} МСК`;
  el['program-source'].href = event.url;
  const wait = make('div', 'dialog-loading');
  wait.append(make('span', 'spinner'), make('span', 'subtle', 'Загружаем программу…'));
  el['program-dialog-body'].replaceChildren(wait);
  if (!dialog.open) dialog.showModal();
  dialog.scrollTop = 0;
  try {
    const data = await read('detail', { event: event.id });
    if (version !== detailVersion) return;
    state.detail = data.event;
    el['program-dialog-heading'].textContent = data.event.title;
    el['program-dialog-body'].replaceChildren(dialogBody(data.event));
  } catch (error) {
    if (version === detailVersion) el['program-dialog-body'].replaceChildren(make('p', 'help-note', error.message));
  }
}

// ---------------------------------------------------------------------------
// Редактура: короткое название для файла и «участие позволит»
// ---------------------------------------------------------------------------

function renderTopics(event, editorial) {
  const names = editorial.rename_topics || {};
  const rows = (event.topics || []).map((topic, index) => {
    const row = make('label', 'topic-row');
    row.append(make('span', '', String(index + 1)));
    const input = make('input');
    input.type = 'text';
    input.maxLength = 400;
    input.dataset.number = String(index + 1);
    input.value = names[String(index + 1)] || '';
    input.placeholder = topic.title;
    row.append(input);
    return row;
  });
  el['ed-topics'].replaceChildren(...rows);
}

async function openEditorial() {
  const event = activeEvent();
  if (!event) return;
  el['editorial-heading'].textContent = event.title;
  el['editorial-date'].textContent = `${event.date_ru} · ${event.hours} МСК`;
  feedback('editorial-feedback');
  el['editorial-dialog'].showModal();
  try {
    const data = await read('editorial', { event: event.id });
    const edit = data.editorial || {};
    state.editorial = { key: data.key, event: data.event };
    el['ed-file_title'].value = edit.file_title || '';
    el['ed-benefit'].value = edit.benefit || '';
    el['ed-audience'].value = edit.audience || '';
    el['ed-with_whom'].value = edit.with_whom || '';
    el['ed-intro_suffix'].value = edit.intro_suffix || '';
    renderTopics(data.event, edit);
  } catch (error) {
    feedback('editorial-feedback', error.message || 'Не удалось загрузить редактуру.', true);
  }
}

async function saveEditorial() {
  if (!state.editorial?.key) return;
  const topics = {};
  for (const input of el['ed-topics'].querySelectorAll('input[data-number]')) {
    if (input.value.trim()) topics[input.dataset.number] = input.value.trim();
  }
  const entry = {
    file_title: el['ed-file_title'].value.trim(),
    benefit: el['ed-benefit'].value.trim(),
    audience: el['ed-audience'].value.trim(),
    with_whom: el['ed-with_whom'].value.trim(),
    intro_suffix: el['ed-intro_suffix'].value.trim(),
    rename_topics: topics,
  };
  el['editorial-save'].disabled = true;
  try {
    const data = await request('editorial_save', form({ key: state.editorial.key, entry: JSON.stringify(entry) }));
    feedback('editorial-feedback', data.notice);
    toast('Редактура сохранена.');
  } catch (error) {
    feedback('editorial-feedback', error.message || 'Не удалось сохранить редактуру.', true);
  } finally {
    el['editorial-save'].disabled = false;
  }
}

function clearEditorial() {
  for (const id of ['ed-file_title', 'ed-benefit', 'ed-audience', 'ed-with_whom']) el[id].value = '';
  el['ed-intro_suffix'].value = '';
  for (const input of el['ed-topics'].querySelectorAll('input[data-number]')) input.value = '';
  feedback('editorial-feedback', 'Поля очищены. Нажмите «Сохранить редактуру», чтобы вернуть значения из карточки.');
}

// ---------------------------------------------------------------------------
// Мониторинг Telegram
// ---------------------------------------------------------------------------

const monitorUi = { data: boot.monitor || null, pending: false, task: null, pollId: null, loading: false };
const taskActive = task => ['starting', 'waiting', 'finishing', 'cancelling'].includes(task?.state);

function renderMonitor(data) {
  const previous = monitorUi.data;
  monitorUi.data = data;
  el['monitor-times'].replaceChildren(...(data.times || []).map(t => make('span', '', t)));
  el['monitor-state'].textContent = data.enabled ? 'Расписание включено' : 'Расписание приостановлено';
  el['monitor-telegram'].textContent = (data.configured ? 'Чат подключён' : 'Чат не подключён')
    + (data.pending ? ` · в очереди: ${data.pending}` : '');
  el['monitor-telegram'].classList.toggle('is-connected', data.configured);
  el['monitor-last'].textContent = data.last_at ? `Последняя проверка: ${checkedTime.format(new Date(data.last_at))} МСК` : 'Проверок ещё не было';
  el['monitor-next'].textContent = data.next_check ? `Следующая: ${checkedTime.format(new Date(data.next_check))} МСК` : '';
  el['monitor-note'].textContent = data.last_note || '';
  el['monitor-note'].classList.toggle('is-error', data.last_ok === false);
  renderMonitorControls();
  if (data.last_at && previous?.last_at && data.last_at !== previous.last_at) refreshStored();
}

function renderMonitorControls() {
  const opt = monitorUi.data;
  const locked = monitorUi.pending;
  const job = monitorUi.task;
  const busy = taskActive(job);
  el['monitor-toggle'].querySelector('span').textContent = opt?.enabled ? 'Приостановить расписание' : 'Включить расписание';
  el['monitor-toggle'].disabled = locked || Boolean(opt?.checking);
  el['monitor-check-now'].disabled = locked;
  el['telegram-token'].disabled = el['telegram-connect'].disabled = locked || busy || Boolean(opt?.env);
  el['telegram-connect'].textContent = opt?.configured ? 'Перепривязать бота' : 'Привязать бота';
  el['telegram-env-note'].hidden = !opt?.env;
  el['telegram-form'].hidden = Boolean(opt?.env);
  el['telegram-test'].hidden = !opt?.configured;
  el['telegram-test'].disabled = locked || busy;
  el['telegram-disconnect'].hidden = !opt?.configured || Boolean(opt?.env);
  el['telegram-disconnect'].disabled = locked || busy;
}

async function refreshMonitor() {
  if (monitorUi.loading) return;
  monitorUi.loading = true;
  try {
    renderMonitor((await read('monitor')).monitor);
  } catch {
    el['monitor-state'].textContent = 'Не удалось получить состояние. Откройте окно заново.';
  } finally {
    monitorUi.loading = false;
  }
}

function renderTelegramTask(task) {
  monitorUi.task = task;
  el['pair-box'].hidden = task.state !== 'waiting';
  if (!el['pair-box'].hidden) {
    el['pair-bot'].href = 'https://t.me/' + task.username;
    el['pair-bot'].textContent = '@' + task.username;
    el['pair-code'].textContent = task.code || '—';
    el['pair-remaining'].textContent = task.expires ? `Код действует ещё ${Math.max(0, task.expires - Math.floor(Date.now() / 1000))} с.` : '';
  } else {
    el['pair-code'].textContent = '';
    el['pair-bot'].removeAttribute('href');
  }
  const message = task.state === 'waiting' ? '' : task.message;
  if (el['monitor-feedback'].textContent !== message) feedback('monitor-feedback', message, task.state === 'error');
  renderMonitorControls();
}

async function followTelegramTask(start) {
  renderTelegramTask(start);
  if (monitorUi.pollId === start.id) return;
  if (!taskActive(start)) {
    refreshMonitor();
    return;
  }
  monitorUi.pollId = start.id;
  let task = start;
  try {
    while (taskActive(task) && monitorUi.pollId === start.id) {
      await delay(1100);
      const data = await read('progress', { token: start.id });
      task = { id: start.id, ...(data.progress || {}) };
      if (monitorUi.pollId !== start.id) break;
      renderTelegramTask(task);
    }
  } catch (error) {
    renderTelegramTask({ ...task, state: 'error', message: error.message || 'Не удалось получить состояние привязки.' });
  } finally {
    if (monitorUi.pollId === start.id) monitorUi.pollId = null;
    refreshMonitor();
  }
}

async function monitorAction(action, fields = {}, success = '') {
  if (monitorUi.pending) return;
  monitorUi.pending = true;
  feedback('monitor-feedback');
  renderMonitorControls();
  try {
    const result = await request(action, form(fields));
    if (result.progress) {
      followTelegramTask({ id: result.progress, state: 'starting', message: 'Связываемся с Telegram…' });
    } else {
      if (result.monitor) renderMonitor(result.monitor);
      feedback('monitor-feedback', result.notice || success);
      if (action === 'monitor_check') {
        if (result.catalog) useCatalog(result.catalog, false, true);
        toast(result.result?.note || 'Проверка завершена.', !result.result?.ok);
      }
    }
  } catch (error) {
    feedback('monitor-feedback', error.message || 'Не удалось выполнить действие.', true);
  } finally {
    monitorUi.pending = false;
    renderMonitorControls();
  }
}

// ---------------------------------------------------------------------------
// Бланк ЦМК
// ---------------------------------------------------------------------------

function renderLayout(layout) {
  el['layout-name'].textContent = layout.name || '—';
  el['layout-size'].textContent = layout.size ? `${Math.round(layout.size / 1024)} КБ` : '—';
  el['layout-source'].textContent = layout.custom ? 'Загружен в настройках' : 'Утверждённый в поставке';
}

async function uploadLayout(file) {
  if (!file) return;
  feedback('layout-feedback', 'Проверяем структуру бланка…');
  const body = new FormData();
  body.append('layout', file);
  try {
    const data = await request('layout_upload', body);
    renderLayout(data.layout);
    feedback('layout-feedback', data.notice);
    toast('Бланк заменён.');
  } catch (error) {
    feedback('layout-feedback', error.message || 'Не удалось загрузить бланк.', true);
  } finally {
    el['layout-file'].value = '';
  }
}

// ---------------------------------------------------------------------------
// События интерфейса
// ---------------------------------------------------------------------------

el['scan-form'].addEventListener('submit', scan);
for (const mode of el['scan-form'].querySelectorAll('[type=radio]')) mode.addEventListener('change', () => {
  const one = scanMode() === 'single';
  el['start-url'].placeholder = one ? 'Ссылка или код карточки' : 'Начать с главной';
  el['start-url'].required = one;
  el['start-url'].removeAttribute('aria-invalid');
  scanFeedback('');
});
el['start-url'].setAttribute('aria-describedby', 'scan-status');
el['start-url'].addEventListener('input', () => {
  if (el['start-url'].hasAttribute('aria-invalid')) {
    el['start-url'].removeAttribute('aria-invalid');
    scanFeedback('');
  }
});
el['event-search'].addEventListener('input', () => { refreshSchedule(); el['table-scroll'].scrollTop = 0; });
for (const [scope, id] of Object.entries(scopeButtons)) el[id].addEventListener('click', () => {
  state.scope = scope;
  refreshSchedule();
  el['table-scroll'].scrollTop = 0;
});
el['date-toggle'].addEventListener('click', () => {
  const open = el['date-controls'].hidden;
  el['date-controls'].hidden = !open;
  el['date-toggle'].setAttribute('aria-expanded', String(open));
  if (open) el['date-from'].focus();
});
for (const key of ['date-from', 'date-to']) el[key].addEventListener('change', () => { refreshSchedule(); el['table-scroll'].scrollTop = 0; });
el['date-reset'].addEventListener('click', () => {
  el['date-from'].value = '';
  el['date-to'].value = '';
  refreshSchedule();
});
el['select-visible'].addEventListener('change', () => {
  const checked = el['select-visible'].checked;
  for (const e of state.visible) {
    if (checked) state.selected.add(e.id);
    else state.selected.delete(e.id);
  }
  selectionChanged();
});
el['clear-selection'].addEventListener('click', () => { state.selected.clear(); selectionChanged(); });
el['make-docs'].addEventListener('click', makeDocs);
el['remake-docs'].addEventListener('click', makeDocs);
el['open-program'].addEventListener('click', openProgram);
el['open-editorial'].addEventListener('click', openEditorial);
el['editorial-save'].addEventListener('click', saveEditorial);
el['editorial-clear'].addEventListener('click', clearEditorial);
el['program-dialog'].addEventListener('close', () => { detailVersion++; });
el['open-help'].addEventListener('click', () => el['help-dialog'].showModal());
el['toast-close'].addEventListener('click', () => { el.toast.hidden = true; });

el['open-monitor'].addEventListener('click', () => {
  el['monitor-dialog'].showModal();
  refreshMonitor();
});
el['monitor-dialog'].addEventListener('close', () => {
  el['telegram-token'].value = '';
  refreshStored();
  refreshMonitor();
});
el['monitor-toggle'].addEventListener('click', () => {
  const enabled = !monitorUi.data?.enabled;
  monitorAction('monitor_schedule', { enabled: enabled ? '1' : '0' },
    enabled ? 'Расписание сохранено и включено.' : 'Следующие автоматические проверки приостановлены.');
});
el['monitor-check-now'].addEventListener('click', () => monitorAction('monitor_check', {}, 'Проверка запущена.'));
el['telegram-form'].addEventListener('submit', event => {
  event.preventDefault();
  const token = el['telegram-token'].value.trim();
  el['telegram-token'].value = '';
  if (token) monitorAction('telegram_pair', { token });
});
el['telegram-test'].addEventListener('click', () => monitorAction('telegram_test', {}, 'Тестовое сообщение отправлено.'));
el['telegram-disconnect'].addEventListener('click', () => {
  if (window.confirm('Отвязать этот чат? История вебинаров останется.')) monitorAction('telegram_disconnect', {}, 'Чат отвязан.');
});

el['open-layout'].addEventListener('click', async () => {
  el['layout-dialog'].showModal();
  feedback('layout-feedback');
  try {
    const data = await read('layout');
    renderLayout(data.layout);
  } catch (error) {
    feedback('layout-feedback', error.message || 'Не удалось прочитать сведения о бланке.', true);
  }
});
el['layout-drop'].addEventListener('click', event => {
  if (event.target !== el['layout-file']) el['layout-file'].click();
});
el['layout-file'].addEventListener('change', () => uploadLayout(el['layout-file'].files[0]));
for (const type of ['dragenter', 'dragover']) el['layout-drop'].addEventListener(type, event => {
  event.preventDefault();
  el['layout-drop'].classList.add('dragover');
});
for (const type of ['dragleave', 'drop']) el['layout-drop'].addEventListener(type, event => {
  event.preventDefault();
  el['layout-drop'].classList.remove('dragover');
});
el['layout-drop'].addEventListener('drop', event => uploadLayout(event.dataTransfer?.files?.[0]));
el['layout-reset'].addEventListener('click', async () => {
  if (!window.confirm('Вернуть утверждённый бланк из поставки? Загруженный бланк будет удалён.')) return;
  try {
    const data = await request('layout_reset', form({}));
    renderLayout(data.layout);
    feedback('layout-feedback', data.notice);
  } catch (error) {
    feedback('layout-feedback', error.message || 'Не удалось вернуть бланк.', true);
  }
});

/* Список обновляется после проверки на сервере — без перезагрузки страницы. */
let refreshing = false;
async function refreshStored() {
  if (state.scanning || state.exporting || refreshing || document.hidden) return;
  refreshing = true;
  try {
    const data = await read('catalog');
    const update = data.catalog;
    if (!update) return;
    if (update.checked_at !== state.catalog?.checked_at
      || update.events.some((e, i) => e.generated_at !== state.catalog?.events[i]?.generated_at || e.is_new !== state.catalog?.events[i]?.is_new)) {
      useCatalog(update, false, true);
    }
  } catch { /* Сетевой сбой не должен стирать уже показанный список. */ }
  finally { refreshing = false; }
}
window.addEventListener('focus', refreshStored);
setInterval(refreshStored, 60000);

renderLayout(boot.layout || {});
refreshSchedule();
if (monitorUi.data) renderMonitor(monitorUi.data);
