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
.item{border:1px solid #e3ebf4;border-radius:8px;padding:9px 11px;margin-bottom:7px;background:#fff}.item .meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.pill{border-radius:999px;padding:2px 8px;font-size:11px;background:#eef3f9;color:var(--muted)}.pill.sent{background:#e8f6ee;color:var(--ok)}.pill.failed{background:#fdecea;color:var(--bad)}.pill.scheduled{background:#fff5e5;color:var(--warn)}
a{color:var(--brand)}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:9px}.muted{color:var(--muted);font-size:12px}.error{color:var(--bad);font-size:12px}.feedback{margin-left:8px;font-size:12px}
@media(max-width:700px){main{padding:12px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><div><h1>Менеджер очереди писем</h1><a class="sub" href="index.php">← Вернуться к формированию и ручной проверке</a></div><div class="sub">Локальное приложение<?= $authEnabled ? ' · вход по паролю' : '' ?></div></header>
<main>
  <section class="card">
    <h2>Настройки рассылки</h2>
    <div class="grid">
      <div class="field"><label for="lettersPerMinute">Писем в минуту (1–60)</label><input id="lettersPerMinute" type="number" min="1" max="60" value="1"></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary" id="saveMailingBtn" type="button">Сохранить лимит</button><span class="feedback" id="mailingFeedback"></span></div>
    </div>
    <p class="hint">Планировщик запускается раз в минуту. За один запуск отправляется не больше указанного числа писем; интервал между письмами соблюдается. Автоотправка включается только для пакетов, где оператор явно поставил галочку и выбрал «в расписание».</p>
  </section>
  <section class="card">
    <h2>Пачки писем</h2>
    <p class="hint">На главной странице остаются только пакеты, ожидающие ручной проверки. Здесь — уже переданные в ручную очередь или в расписание, а также завершённые.</p>
    <div id="batches">Загружаем…</div>
  </section>
</main>
<script>
'use strict';
const CSRF_TOKEN = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let batches = [], items = [];
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const labels = {queued:'в ручной очереди',scheduled:'в расписании',sending:'отправляется',sent:'отправлено',failed:'ошибка',cancelled:'отменено',review:'на проверке',manual:'ручная очередь',done:'завершено'};
function pill(status){const css=['sent','failed','scheduled','sending','cancelled','done'].includes(status)?status:'';return `<span class="pill ${css}">${esc(labels[status]||status||'—')}</span>`;}
function batchItems(id){return items.filter(item=>id==='legacy'?!item.batchId:item.batchId===id);}
function render(){
  $('batches').innerHTML = batches.length ? batches.map(batch=>{
    const rows=batchItems(batch.id);
    const list=rows.map(item=>`<div class="item"><div class="meta"><b>${esc(item.recipientName||item.recordId||'Адресат')}</b>${pill(item.status)}<span class="muted">${esc(item.to||'получатель не указан')}</span><span class="muted">${esc(item.subject||'')}</span><a href="?action=queue_file&id=${encodeURIComponent(item.id)}&format=pdf&inline=1" target="_blank" rel="noopener">PDF</a><a href="index.php?review=${encodeURIComponent(item.id)}">Открыть для проверки</a></div>${item.lastError?`<div class="error">${esc(item.lastError)}</div>`:''}</div>`).join('');
    const canCancel=['manual','scheduled','sending'].includes(batch.status);
    return `<details class="batch" ${batch.status==='scheduled'?'open':''}><summary>Очередь — ${esc(batch.name||'Без названия')} — ${Number(batch.itemCount||rows.length)} писем · Тема: ${esc(batch.subject||'—')} ${pill(batch.status)}</summary><div class="body"><div class="muted">Создано: ${esc(batch.createdAt||'')} · отправлено: ${Number(batch.sentCount||0)} · ошибок: ${Number(batch.failedCount||0)} · режим: ${esc(batch.mode==='auto'?'авто по расписанию':'ручной')}</div><div class="actions"><button class="btn danger" data-action="cancel" data-id="${esc(batch.id)}" ${canCancel?'':'disabled'}>Остановить пачку</button><button class="btn" data-action="retry" data-id="${esc(batch.id)}">Повторить ошибочные</button><span class="feedback" data-feedback="${esc(batch.id)}"></span></div><div style="margin-top:10px">${list||'<span class="muted">Писем нет.</span>'}</div></div></details>`;
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
  const fd=new FormData();fd.append('batch_id',id);
  const target=document.querySelector(`[data-feedback="${CSS.escape(id)}"]`);
  if(target)target.textContent='Выполняем…';
  try{await post(action,fd,action==='cancel'?'Пачка остановлена.':'Повтор поставлен в очередь.');}
  catch(e){if(target)target.textContent=e.message;showToast(e.message,'error');}
}
function showToast(text,kind){const el=document.createElement('div');el.className='pill '+(kind==='success'?'sent':'failed');el.textContent=text;document.querySelector('main').prepend(el);setTimeout(()=>el.remove(),5000);}
$('saveMailingBtn').addEventListener('click',async()=>{
  const fd=new FormData();fd.append('lettersPerMinute',$('lettersPerMinute').value.trim());
  try{const payload=await post('save_mailing_settings',fd,'Лимит сохранён.');$('lettersPerMinute').value=payload.settings.lettersPerMinute;$('mailingFeedback').textContent='Сохранено.';}
  catch(e){$('mailingFeedback').textContent=e.message;}
});
(async()=>{
  try{const response=await fetch('?action=mailing_settings');const payload=await response.json();if(payload.ok)$('lettersPerMinute').value=payload.settings.lettersPerMinute;await load();}
  catch(e){$('batches').textContent=e.message||'Ошибка загрузки.';}
})();
</script>
</body>
</html>
