<?php
// ============================================================
// backend/lib/ArticoloGiornata.php
//
// Costruzione del prompt per l'articolo AI di commento alla giornata
// ("La Gazzetta del Fantamazzone"). NON chiama Anthropic: raccoglie i
// dati, seleziona il contesto e produce il prompt. Il prompt è
// DETERMINISTICO (stessi dati → stesso testo), così l'hash calcolato
// all'anteprima coincide con quello della generazione.
//
// Richiede connect.php (query_all, query_one), lib/StatoGiornata.php e
// lib/AnthropicClient.php già inclusi dal chiamante.
//
// Funzioni:
//   art_cfg($chiave, $default)          parametri "articolo" di config_ai.php
//   art_tabelle_presenti($conn)         tabelle della fase 2 create?
//   art_dati_giornata($stagione, $g)    fatti della giornata (da DB)
//   art_profili($conn, $utenze)         profili dei fantallenatori in gioco
//   art_episodi($conn, $utenze, $max)   episodi storici pertinenti
//   art_costruisci($conn, $s, $g, $opz) prompt completo + riepilogo (formato
//                                       'articolo' o 'edizione', note di giornata)
//   art_contesto_fisso($conn)           blocchi di contesto fisso e di stile
//   art_catalogo_immagini(...)          immagini proposte all'AI
//   art_pagine_attese(...)              pagine di un'edizione sfogliabile
//   art_hash($prompt)                   impronta del prompt
//   art_parse_testo($testo, ...)        titolo / sommario / corpo (articolo)
//   art_parse_edizione($testo, ...)     copertina e pagine (edizione)
//   art_spesa_mese_usd($conn)           spesa AI del mese corrente (articoli + immagini)
// ============================================================

/** Parametro della sezione "articolo" di config_ai.php (con valore di default). */
function art_cfg(string $chiave, $default)
{
    $cfg = ai_config();
    return $cfg["articolo"][$chiave] ?? $default;
}

/** Le tabelle della fase 2 esistono? (NEW_AI_ARTICOLI.sql eseguito) */
function art_tabelle_presenti($conn): bool
{
    foreach (["NEW_PROFILI_FANTALLENATORE", "NEW_EPISODI_LEGA", "NEW_ARTICOLI"] as $t) {
        $r = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
        if (!$r || mysqli_num_rows($r) === 0) return false;
    }
    return true;
}

/** La tabella esiste? (risultato in cache per la durata della richiesta) */
function art_tabella_presente($conn, string $tabella): bool
{
    static $cache = [];
    if (!isset($cache[$tabella])) {
        $r = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $tabella) . "'");
        $cache[$tabella] = $r && mysqli_num_rows($r) > 0;
    }
    return $cache[$tabella];
}

/** La colonna esiste nella tabella? (in cache) */
function art_colonna_presente($conn, string $tabella, string $colonna): bool
{
    static $cache = [];
    $k = $tabella . "." . $colonna;
    if (!isset($cache[$k])) {
        $r = mysqli_query($conn, "SHOW COLUMNS FROM `" . str_replace("`", "", $tabella) . "` LIKE '"
                                 . mysqli_real_escape_string($conn, $colonna) . "'");
        $cache[$k] = $r && mysqli_num_rows($r) > 0;
    }
    return $cache[$k];
}

/** Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql eseguito? (contesto fisso, immagini, edizione) */
function art_fase4_presente($conn): bool
{
    return art_tabella_presente($conn, "NEW_CONTESTO_FISSO")
        && art_tabella_presente($conn, "NEW_IMMAGINI_GAZZETTA")
        && art_tabella_presente($conn, "NEW_ARTICOLI_PAGINE")
        && art_colonna_presente($conn, "NEW_ARTICOLI", "formato");
}

/** Spesa (USD) per le descrizioni AI delle immagini; $soloMese = solo mese corrente. */
function art_spesa_immagini_usd($conn, bool $soloMese): float
{
    if (!art_tabella_presente($conn, "NEW_IMMAGINI_GAZZETTA")) return 0.0;
    $where = $soloMese ? "WHERE data_descrizione >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" : "";
    $r = query_one("SELECT COALESCE(SUM(costo_usd), 0) AS s FROM NEW_IMMAGINI_GAZZETTA $where");
    return (float) ($r["s"] ?? 0);
}

/** Spesa reale (USD) del mese corrente: articoli generati + descrizioni AI delle immagini. */
function art_spesa_mese_usd($conn): float
{
    $r = query_one("SELECT COALESCE(SUM(costo_usd), 0) AS s FROM NEW_ARTICOLI
                    WHERE data_generazione >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    return (float) ($r["s"] ?? 0) + art_spesa_immagini_usd($conn, true);
}

/** Pulisce un testo libero: spazi ridondanti, lunghezza massima. */
function art_pulisci($v, int $max = 600): string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    if (function_exists("mb_substr") && mb_strlen($v) > $max) $v = mb_substr($v, 0, $max) . "…";
    return $v;
}

// ------------------------------------------------------------
// DATI DELLA GIORNATA
// ------------------------------------------------------------

