/* ============================================================
   SERENO SMS — Application dashboard
   Navigation, rendu des panneaux, modals CRUD, actions API
   ============================================================ */

// ---------- Garde d'authentification ----------
if (!API.accessToken) {
  window.location.href = 'login.html';
}

const ETAT = {
  panel: 'dashboard',
  clientsPage: 1,
  clientsRecherche: '',
  vehiculesRecherche: '',
  vehiculeSelectionne: null,
  carnetVehicule: null,
  cacheClients: [],   // pour les selects des modals
  cacheVehicules: [],
};

const PANELS = {
  dashboard:     { titre: 'Tableau de bord',              sub: () => new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) + ' — Vue générale', btn: '＋ Nouveau client',      action: 'nouveau-client' },
  clients:       { titre: 'Gestion des clients',          sub: () => 'Clients enregistrés',           btn: '＋ Nouveau client',      action: 'nouveau-client' },
  vehicules:     { titre: 'Gestion des véhicules',        sub: () => 'Véhicules suivis',              btn: '＋ Nouveau véhicule',    action: 'nouveau-vehicule' },
  contrats:      { titre: 'Contrats CSA',                 sub: () => 'Formules Essentiel · Confort · Premium', btn: '＋ Nouveau contrat', action: 'nouveau-contrat' },
  carnet:        { titre: 'Carnet d\'entretien numérique', sub: () => 'Suivi complet des interventions', btn: '＋ Ajouter entretien',  action: 'nouvel-entretien' },
  diagnostic:    { titre: 'Diagnostics automobiles',      sub: () => 'Codes DTC, devis, suivi',       btn: '＋ Nouveau diagnostic',  action: 'nouveau-diagnostic' },
  interventions: { titre: 'Interventions & Réparations',  sub: () => 'Planification et clôture',      btn: '＋ Planifier',           action: 'nouvelle-intervention' },
  notifications: { titre: 'Notifications & Rappels',      sub: () => 'File d\'attente automatique',   btn: '＋ Notification',        action: 'nouvelle-notification' },
  finances:      { titre: 'Gestion financière',           sub: () => 'CA, paiements, mensualités',    btn: '＋ Paiement',            action: 'nouveau-paiement' },
  stats:         { titre: 'Statistiques & Performance',   sub: () => 'Vision globale et SaaS Afrique', btn: '📄 Rapport PDF',        action: 'rapport-pdf' },
  garages:       { titre: 'Garages partenaires',          sub: () => 'Réseau SERENO — base du SaaS multi-garages', btn: '＋ Ajouter un garage', action: 'nouveau-garage' },
};

// ============================================================
//  UTILITAIRES UI
// ============================================================
function toast(message, type = 'succes') {
  const zone = document.getElementById('toast-zone');
  const el   = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = (type === 'erreur' ? '⚠️ ' : '✓ ') + message;
  zone.appendChild(el);
  setTimeout(() => el.remove(), 4200);
}

function erreurToast(err) {
  toast(err && err.message ? err.message : 'Une erreur est survenue.', 'erreur');
  console.error(err);
}

// ---------- Modal générique ----------
let modalOnValider = null;

function ouvrirModal(titre, corpsHtml, onValider, texteBouton = 'Enregistrer') {
  document.getElementById('modal-titre').textContent = titre;
  document.getElementById('modal-corps').innerHTML = corpsHtml;
  document.getElementById('modal-valider').textContent = texteBouton;
  modalOnValider = onValider;
  document.getElementById('modal-overlay').classList.add('open');
}

function fermerModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  modalOnValider = null;
}

document.getElementById('modal-fermer').onclick  = fermerModal;
document.getElementById('modal-annuler').onclick = fermerModal;
document.getElementById('modal-overlay').addEventListener('click', (e) => {
  if (e.target.id === 'modal-overlay') fermerModal();
});
document.getElementById('modal-valider').onclick = async () => {
  if (!modalOnValider) return;
  const btn = document.getElementById('modal-valider');
  btn.disabled = true;
  try {
    // Un handler peut retourner false pour garder le modal ouvert
    // (ex: il a ouvert un modal de résultat par-dessus).
    const resultat = await modalOnValider();
    if (resultat !== false) fermerModal();
  } catch (err) {
    erreurToast(err);
  } finally {
    btn.disabled = false;
  }
};

const val = (id) => {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
};

function champ(id, label, type = 'text', options = {}) {
  const { placeholder = '', valeur = '', full = false, requis = false } = options;
  return `<div class="form-group${full ? ' full' : ''}">
    <label class="form-label">${label}${requis ? ' *' : ''}</label>
    <input class="form-input" type="${type}" id="${id}" placeholder="${placeholder}" value="${FMT.echap(valeur)}">
  </div>`;
}

function champSelect(id, label, choix, options = {}) {
  const { full = false, valeur = '' } = options;
  const opts = choix.map(c =>
    `<option value="${FMT.echap(c.v)}"${String(c.v) === String(valeur) ? ' selected' : ''}>${FMT.echap(c.t)}</option>`
  ).join('');
  return `<div class="form-group${full ? ' full' : ''}">
    <label class="form-label">${label}</label>
    <select class="form-select" id="${id}">${opts}</select>
  </div>`;
}

function champTextarea(id, label, options = {}) {
  const { placeholder = '', valeur = '' } = options;
  return `<div class="form-group full">
    <label class="form-label">${label}</label>
    <textarea class="form-textarea" id="${id}" placeholder="${placeholder}">${FMT.echap(valeur)}</textarea>
  </div>`;
}

function tableHtml(colonnes, lignesHtml, vide = 'Aucune donnée pour le moment.') {
  if (!lignesHtml) {
    lignesHtml = `<tr><td class="table-vide" colspan="${colonnes.length}">${vide}</td></tr>`;
  }
  return `<table class="data-table">
    <thead><tr>${colonnes.map(c => `<th>${c}</th>`).join('')}</tr></thead>
    <tbody>${lignesHtml}</tbody></table>`;
}

const chargementHtml = '<div class="chargement"><div class="spin"></div><br>Chargement…</div>';

function badgeStatut(statut) {
  const map = {
    'actif': 'green', 'payé': 'green', 'terminé': 'green', 'confirmé': 'green', 'accepté': 'green',
    'en_cours': 'orange', 'en_attente': 'orange', 'suspendu': 'orange', 'envoyé': 'blue',
    'planifié': 'gray', 'brouillon': 'gray',
    'expiré': 'red', 'résilié': 'red', 'échoué': 'red', 'annulé': 'red', 'refusé': 'red',
    'devis_envoyé': 'blue',
  };
  const cls = map[statut] || 'gray';
  return `<span class="badge badge-${cls}">${FMT.echap((statut || '—').replace(/_/g, ' '))}</span>`;
}

const badgeFormule = (f) => {
  const map = { premium: 'green', confort: 'orange', essentiel: 'blue' };
  return f ? `<span class="badge badge-${map[f] || 'gray'}">${f.charAt(0).toUpperCase() + f.slice(1)}</span>`
           : '<span class="badge badge-gray">Aucun</span>';
};

// ============================================================
//  NAVIGATION
// ============================================================
function afficherPanel(nom) {
  ETAT.panel = nom;
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('panel-' + nom);
  if (panel) panel.classList.add('active');

  document.querySelectorAll('.nav-item').forEach(n =>
    n.classList.toggle('active', n.dataset.panel === nom));

  const info = PANELS[nom];
  if (info) {
    document.getElementById('page-title').textContent = info.titre;
    document.getElementById('page-sub').textContent = info.sub();
    const topBtn = document.getElementById('top-btn');
    topBtn.textContent = info.btn;
    topBtn.dataset.action = info.action;
  }

  chargerPanel(nom);
}

document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
  item.addEventListener('click', () => afficherPanel(item.dataset.panel));
});
document.getElementById('btn-cloche').onclick = () => afficherPanel('notifications');

function chargerPanel(nom) {
  const chargeurs = {
    dashboard: chargerDashboard,
    clients: chargerClients,
    vehicules: chargerVehicules,
    contrats: chargerContrats,
    carnet: chargerCarnet,
    diagnostic: chargerDiagnostics,
    interventions: chargerInterventions,
    notifications: chargerNotifications,
    finances: chargerFinances,
    stats: chargerStats,
    garages: chargerGarages,
  };
  (chargeurs[nom] || (() => {}))().catch?.(erreurToast);
}

// ============================================================
//  SESSION UTILISATEUR
// ============================================================
function afficherUtilisateur() {
  const u = API.utilisateur;
  if (!u) return;
  const initiales = ((u.prenom || ' ')[0] + (u.nom || ' ')[0]).toUpperCase();
  document.getElementById('user-avatar').textContent = initiales;
  document.getElementById('user-nom').textContent = `${u.prenom || ''} ${u.nom || ''}`.trim();
  const roles = { admin: 'Administrateur', commercial: 'Commercial', technicien: 'Technicien' };
  document.getElementById('user-role').textContent = roles[u.role] || u.role;
}

document.getElementById('btn-logout').onclick = () => {
  API.logout();
  window.location.href = 'login.html';
};

// ============================================================
//  CACHES (clients / véhicules pour les selects)
// ============================================================
async function rafraichirCaches() {
  const [c, v] = await Promise.all([
    API.get('/clients?limite=100'),
    API.get('/vehicules?limite=100'),
  ]);
  ETAT.cacheClients = c.clients;
  ETAT.cacheVehicules = v.vehicules;
  document.getElementById('badge-clients').textContent = c.total || '';
  document.getElementById('badge-vehicules').textContent = v.total || '';
  return { clients: c.clients, vehicules: v.vehicules };
}

