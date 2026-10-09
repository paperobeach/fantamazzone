<?php
// ============================================================
// api/live_giornata.php
// GET ?stagione=2026
//
// Pagina "LIVE Giornata in corso": per ogni partita della giornata
// non ancora chiusa restituisce un unico "risultato" (con "fonte"):
// i dati di NEW_RISULTATI se esistono, altrimenti la simulazione. Restano
// disponibili anche entrambi i risultati separati:
//   - "reale":        risultato già calcolato in NEW_RISULTATI
//                     (calcolo fase 2, non ancora definitivo);
//   - "simulazione":  risultato di NEW_SIMULAZIONE_RISULTATI (ultima
//                     simulazione con i voti disponibili).
// Oltre alle partite di campionato ("partite"), se nella giornata si gioca
// un turno di Champions restituisce "champions": le sue partite, con la
// stessa struttura (reale = NEW_RISULTATI_CHAMP, simulazione =
// NEW_SIMULAZIONE_RISULTATI_CHAMP), il girone e l'elenco di chi riposa.
// Se la giornata non è in corso (stagione storica, tutte le giornate
// chiuse, nessun calendario) "in_corso" = false e "partite" è vuoto.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";

$stagione = param_int("stagione");
$stato    = sg_stato_corrente($stagione);

if (!$stato["in_corso"]) {
    api_success(["stato" => $stato, "partite" => [], "champions" => null, "simulazione_calcolata_il" => null, "provvisori" => 0, "manuali" => 0]);
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
    // Rappresentazione unica: se la partita ha i dati sulle tabelle NON
    // simulate (NEW_RISULTATI) si mostrano quelli, altrimenti la simulazione
    $reale = live_risultato($reali[$idCasa] ?? null);
    $simul = live_risultato($s);
    $fonte = $reale !== null ? "reale" : ($simul !== null ? "simulazione" : null);
    $out[] = [
        "casa"        => $p["casa"],
        "ospite"      => $p["ospite"],
        "fonte"       => $fonte,                      // reale | simulazione | null
        "risultato"   => $fonte === "reale" ? $reale : $simul,
        "reale"       => $reale,
        "simulazione" => $simul,
        // Giocatori con 6 provvisorio / voto manuale nella simulazione (casa + ospite)
        "provvisori"  => $fonte !== "simulazione" ? 0 : ($flag[$idCasa]["p"] ?? 0) + ($flag[$p["ospite"]["id"]]["p"] ?? 0),
        "manuali"     => $fonte !== "simulazione" ? 0 : ($flag[$idCasa]["m"] ?? 0) + ($flag[$p["ospite"]["id"]]["m"] ?? 0),
    ];
}

