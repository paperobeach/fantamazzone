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
//   art_costruisci($conn, $s, $g)       prompt completo + riepilogo
//   art_hash($prompt)                   impronta del prompt
//   art_parse_testo($testo)             titolo / sommario / corpo
//   art_spesa_mese_usd($conn)           spesa AI del mese corrente
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

/** Spesa reale (USD) degli articoli generati nel mese corrente. */
function art_spesa_mese_usd($conn): float
{
    $r = query_one("SELECT COALESCE(SUM(costo_usd), 0) AS s FROM NEW_ARTICOLI
                    WHERE data_generazione >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    return (float) ($r["s"] ?? 0);
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
    $eventi = ["marcatori" => [], "autogol" => [], "espulsi" => [], "rigori_parati" => [], "rigori_sbagliati" => []];
    $riserveUfficio = [];
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
        if ((int) $v["reti"] > 0)       $eventi["marcatori"][]        = $ev + ["gol" => (int) $v["reti"]];
        if ((int) $v["autogol"] > 0)    $eventi["autogol"][]          = $ev;
        if ((int) $v["espulsioni"] > 0) $eventi["espulsi"][]          = $ev;
        if ((int) $v["rigorep"] > 0)    $eventi["rigori_parati"][]    = $ev;
        if ((int) $v["rigores"] > 0)    $eventi["rigori_sbagliati"][] = $ev;
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
// PROMPT
// ------------------------------------------------------------

function art_system_prompt(int $paroleTarget): string
{
    return <<<TXT
Sei il caporedattore de «La Gazzetta del Fantamazzone», quotidiano sportivo immaginario di una lega di fantacalcio tra amici. Scrivi in italiano un articolo di commento alla giornata appena conclusa, con tono scherzoso, ironico e goliardico, nello stile di un quotidiano sportivo: titoloni, giochi di parole, paragoni iperbolici, pagelle.

REGOLE
1. Per risultati, punteggi, gol, classifica, giocatori e squadre usa ESCLUSIVAMENTE i dati forniti. Non inventare né modificare numeri, nomi o esiti. Se un dato manca, non parlarne.
2. Profili ed episodi storici servono come colore: richiamali con parsimonia e solo quando c'entrano davvero con i fatti della giornata, senza forzature. Non inventare altri fatti sulla vita delle persone.
3. L'ironia deve essere affettuosa. Puoi prendere in giro prestazioni, scelte di formazione, tormentoni e rivalità forniti. Mai insulti né battute su salute, aspetto fisico, famiglia, origini, religione, politica o altri aspetti privati o sensibili.
4. Le citazioni inventate sono ammesse solo se palesemente comiche e attribuite ai fantallenatori della lega.
5. Lunghezza: circa {$paroleTarget} parole.
6. Struttura: titolo, sommario di una o due righe, corpo dell'articolo con qualche intertitolo breve, e una sezione finale «PAGELLE» con un voto in decimi e un commento di una riga per ogni fantallenatore che ha giocato.

FORMATO DI OUTPUT (rispettalo esattamente)
Prima riga: TITOLO: seguito dal titolo.
Seconda riga: SOMMARIO: seguito dal sommario.
Terza riga: ---
Poi il testo dell'articolo in testo semplice: paragrafi separati da una riga vuota, intertitoli in MAIUSCOLO su una riga a sé. Nessun markdown, nessun commento fuori dall'articolo.
TXT;
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
 * Ritorna ["errore"=>string] se la giornata non ha risultati, altrimenti:
 *   system, messages, max_token, parole_target,
 *   riepilogo (n. partite, profili usati/mancanti, episodi scelti,
 *              classifica inclusa), prompt_utente (testo mostrato in anteprima)
 */
function art_costruisci($conn, int $stagione, int $giornata): array
{
    $dati = art_dati_giornata($stagione, $giornata);
    if (!$dati["partite_campionato"]) {
        return ["errore" => "Nessun risultato di campionato per la giornata $giornata della stagione $stagione"];
    }

    $partecipanti = $dati["_partecipanti"];
    unset($dati["_partecipanti"]);

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

    $parole = (int) art_cfg("parole_target", 500);
    $maxToken = (int) art_cfg("max_token", 1800);

    $testo  = "Scrivi l'articolo per la giornata $giornata della stagione $stagione.\n\n";
    $testo .= "DATI DELLA GIORNATA (JSON):\n" . json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    $testo .= "PROFILI DEI FANTALLENATORI:\n";
    $testo .= $righeProfili ? implode("\n", $righeProfili) : "(nessun profilo disponibile)";
    if ($senzaProfilo) $testo .= "\nSenza profilo (usa solo i dati): " . implode(", ", $senzaProfilo) . ".";
    $testo .= "\n\nEPISODI STORICI DELLA LEGA (da richiamare solo se pertinenti):\n";
    $testo .= $righeEpisodi ? implode("\n", $righeEpisodi) : "(nessun episodio disponibile)";

    return [
        "system"        => art_system_prompt($parole),
        "messages"      => [["role" => "user", "content" => $testo]],
        "max_token"     => $maxToken,
        "parole_target" => $parole,
        "prompt_utente" => $testo,
        "riepilogo"     => [
            "partite_campionato"  => count($dati["partite_campionato"]),
            "partite_champions"   => count($dati["partite_champions"]),
            "classifica_inclusa"  => $dati["classifica_inclusa"],
            "profili_usati"       => count($righeProfili),
            "senza_profilo"       => $senzaProfilo,
            "episodi"             => array_map(fn($e) => ["id" => (int) $e["id"], "titolo" => $e["titolo"]], $episodi),
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

/** Separa titolo, sommario e corpo dal testo generato. */
function art_parse_testo(string $testo): array
{
    $testo = trim($testo);
    if (preg_match('/^\s*TITOLO:\s*(.+?)\R\s*SOMMARIO:\s*(.+?)\R\s*---+\s*\R(.*)$/su', $testo, $m)) {
        return ["titolo" => trim($m[1]), "sommario" => trim($m[2]), "testo" => trim($m[3]), "formato_ok" => true];
    }
    // Formato non rispettato: prima riga come titolo, il resto come corpo
    $righe = preg_split('/\R/u', $testo, 2);
    $titolo = trim(preg_replace('/^TITOLO:\s*/iu', '', $righe[0] ?? ""));
    return ["titolo" => $titolo, "sommario" => "", "testo" => trim($righe[1] ?? ""), "formato_ok" => false];
}
