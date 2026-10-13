<?php
// ============================================================
// api/top_flop.php
// GET ?stagione=2024&tipo=top   → top 11
// GET ?stagione=2024&tipo=flop  → flop 11
// GET ?stagione=2024            → entrambi
// GET ...&competizione=CHAMP    → Top/Flop calcolate sulle sole partite
//                                 di Champions (NEW_VOTI_CHAMP); senza il
//                                 parametro (o CAMP) restano quelle del
//                                 campionato (NEW_TOP11 / NEW_FLOP11)
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione     = param_int("stagione");
$tipo         = param_str("tipo", false);
$competizione = strtoupper((string) param_str("competizione", false));

// Composizione modulo 1-3-4-3: ruolo => numero giocatori
// (come TOPFLOP_COMPOSIZIONE in lib/AggiornamentiGiornata.php)
const TOPFLOP_CHAMP_COMPOSIZIONE = [1 => 1, 2 => 3, 3 => 4, 4 => 3];

// ------------------------------------------------------------
// Top 11 / Flop 11 della sola Champions, calcolate al volo da
// NEW_VOTI_CHAMP con la stessa logica del campionato
// (agg_statistiche + agg_top_flop):
//   giocate = SUM(giocata), media = SUM(totale) / giocate
//   (riserve d'ufficio escluse), squadra = quella dell'ultima
//   giornata di Champions con voti.
// Candidati: giocate >= soglia, dove
//   soglia = partite di Champions della squadra che ne ha giocate
//            di più * TOPFLOP_SOGLIA_PERCENTUALE / 100  (default 50).
// Top: media decrescente; Flop: media crescente; a parità più giocate.
// ------------------------------------------------------------
function top_flop_champions(int $stagione, string $ordine): array
{
    $row = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                      WHERE stagione = $stagione AND codice = 'TOPFLOP_SOGLIA_PERCENTUALE'");
    $pct = ($row !== null && is_numeric(trim((string) $row['valore']))) ? (float) $row['valore'] : 50.0;
    $pct = max(0.0, min(100.0, $pct));

    $row = query_one("SELECT MAX(n) AS p FROM (
                          SELECT COUNT(*) AS n FROM NEW_RISULTATI_CHAMP
                          WHERE stagione = $stagione GROUP BY squadra
                      ) t");
    $partiteMax = (int) ($row['p'] ?? 0);
    if ($partiteMax === 0) return [];
    $soglia = sprintf('%.4F', $partiteMax * $pct / 100);

    $out = [];
    foreach (TOPFLOP_CHAMP_COMPOSIZIONE as $ruolo => $quanti) {
        $rows = query_all("SELECT
                a.id_giocatore,
                g.descrizione AS giocatore,
                ROUND(a.totale / a.giocate, 2) AS media,
                g.ruolo,
                a.giocate,
                u.id_squadra,
                s.nome AS squadra,
                s.logo
            FROM (
                SELECT id_giocatore,
                       MAX(giornata) AS ultima,
                       SUM(giocata)  AS giocate,
                       SUM(totale)   AS totale
                FROM NEW_VOTI_CHAMP
                WHERE stagione = $stagione AND rufficio = 0
                GROUP BY id_giocatore
            ) a
            JOIN NEW_VOTI_CHAMP u ON u.stagione = $stagione AND u.id_giocatore = a.id_giocatore
                                 AND u.giornata = a.ultima
            JOIN NEW_GIOCATORI g  ON g.id = a.id_giocatore AND g.stagione = $stagione AND g.flag <> 1
            JOIN NEW_SQUADRE s    ON s.id = u.id_squadra   AND s.stagione = $stagione
            WHERE g.ruolo = $ruolo AND a.giocate > 0 AND a.giocate >= $soglia
            ORDER BY media $ordine, a.giocate DESC, a.id_giocatore
            LIMIT $quanti");
        foreach ($rows as $r) $out[] = $r;
    }
    return $out;
}

$result = [];

if ($competizione === "CHAMP") {
    if ($tipo === null || $tipo === "top")  $result["top11"]  = top_flop_champions($stagione, "DESC");
    if ($tipo === null || $tipo === "flop") $result["flop11"] = top_flop_champions($stagione, "ASC");
} else {
    if ($tipo === null || $tipo === "top") {
        $result["top11"] = query_all("SELECT
                id_giocatore, giocatore, media, ruolo,
                giocate, id_squadra, squadra, logo
            FROM NEW_TOP11
            WHERE stagione = $stagione
            ORDER BY ruolo, media DESC");
    }

    if ($tipo === null || $tipo === "flop") {
        $result["flop11"] = query_all("SELECT
                id_giocatore, giocatore, media, ruolo,
                giocate, id_squadra, squadra, logo
            FROM NEW_FLOP11
            WHERE stagione = $stagione
            ORDER BY ruolo, media ASC");
    }
}

api_success($result);
