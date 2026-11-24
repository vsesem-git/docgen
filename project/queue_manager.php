<?php
declare(strict_types=1);

// Separate queue-manager page. All data/config stay outside the public/ root.
function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/features.php';
docgenBoot('');
$csrf = docgenCsrfToken();
$authEnabled = docgenAuthHash() !== '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Менеджер очереди писем · docgen</title>
<style>
:root{--card:#fff;--line:#d9e3ef;--ink:#162b43;--muted:#64778d;--brand:#0e4f8a;--ok:#1b7f4b;--warn:#a15c00;--bad:#b42318}
*{box-sizing:border-box}body{margin:0;background:#f2f6fb;color:var(--ink);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
header{background:#123b63;color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
header a{color:#dcecff}a h1{font-size:18px;margin:0}a.sub{font-size:12px;opacity:.85;text-decoration:none}
main{max-width:1180px;margin:0 auto;padding:20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:11px;box-shadow:0 2px 10px rgba(20,50,90,.06);padding:16px;margin-bottom:14px}
h2{font-size:16px;margin:0 0 10px;color:#173b60}.hint{color:var(--muted);font-size:12.5px}
button,input,select{font:inherit}button{cursor:pointer}.btn{border:1px solid #bdd0e3;background:#fff;border-radius:8px;padding:8px 12px;color:#174a7e}.btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}.btn.danger{border-color:#e6b4ae;color:var(--bad)}button:disabled{opacity:.45;cursor:not-allowed}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:11px}.field label{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}.field input{width:100%;border:1px solid #cbd7e3;border-radius:8px;padding:8px}
.batch{border:1px solid var(--line);border-radius:10px;margin-bottom:10px;overflow:hidden}.batch>summary{padding:11px 13px;cursor:pointer;background:#fbfdff;font-weight:650}.batch .body{padding:12px 13px;border-top:1px solid var(--line)}
.item{border:1px solid #e3ebf4;border-radius:8px;padding:9px 11px;margin-bottom:7px;background:#fff}.item .meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.pill{border-radius:999px;padding:2px 8px;font-size:11px;background:#eef3f9;color:var(--muted)}.pill.sent{background:#e8f6ee;color:var(--ok)}.pill.failed{background:#fdecea;color:var(--bad)}.pill.scheduled{background:#fff5e5;color:var(--warn)}.pill.paused{background:#ece7f8;color:#5b3fa3}
a{color:var(--brand)}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:9px}.muted{color:var(--muted);font-size:12px}.error{color:var(--bad);font-size:12px}.feedback{margin-left:8px;font-size:12px}
[hidden]{display:none!important}
@media(max-width:700px){main{padding:12px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><div><h1>Менеджер очереди писем</h1><a class="sub" href="index.php" id="backToApp">← Вернуться к формированию и ручной проверке</a><button class="btn" id="closeManagerInPopup" type="button" hidden>Закрыть окно</button></div><div class="sub">Локальное приложение<?= $authEnabled ? ' · вход по паролю' : '' ?></div></header>
<main>
  <section class="card">
    <h2>Настройки рассылки</h2>
    <div class="grid">
      <div class="field"><label for="lettersPerMinute">Писем в минуту (1–6)</label><input id="lettersPerMinute" type="number" min="1" max="6" value="1"></div>
      <div class="field"><label for="dailyCap">Дневной лимит на ящик</label><input id="dailyCap" type="number" min="0" max="300" value="0"><span class="hint" style="display:block;margin-top:4px">0 — без локального ограничения. Для mail.ru ставьте 30–50: сверх этого сервис включает свои лимиты сам.</span></div>
      <div class="field"><label for="windowStart">Окно отправки: с</label><input id="windowStart" type="time" value="09:00"></div>
      <div class="field"><label for="windowEnd">по</label><input id="windowEnd" type="time" value="18:00"></div>
      <div class="field"><label for="jitterSeconds">Случайная задержка между письмами, сек</label><input id="jitterSeconds" type="number" min="0" max="300" value="20"><span class="hint" style="display:block;margin-top:4px">К фиксированному интервалу добавляется случайная пауза: ровные до секунды интервалы — признак робота.</span></div>
      <div class="field"><label>&nbsp;</label><label class="hint" style="display:flex;gap:7px;align-items:center"><input id="weekdaysOnly" type="checkbox" style="width:auto;height:auto"> Только по рабочим дням</label></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary" id="saveMailingBtn" type="button">Сохранить настройки</button><span class="feedback" id="mailingFeedback"></span></div>
    </div>
    <h3 style="font-size:13px;margin:16px 0 8px;color:#173b60">Лимит для каждого SMTP-ящика</h3>
    <div class="grid" id="mailingAccounts">Загружаем ящики…</div>
    <p class="hint">Лимит задаётся отдельно для каждого ящика. Разные ящики отправляют независимо: у каждого свой поток и свой лимит. Ящик без собственного значения использует значение по умолчанию. Планировщик запускается раз в минуту; автоотправка включается только для пачек, где оператор явно поставил галочку и выбрал «в расписание». Руководство по безопасной отправке через mail.ru — в <a href="index.php#sendPolicyCard">блоке «Отправка без блокировок»</a> на главной странице.</p>
  </section>
  <section class="card">
    <h2>Пачки писем</h2>
    <p class="hint">На главной странице остаются только пакеты, ожидающие ручной проверки. Здесь — уже переданные в ручную очередь или в расписание, а также завершённые. Пачки можно ставить на паузу и снимать с неё, перемещать вверх/вниз по очереди и удалять с явным подтверждением. Порядок пачек учитывается планировщиком при автоотправке.</p>
    <div id="batches">Загружаем…</div>
  </section>
</main>
<script>
'use strict';
const CSRF_TOKEN = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let batches = [], items = [];
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const labels = {queued:'в ручной очереди',scheduled:'в расписании',sending:'отправляется',sent:'отправлено',failed:'ошибка',cancelled:'отменено',review:'на проверке',manual:'ручная очередь',done:'завершено',paused:'на паузе'};
function pill(status){const css=['sent','failed','scheduled','sending','cancelled','done','paused'].includes(status)?status:'';return `<span class="pill ${css}">${esc(labels[status]||status||'—')}</span>`;}
function batchItems(id){return items.filter(item=>id==='legacy'?!item.batchId:item.batchId===id);}
function render(){
  $('batches').innerHTML = batches.length ? batches.map(batch=>{
    const rows=batchItems(batch.id);
    const list=rows.map(item=>{
      const hasHtml=String(item.bodyHtml||'').trim()!=='';
      const noText=hasHtml&&String(item.body||'').trim()==='';
      const design=hasHtml?`<a href="?action=queue_file&id=${encodeURIComponent(item.id)}&format=pdf&design=1&inline=1" target="_blank" rel="noopener">HTML-дизайн</a>`:'';
      const textWarn=noText?`<span class="pill failed">нет текстовой версии — отправка заблокирована</span>`:(hasHtml?`<span class="pill">HTML + текст</span>`:'');
      return `<div class="item"><div class="meta"><b>${esc(item.recipientName||item.recordId||'Адресат')}</b>${pill(item.status)}<span class="muted">${esc(item.to||'получатель не указан')}</span><span class="muted">${esc(item.subject||'')}</span>${textWarn}<a href="?action=queue_file&id=${encodeURIComponent(item.id)}&format=pdf&inline=1" target="_blank" rel="noopener">PDF</a>${design}<a href="index.php?review=${encodeURIComponent(item.id)}">Открыть для проверки</a></div>${item.lastError?`<div class="error">${esc(item.lastError)}</div>`:''}</div>`;
    }).join('');
    const canCancel=['manual','scheduled','sending','paused'].includes(batch.status);
    const canPause=['manual','scheduled'].includes(batch.status);
    const index=batches.indexOf(batch);
    const canUp=index>0, canDown=index>=0&&index<batches.length-1;
    return `<details class="batch" ${batch.status==='scheduled'?'open':''}><summary>Очередь — ${esc(batch.name||'Без названия')} — ${Number(batch.itemCount||rows.length)} писем · Тема: ${esc(batch.subject||'—')} ${pill(batch.status)}</summary><div class="body"><div class="muted">Позиция в очереди: ${Number(batch.priority||index+1)} · создано: ${esc(batch.createdAt||'')} · отправлено: ${Number(batch.sentCount||0)} · ошибок: ${Number(batch.failedCount||0)} · режим: ${esc(batch.mode==='auto'?'авто по расписанию':'ручной')}${batch.pausedAt?` · на паузе с ${esc(batch.pausedAt)}`:''}</div><div class="actions"><button class="btn" data-action="up" data-id="${esc(batch.id)}" ${canUp?'':'disabled'} title="Переместить выше">↑ Выше</button><button class="btn" data-action="down" data-id="${esc(batch.id)}" ${canDown?'':'disabled'} title="Переместить ниже">↓ Ниже</button><button class="btn" data-action="${batch.status==='paused'?'resume':'pause'}" data-id="${esc(batch.id)}" ${(batch.status==='paused'||canPause)?'':'disabled'}>${batch.status==='paused'?'Продолжить':'Пауза'}</button><button class="btn danger" data-action="cancel" data-id="${esc(batch.id)}" ${canCancel?'':'disabled'}>Остановить пачку</button><button class="btn" data-action="retry" data-id="${esc(batch.id)}">Повторить ошибочные</button><button class="btn danger" data-action="delete" data-id="${esc(batch.id)}">Удалить пачку</button><span class="feedback" data-feedback="${esc(batch.id)}"></span></div><div style="margin-top:10px">${list||'<span class="muted">Писем нет.</span>'}</div></div></details>`;
  }).join('') : '<p class="muted">Пачек в менеджере пока нет. Сформируйте документы и проверьте новую пачку на главной странице.</p>';
  document.querySelectorAll('[data-action]').forEach(button=>button.addEventListener('click',()=>runBatchAction(button.dataset.action,button.dataset.id)));
}
async function load(){
  const response=await fetch('?action=queue',{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось загрузить очередь.');
  batches=payload.batches||[];items=payload.items||[];render();
}
async function post(action,fd,label){
  const response=await fetch('?action='+action,{method:'POST',headers:{'X-CSRF-Token':CSRF_TOKEN},body:fd});
  const payload=await response.json();
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Операция не выполнена.');
  showToast(label,'success');await load();return payload;
}
async function runBatchAction(action,id){
  if(action==='delete'){
    if(!window.confirm('Удалить пачку вместе со всеми письмами и файлами? Это действие нельзя отменить.'))return;
  }
  const endpoint=action==='up'||action==='down'?'move_batch':`${action}_batch`;
  const fd=new FormData();fd.append('batch_id',id);
  if(action==='delete')fd.append('confirm','1');
  if(action==='up'||action==='down')fd.append('direction',action);
  const target=document.querySelector(`[data-feedback="${CSS.escape(id)}"]`);
  if(target)target.textContent='Выполняем…';
  const messages={cancel:'Пачка остановлена.',retry:'Повтор поставлен в очередь.',pause:'Пачка поставлена на паузу.',resume:'Пачка продолжена.',delete:'Пачка удалена.',up:'Пачка перемещена выше.',down:'Пачка перемещена ниже.'};
  try{await post(endpoint,fd,messages[action]||'Готово.');}
  catch(e){if(target)target.textContent=e.message;showToast(e.message,'error');}
}
function showToast(text,kind){const el=document.createElement('div');el.className='pill '+(kind==='success'?'sent':'failed');el.textContent=text;document.querySelector('main').prepend(el);setTimeout(()=>el.remove(),5000);}
if(window.self!==window.top){
  $('backToApp').hidden=true;
  $('closeManagerInPopup').hidden=false;
  $('closeManagerInPopup').addEventListener('click',()=>window.parent.postMessage('docgen-close-queue-manager','*'));
}
function renderMailingAccounts(accounts){
  $('mailingAccounts').innerHTML=accounts.length?accounts.map(account=>{
    const id=String(account.id||'');
    const label=`${account.label||'Ящик'} · ${account.fromEmail||'без адреса'}${account.limitCustom?' · свой лимит':''}`;
    const sent=Number(account.sentToday||0), cap=Number(account.dailyCap||0);
    const quota=cap>0?`отправлено сегодня ${sent} из ${cap}`:`отправлено сегодня ${sent}, локального суточного лимита нет`;
    return `<div class="field"><label for="limit-${esc(id)}">${esc(label)}</label><input id="limit-${esc(id)}" data-account-limit="${esc(id)}" type="number" min="1" max="6" value="${Number(account.limit||1)}"><span class="hint" style="display:block;margin-top:4px">${esc(quota)}. Писем в минуту.</span></div>`;
  }).join(''):'<p class="muted">SMTP-ящики ещё не настроены.</p>';
}
$('saveMailingBtn').addEventListener('click',async()=>{
  const fd=new FormData();fd.append('lettersPerMinute',$('lettersPerMinute').value.trim());
  document.querySelectorAll('[data-account-limit]').forEach(input=>fd.append('accountLimits['+input.dataset.accountLimit+']',input.value.trim()));
  fd.append('dailyCap',$('dailyCap').value.trim()||'0');
  fd.append('windowStart',$('windowStart').value||'09:00');
  fd.append('windowEnd',$('windowEnd').value||'18:00');
  fd.append('jitterSeconds',$('jitterSeconds').value.trim()||'0');
  if($('weekdaysOnly').checked)fd.append('weekdaysOnly','1');
  try{const payload=await post('save_mailing_settings',fd,'Настройки рассылки сохранены.');applyMailingForm(payload);$('mailingFeedback').textContent='Сохранено.';}
  catch(e){$('mailingFeedback').textContent=e.message;}
});
function applyMailingForm(payload){
  const settings=payload.settings||{};
  $('lettersPerMinute').value=settings.lettersPerMinute||1;
  $('dailyCap').value=Number(settings.dailyCap||0);
  $('windowStart').value=settings.windowStart||'09:00';
  $('windowEnd').value=settings.windowEnd||'18:00';
  $('jitterSeconds').value=Number(settings.jitterSeconds??20);
  $('weekdaysOnly').checked=Boolean(settings.weekdaysOnly);
  renderMailingAccounts(payload.accounts||[]);
}
async function loadMailingSettings(){
  const response=await fetch('?action=mailing_settings',{cache:'no-store'});
  const payload=await response.json();
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Не удалось загрузить настройки рассылки.');
  applyMailingForm(payload);
}
(async()=>{
  try{await loadMailingSettings();await load();}
  catch(e){$('batches').textContent=e.message||'Ошибка загрузки.';}
})();
</script>
</body>
</html>
