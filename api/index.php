<?php
// ============================================================
//  SERENO SMS — Point d'entrée API (routeur)
//  Toutes les requêtes passent ici via .htaccess
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Pré-requête CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/BaseController.php';

// ---- Router simple ----
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Supprimer le préfixe /api si présent
$uri = preg_replace('#^/api#', '', $uri);
if ($uri === '') $uri = '/';

/**
 * Table de routage.
 * Chaque entrée : [méthode, motif regex, fichier contrôleur, callable]
 * Les groupes capturés du motif sont passés en arguments.
 */
$routes = [

    // ---------------- AUTH (Module 1) ----------------
    ['POST', '#^/auth/login$#',                  'auth/AuthController.php',        ['AuthController', 'login']],
    ['POST', '#^/auth/register$#',               'auth/AuthController.php',        ['AuthController', 'register']],
    ['POST', '#^/auth/refresh$#',                'auth/AuthController.php',        ['AuthController', 'refresh']],
    ['POST', '#^/auth/logout$#',                 'auth/AuthController.php',        ['AuthController', 'logout']],
    ['GET',  '#^/auth/moi$#',                    'auth/AuthController.php',        ['AuthController', 'moi']],
    ['POST', '#^/auth/changer-mot-de-passe$#',   'auth/AuthController.php',        ['AuthController', 'changerMotDePasse']],

    // ---------------- CLIENTS (Module 2) ----------------
    ['GET',    '#^/clients$#',                   'clients/ClientController.php',   ['ClientController', 'index']],
    ['GET',    '#^/clients/(\d+)$#',             'clients/ClientController.php',   ['ClientController', 'show']],
    ['POST',   '#^/clients$#',                   'clients/ClientController.php',   ['ClientController', 'store']],
    ['PUT',    '#^/clients/(\d+)$#',             'clients/ClientController.php',   ['ClientController', 'update']],
    ['DELETE', '#^/clients/(\d+)$#',             'clients/ClientController.php',   ['ClientController', 'destroy']],

    // ---------------- VÉHICULES (Module 2) ----------------
    ['GET',    '#^/vehicules$#',                 'vehicules/VehiculeController.php', ['VehiculeController', 'index']],
    ['GET',    '#^/vehicules/(\d+)$#',           'vehicules/VehiculeController.php', ['VehiculeController', 'show']],
    ['POST',   '#^/vehicules$#',                 'vehicules/VehiculeController.php', ['VehiculeController', 'store']],
    ['PUT',    '#^/vehicules/(\d+)$#',           'vehicules/VehiculeController.php', ['VehiculeController', 'update']],
    ['DELETE', '#^/vehicules/(\d+)$#',           'vehicules/VehiculeController.php', ['VehiculeController', 'destroy']],
    ['GET',    '#^/vehicules/(\d+)/rappels$#',   'vehicules/VehiculeController.php', ['VehiculeController', 'rappels']],
    ['GET',    '#^/vehicules/(\d+)/carnet$#',    'carnet/CarnetController.php',      ['CarnetController', 'parVehicule']],

    // ---------------- CONTRATS CSA (Module 3) ----------------
    ['GET',    '#^/formules$#',                  'contrats/ContratController.php', ['ContratController', 'formules']],
    ['GET',    '#^/contrats$#',                  'contrats/ContratController.php', ['ContratController', 'index']],
    ['GET',    '#^/contrats/expirations$#',      'contrats/ContratController.php', ['ContratController', 'expirations']],
    ['GET',    '#^/contrats/(\d+)$#',            'contrats/ContratController.php', ['ContratController', 'show']],
    ['POST',   '#^/contrats$#',                  'contrats/ContratController.php', ['ContratController', 'store']],
    ['PUT',    '#^/contrats/(\d+)$#',            'contrats/ContratController.php', ['ContratController', 'update']],
    ['POST',   '#^/contrats/(\d+)/resilier$#',   'contrats/ContratController.php', ['ContratController', 'resilier']],
    ['POST',   '#^/contrats/(\d+)/renouveler$#', 'contrats/ContratController.php', ['ContratController', 'renouveler']],
    ['GET',    '#^/contrats/(\d+)/couverture$#', 'contrats/ContratController.php', ['ContratController', 'couverture']],
    ['GET',    '#^/contrats/(\d+)/paiements$#',  'paiements/PaiementController.php', ['PaiementController', 'parContrat']],

    // ---------------- PAIEMENTS (Module 3) ----------------
    ['GET',    '#^/paiements$#',                 'paiements/PaiementController.php', ['PaiementController', 'index']],
    ['POST',   '#^/paiements$#',                 'paiements/PaiementController.php', ['PaiementController', 'store']],
    ['PUT',    '#^/paiements/(\d+)$#',           'paiements/PaiementController.php', ['PaiementController', 'update']],
    ['DELETE', '#^/paiements/(\d+)$#',           'paiements/PaiementController.php', ['PaiementController', 'destroy']],

    // ---------------- DIAGNOSTICS (Module 4) ----------------
    ['GET',    '#^/diagnostics$#',               'diagnostics/DiagnosticController.php', ['DiagnosticController', 'index']],
    ['GET',    '#^/diagnostics/(\d+)$#',         'diagnostics/DiagnosticController.php', ['DiagnosticController', 'show']],
    ['POST',   '#^/diagnostics$#',               'diagnostics/DiagnosticController.php', ['DiagnosticController', 'store']],
    ['PUT',    '#^/diagnostics/(\d+)$#',         'diagnostics/DiagnosticController.php', ['DiagnosticController', 'update']],
    ['POST',   '#^/diagnostics/(\d+)/devis$#',   'diagnostics/DiagnosticController.php', ['DiagnosticController', 'genererDevis']],

    // ---------------- DEVIS (Module 4) ----------------
    ['GET',    '#^/devis$#',                     'devis/DevisController.php',      ['DevisController', 'index']],
    ['GET',    '#^/devis/(\d+)$#',               'devis/DevisController.php',      ['DevisController', 'show']],
    ['POST',   '#^/devis$#',                     'devis/DevisController.php',      ['DevisController', 'store']],
    ['PUT',    '#^/devis/(\d+)$#',               'devis/DevisController.php',      ['DevisController', 'update']],
    ['POST',   '#^/devis/(\d+)/statut$#',        'devis/DevisController.php',      ['DevisController', 'changerStatut']],
    ['GET',    '#^/devis/(\d+)/pdf$#',           'devis/DevisController.php',      ['DevisController', 'pdf']],

    // ---------------- CARNET D'ENTRETIEN (Module 4) ----------------
    ['GET',    '#^/carnet/rappels$#',            'carnet/CarnetController.php',    ['CarnetController', 'rappels']],
    ['GET',    '#^/carnet/types$#',              'carnet/CarnetController.php',    ['CarnetController', 'types']],
    ['POST',   '#^/carnet$#',                    'carnet/CarnetController.php',    ['CarnetController', 'store']],
    ['PUT',    '#^/carnet/(\d+)$#',              'carnet/CarnetController.php',    ['CarnetController', 'update']],
    ['DELETE', '#^/carnet/(\d+)$#',              'carnet/CarnetController.php',    ['CarnetController', 'destroy']],

    // ---------------- INTERVENTIONS (Module 5) ----------------
    ['GET',    '#^/interventions$#',             'interventions/InterventionController.php', ['InterventionController', 'index']],
    ['GET',    '#^/interventions/(\d+)$#',       'interventions/InterventionController.php', ['InterventionController', 'show']],
    ['POST',   '#^/interventions$#',             'interventions/InterventionController.php', ['InterventionController', 'store']],
    ['PUT',    '#^/interventions/(\d+)$#',       'interventions/InterventionController.php', ['InterventionController', 'update']],
    ['POST',   '#^/interventions/(\d+)/demarrer$#', 'interventions/InterventionController.php', ['InterventionController', 'demarrer']],
    ['POST',   '#^/interventions/(\d+)/cloturer$#', 'interventions/InterventionController.php', ['InterventionController', 'cloturer']],
    ['POST',   '#^/interventions/(\d+)/annuler$#',  'interventions/InterventionController.php', ['InterventionController', 'annuler']],

    // ---------------- RENDEZ-VOUS ----------------
    ['GET',    '#^/rdv$#',                       'rdv/RdvController.php',          ['RdvController', 'index']],
    ['POST',   '#^/rdv$#',                       'rdv/RdvController.php',          ['RdvController', 'store']],
    ['PUT',    '#^/rdv/(\d+)$#',                 'rdv/RdvController.php',          ['RdvController', 'update']],
    ['DELETE', '#^/rdv/(\d+)$#',                 'rdv/RdvController.php',          ['RdvController', 'destroy']],

    // ---------------- NOTIFICATIONS (Module 6) ----------------
    ['GET',    '#^/notifications$#',             'notifications/NotificationController.php', ['NotificationController', 'index']],
    ['POST',   '#^/notifications$#',             'notifications/NotificationController.php', ['NotificationController', 'store']],
    ['POST',   '#^/notifications/tout-lu$#',     'notifications/NotificationController.php', ['NotificationController', 'toutLu']],
    ['POST',   '#^/notifications/(\d+)/lu$#',    'notifications/NotificationController.php', ['NotificationController', 'marquerLu']],
    ['POST',   '#^/notifications/(\d+)/envoyer$#', 'notifications/NotificationController.php', ['NotificationController', 'envoyer']],

    // ---------------- ESPACE CLIENT (/moi/*) ----------------
    ['GET',  '#^/moi/tableau-bord$#',            'moi/ClientPortailController.php', ['ClientPortailController', 'tableauBord']],
    ['GET',  '#^/moi/vehicules$#',               'moi/ClientPortailController.php', ['ClientPortailController', 'vehicules']],
    ['GET',  '#^/moi/contrats$#',                'moi/ClientPortailController.php', ['ClientPortailController', 'contrats']],
    ['GET',  '#^/moi/devis$#',                   'moi/ClientPortailController.php', ['ClientPortailController', 'devis']],
    ['POST', '#^/moi/devis/(\d+)/reponse$#',     'moi/ClientPortailController.php', ['ClientPortailController', 'repondreDevis']],
    ['GET',  '#^/moi/notifications$#',           'moi/ClientPortailController.php', ['ClientPortailController', 'notifications']],
    ['POST', '#^/moi/notifications/(\d+)/lu$#',  'moi/ClientPortailController.php', ['ClientPortailController', 'marquerLu']],
    ['GET',  '#^/moi/rdv$#',                     'moi/ClientPortailController.php', ['ClientPortailController', 'rdv']],
    ['POST', '#^/moi/rdv$#',                     'moi/ClientPortailController.php', ['ClientPortailController', 'demanderRdv']],

    // ---------------- ACCÈS CLIENT ----------------
    ['POST', '#^/clients/(\d+)/creer-acces$#',   'clients/ClientController.php',   ['ClientController', 'creerAcces']],

    // ---------------- PAIEMENTS MOBILE MONEY ----------------
    ['POST', '#^/paiements/mobile/initier$#',          'paiements/MobileMoneyController.php', ['MobileMoneyController', 'initier']],
    ['GET',  '#^/paiements/mobile/statut/(\d+)$#',     'paiements/MobileMoneyController.php', ['MobileMoneyController', 'statut']],
    ['POST', '#^/paiements/mobile/callback/orange$#',  'paiements/MobileMoneyController.php', ['MobileMoneyController', 'callbackOrange']],
    ['POST', '#^/paiements/mobile/callback/mtn$#',     'paiements/MobileMoneyController.php', ['MobileMoneyController', 'callbackMtn']],

    // ---------------- CONSOLE SAAS (super-admin) ----------------
    ['GET',  '#^/saas/plans$#',                  'saas/SaasController.php',        ['SaasController', 'plans']],
    ['GET',  '#^/saas/garages$#',                'saas/SaasController.php',        ['SaasController', 'garages']],
    ['POST', '#^/saas/garages$#',                'saas/SaasController.php',        ['SaasController', 'creerGarage']],
    ['PUT',  '#^/saas/garages/(\d+)$#',          'saas/SaasController.php',        ['SaasController', 'modifierGarage']],
    ['POST', '#^/saas/garages/(\d+)/renouveler$#', 'saas/SaasController.php',      ['SaasController', 'renouveler']],
    ['GET',  '#^/saas/stats$#',                  'saas/SaasController.php',        ['SaasController', 'stats']],

    // ---------------- GARAGES PARTENAIRES ----------------
    ['GET',    '#^/garages$#',                   'garages/GarageController.php',   ['GarageController', 'index']],
    ['POST',   '#^/garages$#',                   'garages/GarageController.php',   ['GarageController', 'store']],
    ['PUT',    '#^/garages/(\d+)$#',             'garages/GarageController.php',   ['GarageController', 'update']],
    ['DELETE', '#^/garages/(\d+)$#',             'garages/GarageController.php',   ['GarageController', 'destroy']],

    // ---------------- STATS & RAPPORTS (Module 7) ----------------
    ['GET',    '#^/stats/dashboard$#',           'stats/StatsController.php',      ['StatsController', 'dashboard']],
    ['GET',    '#^/stats/ca-mensuel$#',          'stats/StatsController.php',      ['StatsController', 'caMensuel']],
    ['GET',    '#^/stats/commerciaux$#',         'stats/StatsController.php',      ['StatsController', 'commerciaux']],
    ['GET',    '#^/stats/rapport-pdf$#',         'stats/StatsController.php',      ['StatsController', 'rapportPdf']],
];

// ---- Dispatch ----
foreach ($routes as [$m, $motif, $fichier, $callable]) {
    if ($method === $m && preg_match($motif, $uri, $captures)) {
        require_once __DIR__ . '/' . $fichier;
        array_shift($captures); // retirer la correspondance complète
        $captures = array_map('intval', $captures);
        call_user_func_array($callable, $captures);
        exit;
    }
}

// ---- Route santé ----
if ($uri === '/sante' && $method === 'GET') {
    echo json_encode([
        'succes'  => true,
        'app'     => APP_NAME,
        'version' => '2.0.0',
        'statut'  => 'opérationnel',
        'heure'   => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// ---- 404 ----
http_response_code(404);
echo json_encode([
    'succes' => false,
    'erreur' => "Route introuvable : $method $uri",
]);
