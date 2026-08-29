<?php
/* ============================================================================
   GABARIT DOCUMENTAIRE — papier à en-tête officiel du Groupe
   Utilisé par TOUS les documents PDF de l'application :
   facture, proforma, reçu, bulletin de paie, et documents des employés.
   ============================================================================ */

/* Couleurs de la charte */
const C_NAVY      = [10, 31, 68];
const C_NAVY_L    = [20, 50, 100];
const C_GOLD      = [212, 165, 38];
const C_GOLD_L    = [240, 193, 75];
const C_GOLD_D    = [184, 135, 15];
const C_TXT       = [45, 52, 66];
const C_TXT_SOFT  = [110, 118, 133];
const C_LINE      = [223, 227, 234];
const C_ZEBRA     = [248, 249, 251];

class DocTemplate extends FPDF
{
    public string $titre = 'DOCUMENT';       // FACTURE, REÇU, BULLETIN DE PAIE…
    public array  $entete = [];              // [[label, valeur], …] bloc sous le titre
    public array  $st = [];                  // réglages de l'entreprise
    public string $logo = '';                // chemin absolu du logo
    public float  $yBody = 0;                // ordonnée où commence le corps

    private float $mg = 14;                  // marge latérale

    function __construct() {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins($this->mg, 12, $this->mg);
        $this->SetAutoPageBreak(true, 30);
    }

    function larg(): float { return 210 - 2 * $this->mg; }

