<?php
// ============================================================
// api/live_simulazione.php
//
// Gestione della simulazione (l'azione "simula" è libera, senza controllo ruolo) della giornata IN CORSO (pagina "LIVE
// Giornata in corso"). Opera solo su NEW_SIMULAZIONE_VOTI,
// NEW_SIMULAZIONE_RISULTATI e NEW_SIMULAZIONE_EDIT: non tocca mai
// NEW_VOTI / NEW_RISULTATI.
//
// GET  ?stagione=2026&id_squadra=3
//      Giocatori in formazione (titolari + panchina) della squadra per
//      la giornata in corso, con il dato usato dalla simulazione e il
//      flag "modificabile" (squadra di Serie A non ancora scesa in
//      campo e nessun voto reale).
//
// POST { stagione, azione: "simula" [, id_squadra] }
//      Ricostruisce la simulazione dell'intera giornata in corso.
//      Con id_squadra (una delle due squadre della partita) simula e
//      salva SOLO quella partita.
//
// POST { stagione, azione: "salva_edit", id_giocatore,
//        voto, gf, gs, rp, rs, rf, au, amm, esp, ass }
//      Salva l'editing manuale di un giocatore (prevale sul 6
//      provvisorio) e ricalcola/salva la SOLA partita del giocatore.
//      Rifiutato se il giocatore ha già un voto reale o la sua squadra
//      di Serie A ha già giocato.
//
// POST { stagione, azione: "elimina_edit", id_giocatore }
//      Rimuove l'editing manuale di un giocatore (si torna al 6
//      provvisorio) e ricalcola la SOLA partita del giocatore.
//
// POST { stagione, azione: "elimina_simulazione", id_squadra }
//      Cancella la simulazione di UNA partita: voti e risultati
//      simulati delle due squadre e gli editing manuali dei loro
//      giocatori. Le altre partite restano invariate.
//
// Endpoint libero: nessun controllo di ruolo (la simulazione è aperta
// a tutti gli utenti). Non tocca mai NEW_VOTI / NEW_RISULTATI.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/SimulazioneGiornata.php";

mysqli_set_charset($conn, "utf8mb4");

$metodo = $_SERVER["REQUEST_METHOD"];
if ($metodo === "POST") {
    $input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $stagione = (int) ($input["stagione"] ?? 0);
} else {
    $input    = [];
    $stagione = param_int("stagione");
}
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

$stato = sg_stato_corrente($stagione);
if (!$stato["in_corso"]) api_error($stato["motivo"] ?? "Nessuna giornata in corso", 409);
$giornata = $stato["giornata"];

// Difesa: per sicurezza (la giornata in corso non è mai chiusa per costruzione)
if (sg_giornata_chiusa($stagione, $giornata)) {
    api_error("La giornata $giornata è già chiusa", 403);
}

