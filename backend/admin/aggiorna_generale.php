<?php
// ============================================================
// api/admin/aggiorna_generale.php
// POST { stagione, giornata }
//
// Ricalcola la classifica generale (tabella GENERALE) a partire
// dai dati di RISULTATI. Equivale al vecchio web_aggiorna_generale.php
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") api_error("Usare POST", 405);

$stagione = post_int("stagione");
$giornata = post_int("giornata");

// Prendo tutte le squadre della stagione
$squadre = query_all("SELECT id FROM NEW_SQUADRE WHERE stagione = $stagione");
if (empty($squadre)) api_error("Nessuna squadra trovata per questa stagione", 404);

$aggiornate = 0;

foreach ($squadre as $sq) {
    $id = (int)$sq["id"];

    // Tutti i risultati come squadra di casa
    $ris_casa = query_all("SELECT punti, golf, gols, ftotale, segno
                           FROM NEW_RISULTATI
                           WHERE stagione = $stagione
                             AND id_squadra = $id
                             AND giornata <= $giornata");

    // Tutti i risultati come squadra ospite
    $ris_osp = query_all("SELECT punti, gols AS golf, golf AS gols, ftotale_a AS ftotale, segno
                          FROM NEW_RISULTATI
                          WHERE stagione = $stagione
                            AND id_squadra_a = $id
                            AND giornata <= $giornata");

    $tutti = array_merge($ris_casa, $ris_osp);
    if (empty($tutti)) continue;

    $punti = 0; $pg = 0; $vinte = 0; $nulle = 0; $perse = 0;
    $golf = 0; $gols = 0;
    $punteggi = [];

    foreach ($tutti as $r) {
        $pg++;
        $punti += (int)$r["punti"];
        $golf  += (int)$r["golf"];
        $gols  += (int)$r["gols"];
        $punteggi[] = (float)$r["ftotale"];

        switch ($r["segno"]) {
            case "W": $vinte++; break;
            case "N": $nulle++; break;
            case "L": $perse++; break;
        }
    }

    $media = $pg > 0 ? round(array_sum($punteggi) / $pg, 2) : 0;
    $maxp  = !empty($punteggi) ? max($punteggi) : 0;
    $minp  = !empty($punteggi) ? min($punteggi) : 0;

    // Segno trend (confronto ultima giornata vs penultima)
    $ultime = query_all("SELECT segno FROM NEW_RISULTATI
                         WHERE stagione = $stagione AND id_squadra = $id
                         ORDER BY giornata DESC LIMIT 1");
    $ultime_osp = query_all("SELECT segno FROM NEW_RISULTATI
                             WHERE stagione = $stagione AND id_squadra_a = $id
                             ORDER BY giornata DESC LIMIT 1");
    $segno_row = !empty($ultime) ? $ultime[0] : (!empty($ultime_osp) ? $ultime_osp[0] : null);
    $segno = $segno_row ? $segno_row["segno"] : "";

    // Upsert GENERALE
    $exists = query_one("SELECT COUNT(*) AS n FROM NEW_GENERALE
                         WHERE stagione = $stagione AND id_squadra = $id");
    if ((int)$exists["n"] > 0) {
        mysqli_query($conn, "UPDATE NEW_GENERALE SET
                punti     = $punti,
                partiteg  = $pg,
                vinte     = $vinte,
                nulle     = $nulle,
                perse     = $perse,
                golf      = $golf,
                gols      = $gols,
                maxp      = $maxp,
                minp      = $minp,
                media     = $media,
                segno     = '$segno'
            WHERE stagione = $stagione AND id_squadra = $id");
    } else {
        $sq_info = query_one("SELECT nome, logo FROM NEW_SQUADRE
                              WHERE id = $id AND stagione = $stagione");
        $nome = mysqli_real_escape_string($conn, $sq_info["nome"]);
        $logo = mysqli_real_escape_string($conn, $sq_info["logo"]);
        mysqli_query($conn, "INSERT INTO NEW_GENERALE
                (stagione, id_squadra, squadra, logo, punti, partiteg, vinte, nulle, perse,
                 golf, gols, maxp, minp, media, segno)
                VALUES ($stagione, $id, '$nome', '$logo', $punti, $pg, $vinte, $nulle, $perse,
                        $golf, $gols, $maxp, $minp, $media, '$segno')");
    }
    $aggiornate++;
}

api_success(["ok" => true, "squadre_aggiornate" => $aggiornate]);
