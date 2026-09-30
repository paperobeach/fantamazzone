<?php
// ============================================================
// api/admin/risultati.php
//
// GET  ?stagione=2024&giornata=5
// POST { stagione, giornata, id_squadra, id_squadra_a,
//        ftotale, ftotale_a, golf, gols,
//        modificatore, modificatore_a,
//        mod_att, num_cc, tot_cc, mod_cc }
//
// Calcola automaticamente punti e segno (W/N/L) in base ai gol.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/RisultatoPartita.php";

$stagione = param_int("stagione");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input       = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $giornata    = (int)($input["giornata"]      ?? 0);
    $id_sq       = (int)($input["id_squadra"]    ?? 0);
    $id_sq_a     = (int)($input["id_squadra_a"]  ?? 0);
    $ftotale     = (float)($input["ftotale"]     ?? 0);
    $ftotale_a   = (float)($input["ftotale_a"]   ?? 0);
    $golf        = (int)($input["golf"]          ?? 0);  // gol segnati casa
    $gols        = (int)($input["gols"]          ?? 0);  // gol subiti casa
    $mod         = (int)($input["modificatore"]  ?? 0);
    $mod_a       = (int)($input["modificatore_a"]?? 0);
    $mod_att     = (float)($input["mod_att"]     ?? 0);
    $num_cc      = (int)($input["num_cc"]        ?? 0);
    $tot_cc      = (float)($input["tot_cc"]      ?? 0);
    $mod_cc      = (float)($input["mod_cc"]      ?? 0);

    if (!$giornata || !$id_sq || !$id_sq_a) {
        api_error("Parametri obbligatori: giornata, id_squadra, id_squadra_a");
    }

    // Calcolo punti/segno/fattore_campo e upsert: vedi backend/lib/RisultatoPartita.php
    // (usata anche da calcolo_giornata.php per la fase 2 di "Gestione voti")
    $esito = salvaRigaRisultato(
        $conn, $stagione, $giornata, $id_sq, $id_sq_a,
        $ftotale, $ftotale_a, $golf, $gols,
        $mod, $mod_a, $mod_att, $num_cc, $tot_cc, $mod_cc
    );

    $segnoOpposto = ["W" => "L", "N" => "N", "L" => "W"];
    $puntiOspite  = $esito["punti"] === 1 ? 1 : (3 - $esito["punti"]);

    api_success([
        "ok"          => true,
        "punti_casa"  => $esito["punti"],
        "punti_ospite"=> $puntiOspite,
        "segno_casa"  => $esito["segno"],
        "segno_ospite"=> $segnoOpposto[$esito["segno"]],
    ]);

} else {

    $giornata = param_int("giornata");

    $data = query_all("SELECT
            r.*,
            s1.nome AS nome_casa,   s1.logo AS logo_casa,
            s2.nome AS nome_ospite, s2.logo AS logo_ospite
        FROM NEW_RISULTATI r
        JOIN NEW_SQUADRE s1 ON s1.id = r.id_squadra   AND s1.stagione = r.stagione
        JOIN NEW_SQUADRE s2 ON s2.id = r.id_squadra_a AND s2.stagione = r.stagione
        WHERE r.stagione = $stagione AND r.giornata = $giornata
        ORDER BY r.id_squadra");

    api_success($data);
}