const choixClients = () => [{ v: '', t: '— Choisir un client —' },
  ...ETAT.cacheClients.map(c => ({ v: c.id, t: c.nom + ' (' + c.telephone + ')' }))];

const choixVehicules = (clientId) => [{ v: '', t: '— Choisir un véhicule —' },
  ...ETAT.cacheVehicules
    .filter(v => !clientId || String(v.client_id) === String(clientId))
    .map(v => ({ v: v.id, t: `${v.marque} ${v.modele}` + (v.immatriculation ? ` (${v.immatriculation})` : '') + ' — ' + v.client_nom }))];

// ============================================================
//  DASHBOARD
// ============================================================
async function chargerDashboard() {
  const zone1 = document.getElementById('dash-kpi-1');
  zone1.innerHTML = chargementHtml;

  const [stats, rdv, rappels, expirations, interventions] = await Promise.all([
    API.get('/stats/dashboard'),
    API.get('/rdv?date=today'),
    API.get('/carnet/rappels?limite=6'),
    API.get('/contrats/expirations?jours=30'),
    API.get('/interventions?limite=8'),
  ]);

  const kpi = (icone, valeur, label, trend, classe = '', panel = '') => `
    <div class="kpi-card ${classe}" ${panel ? `onclick="afficherPanel('${panel}')"` : ''}>
      <div class="kpi-icon">${icone}</div>
      <div class="kpi-value">${valeur}</div>
      <div class="kpi-label">${label}</div>
      <div class="kpi-trend ${trend.cls}">${trend.txt}</div>
    </div>`;

  zone1.innerHTML =
    kpi('👥', stats.clients.total, 'Clients actifs',
        { cls: 'trend-up', txt: `↑ +${stats.clients.nouveaux_mois} ce mois` }, '', 'clients') +
    kpi('🚗', stats.vehicules.total, 'Véhicules suivis',
        { cls: 'trend-up', txt: `↑ +${stats.vehicules.nouveaux_mois} ce mois` }, 'orange', 'vehicules') +
    kpi('📋', stats.contrats.actifs, 'Contrats actifs',
        stats.contrats.expirent_bientot > 0
          ? { cls: 'trend-warn', txt: `⚠ ${stats.contrats.expirent_bientot} expirent bientôt` }
          : { cls: 'trend-up', txt: 'Portefeuille sain' }, '', 'contrats') +
    kpi('🛠️', stats.interventions.en_cours, 'Réparations en cours',
        { cls: 'trend-down', txt: `${stats.interventions.planifiees} planifiée(s)` }, 'red', 'interventions');

  document.getElementById('dash-kpi-2').innerHTML =
    kpi('📅', stats.rdv.aujourdhui, 'Rendez-vous aujourd\'hui',
        { cls: 'trend-up', txt: `${stats.rdv.confirmes} confirmé(s)` }, 'blue') +
    kpi('💰', FMT.fcfa(stats.finances.ca_mois), 'CA ce mois (F CFA)',
        { cls: 'trend-up', txt: `CSA ${FMT.fcfa(stats.finances.ca_csa)} + Répar. ${FMT.fcfa(stats.finances.ca_reparations)}` }) +
    kpi('⚠️', rappels.total, 'Alertes entretien',
        { cls: 'trend-warn', txt: 'Vidanges, pneus, batteries…' }, 'orange', 'carnet') +
    kpi('🔍', stats.diagnostics.mois, 'Diagnostics ce mois',
        { cls: 'trend-up', txt: 'Codes DTC analysés' }, '', 'diagnostic');

  // RDV du jour
  document.getElementById('dash-rdv-badge').textContent = rdv.rdv.length + ' aujourd\'hui';
  document.getElementById('dash-rdv').innerHTML = rdv.rdv.length
    ? rdv.rdv.map(r => `
        <div class="rdv-item">
          <div class="rdv-heure">${FMT.heure(r.date_rdv)}</div>
          <div class="rdv-info">
            <div class="rdv-client">${FMT.echap(r.client_nom)} — ${FMT.echap(r.marque + ' ' + r.modele)}</div>
            <div class="rdv-detail">${FMT.echap(r.motif || 'Rendez-vous')}</div>
          </div>
          ${badgeStatut(r.statut)}
        </div>`).join('')
    : '<div class="chargement">Aucun rendez-vous aujourd\'hui.</div>';

  // Alertes = rappels entretien urgents + expirations contrats
  const alertes = [];
  rappels.rappels.slice(0, 4).forEach(r => alertes.push({
    dot: r.urgence === 'depasse' ? 'rouge' : 'orange',
    texte: `<b>${FMT.echap(r.libelle)}</b> — ${FMT.echap(r.client_nom)}<br>${FMT.echap(r.vehicule)} — ` +
      (r.jours_restants != null
        ? (r.jours_restants < 0 ? `dépassé de ${-r.jours_restants} j` : `dans ${r.jours_restants} j`)
        : 'échéance km proche'),
    temps: 'Entretien',
  }));
  expirations.expirations.slice(0, 3).forEach(e => alertes.push({
    dot: e.jours_restants <= 7 ? 'rouge' : 'orange',
    texte: `<b>Contrat CSA</b> — ${FMT.echap(e.client_nom)}<br>${FMT.echap(e.reference)} expire dans ${e.jours_restants} jour(s)`,
    temps: 'Contrat',
  }));

  document.getElementById('dash-alertes-badge').textContent = alertes.length + ' actives';
  document.getElementById('dash-alertes').innerHTML = alertes.length
    ? alertes.map(a => `
        <div class="alerte-item">
          <div class="alerte-dot ${a.dot}"></div>
          <div><div class="alerte-text">${a.texte}</div>
          <div class="alerte-time">${a.temps}</div></div>
        </div>`).join('')
    : '<div class="chargement">Aucune alerte active. 👍</div>';

  // Interventions
  document.getElementById('dash-interventions').innerHTML = tableHtml(
    ['Réf.', 'Client', 'Véhicule', 'Technicien', 'Devis', 'Statut'],
    interventions.interventions.map(i => `
      <tr>
        <td><b>${FMT.echap(i.reference)}</b></td>
        <td>${FMT.echap(i.client_nom)}</td>
        <td>${FMT.echap(i.marque + ' ' + i.modele)}</td>
        <td>${FMT.echap(i.technicien || '—')}</td>
        <td>${i.devis_total ? '<b>' + FMT.fcfaLong(i.devis_total) + '</b>' : '—'}</td>
        <td>${badgeStatut(i.statut)}</td>
      </tr>`).join(''),
    'Aucune intervention. Utilisez « + Nouvelle intervention ».'
  );

  // Cloche notifications
  document.getElementById('notif-dot').classList.toggle('visible', stats.notifications.non_lues > 0);
  document.getElementById('badge-notifs').textContent = stats.notifications.non_lues || '';
}

// ============================================================
//  CLIENTS
// ============================================================
async function chargerClients() {
  const zone = document.getElementById('table-clients');
  zone.innerHTML = chargementHtml;

  const data = await API.get(`/clients?page=${ETAT.clientsPage}&limite=15&recherche=${encodeURIComponent(ETAT.clientsRecherche)}`);
  document.getElementById('clients-count').textContent = data.total + ' client(s)';
  document.getElementById('badge-clients').textContent = data.total || '';

  zone.innerHTML = tableHtml(
    ['Nom', 'Téléphone', 'Véhicule(s)', 'Contrat CSA', 'Expiration', 'Actions'],
    data.clients.map(c => `
      <tr>
        <td><b>${FMT.echap(c.nom)}</b></td>
        <td>${FMT.echap(c.telephone)}</td>
        <td>${FMT.echap(c.vehicules_resume || '—')} <span class="badge badge-gray">${c.nb_vehicules}</span></td>
        <td>${badgeFormule(c.formule_active)}</td>
        <td>${FMT.date(c.prochaine_expiration)}</td>
        <td style="white-space:nowrap;">
          <button class="btn-secondary btn-mini" onclick="modalClient(${c.id})">✏️</button>
          <button class="btn-secondary btn-mini" onclick="voirClient(${c.id})">👁</button>
          ${!c.utilisateur_id ? `<button class="btn-secondary btn-mini" title="Créer l'accès espace client" onclick="creerAccesClient(${c.id}, '${FMT.echap(c.nom)}')">🔑</button>` : '<span title="Accès espace client actif">🟢</span>'}
        </td>
      </tr>`).join(''),
    ETAT.clientsRecherche ? 'Aucun client ne correspond à cette recherche.' : 'Aucun client. Créez le premier !'
  );

  // Pagination
  const pag = document.getElementById('pagination-clients');
  pag.innerHTML = data.pages > 1 ? `
    <button ${data.page <= 1 ? 'disabled' : ''} onclick="ETAT.clientsPage--; chargerClients()">← Précédent</button>
    <span>Page ${data.page} / ${data.pages}</span>
    <button ${data.page >= data.pages ? 'disabled' : ''} onclick="ETAT.clientsPage++; chargerClients()">Suivant →</button>` : '';
}

let rechercheTimer = null;
document.getElementById('recherche-clients').addEventListener('input', (e) => {
  clearTimeout(rechercheTimer);
  rechercheTimer = setTimeout(() => {
    ETAT.clientsRecherche = e.target.value;
    ETAT.clientsPage = 1;
    chargerClients().catch(erreurToast);
  }, 350);
});

