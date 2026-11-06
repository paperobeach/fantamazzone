<?php
// ============================================================
// api/all_star_game.php
//
// ALL STAR GAME (Est contro Ovest): vedi lib/AllStarGame.php per le regole.
//
// GET ?stagione=2026&modo=pagina[&id_richiedente=3]
//     Dati della pagina "All Star Game" (menu Generale): giornata configurata
//     (parametro ASG_GIORNATA), stato (non_configurato | da_giocare | in_corso | giocata),
//     le due compagini con le squadre che le compongono, lo stato della formazione di
//     ciascuna (inviata, ok dei fantallenatori) e, se visibile, la formazione. La
//     formazione di una compagine è visibile ai suoi componenti, e a tutti dall'inizio
//     della prima partita di Serie A della giornata. Se la partita è stata calcolata a
//     giornata chiusa (o su stagione storica) c'è anche "risultato".
//
// GET ?stagione=2026&modo=dettaglio
//     Dettaglio voti della partita (stessa struttura di dettaglio_partita.php, con
//     "extra" per supplementari e rigori). Solo a giornata chiusa o su stagioni storiche.
//
// GET ?stagione=2026&giornata=5&id_squadra=3&modo=contesto
//     Tutto ciò che serve all'inserimento della formazione dell'All Star Game per il
//     fantallenatore id_squadra: se la giornata è quella dell'All Star Game ("attivo"),
//     la sua compagine, i compagni con l'ok dato, la rosa (unione delle 4 rose), la
//     bozza salvata, l'ordine dei rigoristi, lo stato di invio e se è modificabile.
//
// POST { stagione, giornata, id_squadra, azione, ... }   id_squadra = fantallenatore che opera
//     azione "salva"     { giocatori: [{id, maglia}], rigoristi?: [id...] }  salva la bozza della
//                        compagine (gli ok-delega già dati restano validi)
//     azione "ok"        registra l'ok-delega del fantallenatore (anche prima della bozza)
//     azione "revoca_ok" ritira l'ok
//     azione "invia"     invia la formazione (serve l'ok-delega di TUTTI): definitiva, notificata via
//                        mail a tutti i fantallenatori (Est e Ovest)
// L'API non ha sessioni: l'operatore è indicato dal front end con id_squadra (= id utente).
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/AllStarGame.php";
require_once __DIR__ . "/lib/AvversarieSerieA.php";

mysqli_set_charset($conn, "utf8mb4");

