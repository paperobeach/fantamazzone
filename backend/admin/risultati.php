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

    // Calcolo punti e segno
    if ($golf > $gols) {
        $punti_casa   = 3; $punti_osp = 0;
        $segno_casa   = "W"; $segno_osp = "L";
        $fc = 1; // fattore campo (vittoria in casa)
    } elseif ($golf === $gols) {
        $punti_casa = 1; $punti_osp = 1;
        $segno_casa = "N"; $segno_osp = "N";
        $fc = 0;
    } else {
        $punti_casa   = 0; $punti_osp = 3;
        $segno_casa   = "L"; $segno_osp = "W";
        $fc = 0;
    }

    // Upsert risultato squadra di casa
    $exists = query_one("SELECT COUNT(*) AS n FROM NEW_RISULTATI
                         WHERE stagione = $stagione AND giornata = $giornata
                           AND id_squadra = $id_sq");
    if ((int)$exists["n"] > 0) {
        mysqli_query($conn, "UPDATE NEW_RISULTATI SET
            id_squadra_a = $id_sq_a,
            ftotale = $ftotale, ftotale_a = $ftotale_a,
            golf = $golf, gols = $gols,
            modificatore = $mod, modificatore_a = $mod_a,
            punti = $punti_casa, fattore_campo = $fc, segno = '$segno_casa',
            mod_att = $mod_att, num_cc = $num_cc, tot_cc = $tot_cc, mod_cc = $mod_cc
            WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $id_sq");
    } else {
        mysqli_query($conn, "INSERT INTO NEW_RISULTATI
            (giornata, stagione, id_squadra, id_squadra_a,
             ftotale, ftotale_a, golf, gols,
             modificatore, modificatore_a, punti, fattore_campo, segno,
             mod_att, num_cc, tot_cc, mod_cc)
            VALUES ($giornata, $stagione, $id_sq, $id_sq_a,
                    $ftotale, $ftotale_a, $golf, $gols,
                    $mod, $mod_a, $punti_casa, $fc, '$segno_casa',
                    $mod_att, $num_cc, $tot_cc, $mod_cc)");
    }

    api_success([
        "ok"          => true,
        "punti_casa"  => $punti_casa,
        "punti_ospite"=> $punti_osp,
        "segno_casa"  => $segno_casa,
        "segno_ospite"=> $segno_osp,
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