async function voirClient(id) {
  const data = await API.get('/clients/' + id).catch(erreurToast);
  if (!data) return;
  const c = data.client;
  ouvrirModal('👤 ' + c.nom, `
    <div class="stat-row"><span class="stat-label">Téléphone</span><span class="stat-value">${FMT.echap(c.telephone)}</span></div>
    <div class="stat-row"><span class="stat-label">Email</span><span class="stat-value">${FMT.echap(c.email || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">Adresse</span><span class="stat-value">${FMT.echap(c.adresse || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">Type</span><span class="stat-value">${FMT.echap(c.type_client)}</span></div>
    <div class="stat-row"><span class="stat-label">Client depuis</span><span class="stat-value">${FMT.date(c.created_at)}</span></div>
    <h4 style="margin:14px 0 6px; font-size:13px; color:var(--marine);">🚗 Véhicules (${data.vehicules.length})</h4>
    ${data.vehicules.map(v => `<div class="stat-row"><span class="stat-label">${FMT.echap(v.marque + ' ' + v.modele)}</span>
      <span class="stat-value">${Number(v.kilometrage).toLocaleString('fr-FR')} km</span></div>`).join('') || '<div class="stat-label">Aucun véhicule.</div>'}
    <h4 style="margin:14px 0 6px; font-size:13px; color:var(--marine);">📋 Contrats (${data.contrats.length})</h4>
    ${data.contrats.map(ct => `<div class="stat-row"><span class="stat-label">${FMT.echap(ct.reference)} · ${badgeFormule(ct.formule)}</span>
      <span class="stat-value">${badgeStatut(ct.statut)}</span></div>`).join('') || '<div class="stat-label">Aucun contrat.</div>'}
  `, null, 'Fermer');
  document.getElementById('modal-valider').style.display = 'none';
  document.getElementById('modal-annuler').textContent = 'Fermer';
  const restaurer = () => {
    document.getElementById('modal-valider').style.display = '';
    document.getElementById('modal-annuler').textContent = 'Annuler';
  };
  document.getElementById('modal-annuler').addEventListener('click', restaurer, { once: true });
  document.getElementById('modal-fermer').addEventListener('click', restaurer, { once: true });
}

function modalClient(id = null) {
  const existant = id ? ETAT.cacheClients.find(c => String(c.id) === String(id)) : null;
  ouvrirModal(id ? '✏️ Modifier le client' : '＋ Nouveau client', `
    <div class="form-grid">
      ${champ('f-nom', 'Nom complet / Raison sociale', 'text', { requis: true, valeur: existant?.nom || '', full: true })}
      ${champ('f-telephone', 'Téléphone', 'tel', { requis: true, placeholder: '+237 6XX XXX XXX', valeur: existant?.telephone || '' })}
      ${champ('f-email', 'Email', 'email', { valeur: existant?.email || '' })}
      ${champSelect('f-type', 'Type de client', [
        { v: 'particulier', t: 'Particulier' }, { v: 'entreprise', t: 'Entreprise' },
      ], { valeur: existant?.type_client || 'particulier' })}
      ${champ('f-profession', 'Profession', 'text', { valeur: existant?.profession || '' })}
      ${champ('f-adresse', 'Adresse', 'text', { valeur: existant?.adresse || '', full: true })}
    </div>`,
    async () => {
      const corps = {
        nom: val('f-nom'), telephone: val('f-telephone'),
        email: val('f-email') || null, type_client: val('f-type'),
        profession: val('f-profession') || null, adresse: val('f-adresse') || null,
      };
      if (!corps.nom || !corps.telephone) throw new Error('Nom et téléphone sont requis.');
      if (id) { await API.put('/clients/' + id, corps); toast('Client mis à jour.'); }
      else    { await API.post('/clients', corps);      toast('Client créé.'); }
      await rafraichirCaches();
      if (ETAT.panel === 'clients') chargerClients();
    });
}

function creerAccesClient(id, nom) {
  ouvrirModal('🔑 Créer l\'accès espace client — ' + nom, `
    <p style="font-size:13px; color:var(--sous-texte); margin-bottom:12px;">
      Un compte (rôle client) sera créé avec l'email de la fiche.
      Laissez vide pour générer un mot de passe temporaire.</p>
    ${champ('f-mdp', 'Mot de passe (optionnel, min. 8 caractères)', 'text', { full: true })}`,
    async () => {
      const corps = {};
      if (val('f-mdp')) corps.mot_de_passe = val('f-mdp');
      const r = await API.post(`/clients/${id}/creer-acces`, corps);
      if (r.mot_de_passe_temporaire) {
        ouvrirModal('✅ Accès créé', `
          <p style="font-size:13px; margin-bottom:10px;">Transmettez ces identifiants au client (affichés une seule fois) :</p>
          <div class="stat-row"><span class="stat-label">Email</span><span class="stat-value">${FMT.echap(r.email)}</span></div>
          <div class="stat-row"><span class="stat-label">Mot de passe temporaire</span>
            <span class="stat-value" style="font-family:monospace; font-size:15px; color:var(--orange);">${FMT.echap(r.mot_de_passe_temporaire)}</span></div>`,
          null, 'Fermer');
        document.getElementById('modal-valider').style.display = 'none';
        const restaurer = () => { document.getElementById('modal-valider').style.display = ''; };
        document.getElementById('modal-annuler').addEventListener('click', restaurer, { once: true });
        document.getElementById('modal-fermer').addEventListener('click', restaurer, { once: true });
        await rafraichirCaches();
        chargerClients();
        return false; // le modal de résultat reste affiché
      }
      toast('Accès espace client créé.');
      await rafraichirCaches();
      chargerClients();
    }, 'Créer l\'accès');
}

// ============================================================
//  VÉHICULES
// ============================================================
async function chargerVehicules() {
  const zone = document.getElementById('liste-vehicules');
  zone.innerHTML = chargementHtml;

  const data = await API.get('/vehicules?limite=50&recherche=' + encodeURIComponent(ETAT.vehiculesRecherche));
  document.getElementById('vehicules-count').textContent = data.total + ' véhicule(s)';

  const icones = { 'électrique': '🔋', 'hybride': '♻️', 'diesel': '🚐', 'essence': '🚗', 'gaz': '🚙' };
  zone.innerHTML = data.vehicules.length
    ? data.vehicules.map(v => `
      <div class="veh-card${String(ETAT.vehiculeSelectionne) === String(v.id) ? ' selected' : ''}" onclick="voirVehicule(${v.id})">
        <div class="veh-icon">${icones[v.carburant] || '🚗'}</div>
        <div class="veh-info">
          <div class="veh-name">${FMT.echap(v.marque + ' ' + v.modele)}</div>
          <div class="veh-sub">${FMT.echap(v.client_nom)}${v.vin ? ' · VIN : ' + FMT.echap(v.vin) : ''}</div>
          <div class="veh-km">${Number(v.kilometrage).toLocaleString('fr-FR')} km · ${FMT.echap(v.carburant)}${v.annee ? ' · ' + v.annee : ''}</div>
        </div>
        ${badgeFormule(v.formule)}
      </div>`).join('')
    : '<div class="chargement">Aucun véhicule enregistré.</div>';
}

document.getElementById('recherche-vehicules').addEventListener('input', (e) => {
  clearTimeout(rechercheTimer);
  rechercheTimer = setTimeout(() => {
    ETAT.vehiculesRecherche = e.target.value;
    chargerVehicules().catch(erreurToast);
  }, 350);
});

async function voirVehicule(id) {
  ETAT.vehiculeSelectionne = id;
  document.querySelectorAll('.veh-card').forEach(c => c.classList.remove('selected'));
  const fiche = document.getElementById('fiche-vehicule');
  fiche.innerHTML = chargementHtml;
  chargerVehicules().catch(() => {});

  const data = await API.get('/vehicules/' + id).catch(erreurToast);
  if (!data) return;
  const v = data.vehicule;
  const urgents = data.rappels.filter(r => ['depasse', 'urgent'].includes(r.urgence));

  fiche.innerHTML = `
    <div style="text-align:center; font-size:44px; margin-bottom:12px;">🚙</div>
    <div style="font-family:'Space Grotesk',sans-serif; font-size:17px; font-weight:700; color:var(--marine); text-align:center; margin-bottom:4px;">
      ${FMT.echap(v.marque + ' ' + v.modele)}</div>
    <div style="text-align:center; margin-bottom:14px;">
      ${urgents.length ? `<span class="badge badge-orange">${urgents.length} entretien(s) à prévoir</span>` : '<span class="badge badge-green">Entretien à jour</span>'}
    </div>
    <div class="stat-row"><span class="stat-label">Propriétaire</span><span class="stat-value">${FMT.echap(v.client_nom)}</span></div>
    <div class="stat-row"><span class="stat-label">Immatriculation</span><span class="stat-value">${FMT.echap(v.immatriculation || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">VIN</span><span class="stat-value" style="font-size:11px;">${FMT.echap(v.vin || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">Kilométrage</span><span class="stat-value">${Number(v.kilometrage).toLocaleString('fr-FR')} km</span></div>
    <div class="stat-row"><span class="stat-label">Carburant</span><span class="stat-value">${FMT.echap(v.carburant)}</span></div>
    <div class="stat-row"><span class="stat-label">Boîte</span><span class="stat-value">${FMT.echap(v.boite_vitesses)}</span></div>
    <div class="stat-row"><span class="stat-label">Contrat CSA</span><span class="stat-value">
      ${data.contrat ? badgeFormule(data.contrat.formule) + ' ' + badgeStatut(data.contrat.statut) : '<span class="badge badge-gray">Aucun</span>'}</span></div>
    <div class="stat-row"><span class="stat-label">Dernier diagnostic</span><span class="stat-value">
      ${data.diagnostics.length ? FMT.date(data.diagnostics[0].date_diagnostic) : '—'}</span></div>
    <div style="display:flex; gap:8px; margin-top:14px;">
      <button class="btn-secondary btn-mini" onclick="modalVehicule(${v.id})">✏️ Modifier</button>
      <button class="btn-secondary btn-mini" onclick="ETAT.carnetVehicule=${v.id}; afficherPanel('carnet')">🔧 Carnet</button>
    </div>`;
}

