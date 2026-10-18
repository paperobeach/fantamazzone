<?php
// ============================================================
// api/immagine_gazzetta.php   (PUBBLICO, sola lettura)
//
// Serve un'immagine della libreria della Gazzetta (NEW_IMMAGINI_GAZZETTA).
//
// GET ?id=12            → immagine a piena risoluzione (JPEG)
// GET ?id=12&v=thumb    → miniatura (240 px)
//
// Sono servite SOLO le immagini usate da un articolo (copertina, pagina o
// segnaposto nel testo, anche in bozza): quelle che stanno solo in libreria
// sono visibili unicamente dalla pagina admin. Le risposte sono cacheabili
// a lungo: il contenuto di un id non cambia mai.
// ============================================================
require_once __DIR__ . "/connect.php";

mysqli_set_charset($conn, "utf8mb4");

$id = param_int("id");
$thumb = (($_GET["v"] ?? "") === "thumb");

$esiste = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_IMMAGINI_GAZZETTA'");
$colonna = mysqli_query($conn, "SHOW COLUMNS FROM NEW_ARTICOLI LIKE 'immagini_usate'");
if (!$esiste || mysqli_num_rows($esiste) === 0 || !$colonna || mysqli_num_rows($colonna) === 0) {
    api_error("Immagine non trovata", 404);
}

$usata = query_one("SELECT 1 AS ok FROM NEW_ARTICOLI WHERE FIND_IN_SET($id, immagini_usate) > 0 LIMIT 1");
if (!$usata) api_error("Immagine non trovata", 404);

$r = query_one("SELECT mime, dati, miniatura, hash_file FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
if (!$r) api_error("Immagine non trovata", 404);

$bytes = ($thumb && (string) $r["miniatura"] !== "") ? (string) $r["miniatura"] : (string) $r["dati"];
$etag = '"' . ($r["hash_file"] !== "" ? $r["hash_file"] : "img$id") . ($thumb ? "-t" : "") . '"';

while (ob_get_level() > 0) ob_end_clean();
header("Content-Type: " . ($r["mime"] ?: "image/jpeg"));
header("Cache-Control: public, max-age=31536000, immutable");
header("ETag: $etag");
if (($_SERVER["HTTP_IF_NONE_MATCH"] ?? "") === $etag) {
    http_response_code(304);
    exit;
}
header("Content-Length: " . strlen($bytes));
echo $bytes;
exit;