// ============================================================
// GET: giocatori della squadra con dato di simulazione
// ============================================================
if ($metodo === "GET") {
    $idSquadra = param_int("id_squadra");

    $righe = query_all("SELECT f.ID_GIOCATORE AS id_giocatore, f.MAGLIA AS maglia, g.ruolo, g.descrizione
                        FROM NEW_FORMAZIONI f
                        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                        WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idSquadra AND f.GIORNATA = $giornata
                        ORDER BY f.MAGLIA");

    $ctx = sim_contesto($stagione, $giornata);
    $out = [];
    foreach ($righe as $r) {
        $id = (int) $r["id_giocatore"];
        [$modificabile, $motivo] = sim_giocatore_modificabile($ctx, $id);

        $reale = $ctx["voti"][$id] ?? null;
        $edit  = $ctx["edit"][$id] ?? null;
        $stato_dato = $reale !== null ? "reale"
                    : ($edit !== null && $modificabile ? "manuale"
                    : ($modificabile ? "provvisorio" : "senza_voto"));

        $dato = $reale ?? ($modificabile ? $edit : null);
        $out[] = [
            "id_giocatore" => $id,
            "giocatore"    => $r["descrizione"],
            "ruolo"        => (int) $r["ruolo"],
            "maglia"       => (int) $r["maglia"],
            "titolare"     => (int) $r["maglia"] <= 11,
            "squadra_serie_a" => $ctx["squadre"][$id] ?? null,
            "modificabile" => $modificabile,
            "motivo_blocco" => $motivo,
            "stato_dato"   => $stato_dato,
            "voto" => $dato !== null && $dato["voto"] !== null ? (float) $dato["voto"]
                    : ($stato_dato === "provvisorio" ? SIM_VOTO_PROVVISORIO : null),
            "gf"  => (int) ($dato["gf"]  ?? 0), "gs"  => (int) ($dato["gs"]  ?? 0),
            "rp"  => (int) ($dato["rp"]  ?? 0), "rs"  => (int) ($dato["rs"]  ?? 0),
            "rf"  => (int) ($dato["rf"]  ?? 0), "au"  => (int) ($dato["au"]  ?? 0),
            "amm" => (int) ($dato["amm"] ?? 0), "esp" => (int) ($dato["esp"] ?? 0),
            "ass" => (int) ($dato["ass"] ?? 0),
        ];
    }
    api_success(["giornata" => $giornata, "giocatori" => $out]);
}

if ($metodo !== "POST") api_error("Metodo non consentito. Usare GET o POST.", 405);

$azione = (string) ($input["azione"] ?? "");

// Lock applicativo: una sola scrittura alla volta per stagione
$lockName = "fantamazzone_live_$stagione";
$lockRes  = mysqli_query($conn, "SELECT GET_LOCK('$lockName', 5) AS l");
$lockRow  = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
if (!$lockRow || (int) $lockRow["l"] !== 1) {
    api_error("Un'altra operazione sulla simulazione è già in corso", 409);
}
$rilascia = function () use ($conn, $lockName) { mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')"); };

try {
    if ($azione === "simula") {
        $solo = (int) ($input["id_squadra"] ?? 0) ?: null;
        $res = sim_esegui($conn, $stagione, $giornata, $solo);
        $rilascia();
        api_success(array_merge(["ok" => true, "stagione" => $stagione, "giornata" => $giornata], $res));
    }

    if ($azione === "salva_edit" || $azione === "elimina_edit") {
        $idG = (int) ($input["id_giocatore"] ?? 0);
        if (!$idG) { $rilascia(); api_error("Parametro obbligatorio mancante: id_giocatore", 400); }

        // Il giocatore deve far parte di una formazione della giornata
        $f = query_one("SELECT ID_SQUADRA AS sq FROM NEW_FORMAZIONI
                        WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_GIOCATORE = $idG");
        $squadraG = (int) ($f["sq"] ?? 0);
        if ($squadraG === 0) { $rilascia(); api_error("Il giocatore non è schierato in nessuna formazione della giornata", 400); }

        if ($azione === "elimina_edit") {
            mysqli_query($conn, "DELETE FROM NEW_SIMULAZIONE_EDIT
                                 WHERE stagione = $stagione AND giornata = $giornata AND id_giocatore = $idG");
            $res = sim_esegui($conn, $stagione, $giornata, $squadraG);
            $rilascia();
            api_success(array_merge(["ok" => true, "giornata" => $giornata, "id_giocatore" => $idG], $res));
        }

        $ctx = sim_contesto($stagione, $giornata);
        [$modificabile, $motivo] = sim_giocatore_modificabile($ctx, $idG);
        if (!$modificabile) { $rilascia(); api_error($motivo, 409); }

        $voto = isset($input["voto"]) && is_numeric($input["voto"]) ? round((float) $input["voto"], 1) : null;
        if ($voto === null || $voto < 0 || $voto > 10) { $rilascia(); api_error("Voto non valido (0-10)", 400); }

        $campi = [];
        foreach (["gf", "gs", "rp", "rs", "rf", "au", "amm", "esp", "ass"] as $c) {
            $v = (int) ($input[$c] ?? 0);
            if ($v < 0 || $v > 20) { $rilascia(); api_error("Valore non valido per $c", 400); }
            $campi[$c] = $v;
        }
        $votoS = sprintf("%.1f", $voto);
        $ok = mysqli_query($conn, "REPLACE INTO NEW_SIMULAZIONE_EDIT
            (stagione, giornata, id_giocatore, voto, gf, gs, rp, rs, rf, au, amm, esp, ass, aggiornato_il)
            VALUES ($stagione, $giornata, $idG, $votoS,
                    {$campi['gf']}, {$campi['gs']}, {$campi['rp']}, {$campi['rs']}, {$campi['rf']},
                    {$campi['au']}, {$campi['amm']}, {$campi['esp']}, {$campi['ass']}, NOW(3))");
        if (!$ok) throw new Exception(mysqli_error($conn));
        $res = sim_esegui($conn, $stagione, $giornata, $squadraG);
        $rilascia();
        api_success(array_merge(["ok" => true, "giornata" => $giornata, "id_giocatore" => $idG], $res));
    }

    if ($azione === "elimina_simulazione") {
        $idS = (int) ($input["id_squadra"] ?? 0);
        if (!$idS) { $rilascia(); api_error("Parametro obbligatorio mancante: id_squadra", 400); }
        $edit = sim_elimina_partita($conn, $stagione, $giornata, $idS);
        $rilascia();
        api_success(["ok" => true, "giornata" => $giornata, "edit_rimossi" => $edit]);
    }

    $rilascia();
    api_error("Azione non valida (simula | salva_edit | elimina_edit | elimina_simulazione)", 400);
} catch (Throwable $e) {
    $rilascia();
    api_error("Errore: " . $e->getMessage(), 500);
}