function modalVehicule(id = null) {
  const existant = id ? ETAT.cacheVehicules.find(v => String(v.id) === String(id)) : null;
  ouvrirModal(id ? '✏️ Modifier le véhicule' : '＋ Nouveau véhicule', `
    <div class="form-grid">
      ${champSelect('f-client', 'Client propriétaire', choixClients(), { full: true, valeur: existant?.client_id || '' })}
      ${champ('f-marque', 'Marque', 'text', { requis: true, valeur: existant?.marque || '', placeholder: 'Toyota' })}
      ${champ('f-modele', 'Modèle', 'text', { requis: true, valeur: existant?.modele || '', placeholder: 'RAV4' })}
      ${champ('f-annee', 'Année', 'number', { valeur: existant?.annee || '' })}
      ${champ('f-immat', 'Immatriculation', 'text', { valeur: existant?.immatriculation || '' })}
      ${champ('f-vin', 'VIN', 'text', { valeur: existant?.vin || '', full: true })}
      ${champ('f-km', 'Kilométrage', 'number', { valeur: existant?.kilometrage ?? '' })}
      ${champSelect('f-carburant', 'Carburant', ['essence', 'diesel', 'électrique', 'hybride', 'gaz'].map(x => ({ v: x, t: x })), { valeur: existant?.carburant || 'essence' })}
      ${champSelect('f-boite', 'Boîte de vitesses', ['manuelle', 'automatique', 'CVT'].map(x => ({ v: x, t: x })), { valeur: existant?.boite_vitesses || 'manuelle' })}
      ${champ('f-couleur', 'Couleur', 'text', { valeur: existant?.couleur || '' })}
    </div>`,
    async () => {
      const corps = {
        marque: val('f-marque'), modele: val('f-modele'),
        annee: val('f-annee') || null, immatriculation: val('f-immat') || null,
        vin: val('f-vin') || null, kilometrage: Number(val('f-km') || 0),
        carburant: val('f-carburant'), boite_vitesses: val('f-boite'),
        couleur: val('f-couleur') || null,
      };
      if (!corps.marque || !corps.modele) throw new Error('Marque et modèle sont requis.');
      if (id) {
        await API.put('/vehicules/' + id, corps);
        toast('Véhicule mis à jour.');
      } else {
        corps.client_id = Number(val('f-client'));
        if (!corps.client_id) throw new Error('Choisissez le client propriétaire.');
        await API.post('/vehicules', corps);
        toast('Véhicule enregistré.');
      }
      await rafraichirCaches();
      if (ETAT.panel === 'vehicules') chargerVehicules();
    });
}

// ============================================================
//  CONTRATS CSA
// ============================================================
async function chargerContrats() {
  const zone = document.getElementById('table-contrats');
  zone.innerHTML = chargementHtml;

  const [data, stats] = await Promise.all([
    API.get('/contrats?limite=30'),
    API.get('/stats/dashboard'),
  ]);

  zone.innerHTML = tableHtml(
    ['Réf.', 'Client', 'Formule', 'Expiration', 'Mensualité', 'Couv.', 'Paiement', 'Statut', 'Actions'],
    data.contrats.map(ct => {
      const exp = ct.jours_avant_expiration;
      const expBadge = ct.statut === 'actif' && exp != null && exp <= 30 && exp >= 0
        ? `<span class="badge badge-red">Expire dans ${exp}j</span>` : badgeStatut(ct.statut);
      return `
      <tr>
        <td><b>${FMT.echap(ct.reference)}</b></td>
        <td>${FMT.echap(ct.client_nom)}<br><span style="font-size:11px;color:var(--sous-texte)">${FMT.echap(ct.marque + ' ' + ct.modele)}</span></td>
        <td>${badgeFormule(ct.formule)}</td>
        <td>${FMT.date(ct.date_expiration)}</td>
        <td>${FMT.fcfaLong(ct.mensualite)}</td>
        <td>${ct.couverture_pct}%</td>
        <td>${ct.echeances.a_jour ? '<span class="badge badge-green">À jour</span>'
             : `<span class="badge badge-orange">${ct.echeances.mensualites_retard} retard</span>`}</td>
        <td>${expBadge}</td>
        <td style="white-space:nowrap;">
          ${ct.statut === 'actif' ? `
            <button class="btn-secondary btn-mini" title="Paiement" onclick="modalPaiement(${ct.id})">💳</button>
            <button class="btn-secondary btn-mini" title="Renouveler" onclick="renouvelerContrat(${ct.id})">🔄</button>
            <button class="btn-secondary btn-mini" title="Résilier" onclick="resilierContrat(${ct.id}, '${FMT.echap(ct.reference)}')">🛑</button>`
          : `<button class="btn-secondary btn-mini" title="Renouveler" onclick="renouvelerContrat(${ct.id})">🔄</button>`}
        </td>
      </tr>`;
    }).join(''),
    'Aucun contrat CSA. Créez le premier !'
  );

  // Répartition
  const rep     = stats.contrats.repartition;
  const totalCt = rep.reduce((s, r) => s + Number(r.nb), 0) || 1;
  const couleurs = { premium: 'var(--sereno)', confort: 'var(--orange)', essentiel: '#3B82F6' };
  const emojis   = { premium: '🟢', confort: '🟠', essentiel: '🔵' };
  document.getElementById('contrats-repartition').innerHTML = rep.length
    ? rep.map(r => `
      <div class="fin-row">
        <div class="fin-label-row">
          <span class="fin-label">${emojis[r.nom] || ''} ${r.nom.charAt(0).toUpperCase() + r.nom.slice(1)}</span>
          <span class="fin-val">${r.nb} contrat(s)</span></div>
        <div class="progress-bar"><div class="progress-fill" style="width:${Math.round(r.nb / totalCt * 100)}%; background:${couleurs[r.nom] || 'var(--gris-bord)'}"></div></div>
      </div>`).join('')
    : '<div class="chargement">Aucun contrat actif.</div>';

  document.getElementById('contrats-mensualites').innerHTML =
    rep.map(r => `<div class="stat-row">
      <span class="stat-label">${r.nom.charAt(0).toUpperCase() + r.nom.slice(1)} (${r.nb})</span>
      <span class="stat-value">${FMT.fcfaLong(r.mensualites)}</span></div>`).join('') +
    `<div class="stat-row" style="margin-top:8px; border-top:2px solid var(--sereno); padding-top:10px;">
      <span class="stat-label" style="font-weight:700; color:var(--marine)">Total mensuel attendu</span>
      <span class="stat-value" style="color:var(--sereno); font-size:16px;">${FMT.fcfaLong(stats.contrats.mensualites_attendues)}</span></div>`;
}

function modalContrat() {
  ouvrirModal('＋ Nouveau contrat CSA', `
    <div class="form-grid">
      ${champSelect('f-client', 'Client', choixClients(), { full: true })}
      ${champSelect('f-vehicule', 'Véhicule', choixVehicules(), { full: true })}
      ${champSelect('f-formule', 'Formule CSA', [
        { v: 'essentiel', t: 'Essentiel — 15 000 F/mois · 40% couverture' },
        { v: 'confort',   t: 'Confort — 28 000 F/mois · 60% couverture' },
        { v: 'premium',   t: 'Premium — 45 000 F/mois · 80% couverture' },
      ], { full: true, valeur: 'confort' })}
      ${champ('f-debut', 'Date de début', 'date', { valeur: new Date().toISOString().slice(0, 10) })}
      ${champ('f-duree', 'Durée (mois)', 'number', { valeur: 12 })}
      ${champTextarea('f-notes', 'Notes (optionnel)')}
    </div>`,
    async () => {
      const clientId = Number(val('f-client'));
      const vehiculeId = Number(val('f-vehicule'));
      if (!clientId || !vehiculeId) throw new Error('Client et véhicule sont requis.');
      const r = await API.post('/contrats', {
        client_id: clientId, vehicule_id: vehiculeId,
        formule: val('f-formule'), date_debut: val('f-debut'),
        duree_mois: Number(val('f-duree') || 12), notes: val('f-notes') || null,
      });
      toast(`Contrat ${r.reference} créé (expire le ${FMT.date(r.date_expiration)}).`);
      if (ETAT.panel === 'contrats') chargerContrats();
    });

  // Filtrer les véhicules quand le client change
  document.getElementById('f-client').addEventListener('change', (e) => {
    const select = document.getElementById('f-vehicule');
    select.innerHTML = choixVehicules(e.target.value)
      .map(c => `<option value="${c.v}">${FMT.echap(c.t)}</option>`).join('');
  });
}

function resilierContrat(id, reference) {
  ouvrirModal('🛑 Résilier le contrat ' + reference, `
    <p style="font-size:13px; color:var(--sous-texte); margin-bottom:12px;">
      Cette action est définitive. Le véhicule ne sera plus couvert.</p>
    ${champTextarea('f-motif', 'Motif de résiliation')}`,
    async () => {
      await API.post(`/contrats/${id}/resilier`, { motif: val('f-motif') });
      toast('Contrat résilié.');
      chargerContrats();
    }, 'Confirmer la résiliation');
}

function renouvelerContrat(id) {
  ouvrirModal('🔄 Renouveler le contrat', `
    <div class="form-grid">
      ${champSelect('f-formule', 'Formule (garder ou changer)', [
        { v: '', t: '— Garder la formule actuelle —' },
        { v: 'essentiel', t: 'Essentiel — 15 000 F · 40%' },
        { v: 'confort',   t: 'Confort — 28 000 F · 60%' },
        { v: 'premium',   t: 'Premium — 45 000 F · 80%' },
      ], { full: true })}
      ${champ('f-duree', 'Durée (mois)', 'number', { valeur: 12, full: true })}
    </div>`,
    async () => {
      const corps = { duree_mois: Number(val('f-duree') || 12) };
      if (val('f-formule')) corps.formule = val('f-formule');
      const r = await API.post(`/contrats/${id}/renouveler`, corps);
      toast(`Nouveau contrat ${r.reference} — du ${FMT.date(r.date_debut)} au ${FMT.date(r.date_expiration)}.`);
      chargerContrats();
    }, 'Renouveler');
}