/** Squadre della stagione: id → nome squadra, allenatore, utenza. */
function art_squadre(int $stagione): array
{
    $righe = query_all("SELECT s.id, s.nome, a.descrizione AS allenatore,
                               u.utenza, u.descrizione AS utenza_descr
                        FROM NEW_SQUADRE s
                        LEFT JOIN NEW_ALLENATORI a ON a.id = s.id AND a.stagione = s.stagione
                        LEFT JOIN NEW_UTENZE u     ON u.id = s.id AND u.stagione = s.stagione
                        WHERE s.stagione = $stagione");
    $out = [];
    foreach ($righe as $r) {
        $nomeAll = trim((string) $r["allenatore"]);
        if ($nomeAll === "") $nomeAll = trim((string) $r["utenza_descr"]);
        if ($nomeAll === "") $nomeAll = trim((string) $r["utenza"]);
        $out[(int) $r["id"]] = [
            "id"         => (int) $r["id"],
            "nome"       => trim((string) $r["nome"]),
            "allenatore" => $nomeAll,
            "utenza"     => trim((string) $r["utenza"]),
        ];
    }
    return $out;
}

/**
 * Fatti della giornata, già pronti per il prompt. Contiene SOLO dati
 * presenti a DB (punteggi, gol, classifica, giocatori): il modello non
 * deve calcolare nulla, i riepiloghi (migliore/peggiore, scarto
 * massimo, pareggi) sono precalcolati qui.
 */
function art_dati_giornata(int $stagione, int $giornata): array
{
    $sq  = art_squadre($stagione);
    $nm  = fn(int $id) => $sq[$id]["nome"] ?? "Squadra $id";

    // --- Partite di campionato (riga della squadra di casa) ---
    $righe = query_all("SELECT r.id_squadra AS id_casa, r.id_squadra_a AS id_ospite,
                               r.ftotale, r.ftotale_a, r.golf, r.gols, r.segno
                        FROM NEW_RISULTATI r
                        JOIN NEW_CALENDARIO cal ON cal.stagione = r.stagione
                                               AND cal.giornata = r.giornata
                                               AND cal.squadra  = r.id_squadra
                        WHERE r.stagione = $stagione AND r.giornata = $giornata
                          AND cal.posizione % 2 = 1
                        ORDER BY cal.posizione");
    $partite = [];
    foreach ($righe as $r) {
        $c = (int) $r["id_casa"];
        $o = (int) $r["id_ospite"];
        $partite[] = [
            "casa"            => $nm($c),
            "ospite"          => $nm($o),
            "gol_casa"        => (int) $r["golf"],
            "gol_ospite"      => (int) $r["gols"],
            "punteggio_casa"  => (float) $r["ftotale"],
            "punteggio_ospite" => (float) $r["ftotale_a"],
            "_id_casa" => $c, "_id_ospite" => $o,
        ];
    }

    // --- Champions (le righe sono una per prospettiva: elimino i doppioni) ---
    $righeCh = query_all("SELECT squadra AS id_a, id_squadra_a AS id_b, ftotale, ftotale_a,
                                 golf, gols, girone
                          FROM NEW_RISULTATI_CHAMP
                          WHERE stagione = $stagione AND giornata = $giornata
                          ORDER BY girone, squadra");
    $champions = [];
    $visti = [];
    foreach ($righeCh as $r) {
        $a = (int) $r["id_a"];
        $b = (int) $r["id_b"];
        $chiave = min($a, $b) . "-" . max($a, $b);
        if (isset($visti[$chiave])) continue;
        $visti[$chiave] = true;
        $champions[] = [
            "girone"           => trim((string) $r["girone"]),
            "squadra_a"        => $nm($a),
            "squadra_b"        => $nm($b),
            "gol_a"            => (int) $r["golf"],
            "gol_b"            => (int) $r["gols"],
            "punteggio_a"      => (float) $r["ftotale"],
            "punteggio_b"      => (float) $r["ftotale_a"],
        ];
    }

    // --- Riepiloghi precalcolati sul campionato ---
    $riepilogo = [];
    if ($partite) {
        $punteggi = [];
        foreach ($partite as $p) {
            $punteggi[] = ["squadra" => $p["casa"],   "p" => $p["punteggio_casa"]];
            $punteggi[] = ["squadra" => $p["ospite"], "p" => $p["punteggio_ospite"]];
        }
        usort($punteggi, fn($x, $y) => $y["p"] <=> $x["p"]);
        $riepilogo["miglior_punteggio"] = ["squadra" => $punteggi[0]["squadra"], "punti" => $punteggi[0]["p"]];
        $ultimo = end($punteggi);
        $riepilogo["peggior_punteggio"] = ["squadra" => $ultimo["squadra"], "punti" => $ultimo["p"]];

        $maxScarto = -1; $part = null; $minScarto = PHP_FLOAT_MAX; $partMin = null;
        foreach ($partite as $p) {
            $scarto = abs($p["punteggio_casa"] - $p["punteggio_ospite"]);
            if ($scarto > $maxScarto) { $maxScarto = $scarto; $part = $p; }
            if ($scarto < $minScarto) { $minScarto = $scarto; $partMin = $p; }
        }
        $riepilogo["partita_piu_netta"]  = ["partita" => $part["casa"] . " - " . $part["ospite"], "scarto_punti" => round($maxScarto, 1)];
        $riepilogo["partita_piu_tirata"] = ["partita" => $partMin["casa"] . " - " . $partMin["ospite"], "scarto_punti" => round($minScarto, 1)];

        $pareggi = [];
        foreach ($partite as $p) {
            if ($p["gol_casa"] === $p["gol_ospite"]) $pareggi[] = $p["casa"] . " - " . $p["ospite"];
        }
        $riepilogo["pareggi"] = $pareggi;
        $riepilogo["gol_totali_campionato"] = array_sum(array_map(fn($p) => $p["gol_casa"] + $p["gol_ospite"], $partite));
    }

    // --- Classifica (solo se NEW_GENERALE rispecchia questa giornata) ---
    $ck = query_one("SELECT MAX(giornata) AS m FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione AND ck_giocata = 'S'");
    $ultimaChiusa = (int) ($ck["m"] ?? 0);
    $classifica = [];
    $classificaInclusa = $ultimaChiusa === $giornata;
    if ($classificaInclusa) {
        $pos = 1;
        foreach (query_all("SELECT squadra, punti, partiteg, vinte, nulle, perse, golf, gols
                            FROM NEW_GENERALE WHERE stagione = $stagione
                            ORDER BY punti DESC, golf DESC, gols DESC") as $r) {
            $classifica[] = [
                "pos" => $pos++, "squadra" => trim((string) $r["squadra"]),
                "punti" => (int) $r["punti"], "giocate" => (int) $r["partiteg"],
                "v" => (int) $r["vinte"], "n" => (int) $r["nulle"], "p" => (int) $r["perse"],
                "gol_fatti" => (int) $r["golf"], "gol_subiti" => (int) $r["gols"],
            ];
        }
    }

    // --- Giocatori (campionato) ---
    $voti = query_all("SELECT v.id_squadra, COALESCE(g.descrizione, 'Riserva d''ufficio') AS giocatore,
                              COALESCE(g.ruolo, FLOOR(ABS(v.id_giocatore) / 1000000)) AS ruolo,
                              v.voto, v.totale, v.giocata, v.rufficio,
                              v.reti, v.assist, v.espulsioni, v.autogol, v.rigorep, v.rigores
                       FROM NEW_VOTI v
                       LEFT JOIN NEW_GIOCATORI g ON g.id = v.id_giocatore AND g.stagione = v.stagione
                       WHERE v.stagione = $stagione AND v.giornata = $giornata");
    $ruoli = [1 => "P", 2 => "D", 3 => "C", 4 => "A"];
    $conVoto = [];
    $eventi = ["marcatori" => [], "autogol" => [], "espulsi" => [], "rigori_segnati" => [], "rigori_parati" => [], "rigori_sbagliati" => []];
    $riserveUfficio = [];
    $marcatoriSquadra = [];
    foreach ($voti as $v) {
        $idSq = (int) $v["id_squadra"];
        $rec = [
            "giocatore" => trim((string) $v["giocatore"]),
            "ruolo"     => $ruoli[(int) $v["ruolo"]] ?? "?",
            "squadra"   => $nm($idSq),
            "voto"      => (float) $v["voto"],
            "totale"    => (float) $v["totale"],
        ];
        if ((int) $v["rufficio"] === 1) {
            $riserveUfficio[$idSq] = ($riserveUfficio[$idSq] ?? 0) + 1;
            continue;
        }
        if ((int) $v["giocata"] === 1) $conVoto[] = $rec;
        $ev = ["giocatore" => $rec["giocatore"], "squadra" => $rec["squadra"]];
        if ((int) $v["reti"] > 0) {
            $eventi["marcatori"][] = $ev + ["gol" => (int) $v["reti"]];
            $marcatoriSquadra[$ev["squadra"]][] = ["giocatore" => $ev["giocatore"], "gol" => (int) $v["reti"]];   // elenco completo, per le pagine
        }
        if ((int) $v["autogol"] > 0)    $eventi["autogol"][]          = $ev;
        if ((int) $v["espulsioni"] > 0) $eventi["espulsi"][]          = $ev;
        // Mapping rigori: rigores = Rp + Rf (parato se portiere, altrimenti
        // segnato), rigorep = Rs (sbagliato).
        if ((int) $v["rigores"] > 0) {
            $chiave = (int) $v["ruolo"] === 1 ? "rigori_parati" : "rigori_segnati";
            $eventi[$chiave][] = $ev;
        }
        if ((int) $v["rigorep"] > 0)    $eventi["rigori_sbagliati"][] = $ev;
    }
    usort($conVoto, fn($a, $b) => [$b["totale"], $a["giocatore"]] <=> [$a["totale"], $b["giocatore"]]);
    $migliori = array_slice($conVoto, 0, 5);
    $peggiori = array_slice(array_reverse($conVoto), 0, 5);
    usort($eventi["marcatori"], fn($a, $b) => $b["gol"] <=> $a["gol"]);
    foreach ($eventi as $k => $lista) $eventi[$k] = array_slice($lista, 0, 8);

    $ru = [];
    foreach ($riserveUfficio as $idSq => $n) $ru[] = ["squadra" => $nm($idSq), "riserve" => $n];

    // Partecipanti (campionato + Champions) per profili/episodi
    $ids = [];
    foreach ($partite as $p) { $ids[$p["_id_casa"]] = true; $ids[$p["_id_ospite"]] = true; }
    foreach ($righeCh as $r) { $ids[(int) $r["id_a"]] = true; $ids[(int) $r["id_b"]] = true; }
    $partecipanti = [];
    foreach (array_keys($ids) as $id) if (isset($sq[$id])) $partecipanti[] = $sq[$id];
    usort($partecipanti, fn($a, $b) => $a["id"] <=> $b["id"]);

    foreach ($partite as &$p) unset($p["_id_casa"], $p["_id_ospite"]);
    unset($p);

    return [
        "stagione"             => $stagione,
        "giornata"             => $giornata,
        "partite_campionato"   => $partite,
        "partite_champions"    => $champions,
        "riepilogo"            => $riepilogo,
        "classifica_generale"  => $classifica,
        "classifica_inclusa"   => $classificaInclusa,
        "migliori_giocatori"   => $migliori,
        "peggiori_giocatori"   => $peggiori,
        "eventi"               => $eventi,
        "riserve_d_ufficio"    => $ru,
        "_partecipanti"        => $partecipanti,   // uso interno (non va nel prompt)
        "_marcatori_squadra"   => $marcatoriSquadra, // uso interno: marcatori per squadra (pagine dell'edizione)
    ];
}

// ------------------------------------------------------------
// CONTESTO: PROFILI ED EPISODI
// ------------------------------------------------------------

/** Profili dei fantallenatori in gioco, indicizzati per utenza (minuscola). */
function art_profili($conn, array $utenze): array
{
    $utenze = array_values(array_filter(array_unique($utenze), fn($u) => $u !== ""));
    if (!$utenze) return [];
    $in = implode(",", array_map(fn($u) => "'" . mysqli_real_escape_string($conn, $u) . "'", $utenze));
    $out = [];
    foreach (query_all("SELECT utenza, soprannome, carattere, squadra_cuore, tormentoni, rivalita, note
                        FROM NEW_PROFILI_FANTALLENATORE WHERE utenza IN ($in)") as $r) {
        $out[mb_strtolower(trim($r["utenza"]))] = $r;
    }
    return $out;
}

/**
 * Episodi storici pertinenti. Sono candidati gli episodi attivi che
 * riguardano almeno un partecipante, oppure generici (campo utenze
 * vuoto). Ordinamento: più partecipanti coinvolti prima, poi i meno
 * usati di recente (mai usati per primi), poi id. Nessuna casualità.
 */
function art_episodi($conn, array $utenzeInGioco, int $max): array
{
    if ($max <= 0) return [];
    $gioco = array_flip(array_map(fn($u) => mb_strtolower(trim($u)), $utenzeInGioco));
    $cand = [];
    foreach (query_all("SELECT id, data_episodio, titolo, descrizione, tag, utenze, ultimo_utilizzo
                        FROM NEW_EPISODI_LEGA WHERE attivo = 'Y'") as $e) {
        $lista = array_filter(array_map(fn($u) => mb_strtolower(trim($u)), explode(",", (string) $e["utenze"])));
        $coinvolti = count(array_filter($lista, fn($u) => isset($gioco[$u])));
        if ($lista && $coinvolti === 0) continue;          // riguarda solo chi non c'è
        $e["_score"] = $lista ? $coinvolti : 0.5;           // generico: pertinenza bassa
        $cand[] = $e;
    }
    usort($cand, function ($a, $b) {
        if ($a["_score"] !== $b["_score"]) return $b["_score"] <=> $a["_score"];
        $ua = $a["ultimo_utilizzo"] ?? "";                  // NULL/"" = mai usato → prima
        $ub = $b["ultimo_utilizzo"] ?? "";
        if ($ua !== $ub) return strcmp((string) $ua, (string) $ub);
        return (int) $a["id"] <=> (int) $b["id"];
    });
    return array_slice($cand, 0, $max);
}

// ------------------------------------------------------------
// CONTESTO FISSO, STILE, IMMAGINI
// ------------------------------------------------------------

/**
 * Blocchi ATTIVI di NEW_CONTESTO_FISSO, in ordine, divisi per tipo:
 *   ["contesto" => [{id,titolo,testo}...], "stile" => [...]]
 * Vuoti se la tabella non esiste ancora.
 */
function art_contesto_fisso($conn): array
{
    $out = ["contesto" => [], "stile" => []];
    if (!art_tabella_presente($conn, "NEW_CONTESTO_FISSO")) return $out;
    foreach (query_all("SELECT id, tipo, titolo, testo FROM NEW_CONTESTO_FISSO
                        WHERE attivo = 'Y' ORDER BY ordine, id") as $r) {
        $out[$r["tipo"] === "stile" ? "stile" : "contesto"][] = $r;
    }
    return $out;
}

/**
 * Stile di scrittura predefinito (usato se nessun blocco di tipo "stile"
 * è attivo): ispirazione alla vena comica e surreale di Stefano Benni,
 * descritta per tratti e senza riportare brani dell'autore.
 */
function art_stile_default(): string
{
    return <<<TXT
Ispirati alla vena comica e surreale di Stefano Benni: come tono e come modo di guardare le cose, non come imitazione di sue frasi.
- Iperbole visionaria: un fatto minimo (un cambio all'ultimo minuto, un bonus sfumato per un soffio) diventa un evento epico, raccontato con la gravità di un cronista di guerra.
- Similitudini inattese e concrete, ma sempre comprensibili; ogni tanto un neologismo o una parola-valigia (al massimo due o tre per testo, mai forzati).
- Elenchi che partono seri e degenerano nell'assurdo; personificazioni di panchina, destino, sfortuna e tabellino come creature capricciose.
- Periodi lunghi e ricchi alternati a battute secche; spesso una virata finale che ribalta la frase.
- Satira affettuosa: si ride CON i fantallenatori, non DI loro; sotto l'ironia una punta di tenerezza e di malinconia da bar dello sport.
- Niente cliché da telecronaca, niente volgarità, niente ripetizioni dello stesso espediente nello stesso testo.
- Non citare l'autore e non riprodurre né parafrasare passi di opere esistenti.
TXT;
}

/** Unisce i blocchi in un testo unico (titoli come intestazioni), con tetto di caratteri. */
function art_testo_blocchi(array $blocchi, int $maxCaratteri): string
{
    $parti = [];
    foreach ($blocchi as $b) {
        $titolo = trim((string) $b["titolo"]);
        $testo  = trim(str_replace(["\r\n", "\r"], "\n", (string) $b["testo"]));
        if ($testo === "") continue;
        $parti[] = ($titolo !== "" ? "### " . $titolo . "\n" : "") . $testo;
    }
    $out = implode("\n\n", $parti);
    if ($maxCaratteri > 0 && mb_strlen($out) > $maxCaratteri) {
        $out = mb_substr($out, 0, $maxCaratteri) . "…";
    }
    return $out;
}

/**
 * Catalogo delle immagini da proporre all'AI. Sono candidate le immagini
 * ATTIVE con una descrizione; ordinamento deterministico: prima quelle che
 * ritraggono i fantallenatori in gioco (o senza persone indicate), poi le
 * meno usate di recente (mai usate per prime), poi id.
 * Ritorna: righe[] (testo per il prompt), ids (mappa id => true),
 *          attive (immagini attive), senza_descrizione (escluse).
 */
function art_catalogo_immagini($conn, array $partecipanti, int $max): array
{
    $vuoto = ["righe" => [], "ids" => [], "attive" => 0, "senza_descrizione" => 0];
    if ($max <= 0 || !art_tabella_presente($conn, "NEW_IMMAGINI_GAZZETTA")) return $vuoto;

    $inGioco = [];
    foreach ($partecipanti as $sq) $inGioco[mb_strtolower((string) $sq["utenza"])] = (string) $sq["allenatore"];

    $cand = [];
    $attive = 0;
    $senza = 0;
    foreach (query_all("SELECT id, descrizione, tag, tono, utenze, ultimo_utilizzo
                        FROM NEW_IMMAGINI_GAZZETTA WHERE attivo = 'Y'") as $r) {
        $attive++;
        $desc = art_pulisci($r["descrizione"] ?? "", 220);
        if ($desc === "") { $senza++; continue; }
        $lista = array_values(array_filter(array_map("trim", explode(",", (string) $r["utenze"])), fn($u) => $u !== ""));
        $score = $lista ? 0 : 1;
        $nomi = [];
        foreach ($lista as $u) {
            $k = mb_strtolower($u);
            if (isset($inGioco[$k])) { $score += 2; $nomi[] = $inGioco[$k]; }
            else $nomi[] = $u;
        }
        $r["_score"] = $score;
        $r["_desc"]  = $desc;
        $r["_nomi"]  = $nomi;
        $cand[] = $r;
    }
    usort($cand, function ($a, $b) {
        if ($a["_score"] !== $b["_score"]) return $b["_score"] <=> $a["_score"];
        $ua = (string) ($a["ultimo_utilizzo"] ?? "");
        $ub = (string) ($b["ultimo_utilizzo"] ?? "");
        if ($ua !== $ub) return strcmp($ua, $ub);
        return (int) $a["id"] <=> (int) $b["id"];
    });
    $cand = array_slice($cand, 0, $max);

    $righe = [];
    $ids = [];
    foreach ($cand as $r) {
        $id = (int) $r["id"];
        $riga = "[" . $id . "] " . $r["_desc"];
        $tag = art_pulisci($r["tag"] ?? "", 100);
        if ($tag !== "")  $riga .= " | tag: " . $tag;
        $tono = art_pulisci($r["tono"] ?? "", 40);
        if ($tono !== "") $riga .= " | tono: " . $tono;
        if ($r["_nomi"])  $riga .= " | con: " . implode(", ", $r["_nomi"]);
        $righe[] = $riga;
        $ids[$id] = true;
    }
    return ["righe" => $righe, "ids" => $ids, "attive" => $attive, "senza_descrizione" => $senza];
}

/** Id di immagine valido (presente nel catalogo proposto) oppure null. */
function art_normalizza_id_immagine($v, array $catalogo): ?int
{
    if (preg_match('/\d+/', (string) $v, $m)) {
        $id = (int) $m[0];
        if (isset($catalogo[$id])) return $id;
    }
    return null;
}

// ------------------------------------------------------------
// PAGINE DELL'EDIZIONE SFOGLIABILE
// ------------------------------------------------------------

/**
 * Pagine attese di un'edizione: una per partita di campionato, una per
 * partita di Champions (se richiesto) e il COMMENTO finale. Ogni pagina
 * porta con sé i FATTI (punteggi, gol, marcatori, classifica) che la
 * grafica mostra: sono presi dal DB, non dal testo dell'AI.
 */
function art_pagine_attese(array $dati, array $marcatoriSquadra, bool $conChampions): array
{
    $out = [];
    $n = 0;
    foreach ($dati["partite_campionato"] as $p) {
        $n++;
        $out[] = [
            "chiave" => "PARTITA $n", "tipo" => "partita", "competizione" => "campionato",
            "etichetta" => $p["casa"] . " - " . $p["ospite"],
            "dati" => [
                "competizione"     => "campionato",
                "casa"             => $p["casa"],
                "ospite"           => $p["ospite"],
                "gol_casa"         => $p["gol_casa"],
                "gol_ospite"       => $p["gol_ospite"],
                "punteggio_casa"   => $p["punteggio_casa"],
                "punteggio_ospite" => $p["punteggio_ospite"],
                "marcatori_casa"   => $marcatoriSquadra[$p["casa"]] ?? [],
                "marcatori_ospite" => $marcatoriSquadra[$p["ospite"]] ?? [],
            ],
        ];
    }
    if ($conChampions) {
        foreach ($dati["partite_champions"] as $c) {
            $n++;
            $girone = trim((string) ($c["girone"] ?? ""));
            $out[] = [
                "chiave" => "PARTITA $n", "tipo" => "partita", "competizione" => "champions",
                "etichetta" => $c["squadra_a"] . " - " . $c["squadra_b"] . ($girone !== "" ? " (Champions, girone $girone)" : " (Champions)"),
                "dati" => [
                    "competizione"     => "champions",
                    "girone"           => $girone,
                    "casa"             => $c["squadra_a"],
                    "ospite"           => $c["squadra_b"],
                    "gol_casa"         => $c["gol_a"],
                    "gol_ospite"       => $c["gol_b"],
                    "punteggio_casa"   => $c["punteggio_a"],
                    "punteggio_ospite" => $c["punteggio_b"],
                    "marcatori_casa"   => [],
                    "marcatori_ospite" => [],
                ],
            ];
        }
    }
    $out[] = [
        "chiave" => "COMMENTO", "tipo" => "commento", "competizione" => "",
        "etichetta" => "Commento generale",
        "dati" => [
            "classifica_inclusa" => (bool) $dati["classifica_inclusa"],
            "classifica"         => $dati["classifica_generale"],
            "riepilogo"          => $dati["riepilogo"],
        ],
    ];
    return $out;
}

// ------------------------------------------------------------
// PROMPT
// ------------------------------------------------------------

/**
 * Prompt di sistema. Opzioni ($o):
 *   formato ('articolo'|'edizione'), stile, contesto (testo, può essere vuoto),
 *   con_immagini (bool), parole_pagina, parole_commento, max_inline
 */
function art_system_prompt(int $paroleTarget, array $o): string
{
    $edizione = ($o["formato"] ?? "articolo") === "edizione";
    $conImg   = !empty($o["con_immagini"]);
    $compito  = $edizione
        ? "l'edizione del giorno: un giornale sfogliabile con una pagina per ogni partita della giornata e una pagina finale di commento generale"
        : "un articolo di commento alla giornata appena conclusa";

    $t  = "Sei il caporedattore de «La Gazzetta del Fantamazzone», quotidiano sportivo immaginario di una lega di fantacalcio tra amici. Scrivi in italiano $compito, con tono scherzoso, ironico e goliardico, nello stile di un quotidiano sportivo: titoloni, giochi di parole, paragoni iperbolici.\n\n";
    $t .= "STILE DI SCRITTURA\n" . $o["stile"] . "\n\n";
    if (trim((string) ($o["contesto"] ?? "")) !== "") {
        $t .= "CONTESTO FISSO DELLA LEGA\nInformazioni stabili sulla lega, sempre valide: usale come sfondo, senza elencarle e solo quando c'entrano con i fatti.\n"
            . $o["contesto"] . "\n\n";
    }

    $t .= "REGOLE\n";
    $regole = [];
    $regole[] = "Per risultati, punteggi, gol, classifica, giocatori e squadre usa ESCLUSIVAMENTE i dati forniti. Non inventare né modificare numeri, nomi o esiti. Se un dato manca, non parlarne.";
    $regole[] = "Profili, episodi storici e contesto fisso servono come colore: richiamali con parsimonia e solo quando c'entrano davvero con i fatti della giornata, senza forzature. Non inventare altri fatti sulla vita delle persone.";
    $regole[] = "Se nel messaggio ci sono NOTE DELLA REDAZIONE, sono informazioni affidabili sulla giornata fornite da chi dirige il giornale: usale anche se non compaiono nei dati, senza però mai contraddire i numeri dei dati.";
    $regole[] = "L'ironia deve essere affettuosa. Puoi prendere in giro prestazioni, scelte di formazione, tormentoni e rivalità forniti. Mai insulti né battute su salute, aspetto fisico, famiglia, origini, religione, politica o altri aspetti privati o sensibili.";
    $regole[] = "Le citazioni inventate sono ammesse solo se palesemente comiche e attribuite ai fantallenatori della lega.";
    if ($edizione) {
        $regole[] = "Lunghezza: circa " . (int) $o["parole_pagina"] . " parole per ogni pagina di partita e circa " . (int) $o["parole_commento"] . " parole per il commento generale, pagelle escluse.";
        $regole[] = "Struttura: una pagina per ogni partita elencata in PAGINE DELL'EDIZIONE, nello stesso ordine e con lo stesso numero, in cui commenti quella partita (cosa è successo, chi ha deciso, chi ha sbagliato). Punteggi e gol sono già mostrati in grafica nella pagina: non ripeterli come elenco, commentali. Poi una pagina COMMENTO con il commento generale della giornata (classifica, miglior e peggior punteggio, tendenze) e, in fondo, una sezione «PAGELLE» con un voto in decimi e un commento di una riga per ogni fantallenatore che ha giocato.";
    } else {
        $regole[] = "Lunghezza: circa " . $paroleTarget . " parole.";
        $regole[] = "Struttura: titolo, sommario di una o due righe, corpo dell'articolo con qualche intertitolo breve, e una sezione finale «PAGELLE» con un voto in decimi e un commento di una riga per ogni fantallenatore che ha giocato.";
    }
    if ($conImg) {
        $regole[] = "Nel messaggio c'è un CATALOGO IMMAGINI: scegli le immagini SOLO tra quelle elencate (usa l'id tra parentesi quadre), in base a descrizione, tag, tono e persone indicate, e solo se c'entrano davvero con ciò che racconti. Ogni immagine al massimo una volta; se nessuna è adatta scrivi nessuna. Non dare per vero nulla che non sia nella descrizione.";
        if (!$edizione) {
            $regole[] = "Oltre all'immagine di apertura puoi inserire nel testo fino a " . (int) $o["max_inline"] . " immagini, ciascuna su una riga a sé nel formato [IMG:id|didascalia breve e ironica], dopo il paragrafo a cui si riferiscono.";
        }
    }
    foreach ($regole as $i => $r) $t .= ($i + 1) . ". " . $r . "\n";

    $t .= "\nFORMATO DI OUTPUT (rispettalo esattamente)\n";
    $t .= "Prima riga: TITOLO: seguito dal " . ($edizione ? "titolo di copertina (il titolone dell'edizione)" : "titolo") . ".\n";
    $t .= "Seconda riga: SOMMARIO: seguito dal sommario" . ($edizione ? " di copertina" : "") . ".\n";
    if ($conImg) {
        $t .= "Terza riga: IMMAGINE: seguito dall'id dell'immagine di apertura scelta dal catalogo, oppure dalla parola nessuna.\n";
        $t .= "Quarta riga: ---\n";
    } else {
        $t .= "Terza riga: ---\n";
    }
    if ($edizione) {
        $t .= "Poi le pagine, ciascuna introdotta da una riga di intestazione e organizzata così:\n";
        $t .= "@@ PARTITA 1\nTITOLO: titolo della pagina\n";
        if ($conImg) $t .= "IMMAGINE: id oppure nessuna\nDIDASCALIA: didascalia breve dell'immagine (solo se hai scelto un'immagine)\n";
        $t .= "TESTO:\ntesto della pagina in testo semplice, paragrafi separati da una riga vuota.\n";
        $t .= "Ripeti per @@ PARTITA 2, @@ PARTITA 3 e così via, per tutte le partite elencate. Per finire:\n";
        $t .= "@@ COMMENTO\nTITOLO: titolo del commento generale\n";
        if ($conImg) $t .= "IMMAGINE: id oppure nessuna\nDIDASCALIA: ...\n";
        $t .= "TESTO:\ncommento generale con intertitoli in MAIUSCOLO su una riga a sé e, in fondo, la sezione PAGELLE.\n";
        $t .= "Nessun markdown, nessun commento fuori dall'edizione.";
    } else {
        $t .= "Poi il testo dell'articolo in testo semplice: paragrafi separati da una riga vuota, intertitoli in MAIUSCOLO su una riga a sé. Nessun markdown, nessun commento fuori dall'articolo.";
    }
    return $t;
}

/** Riga di profilo in testo, per il prompt. */
function art_riga_profilo(array $sq, array $p): string
{
    $parti = [];
    if (($p["soprannome"] ?? "") !== "")    $parti[] = "soprannome «" . art_pulisci($p["soprannome"], 100) . "»";
    if (($p["carattere"] ?? "") !== "")     $parti[] = "carattere: " . art_pulisci($p["carattere"]);
    if (($p["squadra_cuore"] ?? "") !== "") $parti[] = "squadra del cuore: " . art_pulisci($p["squadra_cuore"], 100);
    if (($p["tormentoni"] ?? "") !== "")    $parti[] = "tormentoni: " . art_pulisci($p["tormentoni"]);
    if (($p["rivalita"] ?? "") !== "")      $parti[] = "rivalità: " . art_pulisci($p["rivalita"]);
    if (($p["note"] ?? "") !== "")          $parti[] = "note: " . art_pulisci($p["note"]);
    return "- " . $sq["allenatore"] . " (squadra «" . $sq["nome"] . "»): " . implode("; ", $parti);
}

/**
 * Costruisce prompt e riepilogo per una giornata.
 * $opz: formato ('articolo'|'edizione'), contesto_giornata (note libere)
 * Ritorna ["errore"=>string] se la giornata non ha risultati, altrimenti:
 *   system, messages, max_token, token_tipico, parole_target, formato,
 *   contesto_giornata, pagine_attese[], catalogo_ids (id => true),
 *   riepilogo, prompt_utente (testo mostrato in anteprima)
 */
function art_costruisci($conn, int $stagione, int $giornata, array $opz = []): array
{
    $formato = (($opz["formato"] ?? "articolo") === "edizione") ? "edizione" : "articolo";
    $contestoGiornata = trim(str_replace(["\r\n", "\r"], "\n", (string) ($opz["contesto_giornata"] ?? "")));

    $dati = art_dati_giornata($stagione, $giornata);
    if (!$dati["partite_campionato"]) {
        return ["errore" => "Nessun risultato di campionato per la giornata $giornata della stagione $stagione"];
    }

    $partecipanti = $dati["_partecipanti"];
    $marcatoriSquadra = $dati["_marcatori_squadra"] ?? [];
    unset($dati["_partecipanti"], $dati["_marcatori_squadra"]);

    $utenze = array_map(fn($p) => $p["utenza"], $partecipanti);
    $profili = art_profili($conn, $utenze);
    $righeProfili = [];
    $senzaProfilo = [];
    foreach ($partecipanti as $sq) {
        $p = $profili[mb_strtolower($sq["utenza"])] ?? null;
        if ($p) $righeProfili[] = art_riga_profilo($sq, $p);
        else    $senzaProfilo[] = $sq["allenatore"];
    }

    $maxEpisodi = (int) art_cfg("max_episodi", 3);
    $episodi = art_episodi($conn, $utenze, $maxEpisodi);
    $righeEpisodi = [];
    foreach ($episodi as $e) {
        $data = !empty($e["data_episodio"]) ? " (" . $e["data_episodio"] . ")" : "";
        $righeEpisodi[] = "- " . art_pulisci($e["titolo"], 150) . $data . ": " . art_pulisci($e["descrizione"], 800);
    }

    // Contesto fisso e stile (modificabili da "Profili ed episodi" → "Contesto fisso")
    $fisso = art_contesto_fisso($conn);
    $contestoFisso = art_testo_blocchi($fisso["contesto"], (int) art_cfg("max_caratteri_contesto_fisso", 12000));
    $stile = $fisso["stile"] ? art_testo_blocchi($fisso["stile"], 4000) : art_stile_default();

    // Catalogo immagini
    $catalogo = art_catalogo_immagini($conn, $partecipanti, (int) art_cfg("max_immagini_catalogo", 60));
    $conImmagini = count($catalogo["ids"]) > 0;

    // Pagine dell'edizione
    $pagine = [];
    if ($formato === "edizione") {
        $pagine = art_pagine_attese($dati, $marcatoriSquadra, (bool) art_cfg("champions_in_edizione", true));
    }

    $parole = (int) art_cfg("parole_target", 500);
    $parolePagina = (int) art_cfg("parole_per_pagina", 100);
    $paroleCommento = (int) art_cfg("parole_commento", 200);

    if ($formato === "edizione") {
        $nPartite = count(array_filter($pagine, fn($p) => $p["tipo"] === "partita"));
        $maxToken = (int) art_cfg("token_base_edizione", 450)
                  + $nPartite * (int) art_cfg("token_per_pagina", 300)
                  + (int) art_cfg("token_commento", 450)
                  + count($partecipanti) * (int) art_cfg("token_per_pagella", 45);
        $maxToken = min($maxToken, (int) art_cfg("max_token_edizione", 8000));
        $tokTipico = (int) art_cfg("token_output_tipico_edizione", 0);
        if ($tokTipico <= 0) $tokTipico = (int) round($maxToken * 0.75);
    } else {
        $maxToken = (int) art_cfg("max_token", 1800);
        $tokTipico = (int) art_cfg("token_output_tipico", 1100);
    }
    $maxInline = (int) art_cfg("max_immagini_inline", 2);

    $system = art_system_prompt($parole, [
        "formato" => $formato, "stile" => $stile, "contesto" => $contestoFisso,
        "con_immagini" => $conImmagini, "parole_pagina" => $parolePagina,
        "parole_commento" => $paroleCommento, "max_inline" => $maxInline,
    ]);

    $testo  = ($formato === "edizione"
        ? "Scrivi l'edizione per la giornata $giornata della stagione $stagione.\n\n"
        : "Scrivi l'articolo per la giornata $giornata della stagione $stagione.\n\n");
    $testo .= "DATI DELLA GIORNATA (JSON):\n" . json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    $testo .= "PROFILI DEI FANTALLENATORI:\n";
    $testo .= $righeProfili ? implode("\n", $righeProfili) : "(nessun profilo disponibile)";
    if ($senzaProfilo) $testo .= "\nSenza profilo (usa solo i dati): " . implode(", ", $senzaProfilo) . ".";
    $testo .= "\n\nEPISODI STORICI DELLA LEGA (da richiamare solo se pertinenti):\n";
    $testo .= $righeEpisodi ? implode("\n", $righeEpisodi) : "(nessun episodio disponibile)";
    if ($contestoGiornata !== "") {
        $testo .= "\n\nNOTE DELLA REDAZIONE SULLA GIORNATA (informazioni affidabili, da tenere in considerazione):\n" . $contestoGiornata;
    }
    if ($formato === "edizione") {
        $testo .= "\n\nPAGINE DELL'EDIZIONE (una per partita, nell'ordine indicato; poi la pagina COMMENTO):\n";
        foreach ($pagine as $p) {
            $testo .= $p["chiave"] . ($p["tipo"] === "partita" ? " (" . $p["competizione"] . ")" : "") . ": " . $p["etichetta"] . "\n";
        }
        $testo = rtrim($testo);
    }
    if ($conImmagini) {
        $testo .= "\n\nCATALOGO IMMAGINI (scegli solo da qui, per id):\n" . implode("\n", $catalogo["righe"]);
    }

    return [
        "system"            => $system,
        "messages"          => [["role" => "user", "content" => $testo]],
        "max_token"         => $maxToken,
        "n_partecipanti"    => count($partecipanti),
        "token_tipico"      => $tokTipico,
        "parole_target"     => $formato === "edizione" ? $parolePagina : $parole,
        "formato"           => $formato,
        "contesto_giornata" => $contestoGiornata,
        "pagine_attese"     => $pagine,
        "catalogo_ids"      => $catalogo["ids"],
        "prompt_utente"     => $testo,
        "riepilogo"         => [
            "formato"              => $formato,
            "partite_campionato"   => count($dati["partite_campionato"]),
            "partite_champions"    => count($dati["partite_champions"]),
            "pagine"               => count($pagine),
            "classifica_inclusa"   => $dati["classifica_inclusa"],
            "profili_usati"        => count($righeProfili),
            "senza_profilo"        => $senzaProfilo,
            "episodi"              => array_map(fn($e) => ["id" => (int) $e["id"], "titolo" => $e["titolo"]], $episodi),
            "contesto_fisso_blocchi"    => count($fisso["contesto"]),
            "contesto_fisso_caratteri"  => mb_strlen($contestoFisso),
            "stile_personalizzato"      => count($fisso["stile"]) > 0,
            "contesto_giornata_caratteri" => mb_strlen($contestoGiornata),
            "immagini_catalogo"         => count($catalogo["ids"]),
            "immagini_attive"           => $catalogo["attive"],
            "immagini_senza_descrizione" => $catalogo["senza_descrizione"],
        ],
    ];
}

/** Impronta del prompt (system + messaggi + limite di output), indipendente dal modello. */
function art_hash(array $prompt): string
{
    return hash("sha256", json_encode([$prompt["system"], $prompt["messages"], $prompt["max_token"]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Stima grossolana dei token (usata solo se il conteggio ufficiale non risponde). */
function art_stima_token(array $prompt): int
{
    $n = mb_strlen($prompt["system"]) + mb_strlen($prompt["messages"][0]["content"]);
    return (int) ceil($n / 3);
}

// ------------------------------------------------------------
// GENERAZIONE A FASI (edizione)
// ------------------------------------------------------------

/**
 * Piano delle chiamate per un'edizione. Una sola chiamata lunga (decine di
 * secondi) viene interrotta dall'hosting (cURL 18 "partial file"): la si
 * spezza in più chiamate brevi in sequenza, ciascuna con poche pagine.
 *
 * Ritorna un elenco di fasi:
 *   [ "chiavi" => [...], "intestazione" => bool, "commento" => bool, "max_token" => int ]
 * La somma dei max_token non supera il limite complessivo del prompt, così il
 * "costo massimo" mostrato in anteprima resta garantito.
 * Con un solo elemento (formato articolo o fasi disattivate) la generazione
 * è la chiamata unica di sempre.
 */
function art_piano_chiamate(array $prompt): array
{
    $unica = [[ "chiavi" => [], "intestazione" => true, "commento" => true,
                "max_token" => (int) $prompt["max_token"], "unica" => true ]];
    if (($prompt["formato"] ?? "") !== "edizione" || !(bool) art_cfg("edizione_a_fasi", true)) return $unica;

    $partite = [];
    foreach ($prompt["pagine_attese"] as $p) {
        if ($p["tipo"] === "partita") $partite[] = $p["chiave"];
    }
    if (!$partite) return $unica;

    $perChiamata = max(1, (int) art_cfg("pagine_per_chiamata", 3));
    $tokPagina   = (int) art_cfg("token_per_pagina", 300);
    $tokBase     = (int) art_cfg("token_base_edizione", 450);
    $tokCommento = (int) art_cfg("token_commento", 450) + (int) ($prompt["n_partecipanti"] ?? 0) * (int) art_cfg("token_per_pagella", 45);

    $fasi = [];
    foreach (array_chunk($partite, $perChiamata) as $i => $gruppo) {
        $fasi[] = [
            "chiavi" => $gruppo, "intestazione" => $i === 0, "commento" => false,
            "max_token" => count($gruppo) * $tokPagina + ($i === 0 ? $tokBase : 0),
        ];
    }
    $fasi[] = ["chiavi" => ["COMMENTO"], "intestazione" => false, "commento" => true, "max_token" => $tokCommento];

    // Rientra nel limite complessivo (che include il tetto max_token_edizione)
    $somma = array_sum(array_column($fasi, "max_token"));
    $tetto = (int) $prompt["max_token"];
    if ($somma > $tetto && $somma > 0) {
        foreach ($fasi as &$f) $f["max_token"] = max(200, (int) floor($f["max_token"] * $tetto / $somma));
        unset($f);
    }
    return $fasi;
}

/** Istruzione aggiunta al messaggio per una fase. */
function art_istruzione_fase(array $fase, bool $conImmagini, string $giaScritto): string
{
    if (!empty($fase["commento"])) {
        $quali = "SOLO la pagina «@@ COMMENTO» (commento generale e sezione PAGELLE), che inizia direttamente con la riga «@@ COMMENTO»";
    } else {
        $elenco = implode(", ", $fase["chiavi"]);
        $quali = $fase["intestazione"]
            ? "SOLO l'intestazione (TITOLO, SOMMARIO" . ($conImmagini ? ", IMMAGINE" : "") . " e la riga ---) seguita dalle pagine: $elenco"
            : "SOLO le pagine: $elenco. Non scrivere TITOLO, SOMMARIO né la riga --- iniziali: parti direttamente con la prima riga «@@ " . $fase["chiavi"][0] . "»";
    }
    $t  = "\n\nISTRUZIONE PER QUESTA RICHIESTA\nL'edizione viene scritta in più richieste successive. In questa scrivi $quali. ";
    $t .= "Non scrivere le altre pagine: saranno richieste a parte. Rispetta il formato di output e le regole già indicate.";
    if ($giaScritto !== "") {
        $t .= "\n\nPAGINE GIÀ SCRITTE (per coerenza di tono: non ripetere battute, paragoni, aneddoti"
            . ($conImmagini ? " né immagini" : "") . " già usati, e non riscriverle):\n" . $giaScritto;
    }
    return $t;
}

/**
 * Genera l'edizione a fasi. Stessa forma di risultato di ai_messaggio_stream():
 *   ok, http, ms, errore, dati{content,stop_reason,usage}, [parziale], chiamate
 * Se una fase fallisce dopo averne completate altre (o con testo parziale),
 * ok=false e "parziale" contiene tutto il testo già ottenuto (anche questo è
 * stato fatturato), così il chiamante può salvarlo come bozza troncata.
 * Una fase che fallisce senza ricevere nulla viene ritentata (retry_chiamata, default 1).
 */
function art_genera_a_fasi(string $modello, array $prompt, array $fasi, ?int $timeoutSec, bool $streaming): array
{
    $conImmagini = count($prompt["catalogo_ids"] ?? []) > 0;
    $base    = (string) $prompt["messages"][0]["content"];
    $retry   = max(0, (int) art_cfg("retry_chiamata", 1));
    $testo   = "";
    $tokIn   = 0;
    $tokOut  = 0;
    $ms      = 0;
    $stop    = "end_turn";
    $fatte   = 0;

    foreach ($fasi as $i => $fase) {
        $msg = $base;
        if (empty($fase["unica"])) $msg .= art_istruzione_fase($fase, $conImmagini, $testo);
        $messaggi = [["role" => "user", "content" => $msg]];

        $gen = null;
        for ($tentativo = 0; $tentativo <= $retry; $tentativo++) {
            $gen = $streaming
                ? ai_messaggio_stream($modello, $messaggi, (int) $fase["max_token"], $prompt["system"], $timeoutSec)
                : ai_messaggio($modello, $messaggi, (int) $fase["max_token"], $prompt["system"], $timeoutSec);
            $ms += (int) ($gen["ms"] ?? 0);
            if ($gen["ok"] || isset($gen["parziale"])) break;
            // errore HTTP dell'API (chiave, quota, richiesta non valida): inutile ritentare
            if (($gen["http"] ?? 0) >= 400) break;
        }

        if ($gen["ok"]) {
            $t = "";
            foreach (($gen["dati"]["content"] ?? []) as $b) {
                if (($b["type"] ?? "") === "text") $t .= (string) ($b["text"] ?? "");
            }
            if ($i > 0 && !empty($fase["chiavi"])) {
                // le fasi successive devono partire dalla riga "@@ ...": scarto eventuali preamboli
                if (preg_match('/^[ \t]*@@/m', $t, $m, PREG_OFFSET_CAPTURE)) $t = substr($t, $m[0][1]);
            }
            $testo  .= ($testo !== "" ? "\n\n" : "") . trim($t);
            $tokIn  += (int) ($gen["dati"]["usage"]["input_tokens"] ?? 0);
            $tokOut += (int) ($gen["dati"]["usage"]["output_tokens"] ?? 0);
            if (($gen["dati"]["stop_reason"] ?? "") === "max_tokens") $stop = "max_tokens";
            $fatte++;
            continue;
        }

        // Fase fallita: salvo il salvabile
        $errore = (string) $gen["errore"] . " (fase " . ($i + 1) . " di " . count($fasi) . ")";
        $stimato = null;
        if (isset($gen["parziale"])) {
            $pp = $gen["parziale"];
            $t = (string) $pp["testo"];
            if ($i > 0 && !empty($fase["chiavi"]) && preg_match('/^[ \t]*@@/m', $t, $m, PREG_OFFSET_CAPTURE)) $t = substr($t, $m[0][1]);
            $testo  .= ($testo !== "" ? "\n\n" : "") . trim($t);
            $tokIn  += (int) $pp["token_input"];
            $tokOut += (int) ($pp["token_output_stimato"] ?? $pp["token_output"]);
            $stimato = $pp["token_output_stimato"];
        }
        $res = ["ok" => false, "http" => (int) $gen["http"], "ms" => $ms, "dati" => null,
                "errore" => $errore, "chiamate" => $fatte];
        if (trim($testo) !== "") {
            $res["parziale"] = ["testo" => $testo, "token_input" => $tokIn, "token_output" => $tokOut,
                                "token_output_stimato" => $stimato !== null ? $tokOut : null];
        }
        return $res;
    }

    return ["ok" => true, "http" => 200, "ms" => $ms, "errore" => null, "chiamate" => $fatte, "dati" => [
        "content"     => [["type" => "text", "text" => $testo]],
        "stop_reason" => $stop,
        "usage"       => ["input_tokens" => $tokIn, "output_tokens" => $tokOut],
    ]];
}

// ------------------------------------------------------------
// LETTURA DELLA RISPOSTA
// ------------------------------------------------------------

/**
 * Legge campi "CHIAVE: valore" da un blocco di testo. Le righe senza
 * chiave proseguono il campo precedente. Dopo la chiave $chiaveTesto
 * (es. TESTO) tutto il resto del blocco fa parte di quel campo.
 */
function art_leggi_campi(string $blocco, array $chiavi, string $chiaveTesto = ""): array
{
    $out = [];
    $corrente = null;
    foreach (explode("\n", $blocco) as $riga) {
        if ($chiaveTesto !== "" && $corrente === $chiaveTesto) {
            $out[$corrente] .= "\n" . $riga;
            continue;
        }
        if (preg_match('/^[ \t]*([A-Za-z]+):[ \t]*(.*)$/u', $riga, $m) && in_array(strtoupper($m[1]), $chiavi, true)) {
            $corrente = strtoupper($m[1]);
            $out[$corrente] = $m[2];
            continue;
        }
        if ($corrente !== null) $out[$corrente] .= "\n" . $riga;
    }
    foreach ($out as $k => $v) $out[$k] = trim($v);
    return $out;
}

/**
 * Pulisce i segnaposto immagine del testo di un articolo: restano solo
 * quelli su riga propria, con id presente nel catalogo, mai ripetuti
 * (nemmeno rispetto a $usate) e in numero massimo; gli altri spariscono.
 * $usate (id => true) viene aggiornato.
 */
function art_pulisci_marcatori_img(string $testo, array $catalogo, int $max, array &$usate): string
{
    $out = [];
    $n = 0;
    foreach (explode("\n", $testo) as $riga) {
        if (preg_match('/^\s*\[IMG:\s*(\d+)\s*(?:\|\s*([^\]]*))?\]\s*$/u', $riga, $m)) {
            $id = (int) $m[1];
            if (!isset($catalogo[$id]) || isset($usate[$id]) || $n >= $max) continue;
            $usate[$id] = true;
            $n++;
            $dida = trim(preg_replace('/\s+/u', ' ', (string) ($m[2] ?? "")));
            if (mb_strlen($dida) > 200) $dida = mb_substr($dida, 0, 200);
            $out[] = "";
            $out[] = "[IMG:$id" . ($dida !== "" ? "|" . $dida : "") . "]";
            $out[] = "";
            continue;
        }
        $pulita = preg_replace('/\[IMG:[^\]]*\]/u', "", $riga);   // segnaposto fuori posto: via
        if ($pulita !== $riga) $pulita = rtrim(preg_replace('/[ \t]{2,}/u', " ", $pulita));
        $out[] = $pulita;
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
}

/**
 * Separa titolo, sommario, immagine di apertura e corpo dal testo generato
 * (formato "articolo"). $catalogo = id ammessi (id => true).
 * Ritorna titolo, sommario, testo, formato_ok, id_immagine, immagini[].
 */
function art_parse_testo(string $testo, array $catalogo = [], int $maxInline = 2): array
{
    $testo = trim(str_replace(["\r\n", "\r"], "\n", $testo));
    $usate = [];
    $titolo = "";
    $sommario = "";
    $corpo = "";
    $idCop = null;
    $ok = false;

    if (preg_match('/^(.*?)\n[ \t]*-{3,}[ \t]*\n(.*)$/su', $testo, $m)) {
        $c = art_leggi_campi($m[1], ["TITOLO", "SOMMARIO", "IMMAGINE"]);
        if (($c["TITOLO"] ?? "") !== "" && array_key_exists("SOMMARIO", $c)) {
            $titolo = $c["TITOLO"];
            $sommario = $c["SOMMARIO"];
            $corpo = trim($m[2]);
            $idCop = art_normalizza_id_immagine($c["IMMAGINE"] ?? "", $catalogo);
            if ($idCop !== null) $usate[$idCop] = true;
            $ok = true;
        }
    }
    if (!$ok) {
        // Formato non rispettato: prima riga come titolo, il resto come corpo
        $righe = preg_split('/\R/u', $testo, 2);
        $titolo = trim(preg_replace('/^TITOLO:\s*/iu', '', $righe[0] ?? ""));
        $corpo = trim($righe[1] ?? "");
    }
    $corpo = art_pulisci_marcatori_img($corpo, $catalogo, $maxInline, $usate);

    return [
        "titolo" => $titolo, "sommario" => $sommario, "testo" => $corpo, "formato_ok" => $ok,
        "id_immagine" => $idCop, "immagini" => array_map("intval", array_keys($usate)),
    ];
}

/**
 * Legge l'edizione sfogliabile generata. $pagineAttese = elenco di
 * art_pagine_attese(). Ritorna:
 *   titolo, sommario, id_immagine (copertina), testo (versione piatta),
 *   pagine[] { chiave, tipo, competizione, titolo, testo, id_immagine, didascalia, dati },
 *   mancanti[] (chiavi delle pagine non trovate), formato_ok, immagini[]
 * Le pagine mancanti sono create comunque (con i soli fatti) per non
 * perdere la grafica; le immagini non valide o ripetute vengono scartate.
 */
function art_parse_edizione(string $testo, array $pagineAttese, array $catalogo): array
{
    $testo = trim(str_replace(["\r\n", "\r"], "\n", $testo));
    $usate = [];
    $intest = "";
    $corpo = "";

    if (preg_match('/^(.*?)\n[ \t]*-{3,}[ \t]*\n(.*)$/su', $testo, $m)) {
        $intest = $m[1];
        $corpo = $m[2];
    } elseif (preg_match('/^[ \t]*@@/m', $testo, $m, PREG_OFFSET_CAPTURE)) {
        $intest = substr($testo, 0, $m[0][1]);
        $corpo = substr($testo, $m[0][1]);
    } else {
        $intest = $testo;
    }
    $c = art_leggi_campi($intest, ["TITOLO", "SOMMARIO", "IMMAGINE"]);
    $titolo = (string) ($c["TITOLO"] ?? "");
    $sommario = (string) ($c["SOMMARIO"] ?? "");
    $headerOk = $titolo !== "";
    $idCop = art_normalizza_id_immagine($c["IMMAGINE"] ?? "", $catalogo);
    if ($idCop !== null) $usate[$idCop] = true;

    // Blocchi per intestazione "@@ PARTITA n" / "@@ COMMENTO"
    $trovate = [];
    $pezzi = preg_split('/^[ \t]*@@[ \t]*(PARTITA[ \t]+\d+|COMMENTO)[ \t]*:?[ \t]*$/mi', $corpo, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($pezzi); $i += 2) {
        $chiave = strtoupper(preg_replace('/\s+/', ' ', trim($pezzi[$i])));
        $blocco = $pezzi[$i + 1] ?? "";
        if (!isset($trovate[$chiave])) {
            $trovate[$chiave] = art_leggi_campi($blocco, ["TITOLO", "IMMAGINE", "DIDASCALIA", "TESTO"], "TESTO");
        }
    }

    $pagine = [];
    $mancanti = [];
    foreach ($pagineAttese as $p) {
        $f = $trovate[$p["chiave"]] ?? null;
        if ($f === null || trim((string) ($f["TESTO"] ?? "")) === "") $mancanti[] = $p["chiave"];
        $idImm = null;
        $dida = "";
        if ($f !== null) {
            $idImm = art_normalizza_id_immagine($f["IMMAGINE"] ?? "", $catalogo);
            if ($idImm !== null && isset($usate[$idImm])) $idImm = null;   // niente immagini ripetute
            if ($idImm !== null) {
                $usate[$idImm] = true;
                $dida = trim(preg_replace('/\s+/u', ' ', (string) ($f["DIDASCALIA"] ?? "")));
                if (mb_strlen($dida) > 300) $dida = mb_substr($dida, 0, 300);
            }
        }
        $testoPag = $f !== null ? trim(preg_replace('/\[IMG:[^\]]*\]/u', "", (string) ($f["TESTO"] ?? ""))) : "";
        $titPag = trim((string) ($f["TITOLO"] ?? ""));
        if ($titPag === "") $titPag = (string) $p["etichetta"];
        if (mb_strlen($titPag) > 250) $titPag = mb_substr($titPag, 0, 250);
        $pagine[] = [
            "chiave" => $p["chiave"], "tipo" => $p["tipo"], "competizione" => $p["competizione"],
            "titolo" => $titPag, "testo" => $testoPag, "id_immagine" => $idImm, "didascalia" => $dida,
            "dati" => $p["dati"],
        ];
    }

    return [
        "titolo" => $titolo, "sommario" => $sommario, "id_immagine" => $idCop,
        "testo" => art_testo_piatto($pagine),
        "pagine" => $pagine, "mancanti" => $mancanti,
        "formato_ok" => $headerOk && !$mancanti,
        "immagini" => array_map("intval", array_keys($usate)),
    ];
}

/** Versione "piatta" di un'edizione (titolo e testo di ogni pagina), per NEW_ARTICOLI.testo. */
function art_testo_piatto(array $pagine): string
{
    $parti = [];
    foreach ($pagine as $pg) {
        $t = trim((string) ($pg["titolo"] ?? ""));
        $x = trim((string) ($pg["testo"] ?? ""));
        if ($t !== "") $parti[] = mb_strtoupper($t);
        if ($x !== "") $parti[] = $x;
    }
    return trim(implode("\n\n", $parti));
}

// ------------------------------------------------------------
// IMMAGINI USATE
// ------------------------------------------------------------

/** Elenco CSV (max 300 caratteri) degli id immagine usati da copertina, segnaposto nel testo e pagine. */
function art_immagini_usate_csv(?int $copertina, string $testo, array $pagine): string
{
    $ids = [];
    if ($copertina) $ids[(int) $copertina] = true;
    if (preg_match_all('/\[IMG:\s*(\d+)/', $testo, $m)) {
        foreach ($m[1] as $i) $ids[(int) $i] = true;
    }
    foreach ($pagine as $p) {
        if (!empty($p["id_immagine"])) $ids[(int) $p["id_immagine"]] = true;
    }
    $lista = array_keys($ids);
    sort($lista);
    $csv = implode(",", $lista);
    if (strlen($csv) > 300) $csv = substr($csv, 0, strrpos(substr($csv, 0, 300), ","));
    return $csv;
}

/** Quali id esistono davvero in NEW_IMMAGINI_GAZZETTA? Ritorna l'elenco di quelli presenti. */
function art_immagini_esistenti($conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $in = implode(",", $ids);
    return array_map(fn($r) => (int) $r["id"], query_all("SELECT id FROM NEW_IMMAGINI_GAZZETTA WHERE id IN ($in)"));
}

/** Registra l'uso delle immagini (la rotazione preferisce le meno usate). */
function art_segna_immagini_usate($conn, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    if (!$ids) return;
    mysqli_query($conn, "UPDATE NEW_IMMAGINI_GAZZETTA SET ultimo_utilizzo = NOW() WHERE id IN (" . implode(",", $ids) . ")");
}
