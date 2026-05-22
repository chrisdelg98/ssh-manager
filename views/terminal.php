<?php
use App\CsrfGuard;
/** @var array $server @var array $templates @var array $commands */
include __DIR__ . '/layout.php';
$csrfToken = CsrfGuard::token();
?>

<div class="page-header">
  <h2>Terminal —
    <span style="border-left:4px solid <?= htmlspecialchars($server['color_tag']) ?>;padding-left:.5rem">
      <?= htmlspecialchars($server['name']) ?>
    </span>
    <small class="server-host-label"><?= htmlspecialchars($server['host']) ?>:<?= (int)$server['port'] ?></small>
  </h2>
  <a href="?action=dashboard" class="btn btn-secondary">← Servidores</a>
</div>

<div id="job-banner" class="job-banner hidden">
  <div class="job-banner-info">
    <span id="job-banner-label" class="job-banner-label"></span>
    <code id="job-banner-cmd" class="job-banner-cmd"></code>
    <span id="job-banner-elapsed" class="job-banner-elapsed"></span>
  </div>
  <div class="job-banner-actions">
    <button class="btn btn-primary" onclick="reconnectJob()">Ver output</button>
    <button class="btn btn-ghost" onclick="dismissJobBanner()">Ignorar</button>
  </div>
</div>

<div class="terminal-layout">

  <!-- Sidebar de comandos rápidos -->
  <aside class="terminal-sidebar">
    <div class="sidebar-section">
      <h4>Templates</h4>
      <?php foreach ($templates as $t): ?>
      <button class="cmd-btn" onclick="runTemplate(<?= (int)$t['id'] ?>, <?= htmlspecialchars(json_encode($t['name']), ENT_QUOTES) ?>)">
        <?= htmlspecialchars($t['name']) ?>
        <?php if ($t['os_target']): ?>
        <span class="os-badge"><?= htmlspecialchars($t['os_target']) ?></span>
        <?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="sidebar-section">
      <h4>Comandos rápidos <a href="?action=commands" style="font-size:.8em">ver todos</a></h4>
      <input type="text" id="cmd-filter" placeholder="Buscar nombre, descripción, tag..." oninput="filterCmds()" class="input-sm">
      <select id="cmd-cat-filter" class="input-sm" onchange="filterCmds()" aria-label="Filtrar por categoría">
        <option value="">Todas las categorías</option>
      </select>
      <select id="cmd-os-filter" class="input-sm" onchange="filterCmds()" aria-label="Filtrar por tipo">
        <option value="">Todos los tipos</option>
      </select>
      <select id="cmd-tag-filter" class="input-sm" onchange="filterCmds()" aria-label="Filtrar por etiqueta">
        <option value="">Todas las etiquetas</option>
      </select>
      <div id="cmd-list">
        <?php foreach ($commands as $c): ?>
        <?php
          $haystack = strtolower(trim(
              ($c['title'] ?? '') . ' ' .
              ($c['command'] ?? '') . ' ' .
              ($c['category'] ?? '') . ' ' .
              ($c['os_target'] ?? '') . ' ' .
              ($c['description'] ?? '') . ' ' .
              ($c['tags'] ?? '')
          ));
        ?>
        <button class="cmd-btn"
                data-search="<?= htmlspecialchars($haystack, ENT_QUOTES) ?>"
                data-category="<?= htmlspecialchars($c['category'] ?? 'General', ENT_QUOTES) ?>"
                data-os="<?= htmlspecialchars($c['os_target'] ?? 'General', ENT_QUOTES) ?>"
                data-tags="<?= htmlspecialchars($c['tags'] ?? '', ENT_QUOTES) ?>"
                title="<?= htmlspecialchars(($c['description'] ?? '') ?: ($c['command'] ?? ''), ENT_QUOTES) ?>"
                onclick="sendCommand(<?= htmlspecialchars(json_encode($c['command']), ENT_QUOTES) ?>)">
          <?= htmlspecialchars($c['title']) ?>
          <?php if (!empty($c['os_target']) && $c['os_target'] !== 'General'): ?>
          <span class="os-badge"><?= htmlspecialchars($c['os_target']) ?></span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
      <div id="cmd-empty" class="text-muted hidden" style="font-size:.8rem;margin-top:.5rem">Sin coincidencias.</div>
    </div>
  </aside>

  <!-- Panel principal -->
  <div class="terminal-main">
    <div id="terminal-output" class="terminal-output" aria-live="polite">
      <span class="terminal-welcome">Conectado a <strong><?= htmlspecialchars($server['host']) ?></strong>. Escribe un comando o elige un template.</span>
    </div>

    <div id="terminal-status" class="terminal-status hidden" aria-live="polite"></div>

    <div class="terminal-input-row">
      <span class="terminal-prompt">$</span>
      <input type="text" id="cmd-input" class="terminal-input"
             placeholder="comando a ejecutar..." autocomplete="off" autocorrect="off"
             autocapitalize="off" spellcheck="false">
      <button id="btn-exec" class="btn btn-primary" onclick="runCmd()">Ejecutar</button>
      <button id="btn-stream-disconnect" class="btn btn-secondary hidden" onclick="disconnectStream()" title="Cierra la conexión del navegador — el comando sigue ejecutándose en el servidor">Desconectar</button>
      <button class="btn btn-secondary" onclick="clearOutput()">Limpiar</button>
    </div>

    <div id="template-progress" class="template-progress hidden"></div>
  </div>
