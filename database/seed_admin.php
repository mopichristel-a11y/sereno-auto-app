<?php
// ============================================================
//  SERENO SMS — Créer le premier compte administrateur
//  ⚠️  SUPPRIMER CE FICHIER APRÈS UTILISATION
// ============================================================

require_once __DIR__ . '/../config/database.php';

$db = Database::connect();

// Vérifier qu'il n'existe pas déjà un admin
$stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'");
$stmt->execute();
if ($stmt->fetchColumn() > 0) {
    die("❌ Un compte admin existe déjà. Supprimez ce fichier.");
}

$hash = password_hash('Admin@Sereno2026', PASSWORD_BCRYPT, ['cost' => 12]);

// garage_id NULL = super-admin de la plateforme SaaS (console multi-garages).
// Pour un admin limité au garage fondateur, remplacer NULL par 1.
$db->prepare(
    "INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, role, garage_id)
     VALUES (?, ?, ?, ?, ?, 'admin', NULL)"
)->execute(['Mopi', 'Christel', 'admin@sereno-auto.cm', $hash, '+237 6XX XXX XXX']);

echo "✅ Compte super-admin plateforme créé.\n";
echo "   Email    : admin@sereno-auto.cm\n";
echo "   Mot de passe : Admin@Sereno2026\n";
echo "⚠️  CHANGEZ LE MOT DE PASSE IMMÉDIATEMENT ET SUPPRIMEZ CE FICHIER !\n";
