<?php
// ============================================================
// api/articoli.php   (PUBBLICO, sola lettura)
//
// "La Gazzetta del Fantamazzone": articoli AI di commento alla
// giornata già PUBBLICATI (NEW_ARTICOLI.stato = 'pubblicato'). Le bozze,
// il modello usato e i costi non sono mai esposti qui.
//
// GET ?stagione=2026            → elenco (più recente per primo)
//     [{ id, giornata, titolo, sommario, data_pubblicazione,
//        formato, id_immagine_copertina }]
// GET ?id=12                    → singolo articolo pubblicato
//     { id, stagione, giornata, titolo, sommario, testo, data_pubblicazione,
//       formato, id_immagine_copertina, pagine[] }
//     formato = 'articolo' (testo unico) | 'edizione' (sfogliabile). Per le
//     edizioni "pagine" contiene, in ordine, { ordine, tipo, competizione,
//     titolo, testo, id_immagine, didascalia, dati } con "dati" = i fatti
//     (punteggi, marcatori, classifica) della pagina. Le immagini si leggono
//     da immagine_gazzetta.php?id=N (v=thumb per la miniatura).
//
// Se le tabelle di Script DB/NEW_AI_ARTICOLI.sql o di
// Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql non esistono ancora, l'elenco è
// semplicemente vuoto / senza formato (nessun errore).
// ============================================================
require_once __DIR__ . "/connect.php";

mysqli_set_charset($conn, "utf8mb4");

$esiste = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ARTICOLI'");
$tabella = $esiste && mysqli_num_rows($esiste) > 0;

// Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql eseguito? (colonna "formato" e tabella pagine)
$fase4 = false;
if ($tabella) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM NEW_ARTICOLI LIKE 'formato'");
    $p = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ARTICOLI_PAGINE'");
    $fase4 = $c && mysqli_num_rows($c) > 0 && $p && mysqli_num_rows($p) > 0;
}

$id = param_int("id", false);

if ($id !== null) {
    if (!$tabella) api_error("Articolo non trovato", 404);
    $extra = $fase4 ? ", formato, id_immagine_copertina" : "";
    $r = query_one("SELECT id, stagione, giornata, titolo, sommario, testo, data_pubblicazione$extra
                    FROM NEW_ARTICOLI
                    WHERE id = $id AND stato = 'pubblicato'");
    if (!$r) api_error("Articolo non trovato", 404);
    $r["id"]       = (int) $r["id"];
    $r["stagione"] = (int) $r["stagione"];
    $r["giornata"] = (int) $r["giornata"];
    $r["formato"]  = $fase4 ? (string) $r["formato"] : "articolo";
    $r["id_immagine_copertina"] = $fase4 && $r["id_immagine_copertina"] !== null ? (int) $r["id_immagine_copertina"] : null;
    $r["pagine"] = [];
    if ($fase4 && $r["formato"] === "edizione") {
        foreach (query_all("SELECT ordine, tipo, competizione, titolo, testo, id_immagine, didascalia, dati
                            FROM NEW_ARTICOLI_PAGINE WHERE id_articolo = $id ORDER BY ordine, id") as $p) {
            $p["ordine"] = (int) $p["ordine"];
            $p["id_immagine"] = $p["id_immagine"] !== null ? (int) $p["id_immagine"] : null;
            $p["dati"] = $p["dati"] !== null && $p["dati"] !== "" ? json_decode($p["dati"], true) : null;
            $r["pagine"][] = $p;
        }
    }
    api_success($r);
}

$stagione = param_int("stagione");
if (!$tabella) api_success([]);

$extra = $fase4 ? ", formato, id_immagine_copertina" : "";
$righe = query_all("SELECT id, giornata, titolo, sommario, data_pubblicazione$extra
                    FROM NEW_ARTICOLI
                    WHERE stagione = $stagione AND stato = 'pubblicato'
                    ORDER BY giornata DESC, data_pubblicazione DESC, id DESC");
foreach ($righe as &$r) {
    $r["id"]       = (int) $r["id"];
    $r["giornata"] = (int) $r["giornata"];
    $r["formato"]  = $fase4 ? (string) $r["formato"] : "articolo";
    $r["id_immagine_copertina"] = $fase4 && $r["id_immagine_copertina"] !== null ? (int) $r["id_immagine_copertina"] : null;
}
unset($r);
api_success($righe);