function modalPaiement(contratId = null) {
  const choixContrats = contratId
    ? null
    : API.get('/contrats?statut=actif&limite=100');

  const corpsHtml = (contrats) => `
    <div class="form-grid">
      ${contrats ? champSelect('f-contrat', 'Contrat', [
          { v: '', t: '— Choisir un contrat —' },
          ...contrats.map(ct => ({ v: ct.id, t: `${ct.reference} — ${ct.client_nom} (${FMT.fcfaLong(ct.mensualite)}/mois)` })),
        ], { full: true }) : ''}
      ${champ('f-montant', 'Montant (F CFA)', 'number', { requis: true })}
      ${champSelect('f-moyen', 'Moyen de paiement', [
        { v: 'orange_money', t: '🟠 Orange Money' },
        { v: 'mtn_momo',     t: '🟡 MTN MoMo' },
        { v: 'espèces',      t: '💵 Espèces' },
        { v: 'virement',     t: '🏦 Virement' },
        { v: 'carte',        t: '💳 Carte' },
      ])}
      ${champ('f-date', 'Date du paiement', 'date', { valeur: new Date().toISOString().slice(0, 10) })}
      ${champ('f-ref', 'Référence transaction', 'text', { placeholder: 'ID Orange Money / MoMo…' })}
      ${champSelect('f-statut', 'Statut', [
        { v: 'payé', t: 'Payé' }, { v: 'en_attente', t: 'En attente' },
      ])}
      <div class="form-group full">
        <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
          <input type="checkbox" id="f-enligne">
          📱 Initier la transaction Mobile Money en ligne (Orange Money / MTN MoMo)
        </label>
      </div>
      ${champ('f-telephone', 'Téléphone payeur (MTN en ligne)', 'tel', { placeholder: '+237 6XX XXX XXX' })}
    </div>`;

  const valider = async () => {
    const contratChoisi = contratId || Number(val('f-contrat'));
    if (!contratChoisi) throw new Error('Choisissez un contrat.');
    const montant = Number(val('f-montant'));
    if (!montant) throw new Error('Montant requis.');

    // Paiement en ligne : initiation via l'API opérateur
    if (document.getElementById('f-enligne')?.checked) {
      const moyen = val('f-moyen');
      if (!['orange_money', 'mtn_momo'].includes(moyen)) {
        throw new Error('Le paiement en ligne nécessite Orange Money ou MTN MoMo.');
      }
      const r = await API.post('/paiements/mobile/initier', {
        contrat_id: contratChoisi, montant,
        operateur: moyen === 'orange_money' ? 'orange' : 'mtn',
        telephone: val('f-telephone') || null,
      });
      if (r.payment_url) {
        ouvrirModal('🟠 Lien de paiement Orange Money', `
          <p style="font-size:13px; margin-bottom:10px;">Transmettez ce lien au client (ou ouvrez-le) pour finaliser le paiement :</p>
          <input class="form-input" readonly value="${FMT.echap(r.payment_url)}" onclick="this.select()">
          <div style="margin-top:12px;">
            <a href="${FMT.echap(r.payment_url)}" target="_blank" class="btn-primary btn-orange" style="text-decoration:none;">Ouvrir la page de paiement</a>
          </div>`, null, 'Fermer');
        document.getElementById('modal-valider').style.display = 'none';
        const restaurer = () => { document.getElementById('modal-valider').style.display = ''; };
        document.getElementById('modal-annuler').addEventListener('click', restaurer, { once: true });
        document.getElementById('modal-fermer').addEventListener('click', restaurer, { once: true });
        return false;
      }
      toast(r.message || 'Demande MoMo envoyée : le client valide sur son téléphone.');
      if (ETAT.panel === 'finances') chargerFinances();
      return;
    }

    // Enregistrement manuel classique
    await API.post('/paiements', {
      contrat_id: contratChoisi, montant,
      moyen: val('f-moyen'), date_paiement: val('f-date'),
      reference_paiement: val('f-ref') || null, statut: val('f-statut'),
    });
    toast('Paiement enregistré.');
    if (ETAT.panel === 'contrats') chargerContrats();
    if (ETAT.panel === 'finances') chargerFinances();
  };

  if (contratId) {
    ouvrirModal('💳 Enregistrer un paiement', corpsHtml(null), valider);
  } else {
    choixContrats.then(d => ouvrirModal('💳 Enregistrer un paiement', corpsHtml(d.contrats), valider))
                 .catch(erreurToast);
  }
}

// ============================================================
//  CARNET D'ENTRETIEN
// ============================================================
async function chargerCarnet() {
  // Remplir le sélecteur de véhicules
  const select = document.getElementById('carnet-vehicule');
  if (!ETAT.cacheVehicules.length) await rafraichirCaches();
  select.innerHTML = '<option value="">Choisir un véhicule…</option>' +
    ETAT.cacheVehicules.map(v =>
      `<option value="${v.id}"${String(ETAT.carnetVehicule) === String(v.id) ? ' selected' : ''}>
        ${FMT.echap(v.marque + ' ' + v.modele + ' — ' + v.client_nom)}</option>`).join('');

  // Rappels globaux
  const rappels = await API.get('/carnet/rappels?limite=10');
  document.getElementById('carnet-rappels').innerHTML = rappels.rappels.length
    ? rappels.rappels.map(r => `
      <div class="alerte-item">
        <div class="alerte-dot ${r.urgence === 'depasse' ? 'rouge' : r.urgence === 'urgent' ? 'orange' : 'vert'}"></div>
        <div>
          <div class="alerte-text"><b>${FMT.echap(r.libelle)}</b> — ${
            r.jours_restants != null
              ? (r.jours_restants < 0 ? 'dépassé de ' + (-r.jours_restants) + ' j' : 'dans ' + r.jours_restants + ' j')
              : 'échéance km'
          }</div>
          <div class="alerte-time">${FMT.echap(r.vehicule)} · ${FMT.echap(r.client_nom)}</div>
        </div>
      </div>`).join('')
    : '<div class="chargement">Aucun rappel urgent. 👍</div>';

  if (ETAT.carnetVehicule) afficherCarnetVehicule(ETAT.carnetVehicule);
}

document.getElementById('carnet-vehicule').addEventListener('change', (e) => {
  ETAT.carnetVehicule = e.target.value || null;
  if (ETAT.carnetVehicule) afficherCarnetVehicule(ETAT.carnetVehicule);
});

const ICONES_ENTRETIEN = {
  vidange: '🛢️', filtre_air: '🔩', filtre_habitacle: '🔩', filtre_carburant: '🔩', filtre_boite: '⚙️',
  plaquettes: '🛑', disques: '🔄', pneus: '🛞', batterie: '🔋', amortisseurs: '🌀',
  courroie: '⛓️', climatisation: '❄️', diagnostic: '🔍', reparation_autre: '🔧',
};

async function afficherCarnetVehicule(id) {
  const zone = document.getElementById('carnet-liste');
  zone.innerHTML = chargementHtml;
  const data = await API.get(`/vehicules/${id}/carnet`).catch(erreurToast);
  if (!data) return;

  document.getElementById('carnet-titre').textContent =
    data.vehicule.marque + ' ' + data.vehicule.modele + ' · ' +
    Number(data.vehicule.kilometrage).toLocaleString('fr-FR') + ' km';

  // Fusionner rappels (état par type) avec historique
  zone.innerHTML = data.rappels.map(r => {
    let etat, cls;
    if (r.urgence === 'inconnu')       { etat = 'Jamais enregistré';                        cls = ''; }
    else if (r.urgence === 'depasse')  { etat = '🔴 Échéance dépassée';                     cls = 'danger'; }
    else if (r.urgence === 'urgent')   { etat = '⚠ ' + (r.jours_restants != null ? 'Dans ' + r.jours_restants + ' j' : 'Km presque atteint'); cls = ''; }
    else if (r.urgence === 'bientot')  { etat = '⚠ ' + (r.jours_restants != null ? 'Dans ' + r.jours_restants + ' j' : 'À surveiller');       cls = ''; }
    else                               { etat = '✓ OK' + (r.jours_restants != null ? ' — dans ' + Math.round(r.jours_restants / 30) + ' mois' : ''); cls = 'ok'; }
    return `
      <div class="carnet-item">
        <div class="carnet-check">${ICONES_ENTRETIEN[r.type] || '🔧'}</div>
        <div class="carnet-label">${FMT.echap(r.libelle)}</div>
        <div style="text-align:right;">
          <div class="carnet-date">${r.derniere_date ? 'Dernière : ' + FMT.date(r.derniere_date) + (r.dernier_km ? ' · ' + Number(r.dernier_km).toLocaleString('fr-FR') + ' km' : '') : 'Aucun historique'}</div>
          <div class="carnet-next ${cls}">${etat}</div>
        </div>
      </div>`;
  }).join('') + `
    <h4 style="margin:16px 0 6px; font-size:12px; text-transform:uppercase; color:var(--sous-texte);">Historique (${data.historique.length})</h4>
    ${data.historique.slice(0, 15).map(h => `
      <div class="carnet-item">
        <div class="carnet-check">${ICONES_ENTRETIEN[h.type_entretien] || '🔧'}</div>
        <div class="carnet-label">${FMT.echap(h.libelle)}
          ${h.intervention_reference ? `<span class="badge badge-gray">${FMT.echap(h.intervention_reference)}</span>` : ''}</div>
        <div class="carnet-date">${FMT.date(h.date_intervention)}${h.kilometrage ? ' · ' + Number(h.kilometrage).toLocaleString('fr-FR') + ' km' : ''}</div>
      </div>`).join('') || '<div class="stat-label">Aucune entrée.</div>'}`;
}

