<?php
// ============================================================
// api/admin/champions_genera_fase.php
//
// Genera in NEW_CALENDARIO_CHAMP le fasi della Champions che dipendono dai
// risultati (la fase 1 si genera all'inizializzazione della stagione).
// Le regole sono in lib/ChampionsGenera.php. GIORNATA_CAMP è letta dalla
// configurazione (NEW_PARAMETRI_STAGIONE, codici CHAMP_*).
//
// POST { stagione, fase, vincitori? }
//   fase = "fase2"       gironi C e D, dalle classifiche finali dei gironi A e B
//          "semifinali"  S1 e S2, dalle classifiche finali dei gironi C e D
//          "finale"      FI, tra le vincitrici delle due semifinali
//          "replay"      FR, solo se la finale è finita in parità
//   vincitori (solo "finale", facoltativo) { "S1": idSquadra, "S2": idSquadra }
//          da indicare se una semifinale è in parità sul totale dei gol;
//          ogni id deve essere una delle due squadre di quella semifinale.
//
// L'operazione è ripetibile (le righe del girone/fase vengono sostituite) ma
// è rifiutata se esistono già le fasi successive. Richiede classifiche/
// risultati completi: altrimenti risponde con l'errore e non modifica nulla.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/ChampionsGenera.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito: usare POST", 405);
}

$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$fase     = (string) ($input["fase"] ?? "");

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($fase, ["fase2", "semifinali", "finale", "replay"], true)) {
    api_error("Parametro 'fase' non valido: valori ammessi 'fase2', 'semifinali', 'finale', 'replay'", 400);
}

// Fasi successive già presenti: rigenerare renderebbe incoerente il calendario
$successive = [
    "fase2"      => ["S1", "S2", "FI", "FR"],
    "semifinali" => ["FI", "FR"],
    "finale"     => ["FR"],
    "replay"     => [],
];
foreach (query_all("SELECT DISTINCT TRIM(girone) AS g FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione") as $r) {
    if (in_array(champions_gruppo_girone($r["g"]), $successive[$fase], true)) {
        api_error("Esistono già le fasi successive (" . champions_gruppo_girone($r["g"]) . "): operazione non consentita", 409);
    }
}

$champGiornate = champions_parametri_stagione($stagione);

try {
    switch ($fase) {
        case "fase2":
            $righe   = champions_righe_fase2(
                champions_classifica_gruppo($stagione, "A"),
                champions_classifica_gruppo($stagione, "B"));
            $gironi  = ["C", "D"];
            break;

        case "semifinali":
            $righe   = champions_righe_semifinali(
                champions_classifica_gruppo($stagione, "C"),
                champions_classifica_gruppo($stagione, "D"));
            $gironi  = ["S1", "S2"];
            break;

        case "finale":
            $vinc = [];
            foreach (["S1", "S2"] as $semi) {
                $indicato = (int) (($input["vincitori"][$semi] ?? 0));
                if ($indicato > 0) {
                    $squadre = array_unique(array_merge(...array_map(
                        fn($p) => [$p["casa"], $p["ospite"]], champions_partite_gruppo($stagione, $semi))));
                    if (!in_array($indicato, $squadre, true)) {
                        throw new RuntimeException("La squadra $indicato non gioca la semifinale $semi");
                    }
                    $vinc[$semi] = $indicato;
                } else {
                    $vinc[$semi] = champions_vincitore_semifinale($stagione, $semi);
                }
            }
            $righe   = champions_righe_finale($vinc["S1"], $vinc["S2"]);
            $gironi  = ["FI"];
            break;

        case "replay":
            $esito = champions_finale_esito($stagione);
            if (!$esito["pari"]) {
                throw new RuntimeException("La finale non è finita in parità: il replay non serve");
            }
            $righe   = champions_righe_finale($esito["casa"], $esito["ospite"], true);
            $gironi  = ["FR"];
            break;
    }

    mysqli_begin_transaction($conn);
    try {
        champions_scrivi_righe($conn, $stagione, $righe, $champGiornate, $gironi);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

api_success([
    "ok"       => true,
    "stagione" => $stagione,
    "fase"     => $fase,
    "righe"    => count($righe),
]);
