/* ============================================================
   SERENO SMS — Couche d'accès API
   JWT (access + refresh automatique), helpers HTTP
   ============================================================ */

const API = {
  // Base de l'API : même domaine par défaut (Hostinger : /api à la racine)
  BASE: window.SERENO_API_BASE || '/api',

  // ---------- Tokens ----------
  get accessToken()  { return localStorage.getItem('sereno_access'); },
  get refreshToken() { return localStorage.getItem('sereno_refresh'); },
  get utilisateur()  {
    try { return JSON.parse(localStorage.getItem('sereno_user') || 'null'); }
    catch { return null; }
  },

  stockerSession(data) {
    localStorage.setItem('sereno_access', data.access_token);
    if (data.refresh_token) localStorage.setItem('sereno_refresh', data.refresh_token);
    if (data.utilisateur)   localStorage.setItem('sereno_user', JSON.stringify(data.utilisateur));
    // Mémoriser l'expiration locale (marge de 30 s)
    localStorage.setItem('sereno_expire', String(Date.now() + (data.expire_dans - 30) * 1000));
  },

  tokenValide() {
    return !!this.accessToken && Date.now() < Number(localStorage.getItem('sereno_expire') || 0);
  },

  // ---------- Auth ----------
  async login(email, mot_de_passe) {
    const rep = await fetch(this.BASE + '/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, mot_de_passe }),
    });
    const json = await rep.json().catch(() => ({}));
    if (!rep.ok || !json.succes) throw new Error(json.erreur || 'Identifiants incorrects.');
    this.stockerSession(json.data);
    return json.data;
  },

  async rafraichir() {
    if (!this.refreshToken) return false;
    try {
      const rep = await fetch(this.BASE + '/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: this.refreshToken }),
      });
      const json = await rep.json().catch(() => ({}));
      if (!rep.ok || !json.succes) return false;
      this.stockerSession(json.data);
      return true;
    } catch { return false; }
  },

  logout(appelServeur = true) {
    if (appelServeur && this.accessToken) {
      fetch(this.BASE + '/auth/logout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + this.accessToken,
        },
        body: JSON.stringify({ refresh_token: this.refreshToken }),
      }).catch(() => {});
    }
    ['sereno_access', 'sereno_refresh', 'sereno_user', 'sereno_expire']
      .forEach(k => localStorage.removeItem(k));
  },

  // ---------- Requête générique avec refresh automatique ----------
  async requete(chemin, options = {}, deuxiemeEssai = false) {
    // Renouveler le token s'il approche de l'expiration
    if (!this.tokenValide() && this.refreshToken && !deuxiemeEssai) {
      await this.rafraichir();
    }

    const rep = await fetch(this.BASE + chemin, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(this.accessToken ? { 'Authorization': 'Bearer ' + this.accessToken } : {}),
        ...(options.headers || {}),
      },
    });

    if (rep.status === 401 && !deuxiemeEssai && await this.rafraichir()) {
      return this.requete(chemin, options, true);
    }
    if (rep.status === 401) {
      this.logout(false);
      window.location.href = 'login.html';
      throw new Error('Session expirée.');
    }

    const json = await rep.json().catch(() => ({}));
    if (!rep.ok || json.succes === false) {
      throw new Error(json.erreur || ('Erreur serveur (' + rep.status + ')'));
    }
    return json.data ?? json;
  },

  get(chemin)          { return this.requete(chemin); },
  post(chemin, corps)  { return this.requete(chemin, { method: 'POST', body: JSON.stringify(corps || {}) }); },
  put(chemin, corps)   { return this.requete(chemin, { method: 'PUT',  body: JSON.stringify(corps || {}) }); },
  supprimer(chemin)    { return this.requete(chemin, { method: 'DELETE' }); },

  // ---------- Téléchargement (PDF) ----------
  async telecharger(chemin, nomFichier) {
    if (!this.tokenValide() && this.refreshToken) await this.rafraichir();
    const rep = await fetch(this.BASE + chemin, {
      headers: { 'Authorization': 'Bearer ' + this.accessToken },
    });
    if (!rep.ok) {
      const json = await rep.json().catch(() => ({}));
      throw new Error(json.erreur || 'Téléchargement impossible.');
    }
    const blob = await rep.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = nomFichier;
    a.click();
    URL.revokeObjectURL(url);
  },
};

// ---------- Formatage ----------
const FMT = {
  fcfa(montant) {
    const n = Number(montant || 0);
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(2).replace('.', ',') + 'M';
    return n.toLocaleString('fr-FR').replace(/ /g, ' ');
  },
  fcfaLong(montant) {
    return Number(montant || 0).toLocaleString('fr-FR').replace(/ /g, ' ') + ' F';
  },
  date(d) {
    if (!d) return '—';
    const date = new Date(d.replace(' ', 'T'));
    return isNaN(date) ? '—' : date.toLocaleDateString('fr-FR');
  },
  dateHeure(d) {
    if (!d) return '—';
    const date = new Date(d.replace(' ', 'T'));
    return isNaN(date) ? '—'
      : date.toLocaleDateString('fr-FR') + ' ' +
        date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  },
  heure(d) {
    if (!d) return '—';
    const date = new Date(d.replace(' ', 'T'));
    return isNaN(date) ? '—'
      : date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', 'h');
  },
  echap(texte) {
    const div = document.createElement('div');
    div.textContent = texte == null ? '' : String(texte);
    return div.innerHTML;
  },
};