function modalEntretien() {
  ouvrirModal('＋ Ajouter un entretien au carnet', `
    <div class="form-grid">
      ${champSelect('f-vehicule', 'Véhicule', choixVehicules(), { full: true, valeur: ETAT.carnetVehicule || '' })}
      ${champSelect('f-type', 'Type d\'entretien', Object.entries(ICONES_ENTRETIEN).map(([t, i]) =>
        ({ v: t, t: i + ' ' + t.replace(/_/g, ' ') })), { full: true })}
      ${champ('f-date', 'Date', 'date', { valeur: new Date().toISOString().slice(0, 10) })}
      ${champ('f-km', 'Kilométrage relevé', 'number')}
      ${champTextarea('f-notes', 'Notes')}
    </div>
    <p style="font-size:12px; color:var(--sous-texte); margin-top:10px;">
      💡 La prochaine échéance (km + date) est calculée automatiquement selon les intervalles constructeur.</p>`,
    async () => {
      const vehiculeId = Number(val('f-vehicule'));
      if (!vehiculeId) throw new Error('Choisissez un véhicule.');
      const corps = {
        vehicule_id: vehiculeId, type_entretien: val('f-type'),
        date_intervention: val('f-date'), notes: val('f-notes') || null,
      };
      if (val('f-km')) corps.kilometrage = Number(val('f-km'));
      const r = await API.post('/carnet', corps);
      toast('Entretien ajouté. Prochaine échéance : ' +
        (r.prochaine_date ? FMT.date(r.prochaine_date) : '—') +
        (r.prochain_km ? ' / ' + Number(r.prochain_km).toLocaleString('fr-FR') + ' km' : ''));
      ETAT.carnetVehicule = vehiculeId;
      if (ETAT.panel === 'carnet') chargerCarnet();
    });
}

// ============================================================
//  DIAGNOSTICS & DEVIS
// ============================================================
async function chargerDiagnostics() {
  const zone = document.getElementById('table-diagnostics');
  zone.innerHTML = chargementHtml;

  const [diags, devis] = await Promise.all([
    API.get('/diagnostics?limite=20'),
    API.get('/devis?limite=20'),
  ]);

  zone.innerHTML = tableHtml(
    ['Date', 'Réf.', 'Client', 'Véhicule', 'Outil', 'Codes DTC', 'Devis', 'Action'],
    diags.diagnostics.map(d => `
      <tr>
        <td>${FMT.date(d.date_diagnostic)}</td>
        <td><b>${FMT.echap(d.reference)}</b></td>
        <td>${FMT.echap(d.client_nom)}</td>
        <td>${FMT.echap(d.marque + ' ' + d.modele)}</td>
        <td>${FMT.echap(d.outil || '—')}</td>
        <td><span class="badge badge-${d.nb_codes > 10 ? 'red' : d.nb_codes > 0 ? 'orange' : 'green'}">${d.nb_codes} code(s)</span></td>
        <td>${d.devis_total ? FMT.fcfaLong(d.devis_total) + ' ' + badgeStatut(d.devis_statut) : '—'}</td>
        <td>${!d.devis_id ? `<button class="btn-secondary btn-mini" onclick="modalGenererDevis(${d.id})">🧾 Devis</button>` : ''}
            <button class="btn-secondary btn-mini" onclick="voirDiagnostic(${d.id})">👁</button></td>
      </tr>`).join(''),
    'Aucun diagnostic enregistré.'
  );

  document.getElementById('table-devis').innerHTML = tableHtml(
    ['Réf.', 'Client', 'Véhicule', 'Total', 'CSA', 'Statut', 'Actions'],
    devis.devis.map(d => `
      <tr>
        <td><b>${FMT.echap(d.reference)}</b></td>
        <td>${FMT.echap(d.client_nom)}</td>
        <td>${FMT.echap(d.marque + ' ' + d.modele)}</td>
        <td><b>${FMT.fcfaLong(d.total)}</b></td>
        <td>—</td>
        <td>${badgeStatut(d.statut)}</td>
        <td style="white-space:nowrap;">
          ${d.statut === 'brouillon' ? `<button class="btn-secondary btn-mini" onclick="changerStatutDevis(${d.id}, 'envoyé')">📤 Envoyer</button>` : ''}
          ${d.statut === 'envoyé' ? `
            <button class="btn-secondary btn-mini" onclick="changerStatutDevis(${d.id}, 'accepté')">✅</button>
            <button class="btn-secondary btn-mini" onclick="changerStatutDevis(${d.id}, 'refusé')">❌</button>` : ''}
          <button class="btn-secondary btn-mini" onclick="API.telecharger('/devis/${d.id}/pdf', 'devis-${FMT.echap(d.reference)}.pdf').catch(erreurToast)">📄 PDF</button>
        </td>
      </tr>`).join(''),
    'Aucun devis.'
  );
}

async function voirDiagnostic(id) {
  const data = await API.get('/diagnostics/' + id).catch(erreurToast);
  if (!data) return;
  const d = data.diagnostic;
  ouvrirModal('🔍 ' + d.reference, `
    <div class="stat-row"><span class="stat-label">Véhicule</span><span class="stat-value">${FMT.echap(d.marque + ' ' + d.modele)}</span></div>
    <div class="stat-row"><span class="stat-label">Client</span><span class="stat-value">${FMT.echap(d.client_nom)}</span></div>
    <div class="stat-row"><span class="stat-label">Technicien</span><span class="stat-value">${FMT.echap(d.technicien || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">Outil</span><span class="stat-value">${FMT.echap(d.outil || '—')}</span></div>
    <div class="stat-row"><span class="stat-label">Kilométrage</span><span class="stat-value">${Number(d.kilometrage || 0).toLocaleString('fr-FR')} km</span></div>
    <h4 style="margin:14px 0 6px; font-size:13px; color:var(--marine);">Codes DTC (${d.codes_dtc.length})</h4>
    ${d.codes_dtc.map(c => `<div class="stat-row">
      <span class="stat-label"><b>${FMT.echap(c.code)}</b> — ${FMT.echap(c.description || '')}</span>
      <span class="stat-value">${FMT.echap(c.statut || '')}</span></div>`).join('') || '<div class="stat-label">Aucun code.</div>'}
    ${d.commentaires ? `<h4 style="margin:14px 0 6px; font-size:13px; color:var(--marine);">Commentaires</h4>
      <p style="font-size:13px; color:var(--sous-texte);">${FMT.echap(d.commentaires)}</p>` : ''}
  `, null, 'Fermer');
  document.getElementById('modal-valider').style.display = 'none';
  const restaurer = () => { document.getElementById('modal-valider').style.display = ''; };
  document.getElementById('modal-annuler').addEventListener('click', restaurer, { once: true });
  document.getElementById('modal-fermer').addEventListener('click', restaurer, { once: true });
}

function modalDiagnostic() {
  ouvrirModal('＋ Nouveau diagnostic', `
    <div class="form-grid">
      ${champSelect('f-vehicule', 'Véhicule', choixVehicules(), { full: true })}
      ${champ('f-outil', 'Outil de diagnostic', 'text', { placeholder: 'Launch X431, Car Scanner OBD2…' })}
      ${champ('f-km', 'Kilométrage relevé', 'number')}
      ${champTextarea('f-codes', 'Codes DTC — un par ligne : CODE ; description',
        { placeholder: 'P0301 ; Raté d\'allumage cylindre 1\nB1B9016 ; Tension batterie basse' })}
      ${champTextarea('f-commentaires', 'Commentaires du technicien')}
    </div>`,
    async () => {
      const vehiculeId = Number(val('f-vehicule'));
      if (!vehiculeId) throw new Error('Choisissez un véhicule.');
      const codes = document.getElementById('f-codes').value
        .split('\n').map(l => l.trim()).filter(Boolean)
        .map(l => {
          const [code, ...desc] = l.split(';');
          return { code: code.trim(), description: desc.join(';').trim(), statut: 'actif' };
        });
      const corps = {
        vehicule_id: vehiculeId, outil: val('f-outil') || null,
        codes_dtc: codes, commentaires: val('f-commentaires') || null,
      };
      if (val('f-km')) corps.kilometrage = Number(val('f-km'));
      const r = await API.post('/diagnostics', corps);
      toast('Diagnostic ' + r.reference + ' enregistré (' + codes.length + ' code(s)).');
      if (ETAT.panel === 'diagnostic') chargerDiagnostics();
    });
}

function modalGenererDevis(diagnosticId) {
  ouvrirModal('🧾 Générer un devis depuis le diagnostic', `
    ${champTextarea('f-lignes', 'Lignes du devis — une par ligne : désignation ; montant',
      { placeholder: 'Remplacement amortisseurs arrière ; 180000\nBatterie 12V ; 85000' })}
    <div class="form-grid" style="margin-top:12px;">
      ${champ('f-mo', 'Main d\'oeuvre (%)', 'number', { valeur: 10 })}
      ${champ('f-validite', 'Validité (jours)', 'number', { valeur: 30 })}
    </div>`,
    async () => {
      const lignes = document.getElementById('f-lignes').value
        .split('\n').map(l => l.trim()).filter(Boolean)
        .map(l => {
          const idx = l.lastIndexOf(';');
          if (idx === -1) throw new Error('Format : désignation ; montant — ligne invalide : ' + l);
          return { designation: l.slice(0, idx).trim(), montant: Number(l.slice(idx + 1).trim()) };
        });
      if (!lignes.length) throw new Error('Ajoutez au moins une ligne.');
      const r = await API.post(`/diagnostics/${diagnosticId}/devis`, {
        lignes, main_oeuvre_pct: Number(val('f-mo') || 10),
        validite_jours: Number(val('f-validite') || 30),
      });
      toast(`Devis ${r.reference} généré — Total ${FMT.fcfaLong(r.total)}.`);
      chargerDiagnostics();
    }, 'Générer le devis');
}

