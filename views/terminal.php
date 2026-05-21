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
      <input type="text" id="cmd-filter" placeholder="Buscar..." oninput="filterCmds(this.value)" class="input-sm">
      <div id="cmd-list">
        <?php foreach ($commands as $c): ?>
        <button class="cmd-btn" data-title="<?= htmlspecialchars(strtolower($c['title'])) ?>"
                onclick="sendCommand(<?= htmlspecialchars(json_encode($c['command']), ENT_QUOTES) ?>)">
          <?= htmlspecialchars($c['title']) ?>
        </button>
        <?php endforeach; ?>
      </div>
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
}

function startStatusTimer() {
  stopStatusTimer();
  lastOutputAt = Date.now();
  statusTimer = window.setInterval(() => {
    const seconds = Math.max(0, Math.floor((Date.now() - lastOutputAt) / 1000));
    setStatus(`Ejecutando... última salida hace ${seconds}s`, 'running');
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

function filterCmds(q) {
  const lq = q.toLowerCase();
  document.querySelectorAll('#cmd-list .cmd-btn').forEach(btn => {
    btn.style.display = btn.dataset.title.includes(lq) ? '' : 'none';
  });
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

  appendOutput(`<div class="terminal-line terminal-cmd">$ ${esc(cmd)}</div>`);
  input.value = '';
  setBusy(true);
  setStatus('Preparando ejecución...', 'running');
  startStatusTimer();

  try {
    const fd = new FormData();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('server_id', SERVER_ID);
    fd.append('command', cmd);
    fd.append('action', 'ssh_exec');
    fd.append('stream', '1');

    const r = await fetch('?action=ssh_exec', { method: 'POST', body: fd });

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
    appendOutputText(`Error de red/stream: ${e.message}`, 'terminal-err');
    setStatus('La conexión del navegador al stream se cortó.', 'error');
  }

  setBusy(false);
  hideStatusLater();
  document.getElementById('cmd-input').focus();
}

document.getElementById('cmd-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') runCmd();
});

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
