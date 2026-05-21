// SSH Manager — frontend utilities
'use strict';

(function () {
  const THEMES = ['matrix', 'void', 'daylight', 'dusk'];
  const html = document.documentElement;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // ─── Theme handling ─────────────────────────────────────────────────
  function getCurrentTheme() {
    return html.getAttribute('data-theme') || 'matrix';
  }

  function applyTheme(name) {
    if (!THEMES.includes(name)) name = 'matrix';
    html.setAttribute('data-theme', name);
    try { localStorage.setItem('sshmgr_theme', name); } catch (e) { /* ignore */ }

    document.querySelectorAll('.theme-swatch').forEach(el => {
      el.classList.toggle('is-selected', el.dataset.preview === name);
    });
  }

  function persistTheme(name) {
    const fd = new FormData();
    fd.set('_csrf', csrfToken);
    fd.set('theme', name);
    fetch('?action=settings_theme', { method: 'POST', body: fd, credentials: 'same-origin' })
      .catch(() => { /* swallow; theme already applied locally */ });
  }

  function cycleTheme() {
    const current = getCurrentTheme();
    const next = THEMES[(THEMES.indexOf(current) + 1) % THEMES.length];
    applyTheme(next);
    persistTheme(next);
  }

  // ─── Confirm modal (replaces native confirm) ────────────────────────
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Show a styled confirmation modal. Returns a Promise<boolean>.
   *
   * @param {Object} opts
   * @param {string} opts.title       Modal title.
   * @param {string} opts.message     Body message.
   * @param {string} [opts.confirmText='Eliminar']
   * @param {string} [opts.cancelText='Cancelar']
   * @param {string} [opts.variant='danger']  'danger' | 'primary' | 'warn'
   */
  function showConfirmModal(opts) {
    return new Promise((resolve) => {
      const variant = opts.variant || 'danger';
      const confirmText = opts.confirmText || (variant === 'danger' ? 'Eliminar' : 'Confirmar');
      const cancelText = opts.cancelText || 'Cancelar';
      const title = opts.title || 'Confirmar acción';
      const message = opts.message || '';

      const btnClass = variant === 'danger' ? 'btn-danger'
                     : variant === 'warn'   ? 'btn-secondary'
                                            : 'btn-primary';

      const overlay = document.createElement('div');
      overlay.className = 'modal';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.innerHTML = `
        <div class="modal-box" role="document">
          <h3>${escapeHtml(title)}</h3>
          <p style="color:var(--muted-strong); line-height:1.55; white-space:pre-line">${escapeHtml(message)}</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-cm="cancel">${escapeHtml(cancelText)}</button>
            <button type="button" class="btn ${btnClass}" data-cm="confirm" autofocus>${escapeHtml(confirmText)}</button>
          </div>
        </div>
      `;

      const cleanup = (result) => {
        overlay.remove();
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = '';
        resolve(result);
      };

      const onKey = (e) => {
        if (e.key === 'Escape') { e.preventDefault(); cleanup(false); }
        if (e.key === 'Enter')  { e.preventDefault(); cleanup(true);  }
      };

      overlay.addEventListener('click', (e) => {
        const t = e.target;
        if (t === overlay) cleanup(false);
        else if (t.dataset && t.dataset.cm === 'cancel')  cleanup(false);
        else if (t.dataset && t.dataset.cm === 'confirm') cleanup(true);
      });

      document.addEventListener('keydown', onKey);
      document.body.style.overflow = 'hidden';
      document.body.appendChild(overlay);
      overlay.querySelector('[data-cm="confirm"]').focus();
    });
  }

  /**
   * Wire any <form data-confirm="..."> to use the styled modal instead of native confirm().
   */
  function wireConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach(form => {
      if (form.dataset._wired === '1') return;
      form.dataset._wired = '1';
      form.addEventListener('submit', async (e) => {
        if (form.dataset._confirmed === '1') return; // already confirmed, let it through
        e.preventDefault();
        const ok = await showConfirmModal({
          title:        form.dataset.confirmTitle  || 'Confirmar acción',
          message:      form.dataset.confirm,
          confirmText:  form.dataset.confirmAction || undefined,
          cancelText:   form.dataset.confirmCancel || 'Cancelar',
          variant:      form.dataset.confirmVariant || 'danger',
        });
        if (ok) {
          form.dataset._confirmed = '1';
          form.submit();
        }
      });
    });
  }

  /**
   * Wire buttons with data-confirm (for non-form actions, e.g. links).
   */
  function wireConfirmLinks() {
    document.querySelectorAll('a[data-confirm]').forEach(link => {
      if (link.dataset._wired === '1') return;
      link.dataset._wired = '1';
      link.addEventListener('click', async (e) => {
        e.preventDefault();
        const ok = await showConfirmModal({
          title:        link.dataset.confirmTitle  || 'Confirmar acción',
          message:      link.dataset.confirm,
          confirmText:  link.dataset.confirmAction || undefined,
          variant:      link.dataset.confirmVariant || 'danger',
        });
        if (ok) window.location.href = link.href;
      });
    });
  }

  // ─── DOM ready ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    try {
      const stored = localStorage.getItem('sshmgr_theme');
      if (stored && THEMES.includes(stored) && stored !== getCurrentTheme()) {
        html.setAttribute('data-theme', stored);
      }
    } catch (e) { /* ignore */ }

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) toggleBtn.addEventListener('click', cycleTheme);

    document.querySelectorAll('.theme-swatch').forEach(el => {
      el.addEventListener('click', () => {
        const name = el.dataset.preview;
        applyTheme(name);
        persistTheme(name);
      });
      el.classList.toggle('is-selected', el.dataset.preview === getCurrentTheme());
    });

    // Auto-hide ok/info alerts after 6s (errors stay)
    document.querySelectorAll('.alert-ok, .alert-info').forEach(el => {
      setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
      }, 6000);
    });

    wireConfirmForms();
    wireConfirmLinks();
  });

  // ─── Public API ────────────────────────────────────────────────────
  window.SSHMgr = {
    csrfToken: () => csrfToken,
    applyTheme,
    cycleTheme,
    confirm: showConfirmModal,
    post: async function (action, data = {}) {
      const fd = new FormData();
      fd.set('_csrf', csrfToken);
      for (const k in data) fd.set(k, data[k]);
      const resp = await fetch('?action=' + encodeURIComponent(action), {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const text = await resp.text();
      try { return JSON.parse(text); } catch (_) { return { error: 'Bad response', raw: text }; }
    },
  };
})();