</div>

<!-- Template confirmation modal -->
<div id="tmpl-modal" class="modal hidden">
  <div class="modal-box" style="max-width:680px">
    <h3>Ejecutar Template: <span id="tmpl-modal-name" class="text-accent"></span></h3>
    <p class="tmpl-modal-meta">
      Servidor:&nbsp;<strong><?= htmlspecialchars($server['name']) ?></strong>
      <span class="text-muted">·</span>
      <span id="tmpl-modal-count"></span>
      <span id="tmpl-modal-desc"></span>
    </p>

    <div class="tmpl-steps-wrap">
      <div class="tmpl-steps-label">Comandos que se ejecutarán en orden:</div>
      <ol id="tmpl-modal-steps" class="tmpl-steps"></ol>
    </div>

    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancelar</button>
      <button class="btn btn-primary" onclick="confirmTemplate()">▶ Ejecutar</button>
    </div>
  </div>
</div>

<script>
const SERVER_ID  = <?= (int)$server['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const TEMPLATES  = <?= json_encode(array_column($templates, null, 'id')) ?>;
let pendingTemplateId = null;
let statusTimer = null;
let lastOutputAt = 0;
let streamAbort = null;
const COMMAND_HISTORY_KEY = `sshmgr:terminal-history:${SERVER_ID}`;
const COMMAND_HISTORY_LIMIT = 80;
let commandHistory = loadCommandHistory();
let historyCursor = commandHistory.length;
let historyDraft = '';

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}
function escInline(s) {
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function appendOutput(html) {
  const out = document.getElementById('terminal-output');
  out.innerHTML += html;
  out.scrollTop = out.scrollHeight;
}

function appendOutputText(text, cls = 'terminal-ok') {
  const out = document.getElementById('terminal-output');
  const line = document.createElement('div');
  line.className = `terminal-line ${cls}`;
  line.textContent = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  out.appendChild(line);
  out.scrollTop = out.scrollHeight;
}

function clearOutput() {
  document.getElementById('terminal-output').innerHTML = '';
}

function setStatus(message, state = 'running') {
  const status = document.getElementById('terminal-status');
  status.textContent = message;
  status.dataset.state = state;
  status.classList.remove('hidden');
}

function hideStatusLater() {
  window.setTimeout(() => {
    document.getElementById('terminal-status').classList.add('hidden');
  }, 4500);
}

function setBusy(isBusy) {
  document.getElementById('btn-exec').disabled = isBusy;
  document.getElementById('cmd-input').disabled = isBusy;
  if (!isBusy) {
    document.getElementById('btn-stream-disconnect').classList.add('hidden');
  }
}

function disconnectStream() {
  if (streamAbort) {
    streamAbort.abort();
    streamAbort = null;
  }
}

function startStatusTimer() {
  stopStatusTimer();
  lastOutputAt = Date.now();
  statusTimer = window.setInterval(() => {
    const seconds = Math.max(0, Math.floor((Date.now() - lastOutputAt) / 1000));

    let msg;
    if (seconds < 30) {
      msg = `Ejecutando... última salida hace ${seconds}s`;
    } else if (seconds < 120) {
      msg = `Ejecutando... sin salida hace ${seconds}s — normal en comandos con procesamiento interno`;
    } else {
      msg = `El comando sigue ejecutándose en el servidor. Sin nueva salida hace ${seconds}s. Puedes desconectar el stream — el comando NO se cancela.`;
    }
    setStatus(msg, 'running');

    const btnDisconnect = document.getElementById('btn-stream-disconnect');
    if (btnDisconnect) {
      btnDisconnect.classList.toggle('hidden', seconds < 30);
    }
  }, 1000);
}

function stopStatusTimer() {
  if (statusTimer) {
    window.clearInterval(statusTimer);
    statusTimer = null;
  }
}

function sendCommand(cmd) {
  document.getElementById('cmd-input').value = cmd;
  document.getElementById('cmd-input').focus();
}

function loadCommandHistory() {
  try {
    const parsed = JSON.parse(localStorage.getItem(COMMAND_HISTORY_KEY) || '[]');
    return Array.isArray(parsed) ? parsed.filter(v => typeof v === 'string' && v.trim() !== '') : [];
  } catch (_) {
    return [];
  }
}

function saveCommandHistory() {
  try {
    localStorage.setItem(COMMAND_HISTORY_KEY, JSON.stringify(commandHistory.slice(-COMMAND_HISTORY_LIMIT)));
  } catch (_) {}
}

function rememberCommand(cmd) {
  const clean = String(cmd || '').trim();
  if (!clean) return;

  if (commandHistory[commandHistory.length - 1] !== clean) {
    commandHistory.push(clean);
  }

  if (commandHistory.length > COMMAND_HISTORY_LIMIT) {
    commandHistory = commandHistory.slice(-COMMAND_HISTORY_LIMIT);
  }

  saveCommandHistory();
  historyCursor = commandHistory.length;
  historyDraft = '';
}

function browseCommandHistory(direction) {
  if (commandHistory.length === 0) return;

  const input = document.getElementById('cmd-input');
  if (historyCursor === commandHistory.length) {
    historyDraft = input.value;
  }

  historyCursor = Math.max(0, Math.min(commandHistory.length, historyCursor + direction));
  input.value = historyCursor === commandHistory.length ? historyDraft : commandHistory[historyCursor];

  window.setTimeout(() => {
    input.selectionStart = input.selectionEnd = input.value.length;
  }, 0);
}

function normalizeFilterValue(value) {
  return String(value || '').trim().toLowerCase();
}

function initCommandFilters() {
  const cats = new Set();
  const osTargets = new Set();
  const tags = new Set();

  document.querySelectorAll('#cmd-list .cmd-btn').forEach(btn => {
    if (btn.dataset.category) cats.add(btn.dataset.category);
    if (btn.dataset.os) osTargets.add(btn.dataset.os);
    String(btn.dataset.tags || '').split(',').forEach(tag => {
      const clean = tag.trim();
      if (clean) tags.add(clean);
    });
  });

  fillFilterSelect('cmd-cat-filter', cats);
  fillFilterSelect('cmd-os-filter', osTargets);
  fillFilterSelect('cmd-tag-filter', tags);
}

function fillFilterSelect(id, values) {
  const select = document.getElementById(id);
  const first = select.options[0];
  select.innerHTML = '';
  select.appendChild(first);

  Array.from(values).sort((a, b) => a.localeCompare(b)).forEach(value => {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = value;
    select.appendChild(opt);
  });
}

function filterCmds() {
  const q = normalizeFilterValue(document.getElementById('cmd-filter').value);
  const cat = document.getElementById('cmd-cat-filter').value;
  const os = document.getElementById('cmd-os-filter').value;
  const tag = normalizeFilterValue(document.getElementById('cmd-tag-filter').value);
  let visible = 0;

  document.querySelectorAll('#cmd-list .cmd-btn').forEach(btn => {
    const tags = String(btn.dataset.tags || '').split(',').map(normalizeFilterValue);
    const matches =
      (!q || normalizeFilterValue(btn.dataset.search).includes(q)) &&
      (!cat || btn.dataset.category === cat) &&
      (!os || btn.dataset.os === os) &&
      (!tag || tags.includes(tag));

    btn.style.display = matches ? '' : 'none';
    if (matches) visible++;
  });

  document.getElementById('cmd-empty').classList.toggle('hidden', visible !== 0);
}

async function readNdjsonStream(response, onEvent) {
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';

    for (const line of lines) {
      if (!line.trim()) continue;
      onEvent(JSON.parse(line));
    }
  }

  buffer += decoder.decode();
  if (buffer.trim()) {
    onEvent(JSON.parse(buffer));
  }
}

