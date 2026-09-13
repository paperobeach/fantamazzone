<?php
// ============================================================
// api/formazioni.php
//
// GET  ?stagione=2024&giornata=5&id_squadra=3  → formazione
// POST { stagione, giornata, id_squadra, giocatori: [{id, maglia}] }
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $giornata   = post_int("giornata");
    $id_squadra = post_int("id_squadra");

    // Accetta JSON nel body oppure form data
    $input = file_get_contents("php://input");
    $body  = json_decode($input, true);
    if ($body === null) $body = $_POST;

    $giocatori = $body["giocatori"] ?? null;
    if (!is_array($giocatori) || count($giocatori) === 0) {
        api_error("Campo 'giocatori' obbligatorio (array di {id, maglia})");
    }

    // Verifica che la giornata non sia già giocata
    $ck = query_one("SELECT COUNT(*) AS n FROM CALENDARIO_CK
                     WHERE stagione = $stagione
                       AND giornata <= $giornata
                       AND ck_giocata = 'S'");
    if ((int)($ck["n"] ?? 0) >= $giornata) {
        api_error("Giornata già chiusa, impossibile modificare la formazione", 403);
    }

    // Cancella formazione precedente e reinserisce
    mysqli_query($conn, "DELETE FROM FORMAZIONI
                         WHERE STAGIONE = $stagione
                           AND ID_SQUADRA = $id_squadra
                           AND GIORNATA = $giornata");

    foreach ($giocatori as $g) {
        $id_g   = (int)$g["id"];
        $maglia = (int)$g["maglia"];
        if ($id_g <= 0) continue;
        mysqli_query($conn, "INSERT INTO FORMAZIONI (STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA)
                             VALUES ($stagione, $id_squadra, $id_g, $giornata, $maglia)");
    }

    api_success(["ok" => true]);

} else {

    $giornata   = param_int("giornata");
    $id_squadra = param_int("id_squadra");

    $data = query_all("SELECT
            f.ID_GIOCATORE AS id_giocatore,
            g.descrizione  AS giocatore,
            g.ruolo,
            f.MAGLIA       AS maglia
        FROM FORMAZIONI f
        JOIN GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
        WHERE f.STAGIONE    = $stagione
          AND f.ID_SQUADRA  = $id_squadra
          AND f.GIORNATA    = $giornata
        ORDER BY f.MAGLIA");

    api_success($data);
}
