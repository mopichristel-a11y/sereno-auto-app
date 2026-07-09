# 🛡️ SERENO AUTO — Management System (SMS)

Plateforme de gestion intelligente de véhicules et contrats d'entretien automobile (CSA),
basée à Yaoundé, Cameroun. Conçue pour l'hébergement mutualisé **Hostinger**
(PHP 8.2+ / MySQL 8), sans framework ni dépendance externe.

## ✨ Modules

| # | Module | Fichiers principaux |
|---|--------|--------------------|
| 1 | **Authentification** — JWT maison, 4 rôles (admin, commercial, technicien, client), refresh tokens | `config/jwt.php`, `middleware/auth.php`, `api/auth/` |
| 2 | **Clients & Véhicules** — CRUD, recherche, moteur de rappels (vidange, pneus, batterie, courroie…) | `api/clients/`, `api/vehicules/` |
| 3 | **Contrats CSA** — Essentiel (15 000 F / 40%), Confort (28 000 F / 60%), Premium (45 000 F / 80%) · paiements Orange Money / MTN MoMo · alertes J-30/15/7/1 · calcul de couverture | `api/contrats/`, `api/paiements/` |
| 4 | **Diagnostics & Carnet** — codes DTC (JSON), photos, génération de devis, carnet numérique avec prochaine échéance auto (km + date) | `api/diagnostics/`, `api/devis/`, `api/carnet/` |
| 5 | **Interventions** — planifier / démarrer / clôturer, rapport technicien, liaison automatique au carnet | `api/interventions/` |
| 6 | **Notifications** — file d'attente, WhatsApp (CallMeBot), SMS (passerelle HTTP), Email, cron Hostinger | `api/notifications/`, `services/NotificationService.php`, `cron/rappels.php` |
| 7 | **Rapports & Stats** — KPIs, CA mensuel, performance commerciaux, export PDF (générateur intégré sans dépendance) | `api/stats/`, `lib/PdfMinimal.php` |
| 8 | **Frontend** — dashboard HTML/CSS/JS vanilla : login, KPIs, clients, véhicules, contrats, carnet, diagnostics, interventions, notifications, finances, statistiques | `public/` |
| 9 | **Espace client** — portail dédié (rôle client) : véhicules, contrats, devis à accepter/refuser, demandes de RDV, notifications, paiement en ligne | `api/moi/`, `public/client.html` |
| 10 | **Paiements en ligne** — Orange Money Web Payment (redirection) et MTN MoMo Collections (validation sur téléphone), callbacks avec re-vérification serveur | `services/MobileMoneyService.php`, `api/paiements/MobileMoneyController.php` |
| 11 | **Garages partenaires** — CRUD du réseau (base du SaaS multi-garages) | `api/garages/` |

## 🚀 Déploiement sur Hostinger

### 1. Base de données
1. hPanel → **Bases de données MySQL** → créer une base + un utilisateur.
2. phpMyAdmin → importer `database/schema.sql`.
3. (Migration depuis la v1.0 uniquement : exécuter `database/migration_v1_1.sql`.)

### 2. Fichiers
Téléverser **tout le dépôt** dans `public_html/` (les dossiers sensibles
`config/`, `database/`, `services/`, `lib/`, `middleware/` sont bloqués par le `.htaccess` racine).

### 3. Variables d'environnement
hPanel → **Avancé → PHP Configuration** (ou `.htaccess` `SetEnv`) :

```
DB_HOST=localhost
DB_NAME=uXXXXXX_sereno
DB_USER=uXXXXXX_sereno
DB_PASS=********
JWT_SECRET=une-chaine-aleatoire-de-40-caracteres-minimum
APP_URL=https://votre-domaine.com
WHATSAPP_API_KEY=cle_callmebot        # optionnel
SMS_API_URL=https://...               # optionnel
MAIL_FROM=contact@votre-domaine.com
SERENO_TELEPHONE=+237 6XX XXX XXX

# Orange Money Web Payment (developer.orange.com)
OM_CONSUMER_KEY=base64(client_id:client_secret)
OM_MERCHANT_KEY=votre_merchant_key

# MTN MoMo Collections (momodeveloper.mtn.com)
MOMO_SUBSCRIPTION_KEY=...
MOMO_API_USER=uuid_api_user
MOMO_API_KEY=api_key
MOMO_ENVIRONMENT=sandbox              # ou mtncameroon en production
MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com
```
Sans ces clés, les paiements mobiles en ligne renvoient une erreur explicite ;
l'enregistrement manuel des paiements reste disponible.

