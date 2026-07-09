/* ============================================================
   SERENO SMS — Espace client
   Mes véhicules, contrats, devis (accepter/payer), notifications
   ============================================================ */

if (!API.accessToken) window.location.href = 'login.html';

// ---------- UI de base (toast + modal, autonomes) ----------
function toast(message, type = 'succes') {
  const zone = document.getElementById('toast-zone');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = (type === 'erreur' ? '⚠️ ' : '✓ ') + message;
  zone.appendChild(el);
  setTimeout(() => el.remove(), 4200);
}
const erreurToast = (err) => { toast(err?.message || 'Erreur.', 'erreur'); console.error(err); };

let modalOnValider = null;
function ouvrirModal(titre, html, onValider, texteBouton = 'Valider') {
  document.getElementById('modal-titre').textContent = titre;
  document.getElementById('modal-corps').innerHTML = html;
  document.getElementById('modal-valider').textContent = texteBouton;
  document.getElementById('modal-valider').style.display = onValider ? '' : 'none';
  modalOnValider = onValider;
  document.getElementById('modal-overlay').classList.add('open');
}
function fermerModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  document.getElementById('modal-valider').style.display = '';
  modalOnValider = null;
}
document.getElementById('modal-fermer').onclick = fermerModal;
document.getElementById('modal-annuler').onclick = fermerModal;
document.getElementById('modal-overlay').addEventListener('click', e => {
  if (e.target.id === 'modal-overlay') fermerModal();
});
document.getElementById('modal-valider').onclick = async () => {
  if (!modalOnValider) return;
  const btn = document.getElementById('modal-valider');
  btn.disabled = true;
  try { await modalOnValider(); fermerModal(); }
  catch (err) { erreurToast(err); }
  finally { btn.disabled = false; }
};
const val = (id) => document.getElementById(id)?.value.trim() ?? '';

const chargementHtml = '<div class="chargement"><div class="spin"></div><br>Chargement…</div>';

const URGENCE = {
  depasse: ['rouge', 'Échéance dépassée'],
  urgent:  ['orange', 'À prévoir rapidement'],
  bientot: ['orange', 'À prévoir bientôt'],
};

// ---------- Navigation par onglets ----------
document.querySelectorAll('.client-tab[data-tab]').forEach(tab => {
  tab.addEventListener('click', () => afficherOnglet(tab.dataset.tab));
});
document.getElementById('btn-logout').onclick = () => {
  API.logout();
  window.location.href = 'login.html';
};

