// assets/js/api.js — KBForms
// PHASE 1 FIX : gestion erreur HTTP 500 ajoutée
// SESSION FIX  : auto-refresh du token à l'activité (expire si inactif, pas si actif)

const KBF_API = {

  // ── Refresh silencieux ──────────────────────────────────────────────────────
  // Appelé automatiquement quand le token expire dans moins de 20 minutes
  // et que l'utilisateur vient de faire une action (requête API).
  _refreshing: false,

  async _tryRefresh() {
    if (this._refreshing) return; // évite les appels en cascade
    this._refreshing = true;
    try {
      const token = localStorage.getItem('kbf_token');
      if (!token) return;
      const res  = await fetch('/auth/refresh', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token }
      });
      if (res.ok) {
        const data = await res.json();
        if (data?.token) {
          localStorage.setItem('kbf_token', data.token);
          window._sessionWarnShown = false; // reset l'avertissement
        }
      }
      // Si 401 → token vraiment expiré, le watcher s'en occupe
    } catch (_) {
      // Erreur réseau — on ne déconnecte pas, on laisse le watcher décider
    } finally {
      this._refreshing = false;
    }
  },

  // ── Vérifie si un refresh est nécessaire après une requête réussie ──────────
  _checkAndRefresh() {
    try {
      const token = localStorage.getItem('kbf_token');
      if (!token) return;
      const payload   = JSON.parse(atob(token.split('.')[1]));
      const exp       = payload.exp * 1000;
      const remaining = exp - Date.now();
      // Refresh si moins de 20 minutes restantes et utilisateur actif
      if (remaining > 0 && remaining < 20 * 60 * 1000) {
        this._tryRefresh();
      }
    } catch (_) {}
  },

  // ── Requête principale ──────────────────────────────────────────────────────
  async request(method, endpoint, body = null, auth = true) {
    const headers = { 'Content-Type': 'application/json' };

    if (auth) {
      const token = localStorage.getItem('kbf_token');
      if (token) headers['Authorization'] = 'Bearer ' + token;
    }

    const options = { method, headers };
    if (body !== null) options.body = JSON.stringify(body);

    try {
      const res = await fetch(endpoint, options);

      // 401 : token invalide côté serveur
      if (res.status === 401 && auth) {
        const path = window.location.pathname;
        const isLoginPage = path === '/login' || path === '/login.html' || path === '/';
        if (!isLoginPage) {
          localStorage.removeItem('kbf_token');
          localStorage.removeItem('kbf_user');
          window.location.href = '/login';
        }
        return { ok: false, status: 401, data: { error: 'Non authentifié' } };
      }

      // 500 : erreur serveur
      if (res.status >= 500) {
        if (typeof showToast === 'function') showToast('Erreur serveur. Réessayez plus tard.', 'error');
      }

      // Lire la réponse
      let data = null;
      const ct = res.headers.get('content-type') || '';
      if (ct.includes('application/json')) {
        data = await res.json().catch(() => ({}));
      } else {
        data = await res.text().catch(() => '');
      }

      // Refresh préventif à chaque requête authentifiée (peu importe le statut HTTP — l'utilisateur est actif)
      if (auth) this._checkAndRefresh();

      return { ok: res.ok, status: res.status, data };

    } catch (err) {
      console.error('[KBF_API] Erreur réseau:', endpoint, err);
      return { ok: false, status: 0, data: { error: 'Erreur réseau' } };
    }
  },

  get(url, auth = true)  { return this.request('GET',    url, null, auth); },
  post(url, body, auth = true) { return this.request('POST',   url, body, auth); },
  put(url, body)         { return this.request('PUT',    url, body, true); },
  patch(url, body)       { return this.request('PATCH',  url, body, true); },
  delete(url)            { return this.request('DELETE', url, null, true); },

  async download(endpoint, filename) {
    try {
      const token = localStorage.getItem('kbf_token');
      const res = await fetch(endpoint, {
        headers: token ? { 'Authorization': 'Bearer ' + token } : {}
      });
      if (!res.ok) throw new Error('Téléchargement échoué');
      const blob = await res.blob();
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (err) {
      console.error('[KBF_API.download]', err);
      if (typeof showToast === 'function') showToast('Téléchargement échoué', 'error');
    }
  }
};

window.KBF_API = KBF_API;

// ── Session watcher ───────────────────────────────────────────────────────────
// Surveille l'expiration toutes les 60s.
// Refresh déclenché par :
//   1. Toute requête API authentifiée (succès OU erreur HTTP)
//   2. Activité utilisateur : click, keydown, mousemove, touchstart (debounce 30s)
(function initSessionWatcher() {
  function getTokenExp() {
    try {
      const token = localStorage.getItem('kbf_token');
      if (!token) return null;
      const payload = JSON.parse(atob(token.split('.')[1]));
      return payload.exp ? payload.exp * 1000 : null;
    } catch { return null; }
  }

  function checkSession() {
    const exp = getTokenExp();
    if (!exp) return;

    const now       = Date.now();
    const remaining = exp - now;
    const path      = window.location.pathname;
    const isLogin   = path === '/login' || path === '/login.html' || path === '/';
    if (isLogin) return;

    if (remaining <= 0) {
      // Vraiment expiré → déconnexion
      localStorage.removeItem('kbf_token');
      localStorage.removeItem('kbf_user');
      if (typeof showToast === 'function') showToast('Session expirée. Redirection…', 'error');
      setTimeout(() => window.location.href = '/login', 1500);

    } else if (remaining <= 5 * 60 * 1000 && !window._sessionWarnShown) {
      // Moins de 5 minutes et aucun refresh n'a pu renouveler → avertir
      window._sessionWarnShown = true;
      const mins = Math.ceil(remaining / 60000);
      if (typeof showToast === 'function') {
        showToast(`Session inactive — expire dans ${mins} min. Faites une action pour rester connecté.`, 'warning');
      }
    } else if (remaining > 5 * 60 * 1000) {
      window._sessionWarnShown = false;
    }
  }

  // ── Refresh déclenché par l'activité utilisateur ─────────────────────────
  // Un simple mouvement de souris ou un clic suffit pour renouveler le token.
  // Debounce 30s pour ne pas spammer /auth/refresh.
  let _activityTimer = null;
  function onUserActivity() {
    if (_activityTimer) return; // déjà planifié
    _activityTimer = setTimeout(() => {
      _activityTimer = null;
      KBF_API._checkAndRefresh();
    }, 30 * 1000);
    // Déclencher aussi immédiatement si le token est vraiment proche de l'expiration
    const exp = getTokenExp();
    if (exp && (exp - Date.now()) < 5 * 60 * 1000) {
      KBF_API._checkAndRefresh();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    checkSession();
    setInterval(checkSession, 60 * 1000);

    // Écouter toute activité utilisateur
    ['click', 'keydown', 'mousemove', 'touchstart', 'scroll'].forEach(evt => {
      document.addEventListener(evt, onUserActivity, { passive: true });
    });
  });
})();