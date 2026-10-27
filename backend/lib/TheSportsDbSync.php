<?php
// ============================================================
// backend/lib/TheSportsDbSync.php
//
// Sincronizzazione con TheSportsDB (provider "thesportsdb"): stessa logica
// del cron di ApiFootballSync.php (quando interrogare lo decide af_decidi).
//
// Chiamate:
//   - una sola volta: eventsseason.php → id degli eventi di tutta la stagione,
//     associati alle partite del calendario per coppia di squadre;
//   - per ogni partita da verificare: lookupevent.php → stato e risultato;
//   - a partita conclusa: lookuplineup.php + lookuptimeline.php → statistiche.
//
// Cosa si ricava (se formazioni e cronologia sono complete):
//   presente/minuti  titolari e subentrati; minuti stimati
//   gf, rf, au, rs   gol su azione / rigori segnati / autogol / rigori sbagliati
//   ass              assist · gs gol subiti dal portiere in campo
//   amm, esp         cartellini (secondo giallo = solo espulsione)
// NON disponibili: rigori parati (rp) e rating (voto_api).
//
// Formazioni troncate (chiave gratuita: 5 giocatori) NON vengono salvate: i
// giocatori non elencati verrebbero scambiati per assenti.
//
// Va incluso da ApiFootballSync.php (usa le sue funzioni af_*).
// ============================================================

/** Stato di TheSportsDB → codice breve usato nelle tabelle e nella UI. */
function tsdb_stato(?string $stato, ?string $rinviata = null): string
{
    if (strtolower(trim((string) $rinviata)) === "yes") return "PST";
    $s = strtoupper(trim((string) $stato));
    if ($s === "" || $s === "NS" || $s === "TBD" || strpos($s, "NOT STARTED") !== false || strpos($s, "SCHEDULED") !== false) return "NS";
    if (strpos($s, "POSTPONED") !== false) return "PST";
    if (strpos($s, "CANCEL") !== false) return "CANC";
    if (strpos($s, "ABANDON") !== false) return "ABD";
    if (strpos($s, "SUSPEND") !== false) return "SUSP";
    if ($s === "AET" || $s === "PEN") return $s;
    if ($s === "FT" || strpos($s, "FINISHED") !== false || strpos($s, "FULL TIME") !== false || strpos($s, "FULL-TIME") !== false) return "FT";
    if ($s === "HT" || strpos($s, "HALF") !== false) return "HT";
    return "LIVE";
}

/** Posizione testuale di TheSportsDB → Goalkeeper/Defender/Midfielder/Attacker ("" se ignota). */
function tsdb_posizione(?string $p): string
{
    $p = strtolower(trim((string) $p));
    if ($p === "") return "";
    if (strpos($p, "goalkeeper") !== false || $p === "keeper") return "Goalkeeper";
    if (strpos($p, "back") !== false || strpos($p, "defen") !== false) return "Defender";
    if (strpos($p, "midfield") !== false) return "Midfielder";
    if (strpos($p, "forward") !== false || strpos($p, "wing") !== false || strpos($p, "striker") !== false
        || strpos($p, "attack") !== false || strpos($p, "offen") !== false) return "Attacker";
    return "";
}

/**
 * Le formazioni sono complete? (almeno 9 titolari per ciascuna delle due squadre)
 * Con la chiave gratuita arrivano solo 5 elementi in tutto: risultato false.
 */
function tsdb_formazioni_complete(array $lineup): bool
{
    $titolari = [];
    foreach ($lineup as $r) {
        if (strtolower((string) ($r["strSubstitute"] ?? "")) === "yes") continue;
        $t = (int) ($r["idTeam"] ?? 0);
        if ($t > 0) $titolari[$t] = ($titolari[$t] ?? 0) + 1;
    }
    if (count($titolari) < 2) return false;
    foreach ($titolari as $n) if ($n < 9) return false;
    return true;
}

