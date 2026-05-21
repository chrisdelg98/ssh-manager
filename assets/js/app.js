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

    // Update any open theme picker selection
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

  // ─── DOM ready ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    // Hydrate theme from localStorage if it differs from server-rendered one
    // (covers the brief moment after a user changes theme on another tab)
    try {
      const stored = localStorage.getItem('sshmgr_theme');
      if (stored && THEMES.includes(stored) && stored !== getCurrentTheme()) {
        html.setAttribute('data-theme', stored);
      }
    } catch (e) { /* ignore */ }

    // Topbar theme cycle button
    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) toggleBtn.addEventListener('click', cycleTheme);

    // Settings page theme swatches
    document.querySelectorAll('.theme-swatch').forEach(el => {
      el.addEventListener('click', () => {
        const name = el.dataset.preview;
        applyTheme(name);
        persistTheme(name);
      });
    });

    // Mark currently selected swatch
    document.querySelectorAll('.theme-swatch').forEach(el => {
      el.classList.toggle('is-selected', el.dataset.preview === getCurrentTheme());
    });

    // Auto-hide success/info alerts after 6s (keep errors sticky)
    document.querySelectorAll('.alert-ok, .alert-info').forEach(el => {
      setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
      }, 6000);
    });

    // Confirm-deletion guard for forms with [data-confirm]
    document.querySelectorAll('form[data-confirm]').forEach(form => {
      form.addEventListener('submit', e => {
        if (!confirm(form.dataset.confirm)) e.preventDefault();
      });
    });
  });

  // ─── Public helpers ────────────────────────────────────────────────
  window.SSHMgr = {
    csrfToken: () => csrfToken,
    applyTheme,
    cycleTheme,
    /**
     * Convenience POST helper that auto-injects the CSRF token.
     * Returns the parsed JSON response.
     */
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
