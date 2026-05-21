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
  <div class="modal-box">
    <h3>Ejecutar Template: <span id="tmpl-modal-name"></span></h3>
    <p>Se ejecutará en <strong><?= htmlspecialchars($server['name']) ?></strong>. ¿Confirmar?</p>
    <div class="modal-actions">
      <button class="btn btn-primary" onclick="confirmTemplate()">Confirmar</button>
      <button class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
    </div>
  </div>
</div>

<script>
const SERVER_ID  = <?= (int)$server['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
let pendingTemplateId = null;

function esc(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

function appendOutput(html) {
  const out = document.getElementById('terminal-output');
  out.innerHTML += html;
  out.scrollTop = out.scrollHeight;
}

function clearOutput() {
  document.getElementById('terminal-output').innerHTML = '';
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

async function runCmd() {
  const input = document.getElementById('cmd-input');
  const cmd   = input.value.trim();
  if (!cmd) return;

  appendOutput(`<div class="terminal-line terminal-cmd">$ ${esc(cmd)}</div>`);
  input.value = '';
  document.getElementById('btn-exec').disabled = true;

  try {
    const fd = new FormData();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('server_id', SERVER_ID);
    fd.append('command', cmd);
    fd.append('action', 'ssh_exec');

    const r    = await fetch('?action=ssh_exec', { method: 'POST', body: fd });
    const data = await r.json();

    if (data.error) {
      appendOutput(`<div class="terminal-line terminal-err">ERROR: ${esc(data.error)}</div>`);
    } else {
      const cls = data.exit_code === 0 ? 'terminal-ok' : 'terminal-warn';
      appendOutput(`<div class="terminal-line ${cls}">${esc(data.output || '(sin output)')}</div>`);
      if (data.exit_code !== 0) {
        appendOutput(`<div class="terminal-line terminal-warn">Exit code: ${data.exit_code}</div>`);
      }
    }
  } catch (e) {
    appendOutput(`<div class="terminal-line terminal-err">Error de red: ${esc(e.message)}</div>`);
  }

  document.getElementById('btn-exec').disabled = false;
  document.getElementById('cmd-input').focus();
}

document.getElementById('cmd-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') runCmd();
});

// Template execution
function runTemplate(id, name) {
  pendingTemplateId = id;
  document.getElementById('tmpl-modal-name').textContent = name;
  document.getElementById('tmpl-modal').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('tmpl-modal').classList.add('hidden');
  pendingTemplateId = null;
}

async function confirmTemplate() {
  closeModal();
  if (!pendingTemplateId) return;

  const prog = document.getElementById('template-progress');
  prog.classList.remove('hidden');
  prog.textContent = 'Ejecutando template...';
  appendOutput('<div class="terminal-line terminal-cmd">▶ Ejecutando template...</div>');

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('server_id', SERVER_ID);
  fd.append('template_id', pendingTemplateId);

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
