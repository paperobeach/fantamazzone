<?php
// ============================================================
// api/statistiche.php
// GET ?stagione=2024
// GET ?stagione=2024&id_squadra=3
// GET ?stagione=2024&ruolo=1       (1=P 2=D 3=C 4=A)
// GET ?stagione=2024&ordine=media|gols|assist|ammonizioni
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione    = param_int("stagione");
$id_squadra  = param_int("id_squadra", false);
$ruolo       = param_int("ruolo", false);
$ordine_raw  = isset($_GET["ordine"]) ? trim($_GET["ordine"]) : "media";

$ordini_validi = ["media", "gols", "assist", "ammonizioni", "espulsioni", "giocate"];
$ordine = in_array($ordine_raw, $ordini_validi) ? $ordine_raw : "media";

$where = ["stagione = $stagione"];
if ($id_squadra !== null) $where[] = "id_squadra = $id_squadra";
if ($ruolo !== null)       $where[] = "ruolo = $ruolo";
$where_sql = implode(" AND ", $where);

$sql = "SELECT
            id_squadra, squadra, logo,
            id_giocatore, giocatore,
            ruolo, giocate, media,
            golf, gols, assist,
            ammonizioni, espulsioni,
            rigores, rigorep, autogol
        FROM NEW_STATISTICHE
        WHERE $where_sql
        ORDER BY $ordine DESC, giocatore ASC";

api_success(query_all($sql));
