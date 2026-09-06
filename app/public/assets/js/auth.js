// assets/js/auth.js — KBForms

const KBF_AUTH = {

  getToken() {
    return localStorage.getItem('kbf_token');
  },

  getUser() {
    try {
      return JSON.parse(localStorage.getItem('kbf_user') || 'null');
    } catch {
      return null;
    }
  },

  decodeJWT(token) {
    try {
      const part = token.split('.')[1]
        .replace(/-/g, '+')
        .replace(/_/g, '/');
      const padded = part + '='.repeat((4 - part.length % 4) % 4);
      return JSON.parse(atob(padded));
    } catch {
      return null;
    }
  },

  isTokenExpired(token) {
    try {
      const payload = this.decodeJWT(token);
      if (!payload) return true;
      if (!payload.exp) return false;
      return (Date.now() / 1000) > payload.exp;
    } catch {
      return true;
    }
  },

  // Sauvegarde token + données minimales du JWT,
  // puis enrichit depuis GET /users/{id} pour avoir first_name, last_name, etc.
  save(token, userFromApi = null) {
    localStorage.setItem('kbf_token', token);
    try {
      const payload = this.decodeJWT(token);
      const userId  = payload?.user_id || userFromApi?.id || null;
      const user = {
        id:           userId,
        email:        payload?.email        || userFromApi?.email        || null,
        account_type: payload?.account_type || userFromApi?.account_type || null,
        first_name:   userFromApi?.first_name || null,
        last_name:    userFromApi?.last_name  || null,
        name:         userFromApi?.name || null,
      };
      localStorage.setItem('kbf_user', JSON.stringify(user));
      // Enrichir si profil incomplet
      if (userId && !user.first_name) {
        this._fetchAndStoreProfile(userId, token);
      }
    } catch (e) {
      console.error('[KBF_AUTH.save]', e);
      localStorage.setItem('kbf_user', JSON.stringify({ id: null, email: null, account_type: null }));
    }
  },

  // Appelle GET /users/{id} et met à jour kbf_user avec le profil complet
  async _fetchAndStoreProfile(userId, token) {
    try {
      const res = await fetch(`/users/${userId}`, {
        headers: { 'Authorization': 'Bearer ' + token }
      });
      if (!res.ok) return;
      const data = await res.json();
      const u    = data?.user || data;
      if (!u) return;

      const current = this.getUser() || {};
      const updated = {
        ...current,
        first_name:   u.first_name   || null,
        last_name:    u.last_name    || null,
        name:         [u.first_name, u.last_name].filter(Boolean).join(' ') || u.name || null,
        organization: u.organization || null,
        industry:     u.industry     || null,
        company_size: u.company_size || null,
        country:      u.country      || null,
        phone:        u.phone        || null,
        website:      u.website      || null,
        job_title:    u.job_title    || null,
        avatar_data:  u.avatar_data  || null,
      };
      localStorage.setItem('kbf_user', JSON.stringify(updated));
      // Notifier les composants qui écoutent (ex: settings.html, sidebar)
      window.dispatchEvent(new CustomEvent('kbf:profile-loaded', { detail: updated }));
    } catch (e) {
      console.warn('[KBF_AUTH._fetchAndStoreProfile]', e);
    }
  },

  // Nom complet ou email comme fallback
  getDisplayName() {
    const u = this.getUser();
    if (!u) return '';
    return [u.first_name, u.last_name].filter(Boolean).join(' ')
        || u.name
        || u.email
        || 'Utilisateur';
  },

  clear() {
    localStorage.removeItem('kbf_token');
    localStorage.removeItem('kbf_user');
  },

  requireAuth() {
    const token = this.getToken();

    if (!token) {
      window.location.href = '/login';
      return false;
    }
    if (this.isTokenExpired(token)) {
      this.clear();
      window.location.href = '/login';
      return false;
    }

    const user = this.getUser();
    if (!user || !user.id) {
      try {
        const payload = this.decodeJWT(token);
        if (payload?.user_id) {
          const rebuilt = {
            id:           payload.user_id,
            email:        payload.email        || null,
            account_type: payload.account_type || null,
            first_name:   null,
            last_name:    null,
            name:         null,
          };
          localStorage.setItem('kbf_user', JSON.stringify(rebuilt));
          this._fetchAndStoreProfile(payload.user_id, token);
        }
      } catch (e) {
        console.warn('[KBF_AUTH.requireAuth]', e);
      }
    } else if (!user.first_name) {
      // Profil partiel — enrichir en arrière-plan
      this._fetchAndStoreProfile(user.id, token);
    }

    return true;
  },

  redirectIfLoggedIn() {
    const token = this.getToken();
    if (token && !this.isTokenExpired(token)) {
      window.location.href = '/dashboard';
    }
  }
};

function logout() {
  KBF_AUTH.clear();
  window.location.href = '/login';
}

window.KBF_AUTH = KBF_AUTH;
window.logout   = logout;