async function runCmd() {
  const input = document.getElementById('cmd-input');
  const cmd   = input.value.trim();
  if (!cmd) return;

  rememberCommand(cmd);
  appendOutput(`<div class="terminal-line terminal-cmd">$ ${esc(cmd)}</div>`);
  input.value = '';
  setBusy(true);
  setStatus('Preparando ejecución...', 'running');
  startStatusTimer();

  streamAbort = new AbortController();

  try {
    const fd = new FormData();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('server_id', SERVER_ID);
    fd.append('command', cmd);
    fd.append('action', 'ssh_exec');
    fd.append('stream', '1');

    const r = await fetch('?action=ssh_exec', { method: 'POST', body: fd, signal: streamAbort.signal });

    if (!r.ok || !r.body) {
      const text = await r.text();
      throw new Error(text || `HTTP ${r.status}`);
    }

    await readNdjsonStream(r, event => {
      if (event.type === 'status') {
        setStatus(event.message, 'running');
      } else if (event.type === 'output') {
        lastOutputAt = Date.now();
        appendOutputText(event.data, 'terminal-ok');
      } else if (event.type === 'done') {
        stopStatusTimer();
        if (event.error) {
          appendOutputText(`ERROR: ${event.error}`, 'terminal-err');
          setStatus(event.error, 'error');
        } else if (event.warning) {
          appendOutputText(`AVISO: ${event.warning}`, 'terminal-warn');
          setStatus(event.warning, 'warn');
        } else if (event.exit_code === 0) {
          appendOutputText(`[terminado] exit code 0`, 'terminal-done');
          setStatus('Comando terminado correctamente.', 'done');
        } else {
          appendOutputText(`[terminado] exit code ${event.exit_code}`, 'terminal-warn');
          setStatus(`Comando terminó con exit code ${event.exit_code}.`, 'warn');
        }
      }
    });
  } catch (e) {
    stopStatusTimer();
    if (e.name === 'AbortError') {
      appendOutputText('[stream desconectado] El comando sigue ejecutándose en el servidor.', 'terminal-warn');
      setStatus('Desconectado del stream. El comando continúa en el servidor.', 'warn');
    } else {
      appendOutputText(`Error de red/stream: ${e.message}`, 'terminal-err');
      setStatus('La conexión del navegador al stream se cortó.', 'error');
    }
  }

  streamAbort = null;

  setBusy(false);
  hideStatusLater();
  document.getElementById('cmd-input').focus();
}

