// assets/js/sidebar.js — Sidebar de navigation KBForms
// PHASE 1 FIX: initials() et escapeHtml() supprimées — utiliser celles de utils.js
// IMPORTANT: charger utils.js AVANT sidebar.js

let _kbfSidebarActiveKey = '';
function renderSidebar(activeKey = '') {
  _kbfSidebarActiveKey = activeKey || _kbfSidebarActiveKey;
  activeKey = _kbfSidebarActiveKey;
  const user = KBF_AUTH.getUser() || {};
  const isAdmin = (user.account_type === 'admin');
  const canManageTeam = isAdmin || (user.account_type === 'enterprise');
  const displayName = user.name || user.email || 'Utilisateur';

  const items = [
    { key: 'dashboard', href: '/dashboard',     label: 'Dashboard',       icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h14V10"/>' },
    { key: 'forms',     href: '/forms',          label: 'Mes formulaires', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z"/>' },
    { key: 'analytics', href: '/form-analytics', label: 'Analytics',       icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/>' },
  ];

  if (canManageTeam) {
    items.push({ key: 'roles', href: '/roles', label: 'Rôles & Permissions', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>' });
  }

  // « Application mobile » — visible seulement si une distribution APK est
  // configurée côté serveur (fichier livré ou KBF_MOBILE_APK_URL).
  if (window.__kbfMobileApkUrl) {
    items.push({ key: 'download', href: '/download', label: 'Application mobile', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a1 1 0 001-1V4a1 1 0 00-1-1H8a1 1 0 00-1 1v16a1 1 0 001 1z"/>' });
  }

  const navHtml = items.map(it => {
    const active = it.key === activeKey;
    return `
      <a href="${it.href}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${active ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'}">
        <svg class="w-5 h-5 ${active ? 'text-indigo-600' : 'text-gray-400'}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">${it.icon}</svg>
        <span>${it.label}</span>
      </a>`;
  }).join('');

  // Liens compte en bas de sidebar (séparés du nav principal)
  const accountLinks = [
    { key: 'profile',    href: '/profile',    label: 'Profil',      icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A7 7 0 0112 15a7 7 0 016.879 2.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>' },
    { key: 'settings',   href: '/settings',   label: 'Paramètres',  icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>' },
  ].map(it => {
    const active = it.key === activeKey;
    return `
      <a href="${it.href}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${active ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'}">
        <svg class="w-5 h-5 ${active ? 'text-indigo-600' : 'text-gray-400'}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">${it.icon}</svg>
        <span>${it.label}</span>
      </a>`;
  }).join('');

  const sidebarHtml = `
    <aside id="kbf-sidebar" class="fixed inset-y-0 left-0 w-60 bg-white border-r border-gray-200 flex flex-col z-40 -translate-x-full md:translate-x-0 transition-transform">
      <div class="px-6 py-5 border-b border-gray-200 flex items-center gap-2">
        <img src="/assets/images/kbforms-mark.svg" alt="" class="w-8 h-8">
        <span class="text-lg font-bold text-gray-900" style="font-family:'Plus Jakarta Sans'">KBForms</span>
      </div>

      <!-- Nav principal -->
      <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">${navHtml}</nav>

      <!-- Compte -->
      <div class="border-t border-gray-200 px-3 py-3 space-y-1">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-widest px-2 mb-1">Compte</p>
        ${accountLinks}
      </div>

      <!-- User footer -->
      <div class="border-t border-gray-200 p-3">
        <div class="flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-gray-50">
          ${user.avatar_data
            ? `<img src="${escapeHtml(user.avatar_data)}" alt="" class="w-9 h-9 rounded-full object-cover border border-gray-200">`
            : `<div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold text-sm">${initials(displayName)}</div>`}
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-gray-900 truncate">${escapeHtml(displayName)}</div>
            <div class="text-xs text-gray-500 truncate">${escapeHtml(user.email || '')}</div>
          </div>
          <button onclick="logout()" aria-label="Se déconnecter" class="p-1.5 text-gray-400 hover:text-red-600 rounded-md">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          </button>
        </div>
      </div>
    </aside>
    <div id="kbf-sidebar-backdrop" onclick="toggleSidebar(false)" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden"></div>
  `;

  const mount = document.getElementById('sidebar-mount');
  if (mount) mount.innerHTML = sidebarHtml;
}

function toggleSidebar(force) {
  const s = document.getElementById('kbf-sidebar');
  const b = document.getElementById('kbf-sidebar-backdrop');
  if (!s || !b) return;
  const open = force === undefined ? s.classList.contains('-translate-x-full') : force;
  if (open) { s.classList.remove('-translate-x-full'); b.classList.remove('hidden'); }
  else      { s.classList.add('-translate-x-full');    b.classList.add('hidden'); }
}

function renderTopBar({ title = '', actions = '' } = {}) {
  const mount = document.getElementById('topbar-mount');
  if (!mount) return;
  mount.innerHTML = `
    <header class="sticky top-0 z-20 bg-white border-b border-gray-200 px-4 md:px-8 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-md hover:bg-gray-100" aria-label="Ouvrir le menu">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-lg md:text-xl font-bold text-gray-900" style="font-family:'Plus Jakarta Sans'">${escapeHtml(title)}</h1>
      </div>
      <div class="flex items-center gap-2">${actions}</div>
    </header>`;
}

window.addEventListener('kbf:profile-loaded', () => {
  if (document.getElementById('sidebar-mount')) renderSidebar();
});

// Récupère une fois le lien de l'app mobile puis rafraîchit la sidebar.
(function loadMobileApkLink() {
  if (window.__kbfMobileApkUrl !== undefined) return;
  window.__kbfMobileApkUrl = null;
  fetch('/auth/config').then(r => r.json()).then(c => {
    window.__kbfMobileApkUrl = (c && c.mobile_apk_url) || null;
    if (window.__kbfMobileApkUrl && document.getElementById('sidebar-mount')) renderSidebar();
  }).catch(() => {});
})();

window.renderSidebar = renderSidebar;
window.renderTopBar  = renderTopBar;
window.toggleSidebar = toggleSidebar;
window.initials      = initials;
window.escapeHtml    = escapeHtml;