// ------------------------------------------------------------
// CHAMPIONS: partite del turno della giornata (se presente)
// ------------------------------------------------------------
$champions = null;
$pc = champions_partite_giornata($stagione, $giornata);
if (!empty($pc["partite"]) || !empty($pc["riposa"])) {
    $nomi = [];
    foreach (query_all("SELECT s.id, s.nome, s.logo
                        FROM NEW_CALENDARIO_CHAMP cc
                        JOIN NEW_SQUADRE s ON s.id = cc.squadra AND s.stagione = cc.stagione
                        WHERE cc.stagione = $stagione AND cc.giornata_camp = $giornata") as $r) {
        $nomi[(int) $r["id"]] = ["id" => (int) $r["id"], "nome" => $r["nome"], "logo" => $r["logo"]];
    }

    $realiC = live_indicizza(query_all("SELECT squadra AS id_squadra, ftotale, ftotale_a, golf, gols, punti, segno
                                        FROM NEW_RISULTATI_CHAMP WHERE stagione = $stagione AND giornata = $giornata"));
    $simC   = live_indicizza(query_all("SELECT id_squadra, ftotale, ftotale_a, golf, gols, punti, segno, calcolato_il, simulato_da
                                        FROM NEW_SIMULAZIONE_RISULTATI_CHAMP WHERE stagione = $stagione AND giornata = $giornata"));
    $flagC = [];
    foreach (query_all("SELECT id_squadra, SUM(provvisorio) AS p, SUM(manuale) AS m
                        FROM NEW_SIMULAZIONE_VOTI_CHAMP
                        WHERE stagione = $stagione AND giornata = $giornata
                        GROUP BY id_squadra") as $r) {
        $flagC[(int) $r["id_squadra"]] = ["p" => (int) $r["p"], "m" => (int) $r["m"]];
    }

    $partiteC = [];
    foreach ($pc["partite"] as $p) {
        $idCasa = $p["casa"]; $idOsp = $p["ospite"];
        $s = $simC[$idCasa] ?? null;
        if ($s !== null && ($calcolatoIl === null || $s["calcolato_il"] > $calcolatoIl)) $calcolatoIl = $s["calcolato_il"];
        $reale = live_risultato($realiC[$idCasa] ?? null);
        $simul = live_risultato($s);
        $fonte = $reale !== null ? "reale" : ($simul !== null ? "simulazione" : null);
        $partiteC[] = [
            "girone"      => $p["girone"],
            "girone_label" => champions_etichetta_gruppo($p["girone"]),
            "casa"        => $nomi[$idCasa],
            "ospite"      => $nomi[$idOsp],
            "fonte"       => $fonte,
            "risultato"   => $fonte === "reale" ? $reale : $simul,
            "reale"       => $reale,
            "simulazione" => $simul,
            "provvisori"  => $fonte !== "simulazione" ? 0 : ($flagC[$idCasa]["p"] ?? 0) + ($flagC[$idOsp]["p"] ?? 0),
            "manuali"     => $fonte !== "simulazione" ? 0 : ($flagC[$idCasa]["m"] ?? 0) + ($flagC[$idOsp]["m"] ?? 0),
            // La squadra schiera una formazione Champions distinta (se ammessa e salvata)
            "formazione_distinta" => [
                "casa"   => formazione_tabella_champions($stagione, $giornata, $idCasa)   === FORMAZIONE_TAB_CHAMPIONS,
                "ospite" => formazione_tabella_champions($stagione, $giornata, $idOsp) === FORMAZIONE_TAB_CHAMPIONS,
            ],
        ];
    }

    // Etichetta del turno (es. "Fase 1 · Gironi - Giornata 2 (andata)"), se ricavabile
    $etichetta = null;
    try {
        $prog = query_one("SELECT MIN(giornata) AS g FROM NEW_CALENDARIO_CHAMP
                           WHERE stagione = $stagione AND giornata_camp = $giornata");
        $idx  = champions_assegna_sorgente($stagione)[(int) ($prog["g"] ?? 0)] ?? null;
        $turno = $idx !== null ? (champions_turni_cronologici()[$idx] ?? null) : null;
        if ($turno) {
            $faseLabel = "";
            foreach (champions_fasi() as $f) if ($f["id"] === $turno["fase_id"]) $faseLabel = $f["label"];
            $etichetta = trim($faseLabel . " - " . $turno["label"], " -");
        }
    } catch (Throwable $e) { /* etichetta facoltativa */ }

    $champions = [
        "turno"   => $etichetta,
        "partite" => $partiteC,
        "riposa"  => array_values(array_filter(array_map(
            fn($r) => isset($nomi[$r["id"]]) ? $nomi[$r["id"]] + ["girone" => $r["girone"]] : null,
            $pc["riposa"]))),
    ];
}

$tot = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m
                  FROM NEW_SIMULAZIONE_VOTI WHERE stagione = $stagione AND giornata = $giornata");
$totC = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m
                   FROM NEW_SIMULAZIONE_VOTI_CHAMP WHERE stagione = $stagione AND giornata = $giornata");

api_success([
    "stato"                    => $stato,
    "partite"                  => $out,
    "champions"                => $champions,
    "simulazione_calcolata_il" => $calcolatoIl,
    "provvisori"               => (int) ($tot["p"] ?? 0) + (int) ($totC["p"] ?? 0),
    "manuali"                  => (int) ($tot["m"] ?? 0) + (int) ($totC["m"] ?? 0),
]);