function afficherOnglet(nom) {
  document.querySelectorAll('.client-main .panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + nom).classList.add('active');
  document.querySelectorAll('.client-tab[data-tab]').forEach(t =>
    t.classList.toggle('active', t.dataset.tab === nom));
  const chargeurs = {
    accueil: chargerAccueil, vehicules: chargerVehicules,
    contrats: chargerContrats, devis: chargerDevis, notifications: chargerNotifications,
  };
  (chargeurs[nom] || (() => {}))().catch?.(erreurToast);
}

// ============================================================
//  ACCUEIL
// ============================================================
let CACHE_ACCUEIL = null;

async function chargerAccueil() {
  const zone = document.getElementById('tab-accueil');
  zone.innerHTML = chargementHtml;
  const d = await API.get('/moi/tableau-bord');
  CACHE_ACCUEIL = d;

  document.getElementById('badge-devis').style.display = d.devis_en_attente ? '' : 'none';
  document.getElementById('badge-devis').textContent = d.devis_en_attente || '';
  document.getElementById('badge-notifs').style.display = d.notifications_non_lues ? '' : 'none';
  document.getElementById('badge-notifs').textContent = d.notifications_non_lues || '';

  zone.innerHTML = `
    <h2 style="font-family:'Space Grotesk',sans-serif; color:var(--marine); margin-bottom:4px;">
      Bonjour ${FMT.echap(d.client.nom)} 👋</h2>
    <p style="color:var(--sous-texte); font-size:13px; margin-bottom:20px;">
      Bienvenue dans votre espace SERENO AUTO.</p>

    <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);">
      <div class="kpi-card"><div class="kpi-icon">🚗</div>
        <div class="kpi-value">${d.vehicules.length}</div>
        <div class="kpi-label">Véhicule(s) suivi(s)</div></div>
      <div class="kpi-card ${d.contrats.length ? '' : 'red'}"><div class="kpi-icon">📋</div>
        <div class="kpi-value">${d.contrats.length}</div>
        <div class="kpi-label">Contrat(s) CSA actif(s)</div></div>
    </div>

    ${d.devis_en_attente ? `
      <div class="card" style="border-color:var(--orange); margin-bottom:16px;">
        <div class="card-body" style="display:flex; align-items:center; gap:12px;">
          <span style="font-size:26px;">🧾</span>
          <div style="flex:1;">
            <b>${d.devis_en_attente} devis attend${d.devis_en_attente > 1 ? 'ent' : ''} votre réponse.</b>
            <div style="font-size:12px; color:var(--sous-texte);">Consultez le détail et répondez en un clic.</div>
          </div>
          <button class="btn-primary btn-orange btn-mini" onclick="afficherOnglet('devis')">Voir les devis</button>
        </div>
      </div>` : ''}

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><div class="card-title">🔔 Entretiens à prévoir</div></div>
      <div class="card-body">
        ${d.rappels.length ? d.rappels.slice(0, 6).map(r => `
          <div class="alerte-item">
            <div class="alerte-dot ${URGENCE[r.urgence]?.[0] || 'vert'}"></div>
            <div>
              <div class="alerte-text"><b>${FMT.echap(r.libelle)}</b> — ${FMT.echap(r.vehicule)}</div>
              <div class="alerte-time">${URGENCE[r.urgence]?.[1] || ''}${
                r.jours_restants != null
                  ? (r.jours_restants < 0 ? ' · dépassé de ' + (-r.jours_restants) + ' j' : ' · dans ' + r.jours_restants + ' j') : ''
              }</div>
            </div>
          </div>`).join('') : '<div class="chargement">Vos entretiens sont à jour. 👍</div>'}
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">📅 Mes rendez-vous</div>
        <button class="btn-primary btn-mini" onclick="modalRdv()">+ Demander un RDV</button>
      </div>
      <div class="card-body">
        ${d.prochains_rdv.length ? d.prochains_rdv.map(r => `
          <div class="rdv-item">
            <div class="rdv-heure">${FMT.heure(r.date_rdv)}</div>
            <div class="rdv-info">
              <div class="rdv-client">${FMT.date(r.date_rdv)} — ${FMT.echap(r.marque + ' ' + r.modele)}</div>
              <div class="rdv-detail">${FMT.echap(r.motif || '')}</div>
            </div>
            <span class="badge badge-${r.statut === 'confirmé' ? 'green' : 'orange'}">${FMT.echap(r.statut)}</span>
          </div>`).join('') : '<div class="chargement">Aucun rendez-vous à venir.</div>'}
      </div>
    </div>`;
}

function modalRdv() {
  const vehicules = CACHE_ACCUEIL?.vehicules || [];
  ouvrirModal('📅 Demander un rendez-vous', `
    <div class="form-group" style="margin-bottom:12px;">
      <label class="form-label">Véhicule</label>
      <select class="form-select" id="f-vehicule">
        ${vehicules.map(v => `<option value="${v.id}">${FMT.echap(v.marque + ' ' + v.modele)}</option>`).join('')}
      </select>
    </div>
    <div class="form-group" style="margin-bottom:12px;">
      <label class="form-label">Date et heure souhaitées</label>
      <input class="form-input" type="datetime-local" id="f-date">
    </div>
    <div class="form-group">
      <label class="form-label">Motif</label>
      <input class="form-input" type="text" id="f-motif" placeholder="Vidange, bruit suspect, contrôle…">
    </div>`,
    async () => {
      if (!val('f-date')) throw new Error('Choisissez une date.');
      await API.post('/moi/rdv', {
        vehicule_id: Number(val('f-vehicule')),
        date_rdv: val('f-date').replace('T', ' ') + ':00',
        motif: val('f-motif') || null,
      });
      toast('Demande envoyée. SERENO AUTO vous confirmera le créneau.');
      chargerAccueil();
    }, 'Envoyer la demande');
}

// ============================================================
//  MES VÉHICULES
// ============================================================
async function chargerVehicules() {
  const zone = document.getElementById('tab-vehicules');
  zone.innerHTML = chargementHtml;
  const d = await API.get('/moi/vehicules');

  zone.innerHTML = d.vehicules.length ? d.vehicules.map(v => {
    const urgents = v.rappels.filter(r => ['depasse', 'urgent', 'bientot'].includes(r.urgence));
    return `
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <div class="card-title">🚗 ${FMT.echap(v.marque + ' ' + v.modele)}${v.annee ? ' · ' + v.annee : ''}</div>
        <span class="badge badge-gray">${Number(v.kilometrage).toLocaleString('fr-FR')} km</span>
      </div>
      <div class="card-body">
        <div class="stat-row"><span class="stat-label">Immatriculation</span><span class="stat-value">${FMT.echap(v.immatriculation || '—')}</span></div>
        <div class="stat-row"><span class="stat-label">Carburant</span><span class="stat-value">${FMT.echap(v.carburant)}</span></div>
        ${urgents.length ? `
          <h4 style="margin:12px 0 4px; font-size:12px; text-transform:uppercase; color:var(--orange);">Entretiens à prévoir</h4>
          ${urgents.map(r => `
            <div class="stat-row">
              <span class="stat-label">${FMT.echap(r.libelle)}</span>
              <span class="stat-value" style="color:${r.urgence === 'depasse' ? 'var(--rouge)' : 'var(--or)'};">
                ${r.jours_restants != null
                  ? (r.jours_restants < 0 ? 'dépassé' : 'dans ' + r.jours_restants + ' j')
                  : 'km bientôt atteint'}</span>
            </div>`).join('')}` : ''}
        <h4 style="margin:12px 0 4px; font-size:12px; text-transform:uppercase; color:var(--sous-texte);">Derniers entretiens</h4>
        ${v.carnet.length ? v.carnet.slice(0, 5).map(c => `
          <div class="stat-row">
            <span class="stat-label">${FMT.echap(c.libelle)}</span>
            <span class="stat-value" style="font-weight:500;">${FMT.date(c.date_intervention)}</span>
          </div>`).join('') : '<div class="stat-label">Aucun historique.</div>'}
      </div>
    </div>`;
  }).join('') : '<div class="chargement">Aucun véhicule enregistré. Contactez SERENO AUTO.</div>';
}

// ============================================================
//  MES CONTRATS
// ============================================================
async function chargerContrats() {
  const zone = document.getElementById('tab-contrats');
  zone.innerHTML = chargementHtml;
  const d = await API.get('/moi/contrats');

  zone.innerHTML = d.contrats.length ? d.contrats.map(ct => `
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <div class="card-title">📋 ${FMT.echap(ct.reference)} — ${FMT.echap(ct.formule.charAt(0).toUpperCase() + ct.formule.slice(1))}</div>
        <span class="badge badge-${ct.statut === 'actif' ? 'green' : 'red'}">${FMT.echap(ct.statut)}</span>
      </div>
      <div class="card-body">
        <div class="stat-row"><span class="stat-label">Véhicule couvert</span>
          <span class="stat-value">${FMT.echap(ct.marque + ' ' + ct.modele)}</span></div>
        <div class="stat-row"><span class="stat-label">Couverture réparations</span>
          <span class="stat-value" style="color:var(--sereno);">${ct.couverture_pct}%</span></div>
        <div class="stat-row"><span class="stat-label">Mensualité</span>
          <span class="stat-value">${FMT.fcfaLong(ct.mensualite)}</span></div>
        <div class="stat-row"><span class="stat-label">Expire le</span>
          <span class="stat-value">${FMT.date(ct.date_expiration)}
            ${ct.statut === 'actif' && ct.jours_avant_expiration <= 30 && ct.jours_avant_expiration >= 0
              ? `<span class="badge badge-red">dans ${ct.jours_avant_expiration} j</span>` : ''}</span></div>
        <div class="stat-row"><span class="stat-label">État des paiements</span>
          <span class="stat-value">${ct.echeances.a_jour
            ? '<span class="badge badge-green">À jour ✓</span>'
            : `<span class="badge badge-orange">${FMT.fcfaLong(ct.echeances.retard)} à régler</span>`}</span></div>

        ${ct.garanties.length ? `
          <h4 style="margin:12px 0 4px; font-size:12px; text-transform:uppercase; color:var(--sous-texte);">Garanties incluses</h4>
          <div>${ct.garanties.map(g => `<span class="badge badge-green" style="margin:2px;">${FMT.echap(g)}</span>`).join(' ')}</div>` : ''}

        ${ct.statut === 'actif' ? `
          <div style="display:flex; gap:8px; margin-top:14px;">
            <button class="btn-primary btn-orange" onclick="modalPayer(${ct.id}, ${ct.mensualite}, '${FMT.echap(ct.reference)}')">
              📱 Payer ma mensualité</button>
          </div>` : ''}

        ${ct.paiements.length ? `
          <h4 style="margin:14px 0 4px; font-size:12px; text-transform:uppercase; color:var(--sous-texte);">Derniers paiements</h4>
          ${ct.paiements.slice(0, 6).map(p => `
            <div class="stat-row">
              <span class="stat-label">${FMT.date(p.date_paiement)} · ${FMT.echap(p.moyen.replace(/_/g, ' '))}</span>
              <span class="stat-value" style="color:${p.statut === 'payé' ? 'var(--sereno)' : p.statut === 'échoué' ? 'var(--rouge)' : 'var(--or)'};">
                ${FMT.fcfaLong(p.montant)} ${p.statut === 'payé' ? '✓' : p.statut === 'échoué' ? '✗' : '⏳'}</span>
            </div>`).join('')}` : ''}
      </div>
    </div>`).join('') : '<div class="chargement">Aucun contrat. Découvrez nos formules CSA auprès de votre conseiller !</div>';
}

// ---------- Paiement Mobile Money ----------
function modalPayer(contratId, mensualite, reference) {
  ouvrirModal('📱 Payer — ' + reference, `
    <div class="form-group" style="margin-bottom:12px;">
      <label class="form-label">Montant (F CFA)</label>
      <input class="form-input" type="number" id="f-montant" value="${mensualite}">
    </div>
    <div class="form-group" style="margin-bottom:12px;">
      <label class="form-label">Opérateur</label>
      <select class="form-select" id="f-operateur">
        <option value="orange">🟠 Orange Money</option>
        <option value="mtn">🟡 MTN Mobile Money</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Numéro payeur (MTN uniquement)</label>
      <input class="form-input" type="tel" id="f-telephone" placeholder="+237 6XX XXX XXX">
    </div>
    <p style="font-size:12px; color:var(--sous-texte); margin-top:10px;">
      🟠 Orange : vous serez redirigé vers la page de paiement sécurisée.<br>
      🟡 MTN : validez la demande directement sur votre téléphone.</p>`,
    async () => {
      const r = await API.post('/paiements/mobile/initier', {
        contrat_id: contratId,
        montant: Number(val('f-montant')),
        operateur: val('f-operateur'),
        telephone: val('f-telephone') || null,
      });
      if (r.payment_url) {
        toast('Redirection vers Orange Money…');
        window.location.href = r.payment_url;
      } else {
        toast(r.message || 'Validez la demande sur votre téléphone.');
        suivrePaiement(r.paiement_id);
      }
    }, 'Payer maintenant');
}

// Suit une transaction MoMo (polling doux ~2 min max)
async function suivrePaiement(paiementId, tentative = 0) {
  if (tentative > 12) { toast('Vérification en cours — consultez vos paiements dans quelques minutes.'); return; }
  await new Promise(r => setTimeout(r, 10000));
  try {
    const s = await API.get('/paiements/mobile/statut/' + paiementId);
    if (s.final) {
      toast(s.statut === 'payé' ? 'Paiement confirmé ! Merci. 🎉' : 'Paiement ' + s.statut + '.',
            s.statut === 'payé' ? 'succes' : 'erreur');
      chargerContrats();
      return;
    }
  } catch { /* réessaie */ }
  suivrePaiement(paiementId, tentative + 1);
}

// ============================================================
//  MES DEVIS
// ============================================================
async function chargerDevis() {
  const zone = document.getElementById('tab-devis');
  zone.innerHTML = chargementHtml;
  const d = await API.get('/moi/devis');

  zone.innerHTML = d.devis.length ? d.devis.map(dv => `
    <div class="card" style="margin-bottom:16px; ${dv.statut === 'envoyé' ? 'border-color:var(--orange);' : ''}">
      <div class="card-header">
        <div class="card-title">🧾 ${FMT.echap(dv.reference)}</div>
        <span class="badge badge-${{ 'envoyé': 'orange', 'accepté': 'green', 'refusé': 'red' }[dv.statut] || 'gray'}">
          ${FMT.echap(dv.statut)}</span>
      </div>
      <div class="card-body">
        <div class="stat-row"><span class="stat-label">Véhicule</span>
          <span class="stat-value">${FMT.echap(dv.marque + ' ' + dv.modele)}</span></div>
        ${dv.lignes.map(l => `
          <div class="stat-row"><span class="stat-label">${FMT.echap(l.designation)}</span>
            <span class="stat-value" style="font-weight:500;">${FMT.fcfaLong(l.montant)}</span></div>`).join('')}
        <div class="stat-row"><span class="stat-label">Main d'oeuvre (${dv.main_oeuvre_pct}%)</span>
          <span class="stat-value" style="font-weight:500;">${FMT.fcfaLong(dv.main_oeuvre)}</span></div>
        <div class="stat-row" style="border-top:2px solid var(--gris-bord);">
          <span class="stat-label" style="font-weight:700; color:var(--marine);">TOTAL</span>
          <span class="stat-value" style="font-size:15px;">${FMT.fcfaLong(dv.total)}</span></div>
        ${dv.couverture ? `
          <div class="stat-row"><span class="stat-label" style="color:var(--sereno);">
            Pris en charge CSA ${dv.couverture.formule} (${dv.couverture.couverture_pct}%)</span>
            <span class="stat-value" style="color:var(--sereno);">− ${FMT.fcfaLong(dv.couverture.pris_en_charge)}</span></div>
          <div class="stat-row"><span class="stat-label" style="font-weight:700;">Votre reste à charge</span>
            <span class="stat-value" style="color:var(--orange); font-size:15px;">${FMT.fcfaLong(dv.couverture.reste_a_charge)}</span></div>` : ''}
        ${dv.statut === 'envoyé' ? `
          <div style="display:flex; gap:8px; margin-top:14px;">
            <button class="btn-primary" onclick="repondreDevis(${dv.id}, 'accepter')">✅ Accepter le devis</button>
            <button class="btn-secondary" onclick="repondreDevis(${dv.id}, 'refuser')">Refuser</button>
          </div>` : ''}
      </div>
    </div>`).join('') : '<div class="chargement">Aucun devis pour le moment.</div>';
}

async function repondreDevis(id, reponse) {
  try {
    const r = await API.post(`/moi/devis/${id}/reponse`, { reponse });
    toast(reponse === 'accepter'
      ? 'Devis accepté — l\'intervention sera planifiée. 🎉'
      : 'Devis refusé.');
    chargerDevis();
  } catch (err) { erreurToast(err); }
}

// ============================================================
//  NOTIFICATIONS
// ============================================================
async function chargerNotifications() {
  const zone = document.getElementById('tab-notifications');
  zone.innerHTML = chargementHtml;
  const d = await API.get('/moi/notifications');

  const icones = {
    vidange: '🛢️', filtre: '🔩', pneus: '🛞', batterie: '🔋', assurance: '🛡️',
    visite_technique: '📋', csa_expiration: '⚠️', paiement: '💰', autre: '🔔',
  };

  zone.innerHTML = '<div class="card">' + (d.notifications.length
    ? d.notifications.map(n => `
      <div class="notif-item${n.lu == 0 ? ' unread' : ''}" onclick="lireNotif(${n.id}, ${n.lu})">
        <div class="notif-icon-circle ${['csa_expiration', 'paiement'].includes(n.type) ? 'red' : 'orange'}">
          ${icones[n.type] || '🔔'}</div>
        <div class="notif-content">
          <div class="notif-title">${FMT.echap(n.titre)}</div>
          <div class="notif-body">${FMT.echap(n.message).replace(/\n/g, '<br>')}</div>
          <div class="notif-meta">📅 ${FMT.dateHeure(n.created_at)}</div>
        </div>
      </div>`).join('')
    : '<div class="chargement">Aucune notification.</div>') + '</div>';
}

async function lireNotif(id, dejaLu) {
  if (dejaLu == 1) return;
  try {
    await API.post(`/moi/notifications/${id}/lu`);
    chargerNotifications();
    chargerAccueil().catch(() => {});
  } catch (err) { erreurToast(err); }
}

// ============================================================
//  DÉMARRAGE
// ============================================================
chargerAccueil().catch(err => {
  erreurToast(err);
  // Compte sans fiche client liée → retour login
  if (String(err.message).includes('Aucune fiche client')) {
    setTimeout(() => { API.logout(); window.location.href = 'login.html'; }, 3500);
  }
});