/**
 * Righe di statistiche di una partita da formazioni e cronologia di TheSportsDB.
 * Funzione PURA. Array vuoto se le formazioni non sono complete.
 * Stesso formato di af_righe_statistiche().
 *
 * Cronologia: "Goal" (dettaglio Normal Goal / Penalty / Own Goal / Missed Penalty),
 * "Card" (Yellow Card / Red Card / Second Yellow), "subst" (idPlayer = giocatore che
 * ESCE, idAssist = giocatore che ENTRA).
 */
function tsdb_righe_statistiche(array $lineup, array $timeline): array
{
    if (!tsdb_formazioni_complete($lineup)) return [];

    $righe = [];
    $squadre = [];
    foreach ($lineup as $r) {
        $pid = (int) ($r["idPlayer"] ?? 0);
        $tid = (int) ($r["idTeam"] ?? 0);
        if ($pid <= 0 || $tid <= 0 || isset($righe[$pid])) continue;
        $squadre[$tid] = true;
        $titolare = strtolower((string) ($r["strSubstitute"] ?? "")) !== "yes";
        $righe[$pid] = [
            "presente" => "N", "minuti" => 0, "titolare" => $titolare ? "Y" : "N", "voto_api" => null,
            "gf" => 0, "gs" => 0, "rp" => 0, "rs" => 0, "rf" => 0, "au" => 0, "amm" => 0, "esp" => 0, "ass" => 0,
            "api_player_id" => $pid, "api_team_id" => $tid, "nome" => (string) ($r["strPlayer"] ?? ""),
            "posizione" => tsdb_posizione($r["strPosition"] ?? ""),
            "numero" => isset($r["intSquadNumber"]) && $r["intSquadNumber"] !== "" ? (int) $r["intSquadNumber"] : null,
        ];
    }
    $idSquadre = array_keys($squadre);

    // Sostituzioni
    $entra = []; $esce = [];
    foreach ($timeline as $x) {
        if (strtolower((string) ($x["strTimeline"] ?? "")) !== "subst") continue;
        $out = (int) ($x["idPlayer"] ?? 0);
        $in  = (int) ($x["idAssist"] ?? 0);
        $min = (int) ($x["intTime"] ?? 0);
        if ($out > 0) $esce[$out] = $min;
        if ($in > 0) $entra[$in] = $min;
    }
    foreach ($righe as $pid => &$r) {
        if ($r["titolare"] === "Y") {
            $r["presente"] = "Y";
            $r["minuti"] = isset($esce[$pid]) ? max(1, min(90, $esce[$pid])) : 90;
        } elseif (isset($entra[$pid])) {
            $r["presente"] = "Y";
            $fine = $esce[$pid] ?? 90;
            $r["minuti"] = max(1, min(90, $fine) - $entra[$pid]);
        }
    }
    unset($r);

    // Gol, rigori, autogol, assist e gol subiti
    $subiti = [];
    foreach ($timeline as $x) {
        if (strtolower((string) ($x["strTimeline"] ?? "")) !== "goal") continue;
        $det = strtolower((string) ($x["strTimelineDetail"] ?? ""));
        $sid = (int) ($x["idPlayer"] ?? 0);
        $min = (int) ($x["intTime"] ?? 0);
        $autogol  = strpos($det, "own") !== false;
        $sbagliato = strpos($det, "miss") !== false;
        $rigore   = !$sbagliato && strpos($det, "pen") !== false;

        if ($sid > 0 && isset($righe[$sid])) {
            if ($sbagliato) $righe[$sid]["rs"]++;
            elseif ($autogol) $righe[$sid]["au"]++;
            elseif ($rigore) $righe[$sid]["rf"]++;
            else $righe[$sid]["gf"]++;
            if ($righe[$sid]["presente"] === "N") { $righe[$sid]["presente"] = "Y"; $righe[$sid]["minuti"] = max(1, $righe[$sid]["minuti"]); }
        }
        if ($sbagliato) continue;   // il rigore sbagliato non è un gol

        $aid = (int) ($x["idAssist"] ?? 0);
        if ($aid > 0 && isset($righe[$aid]) && !$autogol) $righe[$aid]["ass"]++;

        $squadraGol = isset($righe[$sid]) ? $righe[$sid]["api_team_id"] : (int) ($x["idTeam"] ?? 0);
        if ($squadraGol > 0 && count($idSquadre) === 2) {
            $altra = $idSquadre[0] === $squadraGol ? $idSquadre[1] : $idSquadre[0];
            $subiti[] = [$autogol ? $squadraGol : $altra, $min];
        }
    }

    // Portieri in campo nei diversi tratti della partita → gol subiti
    foreach ($idSquadre as $tid) {
        $tratti = [];
        foreach ($righe as $pid => $r) {
            if ($r["api_team_id"] !== $tid || $r["posizione"] !== "Goalkeeper") continue;
            if ($r["titolare"] === "Y") $tratti[] = [$pid, 0, $esce[$pid] ?? 999];
            elseif (isset($entra[$pid])) $tratti[] = [$pid, $entra[$pid], $esce[$pid] ?? 999];
        }
        foreach ($subiti as [$sq, $min]) {
            if ($sq !== $tid) continue;
            $scelto = null;
            foreach ($tratti as $t) if ($t[1] <= $min && $min <= $t[2]) $scelto = $t[0];
            if ($scelto === null && count($tratti) === 1) $scelto = $tratti[0][0];
            if ($scelto !== null) $righe[$scelto]["gs"]++;
        }
    }

    // Cartellini
    $gialli = []; $doppi = []; $rossi = [];
    foreach ($timeline as $x) {
        if (strtolower((string) ($x["strTimeline"] ?? "")) !== "card") continue;
        $pid = (int) ($x["idPlayer"] ?? 0);
        if ($pid <= 0) continue;
        $det = strtolower((string) ($x["strTimelineDetail"] ?? ""));
        if (strpos($det, "second") !== false || strpos($det, "yellow/red") !== false || strpos($det, "yellow-red") !== false) $doppi[$pid] = true;
        elseif (strpos($det, "red") !== false) $rossi[$pid] = true;
        elseif (strpos($det, "yellow") !== false) $gialli[$pid] = true;
    }
    foreach ($righe as $pid => &$r) {
        $r["esp"] = (isset($doppi[$pid]) || isset($rossi[$pid])) ? 1 : 0;
        $r["amm"] = (isset($gialli[$pid]) && !isset($doppi[$pid])) ? 1 : 0;
    }
    unset($r);

    return array_values($righe);
}

