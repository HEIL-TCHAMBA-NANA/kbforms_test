// assets/js/utils.js — Helpers globaux
window.KBF = window.KBF || { cache: {} };

function getQueryParam(name) {
  return new URLSearchParams(window.location.search).get(name);
}

function formatDate(input, withTime = false) {
  if (!input) return '—';
  const d = new Date(input);
  if (isNaN(d)) return String(input);
  const opts = { day: '2-digit', month: 'short', year: 'numeric' };
  if (withTime) { opts.hour = '2-digit'; opts.minute = '2-digit'; }
  return d.toLocaleDateString('fr-FR', opts);
}

function timeAgo(input) {
  const d = new Date(input); if (isNaN(d)) return '';
  const s = Math.floor((Date.now() - d.getTime()) / 1000);
  if (s < 60) return 'à l’instant';
  if (s < 3600) return `il y a ${Math.floor(s/60)} min`;
  if (s < 86400) return `il y a ${Math.floor(s/3600)} h`;
  if (s < 2592000) return `il y a ${Math.floor(s/86400)} j`;
  return formatDate(input);
}

function truncate(str, n = 80) {
  if (!str) return '';
  return str.length > n ? str.slice(0, n - 1) + '…' : str;
}

function debounce(fn, delay = 300) {
  let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); };
}

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

function copyToClipboard(text) {
  // navigator.clipboard indisponible en HTTP (non-secure context) — fallback execCommand
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text)
      .then(() => showToast('Copié dans le presse-papier'))
      .catch(() => _clipboardFallback(text));
  }
  return Promise.resolve(_clipboardFallback(text));
}

function _clipboardFallback(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    showToast('Copié dans le presse-papier');
  } catch {
    window.prompt('Copie ce lien manuellement :', text);
  }
  document.body.removeChild(ta);
}

function showToast(message, type = 'success') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'fixed top-4 right-4 z-50 flex flex-col gap-2';
    document.body.appendChild(container);
  }
  const colors = { success: 'bg-emerald-600', error: 'bg-red-600', info: 'bg-blue-600', warning: 'bg-amber-500' };
  const icons  = { success: 'check', error: 'x', info: 'chat', warning: 'alert-tri' };
  const toast = document.createElement('div');
  toast.className = `${colors[type] || colors.success} text-white text-sm font-medium px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 animate-slide-in`;
  toast.setAttribute('role', 'alert');
  toast.innerHTML = `${kbIcon(icons[type] || 'check', 'w-4 h-4 shrink-0')}<span>${escapeHtml(message)}</span>`;
  container.appendChild(toast);
  setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; }, 3000);
  setTimeout(() => toast.remove(), 3500);
}

function initials(name) {
  if (!name) return '?';
  return name.trim().split(/\s+/).slice(0,2).map(s => s[0].toUpperCase()).join('');
}

// Modal helpers
function openModal(id) { const m = document.getElementById(id); if (m) { m.classList.remove('hidden'); m.classList.add('flex'); } }
function closeModal(id) { const m = document.getElementById(id); if (m) { m.classList.add('hidden'); m.classList.remove('flex'); } }