document.getElementById('cmd-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    runCmd();
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    browseCommandHistory(-1);
  } else if (e.key === 'ArrowDown') {
    e.preventDefault();
    browseCommandHistory(1);
  }
});
initCommandFilters();

// ── Job reconnect (resume output after page refresh) ──────────────────

let jobPollTimer  = null;
let jobOutputOffset = 0;

async function checkActiveJob() {
  try {
    const r    = await fetch(`?action=job_check&server_id=${SERVER_ID}`);
    const data = await r.json();
    if (data.job) showJobBanner(data.job);
  } catch (_) {}
}

function showJobBanner(job) {
  const banner  = document.getElementById('job-banner');
  const label   = document.getElementById('job-banner-label');
  const cmd     = document.getElementById('job-banner-cmd');
  const elapsed = document.getElementById('job-banner-elapsed');

  const isRunning = job.status === 'running';
  label.textContent   = isRunning ? 'Comando en ejecución:' : 'Output de comando reciente:';
  cmd.textContent     = job.command.length > 80 ? job.command.slice(0, 77) + '…' : job.command;
  elapsed.textContent = isRunning
    ? `· iniciado hace ${Math.floor(job.elapsed / 60)}m ${job.elapsed % 60}s`
    : `· ${job.status}`;

  banner.classList.remove('hidden');
}

function dismissJobBanner() {
  document.getElementById('job-banner').classList.add('hidden');
}

async function reconnectJob() {
  dismissJobBanner();
  clearOutput();
  appendOutput('<div class="terminal-line terminal-cmd">▶ Reconectando — cargando output del servidor...</div>');
  setBusy(true);
  startStatusTimer();
  jobOutputOffset = 0;
  await pollJobOutput();
}