/**
 * Passi 2-4 del cron per TheSportsDB. Aggiorna $rep per riferimento.
 *
 * @param array $attive partite da verificare (righe di af_sync_esegui, con "_idx")
 */
function tsdb_sync_attive(int $stagione, array $attive, array &$rep, string $adesso): void
{
    $lega  = (int) af_cfg("tsdb_league_id", 4332);
    $gratuita = tsdb_chiave_gratuita();
    $teamPer = afm_team_per_squadra($stagione);

    $registra = function (string $ep, array $par, array $res) use (&$rep) {
        $rep["chiamate"][] = ["endpoint" => $ep, "parametri" => http_build_query($par), "ok" => $res["ok"], "errore" => $res["errore"], "rimaste" => null];
        $rep["richieste_oggi"] = af_richieste_oggi();
    };
    $errore = function (array $m, string $msg) use (&$rep, $stagione) {
        af_partita_upsert($stagione, (int) $m["giornata"], (int) $m["n_partita"], ["errore" => $msg]);
        $rep["partite"][$m["_idx"]]["esito"] = "errore: " . $msg;
    };

    // 1) Associazione degli id evento: una sola chiamata per l'intera stagione
    $senzaId = array_values(array_filter($attive, fn($m) => empty($m["api_fixture_id"])));
    if ($senzaId) {
        $par = ["id" => $lega, "s" => tsdb_season_str($stagione)];
        $res = tsdb_chiamata("eventsseason.php", $par, $stagione);
        $registra("eventsseason.php", $par, $res);
        if (!$res["ok"]) {
            $rep["ok"] = false;
            $rep["avvisi"][] = "Calendario stagione: " . $res["errore"];
            foreach ($senzaId as $m) $errore($m, (string) $res["errore"]);
            $attive = array_values(array_filter($attive, fn($m) => !empty($m["api_fixture_id"])));
        } else {
            $coppie = [];
            foreach ($res["dati"]["events"] ?? [] as $e) {
                $h = (int) ($e["idHomeTeam"] ?? 0);
                $v = (int) ($e["idAwayTeam"] ?? 0);
                if ($h > 0 && $v > 0) $coppie[$h . "-" . $v] = (int) ($e["idEvent"] ?? 0);
            }
            if ($gratuita) {
                $rep["avvisi"][] = "Chiave gratuita di TheSportsDB: il calendario di stagione è limitato a 15 partite, molte potrebbero non essere trovate.";
            }
            // Associa gli id a tutte le partite del calendario (idempotente)
            foreach (query_all("SELECT giornata, n_partita, squadra_casa, squadra_ospite FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione") as $c) {
                $h = $teamPer[afm_chiave_squadra($c["squadra_casa"])] ?? null;
                $v = $teamPer[afm_chiave_squadra($c["squadra_ospite"])] ?? null;
                $id = ($h !== null && $v !== null) ? ($coppie[$h . "-" . $v] ?? 0) : 0;
                if ($id <= 0) continue;
                af_partita_upsert($stagione, (int) $c["giornata"], (int) $c["n_partita"], ["api_fixture_id" => $id]);
                foreach ($attive as &$x) {
                    if ((int) $x["giornata"] === (int) $c["giornata"] && (int) $x["n_partita"] === (int) $c["n_partita"]) $x["api_fixture_id"] = $id;
                }
                unset($x);
            }
            foreach ($senzaId as $m) {
                $h = $teamPer[afm_chiave_squadra($m["squadra_casa"])] ?? null;
                $v = $teamPer[afm_chiave_squadra($m["squadra_ospite"])] ?? null;
                if ($h === null || $v === null) {
                    $errore($m, "Squadra non associata a TheSportsDB (" . ($h === null ? $m["squadra_casa"] : $m["squadra_ospite"]) . ")");
                    continue;
                }
                $trovata = false;
                foreach ($attive as $x) {
                    if ((int) $x["giornata"] === (int) $m["giornata"] && (int) $x["n_partita"] === (int) $m["n_partita"] && !empty($x["api_fixture_id"])) $trovata = true;
                }
                if (!$trovata) $errore($m, "Partita non trovata nel calendario di TheSportsDB" . ($gratuita ? " (chiave gratuita: solo 15 partite)" : ""));
            }
        }
    }

    // 2) Stato di ogni partita attiva ed eventuali statistiche
    foreach ($attive as $m) {
        $id = (int) ($m["api_fixture_id"] ?? 0);
        if ($id <= 0) continue;

        $par = ["id" => $id];
        $res = tsdb_chiamata("lookupevent.php", $par, $stagione);
        $registra("lookupevent.php", $par, $res);
        if (!$res["ok"]) { $rep["ok"] = false; $rep["avvisi"][] = "Partita $id: " . $res["errore"]; $errore($m, (string) $res["errore"]); continue; }
        $e = $res["dati"]["events"][0] ?? null;
        if (!$e) { $errore($m, "Partita assente nella risposta di TheSportsDB"); continue; }

        $stato = tsdb_stato($e["strStatus"] ?? "", $e["strPostponed"] ?? "");
        $campi = [
            "api_stato" => $stato,
            "gol_casa" => isset($e["intHomeScore"]) && $e["intHomeScore"] !== "" ? (int) $e["intHomeScore"] : null,
            "gol_ospite" => isset($e["intAwayScore"]) && $e["intAwayScore"] !== "" ? (int) $e["intAwayScore"] : null,
            "ultimo_controllo" => $adesso,
            "n_controlli" => (int) $m["n_controlli"] + 1,
            "errore" => null,
        ];
        $esito = "stato $stato";

        if (in_array($stato, AF_STATI_FINALI, true)) {
            $rl = tsdb_chiamata("lookuplineup.php", $par, $stagione);
            $registra("lookuplineup.php", $par, $rl);
            $rt = tsdb_chiamata("lookuptimeline.php", $par, $stagione);
            $registra("lookuptimeline.php", $par, $rt);
            $lineup = $rl["ok"] ? ($rl["dati"]["lineup"] ?? []) : [];
            $timeline = $rt["ok"] ? ($rt["dati"]["timeline"] ?? []) : [];
            $lineup = is_array($lineup) ? $lineup : [];
            $timeline = is_array($timeline) ? $timeline : [];

            $righe = tsdb_righe_statistiche($lineup, $timeline);
            if ($righe) {
                $s = af_salva_statistiche($stagione, $id, $righe, $adesso);
                $campi += ["dettaglio_finale" => "Y", "ultimo_sync" => $adesso,
                           "n_giocatori" => $s["salvate"], "n_non_mappati" => $s["non_mappati"]];
                $rep["salvate"] += $s["salvate"];
                $rep["giornate_aggiornate"][(int) $m["giornata"]] = true;
                $esito = "conclusa: " . $s["salvate"] . " giocatori salvati" . ($s["non_mappati"] ? ", " . $s["non_mappati"] . " non associati" : "");
            } elseif (!$rl["ok"] || !$rt["ok"]) {
                $campi["errore"] = "Formazioni/cronologia non lette: " . ($rl["ok"] ? $rt["errore"] : $rl["errore"]);
                $esito = "conclusa, dettaglio non letto (nuovo tentativo alla prossima esecuzione)";
            } elseif ($gratuita && count($lineup) > 0 && count($lineup) <= 5) {
                // Risposta troncata dal piano gratuito: inutile riprovare
                $campi += ["dettaglio_finale" => "Y", "ultimo_sync" => $adesso, "n_giocatori" => 0, "n_non_mappati" => 0];
                $campi["errore"] = "Chiave gratuita di TheSportsDB: formazioni limitate a 5 giocatori (serve una chiave a pagamento). Salvati solo stato e risultato.";
                $esito = "conclusa " . $campi["gol_casa"] . "-" . $campi["gol_ospite"] . ": formazioni troncate dal piano gratuito";
            } elseif ($campi["n_controlli"] >= 4) {
                $campi += ["dettaglio_finale" => "Y", "ultimo_sync" => $adesso, "n_giocatori" => 0, "n_non_mappati" => 0];
                $campi["errore"] = "Formazioni assenti o incomplete su TheSportsDB dopo " . $campi["n_controlli"] . " controlli (dati inseriti dalla community): salvati solo stato e risultato.";
                $esito = "conclusa: formazioni incomplete, nessuna statistica";
            } else {
                $campi["errore"] = "Partita conclusa ma formazioni non ancora complete";
                $esito = "conclusa, formazioni non ancora complete (nuovo tentativo alla prossima esecuzione)";
            }
        }
        af_partita_upsert($stagione, (int) $m["giornata"], (int) $m["n_partita"], $campi);
        $rep["partite"][$m["_idx"]]["esito"] = $esito;
    }
    $rep["messaggio"] = count($rep["chiamate"]) . " chiamate, " . $rep["salvate"] . " righe di statistiche salvate";
}