// ── Icônes SVG (remplacent les emoji) ──────────────────────────────────────
const KB_ICON_PATHS = {
  'doc-text'   : '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/>',
  'doc-lines'  : '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  'radio'      : '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5" fill="currentColor" stroke="none"/>',
  'check-square':'<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/>',
  'list'       : '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/>',
  'calendar'   : '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
  'clock'      : '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  'chart-bar'  : '<path d="M4 20h16"/><rect x="6" y="11" width="3.2" height="7" rx="1"/><rect x="11" y="7" width="3.2" height="11" rx="1"/><rect x="16" y="13" width="3.2" height="5" rx="1"/>',
  'chart-line' : '<path d="M4 19h16"/><path d="M5 15l4-4 3 3 6-7"/><path d="M18 7h-3M18 7v3"/>',
  'grid'       : '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  'phone'      : '<path d="M6.5 3h3l1.5 5-2 1.5a12 12 0 0 0 5 5l1.5-2 5 1.5v3a2 2 0 0 1-2 2A17 17 0 0 1 4.5 5a2 2 0 0 1 2-2Z"/>',
  'mail'       : '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/>',
  'plus'       : '<path d="M12 5v14M5 12h14"/>',
  'x'          : '<path d="M6 6l12 12M18 6L6 18"/>',
  'check'      : '<path d="M5 13l4 4L19 7"/>',
  'pencil'     : '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M14 6l3 3"/>',
  'gear'       : '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 4.6 13.5H4a2 2 0 0 1 0-4h.1A1.6 1.6 0 0 0 5.2 6.9l-.1-.1A2 2 0 1 1 7.9 4l.1.1a1.6 1.6 0 0 0 2.7-1.1V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
  'link'       : '<path d="M10 14a4 4 0 0 0 6 .5l3-3a4 4 0 0 0-5.7-5.7L11.5 8"/><path d="M14 10a4 4 0 0 0-6-.5l-3 3a4 4 0 0 0 5.7 5.7L12.5 16"/>',
  'copy'       : '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
  'trash'      : '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1L18 7M10 11v6M14 11v6"/>',
  'rocket'     : '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 11a5 5 0 0 1 1-3c2.5-4 8-4 8-4s0 5.5-4 8a5 5 0 0 1-3 1L9 11Z"/><path d="M9 11l4 4M15 9h.01"/>',
  'clipboard'  : '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1H9V4Z"/><path d="M9 11h6M9 15h6"/>',
  'shield'     : '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/>',
  'users'      : '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/><path d="M16 5.5a3.2 3.2 0 0 1 0 6M17.5 20c0-2.4-1-4-2.5-4.6"/>',
  'user'       : '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6.8 7-6.8s7 2.9 7 6.8"/>',
  'building'   : '<path d="M4 21V5.5A1.5 1.5 0 0 1 5.5 4h7A1.5 1.5 0 0 1 14 5.5V21"/><path d="M14 9.5h4.5A1.5 1.5 0 0 1 20 11v10"/><path d="M3 21h18M7 8h4M7 12h4M7 16h4M17 13h.01M17 17h.01"/>',
  'lock'       : '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
  'lock-key'   : '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1.3" fill="currentColor" stroke="none"/>',
  'key'        : '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3M16 7l3 3M14 9l2 2"/>',
  'bell'       : '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2H4.5L6 16Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
  'package'    : '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5v-9Z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>',
  'alert-tri'  : '<path d="M12 4.5 21 19.5H3L12 4.5Z"/><path d="M12 10v4M12 17h.01"/>',
  'download'   : '<path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14"/>',
  'palette'    : '<path d="M12 3a9 9 0 1 0 0 18c1.5 0 2-1 2-2s-.5-1.5-.5-2 .5-1 1.5-1H18a3 3 0 0 0 3-3 8 8 0 0 0-9-7Z"/><circle cx="8" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="10" cy="8" r="1.2" fill="currentColor" stroke="none"/><circle cx="14" cy="8" r="1.2" fill="currentColor" stroke="none"/>',
  'save'       : '<path d="M5 4h11l3 3v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/><path d="M8 4v5h7V4M8 21v-6h8v6"/>',
  'refresh'    : '<path d="M4 9a8 8 0 0 1 14-3l2 2M20 15a8 8 0 0 1-14 3l-2-2"/><path d="M20 4v4h-4M4 20v-4h4"/>',
  'external'   : '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
  'arrow-left' : '<path d="M19 12H5M11 6l-6 6 6 6"/>',
  'arrow-right': '<path d="M5 12h14M13 6l6 6-6 6"/>',
  'chevron-up' : '<path d="M6 15l6-6 6 6"/>',
  'chevron-down':'<path d="M6 9l6 6 6-6"/>',
  'sort'       : '<path d="M8 4v16M8 4L4 8M8 4l4 4M16 20V4M16 20l-4-4M16 20l4-4"/>',
  'monitor'    : '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
  'tablet'     : '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M12 17h.01"/>',
  'smartphone' : '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18h2"/>',
  'inbox'      : '<path d="M4 13h4l1.5 3h5L16 13h4"/><path d="M4 13 6 5h12l2 8v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5Z"/>',
  'sparkles'   : '<path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3Z"/><path d="M18 14l.9 2.1L21 17l-2.1.9L18 20l-.9-2.1L15 17l2.1-.9L18 14Z"/>',
  'frown'      : '<circle cx="12" cy="12" r="9"/><path d="M8.5 15.5c1-1.5 2.2-2 3.5-2s2.5.5 3.5 2M9 9.5h.01M15 9.5h.01"/>',
  'chat'       : '<path d="M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9l-4 4V6a1 1 0 0 1 1-1Z"/>',
  'wrench'     : '<path d="M15 7a4 4 0 0 1-5.3 5.3L5 17l2 2 4.7-4.7A4 4 0 0 0 17 9l-2.5.5L13 8l.5-1.5L15 7Z"/>',
  'table'      : '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 10h17M3.5 15h17M9 4.5v15M15 4.5v15"/>',
  'hand-point' : '<path d="M20 12H9M9 12l4-4M9 12l4 4"/><path d="M4 6v12"/>',
};
function kbIcon(name, cls = 'w-4 h-4') {
  return `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${KB_ICON_PATHS[name] || ''}</svg>`;
}
window.kbIcon = kbIcon;

window.getQueryParam = getQueryParam;
window.formatDate = formatDate;
window.timeAgo = timeAgo;
window.truncate = truncate;
window.debounce = debounce;
window.escapeHtml = escapeHtml;
window.copyToClipboard = copyToClipboard;
window.showToast = showToast;
window.initials = initials;
window.openModal = openModal;
window.closeModal = closeModal;