### 4. Compte administrateur
Exécuter une fois puis **supprimer** le fichier :
```
php database/seed_admin.php
```
→ `admin@sereno-auto.cm` / `Admin@Sereno2026` (à changer immédiatement).

Données de test (environnement de démo uniquement) : `php database/seed_demo.php`.

### 5. Cron des rappels
hPanel → **Avancé → Tâches Cron** — tous les jours à 7h :
```
0 7 * * * php /home/USER/domains/DOMAINE/public_html/cron/rappels.php
```
Le cron : génère les alertes d'expiration CSA (J-30/15/7/1), les rappels
d'entretien (dépassés ou ≤ 15 j / ≤ 1000 km), les relances de paiement,
passe les contrats échus en `expiré`, puis envoie la file de notifications.

## 🔌 API — aperçu des routes

Toutes les réponses : `{ "succes": true|false, "message"?, "data"?, "erreur"? }`.
Authentification : header `Authorization: Bearer <access_token>`.

```
POST /api/auth/login | /refresh | /logout | /register (admin)
GET  /api/auth/moi

GET|POST        /api/clients          GET|PUT|DELETE /api/clients/{id}
GET|POST        /api/vehicules        GET|PUT|DELETE /api/vehicules/{id}
GET             /api/vehicules/{id}/rappels · /api/vehicules/{id}/carnet

GET             /api/formules
GET|POST        /api/contrats         GET|PUT /api/contrats/{id}
POST            /api/contrats/{id}/resilier · /renouveler
GET             /api/contrats/{id}/couverture?montant=X · /paiements
GET             /api/contrats/expirations?jours=30
GET|POST        /api/paiements        PUT|DELETE /api/paiements/{id}

GET|POST        /api/diagnostics      GET|PUT /api/diagnostics/{id}
POST            /api/diagnostics/{id}/devis
GET|POST        /api/devis            GET|PUT /api/devis/{id}
POST            /api/devis/{id}/statut     GET /api/devis/{id}/pdf

GET             /api/carnet/types · /api/carnet/rappels
POST            /api/carnet           PUT|DELETE /api/carnet/{id}

GET|POST        /api/interventions    GET|PUT /api/interventions/{id}
POST            /api/interventions/{id}/demarrer · /cloturer · /annuler

GET|POST        /api/rdv              PUT|DELETE /api/rdv/{id}

GET|POST        /api/notifications
POST            /api/notifications/{id}/lu · /tout-lu · /{id}/envoyer

GET  /api/stats/dashboard · /ca-mensuel · /commerciaux · /rapport-pdf
GET  /api/sante

# Espace client (rôle client)
GET  /api/moi/tableau-bord · /vehicules · /contrats · /devis · /notifications · /rdv
POST /api/moi/devis/{id}/reponse {reponse: accepter|refuser}
POST /api/moi/rdv · /api/moi/notifications/{id}/lu
POST /api/clients/{id}/creer-acces        (équipe : crée le compte client lié)

# Paiement mobile en ligne
POST /api/paiements/mobile/initier {contrat_id, montant, operateur: orange|mtn, telephone?}
GET  /api/paiements/mobile/statut/{paiement_id}
POST /api/paiements/mobile/callback/orange · /callback/mtn   (webhooks opérateurs)

# Garages partenaires
GET|POST /api/garages    PUT|DELETE /api/garages/{id}
```

## 🎨 Frontend

- `public/login.html` — connexion (JWT, refresh automatique, routage par rôle)
- `public/index.html` — dashboard équipe, 11 panneaux
- `public/client.html` — espace client (accueil, véhicules, contrats + paiement mobile, devis, notifications)
- `public/paiement-retour.html` — page de retour Orange Money
- `public/js/api.js` — couche API (tokens, refresh, téléchargements PDF)
- `public/js/app.js` — rendu, modals CRUD, actions
- Design : marine `#0D1B2A` · vert Sereno `#1A6B3C` · orange `#E07B39` · Space Grotesk + Inter

Si l'API n'est pas sur le même domaine, définir avant `api.js` :
```html
<script>window.SERENO_API_BASE = 'https://api.votre-domaine.com/api';</script>
```

## 🌍 Roadmap SaaS
Phase 1 : Cameroun (Yaoundé, Douala) — Phase 2 (2027) : Côte d'Ivoire, Sénégal — Phase 3 (2028+) : SaaS multi-pays Afrique de l'Ouest.