async function pollJobOutput() {
  try {
    const r    = await fetch(`?action=job_output&server_id=${SERVER_ID}&offset=${jobOutputOffset}`);
    const data = await r.json();

    for (const event of (data.lines || [])) {
      if (!event) continue;
      if (event.type === 'output') {
        lastOutputAt = Date.now();
        appendOutputText(event.data, 'terminal-ok');
      } else if (event.type === 'done') {
        stopStatusTimer();
        if (event.error) {
          appendOutputText(`ERROR: ${event.error}`, 'terminal-err');
          setStatus(event.error, 'error');
        } else if (event.warning) {
          appendOutputText(`AVISO: ${event.warning}`, 'terminal-warn');
          setStatus(event.warning, 'warn');
        } else if (event.exit_code === 0) {
          appendOutputText('[terminado] exit code 0', 'terminal-done');
          setStatus('Comando terminado correctamente.', 'done');
        } else {
          appendOutputText(`[terminado] exit code ${event.exit_code}`, 'terminal-warn');
          setStatus(`Comando terminó con exit code ${event.exit_code}.`, 'warn');
        }
        setBusy(false);
        hideStatusLater();
        return;
      }
    }

    jobOutputOffset = data.total;

    if (data.status === 'running') {
      jobPollTimer = window.setTimeout(pollJobOutput, 2000);
    } else {
      stopStatusTimer();
      setStatus('El comando terminó en el servidor.', 'done');
      setBusy(false);
      hideStatusLater();
    }
  } catch (e) {
    stopStatusTimer();
    appendOutputText(`Error al reconectar: ${e.message}`, 'terminal-err');
    setBusy(false);
  }
}

checkActiveJob();

// Template execution
function runTemplate(id, name) {
  pendingTemplateId = id;
  document.getElementById('tmpl-modal-name').textContent = name;

  const tpl   = TEMPLATES[id] || {};
  const steps = Array.isArray(tpl.steps) ? tpl.steps : [];

  // Counter + optional description
  document.getElementById('tmpl-modal-count').innerHTML =
    `<strong>${steps.length}</strong> paso${steps.length === 1 ? '' : 's'}`;
  const descEl = document.getElementById('tmpl-modal-desc');
  descEl.innerHTML = tpl.description
    ? `<span class="text-muted">·</span> ${escInline(tpl.description)}`
    : '';

  // Step list
  const ol = document.getElementById('tmpl-modal-steps');
  ol.innerHTML = steps.map(s => {
    const stop = s.stop_on_error
      ? '<span class="tmpl-step-flag" title="Detiene la ejecución si este paso falla">stop-on-error</span>'
      : '';
    const desc = s.description
      ? `<div class="tmpl-step-desc">${escInline(s.description)}</div>`
      : '';
    return `
      <li class="tmpl-step">
        <code class="tmpl-step-cmd">${escInline(s.command)}</code>
        ${desc}
        ${stop}
      </li>`;
  }).join('') || '<li class="tmpl-step text-muted">Sin pasos definidos.</li>';

  document.getElementById('tmpl-modal').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('tmpl-modal').classList.add('hidden');
  pendingTemplateId = null;
}

async function confirmTemplate() {
  const templateId = pendingTemplateId;
  closeModal();
  if (!templateId) return;

  const prog = document.getElementById('template-progress');
  prog.classList.remove('hidden');
  prog.textContent = 'Ejecutando template...';
  appendOutput('<div class="terminal-line terminal-cmd">▶ Ejecutando template...</div>');

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('server_id', SERVER_ID);
  fd.append('template_id', templateId);

  try {
    const r    = await fetch('?action=template_run', {method:'POST', body:fd});
    const data = await r.json();

    if (data.error) {
      appendOutput(`<div class="terminal-line terminal-err">ERROR: ${esc(data.error)}</div>`);
    } else {
      data.results.forEach(step => {
        appendOutput(`<div class="terminal-line terminal-cmd">Step ${step.step}: $ ${esc(step.command)}</div>`);
        if (step.error) {
          appendOutput(`<div class="terminal-line terminal-err">ERROR: ${esc(step.error)}</div>`);
        } else {
          const cls = step.exit_code === 0 ? 'terminal-ok' : 'terminal-warn';
          appendOutput(`<div class="terminal-line ${cls}">${esc(step.output || '(sin output)')}</div>`);
        }
      });
    }
  } catch(e) {
    appendOutput(`<div class="terminal-line terminal-err">Error: ${esc(e.message)}</div>`);
  }

  prog.classList.add('hidden');
  pendingTemplateId = null;
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