/** Nome leggibile dell'allenatore/squadra per le informazioni di stato. */
function asg_nome_squadra_utente(int $stagione, ?int $idSquadra): ?string
{
    if (!$idSquadra) return null;
    $r = query_one("SELECT s.nome, a.descrizione AS allenatore
                    FROM NEW_SQUADRE s
                    LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                    WHERE s.id = $idSquadra AND s.stagione = $stagione");
    if (!$r) return null;
    return ($r["allenatore"] ?? "") !== "" ? $r["allenatore"] . " (" . $r["nome"] . ")" : $r["nome"];
}

/** Risposta con i dati di una compagine per la pagina pubblica. */
function asg_scheda_compagine(int $stagione, int $giornata, int $idLato, ?int $idRichiedente): array
{
    $stato = asg_stato_lato($stagione, $giornata, $idLato);
    $visibile = asg_formazione_visibile($stagione, $giornata, $idLato, $idRichiedente);
    $righe = asg_formazione($stagione, $giornata, $idLato);
    $elenco = asg_elenco_ok($stagione, $giornata, $idLato);
    return [
        "id"         => $idLato,
        "nome"       => asg_nome_lato($idLato),
        "squadre"    => array_map(fn($s) => ["id" => $s["id"], "nome" => $s["nome"], "logo" => $s["logo"], "allenatore" => $s["allenatore"]],
                                  asg_squadre_lato($stagione, $idLato)),
        "formazione" => [
            "inviata"    => $stato["inviata"],
            "inviata_il" => $stato["inviata_il"],
            "inviata_da" => $stato["inviata_da"] ? asg_nome_squadra_utente($stagione, $stato["inviata_da"]) : null,
            "presente"   => count($righe) > 0,
            "completa"   => asg_formazione_completa($righe),
            "ok"         => array_map(fn($o) => ["id" => $o["id"], "nome" => $o["nome"], "allenatore" => $o["allenatore"], "ok" => $o["ok"]], $elenco),
            "visibile"   => $visibile,
            // la formazione vera e propria solo a chi può vederla
            "righe"      => $visibile ? $righe : [],
            "rigoristi"  => $visibile ? asg_rigoristi($stagione, $giornata, $idLato) : [],
        ],
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) $input = $_POST;

    $stagione   = (int) ($input["stagione"] ?? 0);
    $giornata   = (int) ($input["giornata"] ?? 0);
    $idSquadra  = (int) ($input["id_squadra"] ?? 0);
    $azione     = (string) ($input["azione"] ?? "");
    if (!$stagione || !$giornata || !$idSquadra) api_error("Parametri obbligatori mancanti: stagione, giornata, id_squadra", 400);
    $idLato = asg_lato_di_squadra($idSquadra);

    // Lock applicativo: una sola scrittura alla volta per compagine
    $lockName = "fantamazzone_asg_{$stagione}_{$giornata}_$idLato";
    $lockRes  = mysqli_query($conn, "SELECT GET_LOCK('$lockName', 5) AS l");
    $lockRow  = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
    if (!$lockRow || (int) $lockRow["l"] !== 1) api_error("Un'altra operazione sulla formazione è già in corso, riprova", 409);
    $rilascia = function () use ($conn, $lockName) { mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')"); };

    try {
        if ($azione === "salva") {
            $giocatori = $input["giocatori"] ?? null;
            if (!is_array($giocatori) || count($giocatori) === 0) { $rilascia(); api_error("Campo 'giocatori' obbligatorio (array di {id, maglia})", 400); }
            $rigoristi = array_key_exists("rigoristi", $input) ? (is_array($input["rigoristi"]) ? $input["rigoristi"] : []) : null;
            asg_salva_formazione($stagione, $giornata, $idLato, $idSquadra, $giocatori, $rigoristi);
            $rilascia();
            api_success(["ok" => true, "azione" => "salva", "compagine" => asg_nome_lato($idLato)]);
        }

        if ($azione === "ok" || $azione === "revoca_ok") {
            asg_imposta_ok($stagione, $giornata, $idLato, $idSquadra, $azione === "ok");
            $elenco = asg_elenco_ok($stagione, $giornata, $idLato);
            $rilascia();
            api_success(["ok" => true, "azione" => $azione, "tutti_ok" => asg_tutti_ok($elenco)]);
        }

        if ($azione === "invia") {
            $mail = asg_invia_formazione($stagione, $giornata, $idLato, $idSquadra);
            $rilascia();
            $inviate = count(array_filter($mail, fn($m) => $m["ok"]));
            api_success([
                "ok" => true, "azione" => "invia", "compagine" => asg_nome_lato($idLato),
                "email_inviate" => $inviate, "email_totali" => count($mail), "email" => $mail,
            ]);
        }

        $rilascia();
        api_error("Azione non valida (salva | ok | revoca_ok | invia)", 400);
    } catch (Throwable $e) {
        $rilascia();
        $code = (int) $e->getCode();
        api_error($e->getMessage(), $code >= 400 && $code < 600 ? $code : 500);
    }
    exit;
}

// ============================================================
// GET
// ============================================================
$stagione = param_int("stagione");
$modo = param_str("modo", false) ?? "pagina";

if ($modo === "contesto") {
    $giornata  = param_int("giornata");
    $idSquadra = param_int("id_squadra");
    $cfg = asg_giornata_config($stagione);
    $attivo = $cfg !== null && $cfg === $giornata;

    if (!$attivo) {
        api_success(["attivo" => false, "giornata_asg" => $cfg]);
    }

    $idLato = asg_lato_di_squadra($idSquadra);
    $stato  = asg_stato_lato($stagione, $giornata, $idLato);
    $righe  = asg_formazione($stagione, $giornata, $idLato);
    $elenco = asg_elenco_ok($stagione, $giornata, $idLato);
    $mod    = asg_modificabile($stagione, $giornata);
    $blocco = fb_stato_blocco($stagione, $giornata);

    // Avversaria di Serie A di ogni giocatore della rosa della compagine (informativo)
    $avv = [];
    foreach (asg_ids_squadre_lato($stagione, $idLato) as $idS) {
        try {
            foreach (av_avversarie_rosa($stagione, $giornata, $idS)["giocatori"] ?? [] as $a) $avv[] = $a;
        } catch (Throwable $e) { /* dato facoltativo */ }
    }

    $miOk = false;
    foreach ($elenco as $o) if ($o["id"] === $idSquadra) $miOk = $o["ok"];

    api_success([
        "attivo"        => true,
        "giornata_asg"  => $cfg,
        "id_lato"       => $idLato,
        "nome_lato"     => asg_nome_lato($idLato),
        "avversaria"    => ["id" => asg_altro_lato($idLato), "nome" => asg_nome_lato(asg_altro_lato($idLato))],
        "compagni"      => $elenco,
        "ok_mio"        => $miOk,
        "tutti_ok"      => asg_tutti_ok($elenco),
        "completa"      => asg_formazione_completa($righe),
        "inviata"       => $stato["inviata"],
        "inviata_il"    => $stato["inviata_il"],
        "inviata_da"    => $stato["inviata_da"] ? asg_nome_squadra_utente($stagione, $stato["inviata_da"]) : null,
        "aggiornata_da" => $stato["aggiornata_da"] ? asg_nome_squadra_utente($stagione, $stato["aggiornata_da"]) : null,
        "aggiornata_il" => $stato["aggiornata_il"],
        "modificabile"  => $mod["ok"] && !$stato["inviata"],
        "motivo_blocco" => $mod["motivo"],
        "limite_inserimento" => $blocco["limite"],
        "rosa"          => asg_rosa($stagione, $idLato),
        "formazione"    => $righe,
        "rigoristi"     => asg_rigoristi($stagione, $giornata, $idLato),
        "avversarie"    => $avv,
    ]);
}

if ($modo === "dettaglio") {
    $giornata = param_int("giornata", false);
    if (!$giornata) {
        $r = query_one("SELECT MAX(giornata) AS g FROM " . ASG_TAB_RISULTATI . " WHERE stagione = $stagione");
        $giornata = (int) ($r["g"] ?? 0);
    }
    if (!$giornata || !sg_risultati_pubblici($stagione, $giornata)) api_success(null);
    api_success(asg_dettaglio($stagione, $giornata, "reale"));
}

if ($modo === "pagina") {
    $idRichiedente = param_int("id_richiedente", false);
    $cfg = asg_giornata_config($stagione);

    // Stagioni storiche senza parametro: la giornata si ricava dal risultato salvato
    $giornata = $cfg;
    if ($giornata === null) {
        try {
            $r = query_one("SELECT MAX(giornata) AS g FROM " . ASG_TAB_RISULTATI . " WHERE stagione = $stagione");
            $g = (int) ($r["g"] ?? 0);
            if ($g > 0) $giornata = $g;
        } catch (Throwable $e) { /* tabella non ancora creata */ }
    }

    if ($giornata === null) {
        api_success(["stato" => "non_configurato", "giornata" => null, "compagini" => [], "risultato" => null]);
    }

    $chiusa = sg_giornata_chiusa($stagione, $giornata);
    $storica = $stagione !== sg_ultima_stagione();
    $statoCorrente = sg_stato_corrente($stagione);
    $inCorso = $statoCorrente["in_corso"] && $statoCorrente["giornata"] === $giornata;
    $stato = ($chiusa || $storica) ? "giocata" : ($inCorso ? "in_corso" : "da_giocare");

    $compagini = [
        asg_scheda_compagine($stagione, $giornata, ASG_ID_EST, $idRichiedente),
        asg_scheda_compagine($stagione, $giornata, ASG_ID_OVEST, $idRichiedente),
    ];

    // Risultato: pubblico solo a giornata chiusa o su stagione storica
    $risultato = null;
    if (sg_risultati_pubblici($stagione, $giornata)) {
        $r = query_one("SELECT r.ftotale, r.ftotale_a, r.golf, r.gols, r.segno,
                               r.supp_giocati, r.supp_golf, r.supp_gols, r.rig_giocati, r.rig_golf, r.rig_gols,
                               r.passa AS passa_casa, r2.passa AS passa_ospite
                        FROM " . ASG_TAB_RISULTATI . " r
                        LEFT JOIN " . ASG_TAB_RISULTATI . " r2 ON r2.stagione = r.stagione AND r2.giornata = r.giornata
                                                              AND r2.id_squadra = r.id_squadra_a
                        WHERE r.stagione = $stagione AND r.giornata = $giornata AND r.id_squadra = " . ASG_ID_EST);
        if ($r) {
            $risultato = [
                "ftotale_casa"   => (float) $r["ftotale"],
                "ftotale_ospite" => (float) $r["ftotale_a"],
                "golf"           => (int) $r["golf"],
                "gols"           => (int) $r["gols"],
                "segno"          => $r["segno"],
                "supp"           => (int) $r["supp_giocati"] === 1 ? ["casa" => (int) $r["supp_golf"], "ospite" => (int) $r["supp_gols"]] : null,
                "rig"            => (int) $r["rig_giocati"] === 1 ? ["casa" => (int) $r["rig_golf"], "ospite" => (int) $r["rig_gols"]] : null,
                "passa"          => (int) $r["passa_casa"] === 1 ? "casa" : ((int) $r["passa_ospite"] === 1 ? "ospite" : null),
            ];
        }
    }

    api_success([
        "stato"     => $stato,
        "giornata"  => $giornata,
        "configurata" => $cfg !== null,
        "compagini" => $compagini,
        "risultato" => $risultato,
    ]);
}

api_error("Modo non valido (pagina | dettaglio | contesto)", 400);