async function changerStatutDevis(id, statut) {
  try {
    const r = await API.post(`/devis/${id}/statut`, { statut });
    toast('Devis ' + statut + '.' + (r.intervention_id ? ' Intervention créée automatiquement.' : ''));
    chargerDiagnostics();
  } catch (err) { erreurToast(err); }
}

// ============================================================
//  INTERVENTIONS
// ============================================================
async function chargerInterventions() {
  const zone = document.getElementById('table-interventions');
  zone.innerHTML = chargementHtml;
  const data = await API.get('/interventions?limite=30');

  zone.innerHTML = tableHtml(
    ['Réf.', 'Client', 'Véhicule', 'Technicien', 'Devis', 'Début', 'Statut', 'Actions'],
    data.interventions.map(i => `
      <tr>
        <td><b>${FMT.echap(i.reference)}</b></td>
        <td>${FMT.echap(i.client_nom)}</td>
        <td>${FMT.echap(i.marque + ' ' + i.modele)}</td>
        <td>${FMT.echap(i.technicien || '—')}</td>
        <td>${i.devis_total ? FMT.fcfaLong(i.devis_total) : '—'}</td>
        <td>${FMT.dateHeure(i.date_debut)}</td>
        <td>${badgeStatut(i.statut)}</td>
        <td style="white-space:nowrap;">
          ${i.statut === 'planifié' ? `<button class="btn-primary btn-mini" onclick="demarrerIntervention(${i.id})">▶ Démarrer</button>` : ''}
          ${['planifié', 'en_cours'].includes(i.statut) ? `<button class="btn-primary btn-orange btn-mini" onclick="modalCloturer(${i.id})">✔ Clôturer</button>` : ''}
        </td>
      </tr>`).join(''),
    'Aucune intervention.'
  );
}

async function demarrerIntervention(id) {
  try {
    await API.post(`/interventions/${id}/demarrer`);
    toast('Intervention démarrée.');
    chargerInterventions();
  } catch (err) { erreurToast(err); }
}

function modalIntervention() {
  ouvrirModal('＋ Planifier une intervention', `
    <div class="form-grid">
      ${champSelect('f-vehicule', 'Véhicule', choixVehicules(), { full: true })}
      ${champ('f-date', 'Date prévue', 'datetime-local')}
    </div>`,
    async () => {
      const vehiculeId = Number(val('f-vehicule'));
      if (!vehiculeId) throw new Error('Choisissez un véhicule.');
      const corps = { vehicule_id: vehiculeId };
      if (val('f-date')) corps.date_debut = val('f-date').replace('T', ' ') + ':00';
      const r = await API.post('/interventions', corps);
      toast('Intervention ' + r.reference + ' planifiée.');
      if (['interventions', 'dashboard'].includes(ETAT.panel)) chargerPanel(ETAT.panel);
    });
}

function modalCloturer(id) {
  const types = Object.entries(ICONES_ENTRETIEN).filter(([t]) => t !== 'diagnostic');
  ouvrirModal('✔ Clôturer l\'intervention', `
    ${champTextarea('f-rapport', 'Rapport du technicien *', { placeholder: 'Travaux effectués, pièces remplacées, observations…' })}
    <div class="form-group" style="margin-top:10px;">
      ${champ('f-km', 'Kilométrage relevé', 'number')}
    </div>
    <label class="form-label" style="display:block; margin:12px 0 6px;">Entretiens réalisés (cochez → ajout au carnet + calcul prochaine échéance)</label>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; max-height:180px; overflow-y:auto;">
      ${types.map(([t, icone]) => `
        <label style="display:flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer;">
          <input type="checkbox" class="chk-entretien" value="${t}"> ${icone} ${t.replace(/_/g, ' ')}
        </label>`).join('')}
    </div>`,
    async () => {
      const rapport = val('f-rapport');
      if (!rapport) throw new Error('Le rapport est requis.');
      const entretiens = [...document.querySelectorAll('.chk-entretien:checked')]
        .map(c => ({ type_entretien: c.value }));
      const corps = { rapport, entretiens };
      if (val('f-km')) corps.kilometrage = Number(val('f-km'));
      const r = await API.post(`/interventions/${id}/cloturer`, corps);
      toast('Intervention clôturée. ' + (r.carnet.length ? r.carnet.length + ' entrée(s) ajoutée(s) au carnet.' : ''));
      chargerInterventions();
    }, 'Clôturer');
}

// ============================================================
//  NOTIFICATIONS
// ============================================================
async function chargerNotifications() {
  const zone = document.getElementById('liste-notifications');
  zone.innerHTML = chargementHtml;
  const data = await API.get('/notifications?limite=30');

  document.getElementById('notifs-badge').textContent = data.non_lues + ' non lue(s)';
  document.getElementById('badge-notifs').textContent = data.non_lues || '';
  document.getElementById('notif-dot').classList.toggle('visible', data.non_lues > 0);

  const iconesType = {
    vidange: ['🛢️', 'orange'], filtre: ['🔩', 'orange'], pneus: ['🛞', 'orange'],
    batterie: ['🔋', 'orange'], assurance: ['🛡️', 'orange'], visite_technique: ['📋', 'orange'],
    csa_expiration: ['⚠️', 'red'], paiement: ['💰', 'red'], autre: ['🔔', 'green'],
  };

  zone.innerHTML = data.notifications.length
    ? data.notifications.map(n => {
        const [icone, couleur] = iconesType[n.type] || ['🔔', 'green'];
        return `
        <div class="notif-item${n.lu == 0 ? ' unread' : ''}" onclick="marquerLu(${n.id}, ${n.lu})">
          <div class="notif-icon-circle ${couleur}">${icone}</div>
          <div class="notif-content">
            <div class="notif-title">${FMT.echap(n.titre)}</div>
            <div class="notif-body">${FMT.echap(n.message).replace(/\n/g, '<br>')}</div>
            <div class="notif-meta">📅 ${FMT.dateHeure(n.created_at)}
              ${n.client_nom ? ' · ' + FMT.echap(n.client_nom) : ''}
              · Canal : ${FMT.echap(n.canal)}
              ${n.envoye == 1 ? ' · ✓ Envoyée' : ' · ⏳ En file'}
              ${n.erreur_envoi ? ' · ⚠ ' + FMT.echap(n.erreur_envoi) : ''}</div>
          </div>
        </div>`;
      }).join('')
    : '<div class="chargement">Aucune notification. Le cron des rappels alimentera cette liste.</div>';
}

async function marquerLu(id, dejaLu) {
  if (dejaLu == 1) return;
  try {
    await API.post(`/notifications/${id}/lu`);
    chargerNotifications();
  } catch (err) { erreurToast(err); }
}

function modalNotification() {
  ouvrirModal('＋ Notification manuelle', `
    <div class="form-grid">
      ${champSelect('f-client', 'Client destinataire', choixClients(), { full: true })}
      ${champSelect('f-type', 'Type', ['vidange', 'filtre', 'pneus', 'batterie', 'assurance', 'visite_technique', 'csa_expiration', 'paiement', 'autre'].map(t => ({ v: t, t })), {})}
      ${champSelect('f-canal', 'Canaux', [
        { v: 'app', t: 'Application seulement' },
        { v: 'whatsapp,app', t: 'WhatsApp + App' },
        { v: 'sms,app', t: 'SMS + App' },
        { v: 'email,app', t: 'Email + App' },
        { v: 'whatsapp,sms,email,app', t: 'Tous les canaux' },
      ])}
      ${champ('f-titre', 'Titre', 'text', { full: true, requis: true })}
      ${champTextarea('f-message', 'Message *')}
    </div>`,
    async () => {
      const clientId = Number(val('f-client'));
      if (!clientId) throw new Error('Choisissez un client.');
      if (!val('f-titre') || !val('f-message')) throw new Error('Titre et message requis.');
      await API.post('/notifications', {
        client_id: clientId, type: val('f-type'), canal: val('f-canal'),
        titre: val('f-titre'), message: val('f-message'),
        envoyer_maintenant: true,
      });
      toast('Notification créée et envoi déclenché.');
      if (ETAT.panel === 'notifications') chargerNotifications();
    }, 'Créer & envoyer');
}

