<?php
// ============================================================
// api/admin/ai_articoli.php
//
// FASE 3 - Gestione degli articoli AI generati (bozze e pubblicati).
//
// Solo POST. Body: { stagione, utenza, password, azione, ... }
// ACCESSO: utenza con NEW_UTENZE.abilita_ai = 'Y', password verificata
// lato server (vedi lib/AiAdmin.php).
//
// azione = "elenco"      Body opz.: filtro_stagione (int)
//      → { articoli[], spesa_mese_usd, spesa_totale_usd, cambio_eur }
//        (senza il testo; max 200 righe, le più recenti)
// azione = "dettaglio"   Body: id          → articolo completo
// azione = "salva"       Body: id, titolo, sommario, testo
//      Modifica un articolo (bozza o pubblicato). Se il testo cambia, il
//      flag "troncato" viene azzerato: l'amministratore l'ha riletto.
// azione = "pubblica"    Body: id
//      Pubblica l'articolo. Per la stessa stagione e giornata ne resta
//      UNO solo pubblicato: gli altri tornano in bozza (campo "sostituiti").
// azione = "bozza"       Body: id          → toglie dalla pubblicazione
// azione = "elimina"     Body: id          → cancella definitivamente
//
// Tutte le azioni di modifica rispondono con l'articolo aggiornato.
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AnthropicClient.php";
require_once __DIR__ . "/../lib/AiAdmin.php";
require_once __DIR__ . "/../lib/ArticoloGiornata.php";

mysqli_set_charset($conn, "utf8mb4");

$input  = aiadmin_input($conn);
$azione = strtolower(trim((string) ($input["azione"] ?? "")));

if (!art_tabelle_presenti($conn)) {
    api_error("Tabelle degli articoli AI mancanti: eseguire Script DB/NEW_AI_ARTICOLI.sql", 500);
}

/** Articolo completo (con testo) o null. */
function ai_art_riga(int $id, bool $conTesto = true): ?array
{
    $campi = "id, stagione, giornata, titolo, sommario, stato, modello, troncato,
              token_input_stimati, costo_stimato_usd, token_input, token_output, costo_usd,
              creato_da, episodi_usati, data_generazione, data_pubblicazione"
           . ($conTesto ? ", testo" : "");
    $r = query_one("SELECT $campi FROM NEW_ARTICOLI WHERE id = $id");
    return $r ? ai_art_normalizza($r) : null;
}

function ai_art_normalizza(array $r): array
{
    foreach (["id", "stagione", "giornata", "token_input_stimati", "token_input", "token_output"] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    foreach (["costo_stimato_usd", "costo_usd"] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (float) $r[$k];
    }
    $r["troncato"] = ($r["troncato"] ?? "N") === "Y";
    return $r;
}

function ai_art_esiste_o_404(int $id): array
{
    $r = ai_art_riga($id);
    if (!$r) api_error("Articolo non trovato", 404);
    return $r;
}

switch ($azione) {

    case "elenco":
        $filtro = (int) ($input["filtro_stagione"] ?? 0);
        $where  = $filtro > 0 ? "WHERE stagione = $filtro" : "";
        $righe = array_map("ai_art_normalizza", query_all(
            "SELECT id, stagione, giornata, titolo, sommario, stato, modello, troncato,
                    costo_stimato_usd, costo_usd, creato_da, data_generazione, data_pubblicazione
             FROM NEW_ARTICOLI $where
             ORDER BY data_generazione DESC, id DESC LIMIT 200"));
        $tot = query_one("SELECT COALESCE(SUM(costo_usd), 0) AS t FROM NEW_ARTICOLI");
        $cambio = art_cfg("cambio_eur_per_usd", null);
        api_success([
            "articoli"         => $righe,
            "spesa_mese_usd"   => round(art_spesa_mese_usd($conn), 6),
            "spesa_totale_usd" => round((float) ($tot["t"] ?? 0), 6),
            "cambio_eur"       => $cambio !== null ? (float) $cambio : null,
        ]);

    case "dettaglio":
        api_success(ai_art_esiste_o_404(aiadmin_id($input)));

    case "salva":
        $id  = aiadmin_id($input);
        $att = ai_art_esiste_o_404($id);
        $titolo   = aiadmin_testo($input, "titolo", 250, true);
        $sommario = aiadmin_testo($input, "sommario", 1000, false);
        $testo    = aiadmin_testo($input, "testo", 60000, true);
        $troncato = ($testo !== (string) $att["testo"]) ? "N" : ($att["troncato"] ? "Y" : "N");

        $st = mysqli_prepare($conn, "UPDATE NEW_ARTICOLI SET titolo = ?, sommario = ?, testo = ?, troncato = ? WHERE id = ?");
        mysqli_stmt_bind_param($st, "ssssi", $titolo, $sommario, $testo, $troncato, $id);
        if (!mysqli_stmt_execute($st)) api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st), 500);
        mysqli_stmt_close($st);
        api_success(ai_art_esiste_o_404($id));

    case "pubblica":
        $id  = aiadmin_id($input);
        $att = ai_art_esiste_o_404($id);
        if (trim((string) $att["titolo"]) === "" || trim((string) $att["testo"]) === "") {
            api_error("Titolo e testo sono obbligatori per pubblicare", 400);
        }
        $s = (int) $att["stagione"];
        $g = (int) $att["giornata"];
        mysqli_begin_transaction($conn);
        try {
            mysqli_query($conn, "UPDATE NEW_ARTICOLI SET stato = 'bozza', data_pubblicazione = NULL
                                 WHERE stagione = $s AND giornata = $g AND stato = 'pubblicato' AND id <> $id");
            $sostituiti = mysqli_affected_rows($conn);
            mysqli_query($conn, "UPDATE NEW_ARTICOLI SET stato = 'pubblicato',
                                        data_pubblicazione = COALESCE(data_pubblicazione, NOW())
                                 WHERE id = $id");
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            api_error("Pubblicazione non riuscita: " . $e->getMessage(), 500);
        }
        $r = ai_art_esiste_o_404($id);
        $r["sostituiti"] = max(0, (int) $sostituiti);
        api_success($r);

    case "bozza":
        $id = aiadmin_id($input);
        ai_art_esiste_o_404($id);
        mysqli_query($conn, "UPDATE NEW_ARTICOLI SET stato = 'bozza', data_pubblicazione = NULL WHERE id = $id");
        api_success(ai_art_esiste_o_404($id));

    case "elimina":
        $id = aiadmin_id($input);
        ai_art_esiste_o_404($id);
        mysqli_query($conn, "DELETE FROM NEW_ARTICOLI WHERE id = $id");
        api_success(["id" => $id, "eliminato" => true]);

    default:
        api_error("Azione non valida: usare elenco, dettaglio, salva, pubblica, bozza o elimina", 400);
}
