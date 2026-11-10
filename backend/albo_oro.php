<?php
// ============================================================
// api/albo_oro.php
// GET (nessun parametro)
//
// Albo d'oro storico (tabella NEW_ALBO_ORO), per tutte le stagioni.
//
// Risposta:
// {
//   "disponibile": true,
//   "campionato": [ { stagione, primo, secondo, ultimo } ],   // stagioni DESC
//   "champions":  [ { stagione, vincitore, finalista } ]      // stagioni DESC
// }
// dove ogni squadra e' { utenza, nome, logo } oppure null.
//
// NEW_ALBO_ORO.posizione:
//   CAMP : 1 = primo, 2 = secondo, 99 = ultimo (maglia nera)
//   CHAMP: 1 = vincitore, 2 = finalista
// Nome e logo sono quelli della squadra nella stagione di riferimento
// (NEW_UTENZE.utenza -> NEW_UTENZE.id -> NEW_SQUADRE / NEW_GENERALE); se la
// squadra in quella stagione non ha record o logo (es. squadra uscita dalla
// lega), si usa l'ultimo nome/logo noto della stessa utenza.
// ============================================================
require_once __DIR__ . "/connect.php";

$risposta = [
    "disponibile" => false,
    "campionato"  => [],
    "champions"   => [],
];

$rT = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ALBO_ORO'");
if (!$rT || mysqli_num_rows($rT) === 0) {
    api_success($risposta);
}
$risposta["disponibile"] = true;

$righe = query_all("SELECT a.stagione, a.competizione, a.posizione, a.utenza,
                           COALESCE(NULLIF(s.nome, ''), NULLIF(g.squadra, '')) AS nome,
                           COALESCE(NULLIF(s.logo, ''), NULLIF(g.logo, ''))    AS logo
                    FROM NEW_ALBO_ORO a
                    LEFT JOIN NEW_UTENZE  u ON u.stagione = a.stagione AND u.utenza = a.utenza
                    LEFT JOIN NEW_SQUADRE s ON s.stagione = a.stagione AND s.id = u.id
                    LEFT JOIN NEW_GENERALE g ON g.stagione = a.stagione AND g.id_squadra = u.id
                    ORDER BY a.stagione DESC");

// Fallback per le squadre che non sono piu' in lega (o che in una stagione non
// hanno un record): nome e logo dell'ultima stagione in cui la squadra compare
// in NEW_UTENZE con un valore valorizzato (chiave = utenza, senza maiuscole/spazi).
$ultimi = [];
$storico = query_all("SELECT u.utenza, u.stagione,
                              COALESCE(NULLIF(s.nome, ''), NULLIF(g.squadra, '')) AS nome,
                              COALESCE(NULLIF(s.logo, ''), NULLIF(g.logo, ''))    AS logo
                       FROM NEW_UTENZE u
                       LEFT JOIN NEW_SQUADRE s ON s.stagione = u.stagione AND s.id = u.id
                       LEFT JOIN NEW_GENERALE g ON g.stagione = u.stagione AND g.id_squadra = u.id
                       ORDER BY u.stagione DESC");
foreach ($storico as $h) {
    $k = strtolower(trim($h["utenza"]));
    if (!isset($ultimi[$k])) $ultimi[$k] = ["nome" => null, "logo" => null];
    if ($ultimi[$k]["nome"] === null && !empty($h["nome"])) $ultimi[$k]["nome"] = $h["nome"];
    if ($ultimi[$k]["logo"] === null && !empty($h["logo"])) $ultimi[$k]["logo"] = $h["logo"];
}

$camp  = [];
$champ = [];

foreach ($righe as $r) {
    $st   = (int) $r["stagione"];
    $comp = strtoupper($r["competizione"]);
    $pos  = (int) $r["posizione"];
    $k    = strtolower(trim($r["utenza"]));
    $team = [
        "utenza" => $r["utenza"],
        // nome/logo della stagione; se mancano, ultimo valore noto della squadra
        "nome"   => $r["nome"] ?? $ultimi[$k]["nome"] ?? $r["utenza"],
        "logo"   => $r["logo"] ?? $ultimi[$k]["logo"] ?? null,
    ];

    if ($comp === "CAMP") {
        if (!isset($camp[$st])) $camp[$st] = ["stagione" => $st, "primo" => null, "secondo" => null, "ultimo" => null];
        if ($pos === 1)  $camp[$st]["primo"]   = $team;
        if ($pos === 2)  $camp[$st]["secondo"] = $team;
        if ($pos === 99) $camp[$st]["ultimo"]  = $team;
    } elseif ($comp === "CHAMP") {
        if (!isset($champ[$st])) $champ[$st] = ["stagione" => $st, "vincitore" => null, "finalista" => null];
        if ($pos === 1) $champ[$st]["vincitore"] = $team;
        if ($pos === 2) $champ[$st]["finalista"] = $team;
    }
}

krsort($camp);
krsort($champ);
$risposta["campionato"] = array_values($camp);
$risposta["champions"]  = array_values($champ);

api_success($risposta);