// ============================================================
//  FINANCES
// ============================================================
async function chargerFinances() {
  const zone = document.getElementById('fin-kpi');
  zone.innerHTML = chargementHtml;

  const [stats, ca, paiements] = await Promise.all([
    API.get('/stats/dashboard'),
    API.get('/stats/ca-mensuel'),
    API.get('/paiements?limite=8'),
  ]);

  const f = stats.finances;
  zone.innerHTML = `
    <div class="kpi-card"><div class="kpi-icon">💰</div>
      <div class="kpi-value" style="font-size:22px;">${FMT.fcfa(f.ca_mois)}</div>
      <div class="kpi-label">CA ${new Date().toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}</div>
      <div class="kpi-trend trend-up">CSA + réparations</div></div>
    <div class="kpi-card orange"><div class="kpi-icon">📋</div>
      <div class="kpi-value" style="font-size:22px;">${FMT.fcfa(stats.contrats.mensualites_attendues)}</div>
      <div class="kpi-label">Mensualités CSA attendues</div>
      <div class="kpi-trend trend-up">${stats.contrats.actifs} contrats actifs</div></div>
    <div class="kpi-card"><div class="kpi-icon">🛠️</div>
      <div class="kpi-value" style="font-size:22px;">${FMT.fcfa(f.ca_reparations)}</div>
      <div class="kpi-label">Facturation réparations</div>
      <div class="kpi-trend trend-up">Devis acceptés ce mois</div></div>
    <div class="kpi-card red"><div class="kpi-icon">⏳</div>
      <div class="kpi-value" style="font-size:22px;">${FMT.fcfa(f.paiements_attente.somme)}</div>
      <div class="kpi-label">Paiements en attente</div>
      <div class="kpi-trend trend-warn">${f.paiements_attente.nb} paiement(s)</div></div>`;

  // Graphe CA
  const mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
  const max = Math.max(...ca.ca_mensuel.map(m => m.ca_total), 1);
  const moisActuel = new Date().getMonth();
  document.getElementById('fin-chart').innerHTML = ca.ca_mensuel.map((m, i) => `
    <div class="bar-col">
      <div class="bar${i === moisActuel ? ' orange' : ''}"
           style="height:${Math.max(2, Math.round(m.ca_total / max * 100))}%"
           title="${mois[i]} : ${FMT.fcfaLong(m.ca_total)}"></div>
      <div class="bar-label">${mois[i]}</div>
    </div>`).join('');

  // Derniers paiements
  const iconesStatut = { 'payé': '✓', 'en_attente': '⏳', 'échoué': '✗' };
  const couleursStatut = { 'payé': 'var(--sereno)', 'en_attente': 'var(--or)', 'échoué': 'var(--rouge)' };
  document.getElementById('fin-paiements').innerHTML = paiements.paiements.length
    ? paiements.paiements.map(p => `
      <div class="stat-row">
        <span class="stat-label">${FMT.echap(p.client_nom)} — ${FMT.echap(p.moyen.replace(/_/g, ' '))} · ${FMT.date(p.date_paiement)}</span>
        <span class="stat-value" style="color:${couleursStatut[p.statut]};">${FMT.fcfaLong(p.montant)} ${iconesStatut[p.statut]}</span>
      </div>`).join('')
    : '<div class="chargement">Aucun paiement enregistré.</div>';
}

// ============================================================
//  STATS
// ============================================================
async function chargerStats() {
  const zone = document.getElementById('stats-perf');
  zone.innerHTML = chargementHtml;

  const [dash, commerciaux, contrats] = await Promise.all([
    API.get('/stats/dashboard'),
    API.get('/stats/commerciaux'),
    API.get('/contrats?limite=100'),
  ]);

  // Indicateurs calculés
  const tous     = contrats.contrats;
  const actifs   = tous.filter(c => c.statut === 'actif');
  const aJour    = actifs.filter(c => c.echeances.a_jour).length;
  const txPaiement = actifs.length ? Math.round(aJour / actifs.length * 100) : 100;
  const renouvele  = tous.filter(c => (c.notes || '').includes('Renouvellement')).length;
  const txRenouv   = tous.length ? Math.min(100, Math.round(renouvele / Math.max(1, tous.length - actifs.length) * 100)) : 0;

  const barre = (label, pct, couleur = 'var(--sereno)') => `
    <div class="fin-row">
      <div class="fin-label-row"><span class="fin-label">${label}</span><span class="fin-val">${pct}%</span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:${pct}%; background:${couleur}"></div></div>
    </div>`;

  zone.innerHTML =
    barre('Taux de paiement à temps', txPaiement) +
    barre('Contrats actifs / portefeuille', tous.length ? Math.round(actifs.length / tous.length * 100) : 0) +
    barre('Taux de renouvellement CSA', txRenouv, 'var(--orange)') +
    `<div class="stat-row" style="margin-top:10px;">
      <span class="stat-label">Contrats expirant sous 30 jours</span>
      <span class="stat-value">${dash.contrats.expirent_bientot}</span></div>`;

  const medailles = ['🥇', '🥈', '🥉'];
  document.getElementById('stats-commerciaux').innerHTML =
    commerciaux.commerciaux.filter(c => c.contrats_vendus > 0).map((c, i) => `
      <div class="stat-row">
        <span class="stat-label">${medailles[i] || ''} ${FMT.echap(c.commercial)}</span>
        <span class="stat-value" ${i === 0 ? 'style="color:var(--sereno);"' : ''}>${c.contrats_vendus} contrat(s) · ${FMT.fcfa(c.mensualites_generees)}/mois</span>
      </div>`).join('') || '<div class="chargement">Aucune vente enregistrée.</div>';
}

// ============================================================
//  GARAGES PARTENAIRES
// ============================================================
async function chargerGarages() {
  const zone = document.getElementById('table-garages');
  zone.innerHTML = chargementHtml;
  const data = await API.get('/garages');

  zone.innerHTML = tableHtml(
    ['Garage', 'Adresse', 'Téléphone', 'Spécialités', 'Note', 'Statut', 'Actions'],
    data.garages.map(g => `
      <tr>
        <td><b>${FMT.echap(g.nom)}</b></td>
        <td>${FMT.echap(g.adresse || '—')}</td>
        <td>${FMT.echap(g.telephone || '—')}</td>
        <td>${g.specialites.map(s => `<span class="badge badge-gray" style="margin:1px;">${FMT.echap(s)}</span>`).join(' ') || '—'}</td>
        <td>${g.note > 0 ? '⭐ ' + Number(g.note).toFixed(1) : '—'}</td>
        <td>${g.actif == 1 ? '<span class="badge badge-green">Actif</span>' : '<span class="badge badge-gray">Inactif</span>'}</td>
        <td style="white-space:nowrap;">
          <button class="btn-secondary btn-mini" onclick="modalGarage(${g.id})">✏️</button>
          ${g.actif == 1 ? `<button class="btn-secondary btn-mini" title="Désactiver" onclick="desactiverGarage(${g.id})">🚫</button>` : ''}
        </td>
      </tr>`).join(''),
    'Aucun garage partenaire. Ajoutez le premier pour bâtir le réseau !'
  );
  ETAT.cacheGarages = data.garages;
}

function modalGarage(id = null) {
  const existant = id ? (ETAT.cacheGarages || []).find(g => String(g.id) === String(id)) : null;
  ouvrirModal(id ? '✏️ Modifier le garage' : '＋ Nouveau garage partenaire', `
    <div class="form-grid">
      ${champ('f-nom', 'Nom du garage', 'text', { requis: true, full: true, valeur: existant?.nom || '' })}
      ${champ('f-adresse', 'Adresse', 'text', { full: true, valeur: existant?.adresse || '' })}
      ${champ('f-telephone', 'Téléphone', 'tel', { valeur: existant?.telephone || '' })}
      ${champ('f-email', 'Email', 'email', { valeur: existant?.email || '' })}
      ${champ('f-specialites', 'Spécialités (séparées par des virgules)', 'text',
        { full: true, valeur: (existant?.specialites || []).join(', '), placeholder: 'Électricité auto, Climatisation, Carrosserie' })}
      ${id ? champ('f-note', 'Note (0 à 5)', 'number', { valeur: existant?.note || 0 }) : ''}
    </div>`,
    async () => {
      const corps = {
        nom: val('f-nom'), adresse: val('f-adresse') || null,
        telephone: val('f-telephone') || null, email: val('f-email') || null,
        specialites: val('f-specialites').split(',').map(s => s.trim()).filter(Boolean),
      };
      if (!corps.nom) throw new Error('Le nom du garage est requis.');
      if (id) {
        if (val('f-note') !== '') corps.note = Math.min(5, Math.max(0, Number(val('f-note'))));
        await API.put('/garages/' + id, corps);
        toast('Garage mis à jour.');
      } else {
        await API.post('/garages', corps);
        toast('Garage partenaire ajouté.');
      }
      chargerGarages();
    });
}

async function desactiverGarage(id) {
  try {
    await API.supprimer('/garages/' + id);
    toast('Garage désactivé.');
    chargerGarages();
  } catch (err) { erreurToast(err); }
}

// ============================================================
//  ACTIONS GLOBALES (boutons data-action)
// ============================================================
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const actions = {
    'nouveau-client':        () => modalClient(),
    'nouveau-vehicule':      () => modalVehicule(),
    'nouveau-contrat':       () => modalContrat(),
    'nouvel-entretien':      () => modalEntretien(),
    'nouveau-diagnostic':    () => modalDiagnostic(),
    'nouvelle-intervention': () => modalIntervention(),
    'nouvelle-notification': () => modalNotification(),
    'nouveau-paiement':      () => modalPaiement(),
    'nouveau-garage':        () => modalGarage(),
    'tout-lu':               async () => {
      await API.post('/notifications/tout-lu').catch(erreurToast);
      toast('Toutes les notifications sont lues.');
      chargerNotifications();
    },
    'rapport-pdf':           () => {
      const d = new Date();
      API.telecharger(`/stats/rapport-pdf?mois=${d.getMonth() + 1}&annee=${d.getFullYear()}`,
        `rapport-sereno-${d.getFullYear()}-${d.getMonth() + 1}.pdf`)
        .then(() => toast('Rapport PDF téléchargé.'))
        .catch(erreurToast);
    },
  };
  (actions[btn.dataset.action] || (() => {}))();
});

// ============================================================
//  DÉMARRAGE
// ============================================================
(async function demarrer() {
  afficherUtilisateur();
  try {
    await rafraichirCaches();
    await chargerDashboard();
  } catch (err) {
    erreurToast(err);
  }
  afficherPanel('dashboard');
})();
