<?php
// ============================================================
// api/live_giornata.php
// GET ?stagione=2026
//
// Pagina "LIVE Giornata in corso": per ogni partita della giornata
// non ancora chiusa restituisce
//   - "reale":        risultato già calcolato in NEW_RISULTATI
//                     (calcolo fase 2, non ancora definitivo);
//   - "simulazione":  risultato di NEW_SIMULAZIONE_RISULTATI (ultima
//                     simulazione con i voti disponibili).
// Se la giornata non è in corso (stagione storica, tutte le giornate
// chiuse, nessun calendario) "in_corso" = false e "partite" è vuoto.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";

$stagione = param_int("stagione");
$stato    = sg_stato_corrente($stagione);

if (!$stato["in_corso"]) {
    api_success(["stato" => $stato, "partite" => [], "simulazione_calcolata_il" => null, "provvisori" => 0, "manuali" => 0]);
}
$giornata = $stato["giornata"];

$rows = query_all("SELECT c.posizione, c.squadra AS id_squadra, s.nome AS nome_squadra, s.logo
                   FROM NEW_CALENDARIO c
                   JOIN NEW_SQUADRE s ON s.id = c.squadra AND s.stagione = c.stagione
                   WHERE c.stagione = $stagione AND c.giornata = $giornata
                   ORDER BY c.posizione");

$partite = [];
foreach ($rows as $row) {
    $i = (int) (($row["posizione"] - 1) / 2);
    $lato = $row["posizione"] % 2 === 1 ? "casa" : "ospite";
    $partite[$i][$lato] = ["id" => (int) $row["id_squadra"], "nome" => $row["nome_squadra"], "logo" => $row["logo"]];
}

function live_indicizza(array $righe): array
{
    $out = [];
    foreach ($righe as $r) $out[(int) $r["id_squadra"]] = $r;
    return $out;
}

$reali = live_indicizza(query_all("SELECT id_squadra, ftotale, ftotale_a, golf, gols, punti, segno
                                   FROM NEW_RISULTATI WHERE stagione = $stagione AND giornata = $giornata"));
$sim   = live_indicizza(query_all("SELECT id_squadra, ftotale, ftotale_a, golf, gols, punti, segno, calcolato_il, simulato_da
                                   FROM NEW_SIMULAZIONE_RISULTATI WHERE stagione = $stagione AND giornata = $giornata"));

// Voti provvisori (6) e manuali della simulazione, per squadra
$flag = [];
foreach (query_all("SELECT id_squadra, SUM(provvisorio) AS p, SUM(manuale) AS m
                    FROM NEW_SIMULAZIONE_VOTI
                    WHERE stagione = $stagione AND giornata = $giornata
                    GROUP BY id_squadra") as $r) {
    $flag[(int) $r["id_squadra"]] = ["p" => (int) $r["p"], "m" => (int) $r["m"]];
}

function live_risultato(?array $r): ?array
{
    if ($r === null) return null;
    return [
        "ftotale_casa"   => (float) $r["ftotale"],
        "ftotale_ospite" => (float) $r["ftotale_a"],
        "golf"           => (int) $r["golf"],
        "gols"           => (int) $r["gols"],
        "punti_casa"     => (int) $r["punti"],
        "segno"          => $r["segno"],
        // Solo per la simulazione: quando e da chi è stata eseguita
        "calcolato_il"   => $r["calcolato_il"] ?? null,
        "simulato_da"    => $r["simulato_da"] ?? null,
    ];
}

$calcolatoIl = null;
$out = [];
foreach ($partite as $p) {
    $idCasa = $p["casa"]["id"] ?? null;
    if (!$idCasa || !isset($p["ospite"])) continue;
    $s = $sim[$idCasa] ?? null;
    if ($s !== null && ($calcolatoIl === null || $s["calcolato_il"] > $calcolatoIl)) $calcolatoIl = $s["calcolato_il"];
    $out[] = [
        "casa"        => $p["casa"],
        "ospite"      => $p["ospite"],
        "reale"       => live_risultato($reali[$idCasa] ?? null),
        "simulazione" => live_risultato($s),
        // Giocatori con 6 provvisorio / voto manuale nella simulazione (casa + ospite)
        "provvisori"  => ($flag[$idCasa]["p"] ?? 0) + ($flag[$p["ospite"]["id"]]["p"] ?? 0),
        "manuali"     => ($flag[$idCasa]["m"] ?? 0) + ($flag[$p["ospite"]["id"]]["m"] ?? 0),
    ];
}

$tot = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m
                  FROM NEW_SIMULAZIONE_VOTI WHERE stagione = $stagione AND giornata = $giornata");

api_success([
    "stato"                    => $stato,
    "partite"                  => $out,
    "simulazione_calcolata_il" => $calcolatoIl,
    "provvisori"               => (int) ($tot["p"] ?? 0),
    "manuali"                  => (int) ($tot["m"] ?? 0),
]);