    /* ---------- Petits outils de dessin ---------- */
    function couleurTexte(array $c) { $this->SetTextColor($c[0], $c[1], $c[2]); }
    function couleurFond(array $c)  { $this->SetFillColor($c[0], $c[1], $c[2]); }
    function couleurTrait(array $c) { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    /* Cercle (utilisé pour les pastilles du pied de page) */
    function Cercle(float $x, float $y, float $r, string $style = 'D') {
        $k = $this->k; $h = $this->h; $b = $r * 0.5523;
        $op = $style === 'F' ? 'f' : ($style === 'FD' ? 'B' : 'S');
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $r) * $k, ($h - ($y - $b)) * $k, ($x + $b) * $k, ($h - ($y - $r)) * $k, $x * $k, ($h - ($y - $r)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $b) * $k, ($h - ($y - $r)) * $k, ($x - $r) * $k, ($h - ($y - $b)) * $k, ($x - $r) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $r) * $k, ($h - ($y + $b)) * $k, ($x - $b) * $k, ($h - ($y + $r)) * $k, $x * $k, ($h - ($y + $r)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $b) * $k, ($h - ($y + $r)) * $k, ($x + $r) * $k, ($h - ($y + $b)) * $k, ($x + $r) * $k, ($h - $y) * $k));
        $this->_out($op);
    }

    /* Ligne de pointillés (conduite de lecture du modèle) */
    function Pointilles(float $x1, float $x2, float $y, array $c = C_LINE, float $pas = 1.5) {
        $this->couleurFond($c);
        for ($x = $x1; $x < $x2; $x += $pas) $this->Rect($x, $y, 0.5, 0.35, 'F');
    }

    /* Titre en capitales espacées (« F A C T U R E ») */
    static function espace(string $t): string {
        $out = []; $n = mb_strlen($t);
        for ($i = 0; $i < $n; $i++) $out[] = mb_substr($t, $i, 1);
        return implode(' ', $out);
    }

    /* ---------- EN-TÊTE (identique sur tous les documents) ---------- */
    function Header() {
        $s = $this->st; $L = $this->mg; $R = 210 - $this->mg;

        /* Logo + identité, à gauche */
        $yLogo = 10; $wLogo = 23;
        if ($this->logo && is_file($this->logo)) {
            try { $this->Image($this->logo, $L + 6, $yLogo, $wLogo); } catch (Throwable $e) {}
        }
        $this->SetXY($L, $yLogo + $wLogo + 1.5);
        $this->SetFont('Helvetica', 'B', 13.5); $this->couleurTexte(C_NAVY);
        $this->Cell(84, 6, pdf_txt(mb_strtoupper($s['nom_entreprise'] ?? 'ENTREPRISE')), 0, 2, 'C');
        if (trim((string)($s['slogan'] ?? '')) !== '') {
            $this->SetFont('Helvetica', '', 6.4); $this->couleurTexte(C_GOLD_D);
            $this->Cell(84, 4, pdf_txt(mb_strtoupper(self::espace(''). $s['slogan'])), 0, 2, 'C');
        }

        /* Titre du document, à droite */
        $this->SetXY(110, 14);
        $t = self::espace($this->titre);
        $taille = 25;                       // réduit si le titre est long, pour rester sur une seule ligne
        $this->SetFont('Helvetica', 'B', $taille);
        while ($taille > 11 && $this->GetStringWidth(pdf_txt($t)) > ($R - 112)) {
            $taille -= 0.5; $this->SetFont('Helvetica', 'B', $taille);
        }
        $this->couleurTexte(C_NAVY);
        $this->Cell($R - 110, 12, pdf_txt($t), 0, 2, 'R');

        /* Double filet doré sous le titre */
        $yr = 28.5;
        $this->couleurFond(C_GOLD); $this->Rect(110, $yr, $R - 110, 0.45, 'F');
        $this->couleurFond(C_GOLD_D); $this->Rect($R - 42, $yr - 0.35, 42, 1.1, 'F');

        /* Bloc d'informations (N°, date, échéance…) */
        $y = $yr + 5;
        $this->SetFont('Helvetica', '', 9);
        foreach ($this->entete as $row) {
            $this->SetXY(112, $y);
            $this->couleurTexte(C_TXT);
            $this->Cell(34, 5.6, pdf_txt($row[0]), 0, 0, 'L');
            $this->couleurTexte(C_TXT_SOFT); $this->Cell(3, 5.6, ':', 0, 0, 'L');
            $this->SetFont('Helvetica', 'B', 9); $this->couleurTexte(C_NAVY);
            $this->Cell($R - 149, 5.6, pdf_txt($row[1]), 0, 0, 'L');
            $this->SetFont('Helvetica', '', 9);
            $y += 5.8;
        }

        /* Filet doré de séparation, pleine largeur */
        $ySep = max($y + 2.5, 50);
        $this->couleurFond(C_GOLD); $this->Rect($L, $ySep, $this->larg(), 0.5, 'F');
        $this->yBody = $ySep + 9;
        $this->SetY($this->yBody);
    }

    /* ---------- PIED DE PAGE (identique sur tous les documents) ---------- */
    function Footer() {
        $s = $this->st; $L = $this->mg; $R = 210 - $this->mg; $y = 272;

        $this->couleurFond(C_GOLD); $this->Rect($L, $y, $this->larg(), 0.5, 'F');

        $cols = [
            [ (mb_strtoupper($s['nom_entreprise'] ?? '') . ' ' . ($s['forme_juridique'] ?? '')),
              $s['adresse'] ?? '', '' ],
            [ $s['telephone'] ?? '', $s['whatsapp'] ?? '', '' ],
            [ $s['email'] ?? '', preg_replace('#^https?://#', '', (string)($s['site_url'] ?? '')), '' ],
            [ ($s['rccm'] ?? '') !== '' ? 'RC : ' . $s['rccm'] : '',
              ($s['ncc'] ?? '') !== '' ? 'N° Contribuable : ' . $s['ncc'] : '', '' ],
        ];

        $w = $this->larg() / 4;
        for ($i = 0; $i < 4; $i++) {
            $x = $L + $i * $w;
            if ($i > 0) { $this->couleurFond(C_LINE); $this->Rect($x - 1, $y + 5, 0.3, 12, 'F'); }
            /* pastille dorée */
            $this->couleurTrait(C_GOLD); $this->SetLineWidth(0.35);
            $this->Cercle($x + 4, $y + 10, 2.6, 'D');
            $this->couleurFond(C_GOLD); $this->Cercle($x + 4, $y + 10, 0.85, 'F');
            /* textes */
            $ty = $y + 6.4;
            foreach ($cols[$i] as $k => $line) {
                if (trim((string)$line) === '') continue;
                $this->SetXY($x + 8.5, $ty);
                $this->SetFont('Helvetica', $k === 0 ? 'B' : '', 6.9);
                $this->couleurTexte($k === 0 ? C_NAVY : C_TXT_SOFT);
                $this->Cell($w - 10, 3.6, pdf_txt($line), 0, 2, 'L');
                $ty += 3.7;
            }
        }
        $this->SetXY($L, $y + 19.5);
        $this->SetFont('Helvetica', 'I', 6.3); $this->couleurTexte([160, 166, 178]);
        $this->Cell($this->larg(), 3.2, pdf_txt('Page ' . $this->PageNo() . '/{nb}   ·   Document émis le ' . date('d/m/Y à H:i')), 0, 0, 'C');
    }

    /* ---------- Bloc « CLIENT » / « INFORMATIONS » ---------- */
    function BlocsParties(string $t1, array $l1, string $t2, array $l2) {
        $L = $this->mg; $R = 210 - $this->mg;
        $xg = $L; $xd = 110;
        $y0 = $this->GetY();

        foreach ([[$xg, $t1, $l1, 92], [$xd, $t2, $l2, $R - $xd]] as $bloc) {
            [$x, $titre, $lignes, $w] = $bloc;
            $this->SetXY($x, $y0);
            $this->SetFont('Helvetica', 'B', 9.5); $this->couleurTexte(C_GOLD_D);
            $this->Cell($w, 5, pdf_txt(mb_strtoupper($titre)), 0, 2, 'L');
            $this->couleurFond(C_GOLD); $this->Rect($x, $y0 + 6.2, 13, 0.7, 'F');

            $y = $y0 + 11;
            foreach ($lignes as $row) {
                $this->SetXY($x, $y);
                $this->SetFont('Helvetica', '', 8.6); $this->couleurTexte(C_TXT);
                $this->Cell(31, 5.4, pdf_txt($row[0]), 0, 0, 'L');
                $this->couleurTexte(C_TXT_SOFT); $this->Cell(3, 5.4, ':', 0, 0, 'L');
                $val = trim((string)$row[1]);
                if ($val !== '') {
                    $this->SetXY($x + 35, $y);
                    $this->SetFont('Helvetica', 'B', 8.6); $this->couleurTexte(C_NAVY);
                    $this->Cell($w - 35, 5.4, pdf_txt($val), 0, 0, 'L');
                }
                $y += 5.7;
            }
        }
        $this->SetY(max($y0 + 11 + count($l1) * 5.7, $y0 + 11 + count($l2) * 5.7) + 3);
    }

    /* ---------- Tableau principal ---------- */
    function TableauEntete(array $cols) {
        $L = $this->mg; $y = $this->GetY();
        $this->couleurFond(C_NAVY);
        $this->Rect($L, $y, $this->larg(), 9, 'F');
        $this->SetFont('Helvetica', 'B', 7.6); $this->SetTextColor(255, 255, 255);
        $x = $L;
        foreach ($cols as $c) {
            $this->SetXY($x, $y);
            $this->Cell($c[1], 9, pdf_txt(mb_strtoupper($c[0])), 0, 0, $c[2] ?? 'C');
            $x += $c[1];
        }
        $this->SetXY($L, $y + 9);
    }

    /* Une ligne du tableau : $cells = [[texte, largeur, align]], $sous = éléments inclus */
    /* Réserve la place nécessaire : ajoute une page si le bloc ne tient pas */
    function ReserverPlace(float $h): void {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->SetY($this->yBody);
        }
    }

    function TableauLigne(array $cells, array $sous = [], bool $zebre = false) {
        $L = $this->mg;
        $h = 7.2 + (count($sous) ? count($sous) * 3.7 + 1.0 : 0);
        $this->ReserverPlace($h);
        $y = $this->GetY();

        if ($zebre) { $this->couleurFond(C_ZEBRA); $this->Rect($L, $y, $this->larg(), $h, 'F'); }
        $this->couleurTrait(C_LINE); $this->SetLineWidth(0.15);
        $this->Line($L, $y + $h, $L + $this->larg(), $y + $h);

        $x = $L;
        $this->SetFont('Helvetica', '', 9); $this->couleurTexte(C_TXT);
        foreach ($cells as $c) {
            $this->SetXY($x, $y);
            if (!empty($c[3])) { $this->SetFont('Helvetica', 'B', 9); $this->couleurTexte(C_NAVY); }
            $this->Cell($c[1], 7.2, pdf_txt($c[0]), 0, 0, $c[2] ?? 'L');
            $this->SetFont('Helvetica', '', 9); $this->couleurTexte(C_TXT);
            $x += $c[1];
        }
        /* éléments inclus, sans prix */
        if ($sous) {
            $yy = $y + 6.9;
            $this->SetFont('Helvetica', '', 7.4); $this->couleurTexte(C_TXT_SOFT);
            foreach ($sous as $it) {
                $this->couleurFond(C_GOLD); $this->Rect($L + 7.5, $yy + 1.55, 1.1, 1.1, 'F');
                $this->SetXY($L + 10, $yy - 0.4);
                $this->Cell(100, 4, pdf_txt($it), 0, 0, 'L');
                $yy += 3.9;
            }
        }
        $this->SetXY($L, $y + $h);
    }

    /* ---------- Bloc des totaux (aligné à droite, TOTAL en or) ---------- */
    function Totaux(array $lignes, string $libelleTotal, string $montantTotal) {
        $R = 210 - $this->mg; $w = 84; $x = $R - $w;
        $this->ReserverPlace(count($lignes) * 9 + 13.5);   // le bloc reste d'un seul tenant
        $y = $this->GetY() + 2;
        $wl = 46; $wv = $w - $wl;

        foreach ($lignes as $l) {
            $this->couleurFond([250, 251, 252]); $this->Rect($x, $y, $w, 9, 'F');
            $this->couleurTrait(C_LINE); $this->SetLineWidth(0.15);
            $this->Line($x, $y + 9, $x + $w, $y + 9);
            $this->SetXY($x + 5, $y); $this->SetFont('Helvetica', '', 8.8); $this->couleurTexte(C_TXT);
            $this->Cell($wl, 9, pdf_txt($l[0]), 0, 0, 'L');
            $this->SetXY($x + $wl, $y); $this->SetFont('Helvetica', 'B', 9); $this->couleurTexte(C_NAVY);
            $this->Cell($wv - 5, 9, pdf_txt($l[1]), 0, 0, 'R');
            $y += 9;
        }
        /* TOTAL — bandeau or métallisé */
        metal_rect($this, $x, $y, $w, 11.5, metal_or(), 14);
        $this->SetXY($x + 5, $y); $this->SetFont('Helvetica', 'B', 10); $this->couleurTexte(C_NAVY);
        $this->Cell($wl, 11.5, pdf_txt(mb_strtoupper($libelleTotal)), 0, 0, 'L');
        $this->SetXY($x + $wl, $y); $this->SetFont('Helvetica', 'B', 11);
        $this->Cell($wv - 5, 11.5, pdf_txt($montantTotal), 0, 0, 'R');
        $this->SetY($y + 11.5);
        return $y + 11.5;
    }

    /* ---------- « Arrêtée la présente facture à la somme de : » ---------- */
    function SommeEnLettres(string $intro, string $texte, float $yDepart): float {
        $L = $this->mg; $y = $yDepart;
        $this->SetXY($L, $y);
        $this->SetFont('Helvetica', '', 9); $this->couleurTexte(C_TXT);
        $this->Cell(92, 5.5, pdf_txt($intro), 0, 2, 'L');
        $y += 7;
        foreach ($this->decouper($texte, 90) as $ligne) {
            $this->SetXY($L, $y);
            $this->SetFont('Helvetica', 'BI', 9); $this->couleurTexte(C_NAVY);
            $this->Cell(92, 5.4, pdf_txt($ligne), 0, 0, 'L');
            $y += 5.8;
        }
        return $y + 1;
    }

    /* Découpe un texte en lignes tenant dans une largeur (mm) */
    function decouper(string $t, float $w): array {
        $mots = preg_split('/\s+/', trim($t)); $out = []; $cur = '';
        foreach ($mots as $m) {
            $essai = $cur === '' ? $m : $cur . ' ' . $m;
            if ($this->GetStringWidth(pdf_txt($essai)) > $w && $cur !== '') { $out[] = $cur; $cur = $m; }
            else $cur = $essai;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    /* ---------- Formule de politesse ---------- */
    function Remerciement(string $txt, float $y) {
        $this->SetXY($this->mg, $y + 2);
        $this->SetFont('Helvetica', 'BI', 9.5); $this->couleurTexte(C_NAVY);
        $this->Cell(120, 6, pdf_txt($txt), 0, 1, 'L');
        return $this->GetY();
    }
}
