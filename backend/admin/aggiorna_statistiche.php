<?php
// ============================================================
// api/admin/aggiorna_statistiche.php
// POST { stagione }
//
// Ricalcola la tabella STATISTICHE aggregando tutti i VOTI
// per la stagione. Da chiamare dopo ogni chiusura giornata.
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") api_error("Usare POST", 405);

$stagione = post_int("stagione");

// Svuota e ricalcola da zero
mysqli_query($conn, "DELETE FROM NEW_STATISTICHE WHERE stagione = $stagione");

$sql = "INSERT INTO NEW_STATISTICHE
            (stagione, id_squadra, squadra, logo,
             id_giocatore, giocatore, ruolo,
             giocate, media, golf, gols, assist,
             ammonizioni, espulsioni, rigores, rigorep, autogol)
        SELECT
            v.stagione,
            v.id_squadra,
            s.nome    AS squadra,
            s.logo,
            v.id_giocatore,
            g.descrizione AS giocatore,
            g.ruolo,
            SUM(v.giocata)         AS giocate,
            ROUND(
                CASE WHEN SUM(v.giocata) > 0
                     THEN SUM(CASE WHEN v.giocata = 1 THEN v.voto ELSE 0 END) / SUM(v.giocata)
                     ELSE 0 END, 2) AS media,
            SUM(CASE WHEN v.reti > 0    THEN 1 ELSE 0 END) AS golf,
            SUM(v.reti)            AS gols,
            SUM(v.assist)          AS assist,
            SUM(v.ammonizioni)     AS ammonizioni,
            SUM(v.espulsioni)      AS espulsioni,
            SUM(v.rigores)         AS rigores,
            SUM(v.rigorep)         AS rigorep,
            SUM(v.autogol)         AS autogol
        FROM NEW_VOTI v
        JOIN NEW_SQUADRE  s ON s.id = v.id_squadra   AND s.stagione = v.stagione
        JOIN NEW_GIOCATORI g ON g.id = v.id_giocatore AND g.stagione = v.stagione
        WHERE v.stagione = $stagione
        GROUP BY v.stagione, v.id_squadra, s.nome, s.logo,
                 v.id_giocatore, g.descrizione, g.ruolo";

if (!mysqli_query($conn, $sql)) {
    api_error("Errore aggiornamento statistiche: " . mysqli_error($conn), 500);
}

$count = query_one("SELECT COUNT(*) AS n FROM NEW_STATISTICHE WHERE stagione = $stagione");
api_success(["ok" => true, "righe_inserite" => (int)$count["n"]]);
