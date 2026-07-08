<?php
// ============================================================
//  SERENO SMS — Générateur PDF minimal (sans dépendance)
//  Suffisant pour devis et rapports texte. Police Helvetica.
//  Compatible tout hébergement mutualisé (aucune extension requise).
// ============================================================

class PdfMinimal {

    private array $lignes = [];   // [texte, taille, gras, colonneDroite|null]
    private const LARGEUR  = 595; // A4 points
    private const HAUTEUR  = 842;
    private const MARGE    = 50;

    public function titre(string $texte): void {
        $this->lignes[] = [$texte, 16, true, null];
        $this->lignes[] = ['', 6, false, null]; // espace
    }

    public function ligne(string $texte, int $taille = 11, bool $gras = false): void {
        $this->lignes[] = [$texte, $taille, $gras, null];
    }

    public function deuxColonnes(string $gauche, string $droite, bool $gras = false): void {
        $this->lignes[] = [$gauche, 11, $gras, $droite];
    }

    public function separateur(): void {
        $this->lignes[] = [str_repeat('_', 72), 8, false, null];
        $this->lignes[] = ['', 4, false, null];
    }

    /** Construit le document PDF (une ou plusieurs pages). */
    public function generer(): string {
        // Découper en pages selon la hauteur disponible
        $pages     = [];
        $courante  = [];
        $y         = self::HAUTEUR - self::MARGE;
        foreach ($this->lignes as $l) {
            $interligne = $l[1] + 7;
            if ($y - $interligne < self::MARGE) {
                $pages[]  = $courante;
                $courante = [];
                $y        = self::HAUTEUR - self::MARGE;
            }
            $y -= $interligne;
            $courante[] = [$l[0], $l[1], $l[2], $l[3], $y];
        }
        if ($courante) $pages[] = $courante;
        if (!$pages) $pages = [[]];

        // Objets PDF
        $objets = [];
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $nbPages   = count($pages);
        $refsPages = [];
        $numObjet  = 5; // 1 catalog, 2 pages, 3 police normale, 4 police grasse

        $objets[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objets[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($pages as $contenuPage) {
            $numPage    = $numObjet++;
            $numContenu = $numObjet++;
            $refsPages[] = "$numPage 0 R";

            $flux = "BT\n";
            foreach ($contenuPage as [$texte, $taille, $gras, $droite, $y]) {
                if ($texte === '') continue;
                $police = $gras ? 'F2' : 'F1';
                $flux .= sprintf("/%s %d Tf\n1 0 0 1 %d %.1f Tm\n(%s) Tj\n",
                    $police, $taille, self::MARGE, $y, $this->echapper($texte));
                if ($droite !== null) {
                    // Colonne droite alignée approximativement (police ~0.5 * taille par caractère)
                    $largeurTexte = strlen($this->translitterer($droite)) * $taille * 0.55;
                    $x = self::LARGEUR - self::MARGE - $largeurTexte;
                    $flux .= sprintf("1 0 0 1 %.1f %.1f Tm\n(%s) Tj\n", max($x, 300), $y, $this->echapper($droite));
                }
            }
            $flux .= "ET";

            $objets[$numPage] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R ' .
                '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>',
                self::LARGEUR, self::HAUTEUR, $numContenu
            );
            $objets[$numContenu] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($flux), $flux);
        }

        $objets[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $refsPages), $nbPages);

        // Assemblage
        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        ksort($objets);
        foreach ($objets as $num => $corps) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$corps\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $maxNum  = max(array_keys($objets));
        $pdf .= "xref\n0 " . ($maxNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

        return $pdf;
    }

    /** Envoie le PDF au navigateur et termine le script. */
    public function envoyer(string $nomFichier): never {
        $contenu = $this->generer();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
        header('Content-Length: ' . strlen($contenu));
        echo $contenu;
        exit;
    }

    // ---- Échappement + translittération WinAnsi ----
    private function echapper(string $texte): string {
        $texte = $this->translitterer($texte);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
    }

    private function translitterer(string $texte): string {
        // UTF-8 → WinAnsi (Latin-1 approché) ; les symboles inconnus sont retirés
        $converti = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texte);
        return $converti !== false ? $converti : preg_replace('/[^\x20-\x7E]/', '', $texte);
    }
}
