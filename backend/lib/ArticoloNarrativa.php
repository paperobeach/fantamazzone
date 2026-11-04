<?php
// ============================================================
// backend/lib/ArticoloNarrativa.php
//
// Fonti informative AGGIUNTIVE per il prompt dell'articolo AI
// («La Gazzetta del Fantamazzone»), lette dal DB e rese in testo
// compatto. Servono tre obiettivi:
//
//  1. VARIABILITÀ delle citazioni
//     - episodi storici scelti per collegamento CONCRETO con le partite
//       della giornata (stessi allenatori), con pausa sugli episodi usati
//       di recente e rotazione deterministica;
//     - tormentoni dei profili ruotati (se ne propone solo qualcuno, non
//       citato di recente), rivalità solo quando riguarda chi gioca.
//
//  2. CONTINUITÀ della narrazione tra una giornata e l'altra
//     - «PUNTATE PRECEDENTI»: titolo, sommario, sintesi e pagelle degli
//       ultimi articoli della stagione (da NEW_ARTICOLI);
//     - «ANDAMENTO DELLE SQUADRE»: classifica prima/dopo, ultimi risultati,
//       serie in corso, record stagionali (da NEW_RISULTATI);
//     - scontri diretti (stagione corrente + storico, campionato e
//       Champions) in ogni scheda partita.
//
//  3. ENFASI COMICA sui fatti della singola giornata
//     - SEQUENZA di ogni partita in ordine di calendario di Serie A
//       (punti parziali, sorpassi, ribaltoni);
//     - colpi di fortuna (subentri che producono bonus al posto di un
//       titolare) e di sfortuna (big che non rendono, big titolari non
//       scesi in campo o usciti presto, assenze illustri di giocatori
//       costosi non schierati che invece hanno giocato).
//
// NON chiama Anthropic e NON scrive su DB. Tutto è DETERMINISTICO (stessi
// dati → stesso testo: nessuna casualità, solo rotazioni basate su crc32),
// così l'hash del prompt calcolato all'anteprima coincide con quello della
// generazione. Ogni sezione è isolata: se una fonte fallisce (tabella
// mancante, errore SQL) le altre restano e l'errore finisce nel riepilogo.
//
// Va incluso da lib/ArticoloGiornata.php (usa art_cfg, art_tabella_presente,
// art_pulisci, art_profili, query_all, query_one già disponibili).
// ============================================================

require_once __DIR__ . "/RosaStorico.php";
require_once __DIR__ . "/PanchinaDettaglio.php";
require_once __DIR__ . "/FormazioniChampions.php";

const ARN_RUOLI  = [1 => "P", 2 => "D", 3 => "C", 4 => "A"];
const ARN_GIORNI = ["dom", "lun", "mar", "mer", "gio", "ven", "sab"];

// ------------------------------------------------------------
// UTILITÀ DI TESTO
// ------------------------------------------------------------

/** Numero in forma breve (7.5, 6, -1.25). */
function arn_n($v, int $dec = 2): string
{
    $s = number_format((float) $v, $dec, ".", "");
    if (strpos($s, ".") !== false) $s = rtrim(rtrim($s, "0"), ".");
    return ($s === "-0" || $s === "") ? "0" : $s;
}

/** Maiuscolo senza spazi ai bordi, per confrontare i nomi delle squadre di Serie A. */
function arn_up(?string $s): string
{
    $s = trim((string) $s);
    return function_exists("mb_strtoupper") ? mb_strtoupper($s, "UTF-8") : strtoupper($s);
}

/** Testo normalizzato per i confronti: minuscolo, senza accenti né punteggiatura. */
function arn_nt(string $s): string
{
    $s = function_exists("mb_strtolower") ? mb_strtolower($s, "UTF-8") : strtolower($s);
    $s = strtr($s, ["à" => "a", "á" => "a", "â" => "a", "ä" => "a", "è" => "e", "é" => "e", "ê" => "e", "ë" => "e",
                    "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ò" => "o", "ó" => "o", "ô" => "o", "ö" => "o",
                    "ù" => "u", "ú" => "u", "û" => "u", "ü" => "u", "ç" => "c", "ñ" => "n"]);
    return trim(preg_replace('/[^a-z0-9]+/u', " ", $s));
}

/** Radici (prime 6 lettere) delle parole significative di un frammento. */
function arn_radici(string $s): array
{
    static $stop = ["della", "delle", "degli", "dello", "nella", "nelle", "nello", "negli", "sono", "come", "anche",
                    "questo", "questa", "quello", "quella", "perche", "mentre", "ancora", "sempre", "molto", "dopo",
                    "prima", "altro", "altra", "tutti", "tutto", "ogni", "stato", "essere", "hanno", "aveva", "fatto",
                    "cosa", "cose", "suoi", "loro", "dalla", "dalle", "alla", "alle", "dagli", "quando", "dove", "sulla"];
    $out = [];
    foreach (explode(" ", arn_nt($s)) as $w) {
        if (strlen($w) < 4 || in_array($w, $stop, true)) continue;
        $out[substr($w, 0, 6)] = true;
    }
    return array_keys($out);
}

/**
 * Il frammento (tormentone, titolo di episodio, soprannome...) compare nel
 * testo già normalizzato $testoNorm? Confronto per radici di parola: serve
 * a capire se un richiamo è stato usato di recente, non è una prova certa.
 */
function arn_citato(string $frammento, string $testoNorm): bool
{
    if ($testoNorm === "") return false;
    $rad = arn_radici($frammento);
    if (!$rad) return false;
    $hay = " " . $testoNorm;
    $hit = 0;
    foreach ($rad as $r) if (strpos($hay, " " . $r) !== false) $hit++;
    $n = count($rad);
    return $n === 1 ? $hit === 1 : $hit >= max(2, (int) ceil($n * 0.6));
}

/** Chiave di rotazione deterministica (intero non negativo). */
function arn_rot(string $seed, string $chiave): int
{
    return (int) sprintf("%u", crc32($seed . "|" . $chiave));
}

/** Etichetta ordinale breve ("4ª"). */
function arn_giornate_di_fila(int $n): string
{
    return $n . "ª giornata di fila";
}

// ------------------------------------------------------------
// DATI DI SUPPORTO (per stagione / giornata)
// ------------------------------------------------------------

/** Giornata di Serie A corrispondente alla giornata fanta (GIORNATA_SERIE_A_INIZIO). */
function arn_giornata_serie_a(int $stagione, int $giornata): int
{
    $par = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                      WHERE stagione = $stagione AND codice = 'GIORNATA_SERIE_A_INIZIO'");
    $v = $par ? trim((string) $par["valore"]) : "";
    $ini = ($v !== "" && ctype_digit($v) && (int) $v >= 1) ? (int) $v : 1;
    return $giornata + $ini - 1;
}

/** Partite di Serie A della giornata, indicizzate per squadra (nome maiuscolo). */
function arn_slots(int $stagione, int $gSerieA): array
{
    $out = [];
    foreach (query_all("SELECT n_partita, data_partita, ora_partita, squadra_casa, squadra_ospite
                        FROM NEW_CALENDARIO_SERIE_A
                        WHERE stagione = $stagione AND giornata = $gSerieA") as $p) {
        $ora = !empty($p["ora_partita"]) ? substr((string) $p["ora_partita"], 0, 5) : null;
        $ts  = strtotime((string) $p["data_partita"]);
        $label = ($ts ? ARN_GIORNI[(int) date("w", $ts)] . " " . date("d/m", $ts) : "data n/d")
               . " " . ($ora ?? "(orario da definire)");
        $slot = [
            "key"     => $p["data_partita"] . " " . ($ora ?? "99:99") . " " . str_pad((string) (int) $p["n_partita"], 3, "0", STR_PAD_LEFT),
            "label"   => $label,
            "partita" => trim((string) $p["squadra_casa"]) . "-" . trim((string) $p["squadra_ospite"]),
        ];
        $out[arn_up($p["squadra_casa"])]   = $slot;
        $out[arn_up($p["squadra_ospite"])] = $slot;
    }
    return $out;
}

/**
 * Informazioni sui calciatori della stagione: crediti, ruolo, squadra di
 * Serie A (asta) e soglia "big" per ruolo (percentile dei crediti).
 */
function arn_info_giocatori(int $stagione): array
{
    $crediti = [];
    $ruolo   = [];
    $serieA  = [];
    foreach (query_all("SELECT id_giocatore, squadra_serie_a, crediti, ruolo
                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione") as $r) {
        $id = (int) $r["id_giocatore"];
        $crediti[$id] = (int) $r["crediti"];
        $ruolo[$id]   = (int) $r["ruolo"];
        $serieA[$id]  = trim((string) $r["squadra_serie_a"]);
    }
    foreach (query_all("SELECT id, crediti, ruolo FROM NEW_GIOCATORI WHERE stagione = $stagione") as $r) {
        $id = (int) $r["id"];
        if ((int) $r["crediti"] > 0) $crediti[$id] = (int) $r["crediti"];
        if ((int) $r["ruolo"] > 0)   $ruolo[$id]   = (int) $r["ruolo"];
    }

    // Soglia "big": percentile dei crediti dentro ciascun ruolo (un portiere da 30 crediti è un big, un attaccante no)
    $pct   = max(50, min(99, (int) art_cfg("big_percentile", 80)));
    $minCr = max(0, (int) art_cfg("big_crediti_min", 20));
    $perRuolo = [1 => [], 2 => [], 3 => [], 4 => []];
    $tutti = [];
    foreach ($crediti as $id => $c) {
        if ($c <= 0) continue;
        $tutti[] = $c;
        if (isset($perRuolo[$ruolo[$id] ?? 0])) $perRuolo[$ruolo[$id]][] = $c;
    }
    $soglia = function (array $v) use ($pct, $minCr) {
        if (count($v) < 8) return null;
        sort($v);
        $i = min(count($v) - 1, (int) floor(count($v) * $pct / 100));
        return max($minCr, $v[$i]);
    };
    $sogliaGlob = $soglia($tutti) ?? PHP_INT_MAX;
    $soglie = [];
    foreach ($perRuolo as $r => $v) $soglie[$r] = $soglia($v) ?? $sogliaGlob;

    return ["crediti" => $crediti, "ruolo" => $ruolo, "serie_a" => $serieA, "soglie" => $soglie];
}

/** Il giocatore è un "big" (crediti alti rispetto al suo ruolo)? */
function arn_big(array $info, int $idGiocatore, int $ruolo): bool
{
    $c = (int) ($info["crediti"][$idGiocatore] ?? 0);
    return $c > 0 && $c >= (int) ($info["soglie"][$ruolo] ?? PHP_INT_MAX);
}

/** Voti ufficiali di Serie A della giornata: id_giocatore => riga. */
function arn_voti_serie_a(int $stagione, int $giornata): array
{
    $out = [];
    foreach (query_all("SELECT id_giocatore, squadra, voto, sv, gf, gs, rp, rs, rf, au, amm, esp, ass
                        FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $out[(int) $r["id_giocatore"]] = $r;
    }
    return $out;
}

/**
 * Giornate consecutive PRECEDENTI a $giornata in cui il giocatore non compare
 * nei voti di Serie A (non è entrato in campo). Ritorna id => numero (0..8).
 */
function arn_serie_assenze(int $stagione, int $giornata, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    $out = array_fill_keys($ids, 0);
    if (!$ids || $giornata <= 1) return $out;
    $da = max(1, $giornata - 8);
    $presenze = [];
    foreach (query_all("SELECT id_giocatore, giornata FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND giornata BETWEEN $da AND " . ($giornata - 1) . "
                          AND id_giocatore IN (" . implode(",", $ids) . ")") as $r) {
        $presenze[(int) $r["id_giocatore"]][(int) $r["giornata"]] = true;
    }
    foreach ($ids as $id) {
        $n = 0;
        for ($g = $giornata - 1; $g >= $da; $g--) {
            if (!empty($presenze[$id][$g])) break;
            $n++;
        }
        $out[$id] = $n;
    }
    return $out;
}

/** Minuti giocati (API-Football) di alcuni giocatori nella giornata di Serie A: id => [minuti, titolare, esp]. */
function arn_minuti_api($conn, int $stagione, int $gSerieA, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    foreach (["NEW_API_STATISTICHE", "NEW_API_PARTITE", "NEW_API_MAPPING_GIOCATORI"] as $t) {
        if (!art_tabella_presente($conn, $t)) return [];
    }
    $out = [];
    try {
        foreach (query_all("SELECT m.id_giocatore, s.minuti, s.titolare, s.presente, s.esp
                            FROM NEW_API_STATISTICHE s
                            JOIN NEW_API_PARTITE p ON p.stagione = s.stagione AND p.api_fixture_id = s.api_fixture_id
                            JOIN NEW_API_MAPPING_GIOCATORI m ON m.stagione = s.stagione AND m.api_player_id = s.api_player_id
                            WHERE s.stagione = $stagione AND p.giornata = $gSerieA
                              AND m.id_giocatore IN (" . implode(",", $ids) . ")") as $r) {
            $out[(int) $r["id_giocatore"]] = [
                "minuti" => (int) $r["minuti"], "titolare" => $r["titolare"] === "Y",
                "presente" => $r["presente"] === "Y", "esp" => (int) $r["esp"],
            ];
        }
    } catch (Throwable $e) {
        error_log("ArticoloNarrativa: minuti API non letti: " . $e->getMessage());
    }
    return $out;
}

/** Nomi dei giocatori (rosa o ceduti) per id. */
function arn_nomi_giocatori(int $stagione, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $out = [];
    foreach (query_all("SELECT g.id, g.descrizione, g.ruolo FROM " . rs_sql_anagrafica($stagione) . " g
                        WHERE g.id IN (" . implode(",", $ids) . ")") as $r) {
        $out[(int) $r["id"]] = ["nome" => trim((string) $r["descrizione"]), "ruolo" => (int) $r["ruolo"]];
    }
    return $out;
}

// ------------------------------------------------------------
// MEMORIA DELLE PUNTATE PRECEDENTI
// ------------------------------------------------------------

/** Prime frasi dei paragrafi (salta intertitoli in maiuscolo e la sezione PAGELLE), entro $max caratteri. */
function arn_sintesi_testo(string $testo, int $max): string
{
    $testo = str_replace(["\r\n", "\r"], "\n", $testo);
    if (preg_match('/^\s*PAGELLE\b/mu', $testo, $m, PREG_OFFSET_CAPTURE)) $testo = substr($testo, 0, $m[0][1]);
    $out = [];
    $len = 0;
    foreach (preg_split('/\n\s*\n/u', $testo) as $par) {
        $par = trim(preg_replace('/\[IMG:[^\]]*\]/u', "", $par));
        if ($par === "") continue;
        if (mb_strtoupper($par, "UTF-8") === $par && mb_strlen($par) < 90) continue;   // intertitolo
        $par = trim(preg_replace('/\s+/u', " ", $par));
        if (preg_match('/^(.{40,240}?[.!?…])(\s|$)/us', $par, $m)) $frase = $m[1];
        else $frase = mb_strlen($par) > 200 ? mb_substr($par, 0, 200) . "…" : $par;
        if ($len + mb_strlen($frase) > $max) break;
        $out[] = $frase;
        $len += mb_strlen($frase) + 1;
    }
    return implode(" ", $out);
}

/** Sezione PAGELLE di un testo (voti dati nelle puntate precedenti), entro $max caratteri. */
function arn_pagelle_testo(string $testo, int $max): string
{
    $testo = str_replace(["\r\n", "\r"], "\n", $testo);
    if (!preg_match('/^\s*PAGELLE\b[^\n]*\n(.*)$/msu', $testo, $m)) return "";
    $righe = array_filter(array_map("trim", explode("\n", $m[1])), fn($r) => $r !== "");
    $s = trim(preg_replace('/\s+/u', " ", implode(" | ", $righe)));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . "…" : $s;
}

/**
 * Ultimi articoli della stagione (uno per giornata, giornate precedenti a
 * quella in corso). Ritorna:
 *   righe    testo per il prompt (sintesi + pagelle delle ultime N puntate)
 *   norm     testo normalizzato delle ultime K puntate (rilevamento richiami)
 *   episodi  id degli episodi usati nelle ultime P puntate (pausa)
 *   n        puntate nel prompt
 */
function arn_memoria($conn, int $stagione, int $giornata): array
{
    $vuoto = ["righe" => "", "norm" => "", "episodi" => [], "n" => 0, "giornate" => []];
    $nPrompt = max(0, (int) art_cfg("memoria_articoli", 3));
    $nRilev  = max($nPrompt, (int) art_cfg("memoria_rilevamento", 4));
    $nPausa  = max(0, (int) art_cfg("episodi_pausa_articoli", 2));
    $max     = max($nPrompt, $nRilev, $nPausa);
    if ($max <= 0 || !art_tabella_presente($conn, "NEW_ARTICOLI")) return $vuoto;

    $soloPubb = (bool) art_cfg("memoria_solo_pubblicati", true);
    $righe = query_all("SELECT id, giornata, titolo, sommario, testo, episodi_usati, stato
                        FROM NEW_ARTICOLI
                        WHERE stagione = $stagione AND giornata < $giornata"
                        . ($soloPubb ? " AND stato = 'pubblicato'" : "")
                        . " ORDER BY giornata DESC, id DESC");
    $perGiornata = [];
    foreach ($righe as $r) {
        $g = (int) $r["giornata"];
        if (!isset($perGiornata[$g])) $perGiornata[$g] = $r;     // l'ultimo generato per quella giornata
        if (count($perGiornata) >= $max) break;
    }
    if (!$perGiornata) return $vuoto;
    krsort($perGiornata);
    $lista = array_values($perGiornata);

    $maxSintesi  = max(300, (int) art_cfg("memoria_caratteri", 1800));
    $maxPagelle  = max(0, (int) art_cfg("memoria_pagelle_caratteri", 450));
    $testi = [];
    $episodi = [];
    $giornate = [];
    foreach ($lista as $i => $a) {
        if ($i < $nRilev) $testi[] = arn_nt((string) $a["titolo"] . " " . (string) $a["sommario"] . " " . (string) $a["testo"]);
        if ($i < $nPausa) {
            foreach (explode(",", (string) $a["episodi_usati"]) as $e) if ((int) $e > 0) $episodi[(int) $e] = true;
        }
        if ($i < $nPrompt) {
            $giornate[] = (int) $a["giornata"];
            $t = "- GIORNATA " . (int) $a["giornata"] . " — titolo: «" . art_pulisci($a["titolo"], 160) . "»";
            if (trim((string) $a["sommario"]) !== "") $t .= "\n  sommario: " . art_pulisci($a["sommario"], 300);
            $sint = arn_sintesi_testo((string) $a["testo"], $maxSintesi);
            if ($sint !== "") $t .= "\n  cosa era stato raccontato: " . $sint;
            if ($maxPagelle > 0) {
                $pag = arn_pagelle_testo((string) $a["testo"], $maxPagelle);
                if ($pag !== "") $t .= "\n  pagelle date: " . $pag;
            }
            $testi_prompt[] = $t;
        }
    }
    return [
        "righe"    => isset($testi_prompt) ? implode("\n", $testi_prompt) : "",
        "norm"     => implode(" ", $testi),
        "episodi"  => $episodi,
        "n"        => isset($testi_prompt) ? count($testi_prompt) : 0,
        "giornate" => $giornate,
    ];
}

// ------------------------------------------------------------
// ANDAMENTO DELLE SQUADRE (forma, classifica prima/dopo)
// ------------------------------------------------------------

/** Posizioni e punti in classifica considerando le giornate fino a $fino. */
function arn_classifica_a(array $righe, int $fino): array
{
    $pt = []; $gf = []; $gs = [];
    foreach ($righe as $r) {
        if ((int) $r["giornata"] > $fino) continue;
        $i = (int) $r["id_squadra"];
        $pt[$i] = ($pt[$i] ?? 0) + (int) $r["punti"];
        $gf[$i] = ($gf[$i] ?? 0) + (int) $r["golf"];
        $gs[$i] = ($gs[$i] ?? 0) + (int) $r["gols"];
    }
    $ids = array_keys($pt);
    usort($ids, fn($x, $y) => ($pt[$y] <=> $pt[$x]) ?: ($gf[$y] <=> $gf[$x]) ?: ($gs[$y] <=> $gs[$x]) ?: ($x <=> $y));
    $pos = [];
    foreach ($ids as $k => $i) $pos[$i] = $k + 1;
    return [$pos, $pt];
}

/**
 * Forma delle squadre in gioco: posizione prima/dopo, ultimi risultati, serie
 * in corso, record stagionali. Ritorna ["testo" => string, "squadre" => int].
 */
function arn_forma(int $stagione, int $giornata, array $partecipanti, array $sq): array
{
    $righe = query_all("SELECT giornata, id_squadra, id_squadra_a, ftotale, ftotale_a, golf, gols, punti, segno
                        FROM NEW_RISULTATI
                        WHERE stagione = $stagione AND giornata <= $giornata
                        ORDER BY giornata, id_squadra");
    if (!$righe) return ["testo" => "", "squadre" => 0];

    [$posPrima, $ptPrima] = arn_classifica_a($righe, $giornata - 1);
    [$posDopo,  $ptDopo]  = arn_classifica_a($righe, $giornata);
    $nSquadre = count($posDopo);
    $capoDopo = $posDopo ? array_search(1, $posDopo, true) : null;

    $perSq = [];
    $punteggi = [];     // tutti i punteggi della stagione fino a oggi (per record di lega)
    foreach ($righe as $r) {
        $perSq[(int) $r["id_squadra"]][] = $r;
        $punteggi[] = [(float) $r["ftotale"], (int) $r["id_squadra"], (int) $r["giornata"]];
    }
    $nome = fn(int $id) => $sq[$id]["nome"] ?? ("Squadra $id");
    $nPrec = max(0, (int) art_cfg("forma_ultime", 5));

    // record di lega (punteggio più alto/basso della stagione, se stabilito oggi)
    $recordLega = [];
    if ($punteggi) {
        usort($punteggi, fn($a, $b) => $b[0] <=> $a[0]);
        $alto = $punteggi[0]; $basso = end($punteggi);
        if ($alto[2] === $giornata)  $recordLega[$alto[1]]  = "punteggio PIÙ ALTO della stagione (" . arn_n($alto[0], 1) . ")";
        if ($basso[2] === $giornata) $recordLega[$basso[1]] = "punteggio PIÙ BASSO della stagione (" . arn_n($basso[0], 1) . ")";
    }

    $linee = [];
    foreach ($partecipanti as $p) {
        $id = (int) $p["id"];
        $mie = $perSq[$id] ?? [];
        if (!$mie) continue;
        $oggi = null;
        foreach ($mie as $r) if ((int) $r["giornata"] === $giornata) $oggi = $r;
        $prima = $posPrima[$id] ?? null;
        $dopo  = $posDopo[$id] ?? null;

        $t = "- " . $nome($id) . " (" . $p["allenatore"] . "): ";
        if ($dopo !== null) {
            $t .= $dopo . "° con " . ($ptDopo[$id] ?? 0) . " pt";
            if ($prima !== null && $prima !== $dopo) $t .= " (era " . $prima . "°, " . ($dopo < $prima ? "sale di " : "scende di ") . abs($prima - $dopo) . ")";
            elseif ($prima !== null) $t .= " (posizione invariata)";
            if ($dopo === 1 && $capoDopo === $id && $prima !== null && $prima !== 1) $t .= " — nuova capolista";
            if ($dopo === $nSquadre && $nSquadre > 1 && $prima !== $dopo) $t .= " — scivola all'ultimo posto";
        }

        // ultime N partite, la più recente a destra, con l'avversaria
        $ult = array_slice($mie, -$nPrec);
        $sigle = [];
        foreach ($ult as $r) {
            $sigle[] = "g" . (int) $r["giornata"] . " " . ($r["segno"] ?: "?") . " " . (int) $r["golf"] . "-" . (int) $r["gols"] . " con " . $nome((int) $r["id_squadra_a"]);
        }
        if ($sigle) $t .= "; ultime: " . implode(", ", $sigle);

        // serie in corso (include oggi)
        $serie = 0; $segnoSerie = null;
        for ($i = count($mie) - 1; $i >= 0; $i--) {
            $s = $mie[$i]["segno"];
            if ($segnoSerie === null) $segnoSerie = $s;
            if ($s !== $segnoSerie) break;
            $serie++;
        }
        if ($serie >= 3 && $segnoSerie !== null) {
            $t .= "; serie in corso: " . $serie . " " . ($segnoSerie === "V" ? "vittorie" : ($segnoSerie === "P" ? "sconfitte" : "pareggi")) . " di fila";
        } elseif ($serie >= 2 && $segnoSerie === "P") {
            $t .= "; serie in corso: 2 sconfitte di fila";
        }
        // non vince / non perde da...
        $senzaV = 0; $senzaP = 0;
        for ($i = count($mie) - 1; $i >= 0 && $mie[$i]["segno"] !== "V"; $i--) $senzaV++;
        for ($i = count($mie) - 1; $i >= 0 && $mie[$i]["segno"] !== "P"; $i--) $senzaP++;
        if ($senzaV >= 4 && $serie < 3) $t .= "; non vince da " . $senzaV . " giornate";
        if ($senzaP >= 5 && $segnoSerie !== "P") $t .= "; imbattuta da " . $senzaP . " giornate";

        $media = array_sum(array_map(fn($r) => (float) $r["ftotale"], $mie)) / count($mie);
        $t .= "; punteggio medio " . arn_n($media, 1);
        if ($oggi) {
            $t .= ", oggi " . arn_n($oggi["ftotale"], 1);
            if (isset($recordLega[$id])) $t .= " = " . $recordLega[$id];
        }
        $linee[] = $t;
    }
    return ["testo" => implode("\n", $linee), "squadre" => count($linee)];
}

// ------------------------------------------------------------
// SCONTRI DIRETTI
// ------------------------------------------------------------

/**
 * Storico degli incontri tra due squadre (campionato + Champions, stagione
 * corrente fino alla giornata precedente e stagioni passate). L'identità
 * dell'allenatore si ricava da NEW_UTENZE.utenza, così il confronto vale
 * anche se gli id squadra cambiano da una stagione all'altra.
 */
function arn_scontri($conn, int $stagione, int $giornata, int $idA, int $idB, array $sq): array
{
    $uA = mb_strtolower(trim((string) ($sq[$idA]["utenza"] ?? "")));
    $uB = mb_strtolower(trim((string) ($sq[$idB]["utenza"] ?? "")));
    if ($uA === "" || $uB === "" || $uA === $uB) return [];
    $ea = mysqli_real_escape_string($conn, $uA);
    $eb = mysqli_real_escape_string($conn, $uB);
    $filtroTempo = "(r.stagione < $stagione OR (r.stagione = $stagione AND r.giornata < $giornata))";

    $incontri = [];
    $q = function (string $tab, string $col, string $comp) use (&$incontri, $ea, $eb, $filtroTempo) {
        foreach (query_all("SELECT r.stagione, r.giornata, r.ftotale, r.ftotale_a, r.golf, r.gols
                            FROM $tab r
                            JOIN NEW_UTENZE ua ON ua.id = r.$col AND ua.stagione = r.stagione
                            JOIN NEW_UTENZE ub ON ub.id = r.id_squadra_a AND ub.stagione = r.stagione
                            WHERE LOWER(ua.utenza) = '$ea' AND LOWER(ub.utenza) = '$eb'
                              AND (r.ftotale > 0 OR r.ftotale_a > 0) AND $filtroTempo") as $r) {
            $incontri[] = [
                "stagione" => (int) $r["stagione"], "giornata" => (int) $r["giornata"], "comp" => $comp,
                "ga" => (int) $r["golf"], "gb" => (int) $r["gols"],
                "pa" => (float) $r["ftotale"], "pb" => (float) $r["ftotale_a"],
            ];
        }
    };
    $q("NEW_RISULTATI", "id_squadra", "campionato");
    if (art_tabella_presente($conn, "NEW_RISULTATI_CHAMP")) $q("NEW_RISULTATI_CHAMP", "squadra", "champions");
    if (!$incontri) return [];

    usort($incontri, fn($x, $y) => [$y["stagione"], $y["giornata"], $y["comp"]] <=> [$x["stagione"], $x["giornata"], $x["comp"]]);
    $v = $n = $p = $gfa = $gfb = 0;
    foreach ($incontri as $i) {
        if ($i["ga"] > $i["gb"]) $v++; elseif ($i["ga"] === $i["gb"]) $n++; else $p++;
        $gfa += $i["ga"]; $gfb += $i["gb"];
    }
    // serie aperta guardando dal più recente
    $serie = ""; $k = 0; $segno = null;
    foreach ($incontri as $i) {
        $s = $i["ga"] > $i["gb"] ? "V" : ($i["ga"] === $i["gb"] ? "N" : "P");
        if ($segno === null) $segno = $s;
        if ($s !== $segno) break;
        $k++;
    }
    if ($k >= 2) {
        $serie = $segno === "V" ? "A ha vinto gli ultimi $k incontri" : ($segno === "P" ? "B ha vinto gli ultimi $k incontri" : "gli ultimi $k incontri sono finiti in pareggio");
    }
    return [
        "totale" => count($incontri), "v" => $v, "n" => $n, "p" => $p, "gol_a" => $gfa, "gol_b" => $gfb,
        "serie" => $serie, "ultimi" => array_slice($incontri, 0, max(1, (int) art_cfg("scontri_diretti_ultimi", 3))),
    ];
}

/** Testo dello storico per la scheda (A = prima squadra, B = seconda). */
function arn_testo_scontri(array $s, string $nomeA, string $nomeB): string
{
    if (!$s) return "primo incontro registrato tra i due allenatori (nessuno storico)";
    $t = "storico (" . $s["totale"] . ($s["totale"] === 1 ? " incontro" : " incontri") . " tra campionato e Champions): $nomeA " . $s["v"] . "V " . $s["n"] . "N " . $s["p"] . "P contro $nomeB, gol "
       . $s["gol_a"] . "-" . $s["gol_b"];
    if ($s["serie"] !== "") $t .= "; " . str_replace(["A ha", "B ha"], [$nomeA . " ha", $nomeB . " ha"], $s["serie"]);
    $ult = [];
    foreach ($s["ultimi"] as $i) {
        $ult[] = $i["stagione"] . " g" . $i["giornata"] . " " . ($i["comp"] === "champions" ? "Champions" : "campionato") . " "
               . $i["ga"] . "-" . $i["gb"] . " (punti " . arn_n($i["pa"], 1) . "-" . arn_n($i["pb"], 1) . ")";
    }
    return $t . "; ultimi: " . implode(" · ", $ult) . " [risultati dal punto di vista di $nomeA]";
}

// ------------------------------------------------------------
// BONUS / MALUS E FORTUNA DEI GIOCATORI
// ------------------------------------------------------------

/** Bonus e malus del giocatore in forma testuale. */
function arn_bonus_testo(array $p): string
{
    $b = [];
    if ($p["reti"] > 0)    $b[] = $p["reti"] > 1 ? $p["reti"] . " gol" : "gol";
    if ($p["assist"] > 0)  $b[] = $p["assist"] > 1 ? $p["assist"] . " assist" : "assist";
    if ($p["rig_par"] > 0) $b[] = "rigore parato";
    if ($p["rig_seg"] > 0) $b[] = "rigore segnato";
    if ($p["rig_sba"] > 0) $b[] = "rigore sbagliato";
    if ($p["autogol"] > 0) $b[] = "autogol";
    if ($p["esp"] > 0)     $b[] = "espulso";
    elseif ($p["amm"] > 0) $b[] = "ammonito";
    return implode(", ", $b);
}

/** Esito di un subentro: positivo / negativo / neutro. */
function arn_esito_subentro(array $p): string
{
    if ($p["reti"] > 0 || $p["assist"] > 0 || $p["rig_par"] > 0 || $p["rig_seg"] > 0 || $p["totale"] >= 7.5) return "positivo";
    if ($p["autogol"] > 0 || $p["esp"] > 0 || $p["rig_sba"] > 0 || $p["totale"] <= 4.5) return "negativo";
    return "neutro";
}

/** Descrizione sintetica di un giocatore: Nome (Squadra, R, big 45cr). */
function arn_chi(array $p, string $squadra, bool $conCrediti = true): string
{
    $t = $p["nome"] . " (" . $squadra . ", " . (ARN_RUOLI[$p["ruolo"]] ?? "?");
    if ($conCrediti && $p["crediti"] > 0 && ($p["big"] || $p["crediti"] >= 15)) $t .= ", " . ($p["big"] ? "big " : "") . $p["crediti"] . " crediti";
    return $t . ")";
}

// ------------------------------------------------------------
// SCHEDA DI UNA PARTITA
// ------------------------------------------------------------

/**
 * Scheda di una partita (campionato o Champions): sequenza in ordine di
 * calendario di Serie A e fatti di fortuna/sfortuna. $dett: 2 completa,
 * 1 compatta, 0 senza sequenza. Ritorna ["testo", "fatti" (lista per il
 * riepilogo), "n_slot", "n_subentri", "n_big_flop", "n_assenti"].
 */
function arn_scheda($conn, array $inc, string $etichetta, array $ctx, int $dett): array
{
    $stagione = $ctx["stagione"]; $giornata = $ctx["giornata"];
    $idA = (int) $inc["id_a"]; $idB = (int) $inc["id_b"];
    $champ = $inc["comp"] === "champions";
    $nomeA = $ctx["sq"][$idA]["nome"] ?? "Squadra $idA";
    $nomeB = $ctx["sq"][$idB]["nome"] ?? "Squadra $idB";
    $nomi = [$idA => $nomeA, $idB => $nomeB];
    $info = $ctx["info"];

    $tVoti = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
    $tForm = $champ ? formazione_champions_sql_effettiva($stagione, $giornata, [$idA, $idB]) : "NEW_FORMAZIONI";

    $raw = query_all("SELECT v.id_squadra, v.id_giocatore,
            COALESCE(g.descrizione, 'Riserva d''ufficio') AS giocatore,
            COALESCE(g.ruolo, FLOOR(ABS(v.id_giocatore) / 1000000)) AS ruolo,
            f.MAGLIA AS maglia, v.rufficio, (g.id IS NULL) AS ufficio_fittizio,
            v.voto, v.totale, v.giocata, v.reti, v.ammonizioni, v.espulsioni, v.autogol,
            v.rigores, v.rigorep, v.assist
        FROM $tVoti v
        LEFT JOIN " . rs_sql_anagrafica($stagione) . " g ON g.id = v.id_giocatore AND g.stagione = v.stagione
        LEFT JOIN $tForm f ON f.ID_GIOCATORE = v.id_giocatore AND f.ID_SQUADRA = v.id_squadra
                          AND f.STAGIONE = v.stagione AND f.GIORNATA = v.giornata
        WHERE v.stagione = $stagione AND v.giornata = $giornata AND v.id_squadra IN ($idA, $idB)");

    $form = pd_carica_formazione($stagione, $giornata, $tForm, [$idA, $idB]);

    // Voti per squadra, nel formato richiesto da pd_costruisci_panchina
    $voti = [$idA => [], $idB => []];
    foreach ($raw as $v) {
        $v["ruolo"]            = (int) $v["ruolo"];
        $v["id_giocatore"]     = (int) $v["id_giocatore"];
        $v["maglia"]           = $v["maglia"] === null ? null : (int) $v["maglia"];
        $v["riserva_ufficio"]  = (int) $v["rufficio"] === 1;
        $v["ufficio_fittizio"] = (int) $v["ufficio_fittizio"] === 1;
        $v["subentrato"]       = (int) ($v["maglia"] ?? 0) > 11 && (int) $v["giocata"] === 1 && !$v["riserva_ufficio"];
        $voti[(int) $v["id_squadra"]][] = $v;
    }
    $panchina = [];
    foreach ([$idA, $idB] as $id) {
        $panchina[$id] = pd_costruisci_panchina($form[$id] ?? [], $voti[$id]);
    }

    // Record dei giocatori che contano per il punteggio
    $giocatori = [$idA => [], $idB => []];
    $perId = [$idA => [], $idB => []];
    foreach ([$idA, $idB] as $id) {
        foreach ($voti[$id] as $v) {
            $gid = (int) $v["id_giocatore"];
            $sa  = $ctx["vsa"][$gid]["squadra"] ?? ($info["serie_a"][$gid] ?? "");
            $slot = ($sa !== "" && $sa !== null) ? ($ctx["slots"][arn_up($sa)] ?? null) : null;
            $p = [
                "id" => $gid, "nome" => trim((string) $v["giocatore"]), "ruolo" => $v["ruolo"],
                "maglia" => (int) ($v["maglia"] ?? 0), "totale" => (float) $v["totale"], "voto" => (float) $v["voto"],
                "giocata" => (int) $v["giocata"] === 1, "ufficio" => $v["riserva_ufficio"],
                "reti" => (int) $v["reti"], "assist" => (int) $v["assist"], "amm" => (int) $v["ammonizioni"],
                "esp" => (int) $v["espulsioni"], "autogol" => (int) $v["autogol"],
                "rig_par" => ((int) $v["rigores"] > 0 && $v["ruolo"] === 1) ? 1 : 0,
                "rig_seg" => ((int) $v["rigores"] > 0 && $v["ruolo"] !== 1) ? 1 : 0,
                "rig_sba" => (int) $v["rigorep"],
                "crediti" => (int) ($info["crediti"][$gid] ?? 0),
                "big" => $gid > 0 ? arn_big($info, $gid, $v["ruolo"]) : false,
                "serie_a" => $sa, "slot" => $slot,
                "subentrato" => (bool) $v["subentrato"], "sostituisce" => $v["sostituisce"] ?? null,
                "in_panchina" => !empty($v["in_panchina"]), "fittizio" => $v["ufficio_fittizio"],
            ];
            $giocatori[$id][] = $p;
            $perId[$id][$gid] = count($giocatori[$id]) - 1;
        }
    }

    // Titolari rimasti senza voto utile (non scesi in campo / entrati senza voto) e chi li ha sostituiti
    $sostituitoDa = [];
    $titolariSenzaVoto = [$idA => [], $idB => []];
    foreach ([$idA, $idB] as $id) {
        foreach ($panchina[$id] as $pc) {
            if ($pc["stato"] === "sostituito") $sostituitoDa[$id][$pc["id_giocatore"]] = $pc["sostituito_da"] ?? null;
        }
        foreach (($form[$id] ?? []) as $f) {
            if ($f["maglia"] < 1 || $f["maglia"] > 11) continue;
            $i = $perId[$id][$f["id_giocatore"]] ?? null;
            $haVoto = $i !== null && $giocatori[$id][$i]["giocata"];
            if ($haVoto) continue;
            $gid = $f["id_giocatore"];
            $sa  = $ctx["vsa"][$gid]["squadra"] ?? ($info["serie_a"][$gid] ?? "");
            $titolariSenzaVoto[$id][] = [
                "id" => $gid, "nome" => trim((string) $f["giocatore"]), "ruolo" => (int) $f["ruolo"],
                "crediti" => (int) ($info["crediti"][$gid] ?? 0), "big" => arn_big($info, $gid, (int) $f["ruolo"]),
                "sostituito_da" => $sostituitoDa[$id][$gid] ?? null,
                "stato_sa" => isset($ctx["vsa"][$gid]) ? "entrato senza voto" : "non è sceso in campo",
                "serie_a" => $sa,
            ];
        }
    }

    // Assenze illustri: giocatori costosi in rosa, non convocati, con la loro giornata in Serie A
    $assenti = [$idA => [], $idB => []];
    $maxAss = max(0, (int) art_cfg("assenti_illustri_max", 2));
    if ($maxAss > 0) {
        foreach ([$idA, $idB] as $id) {
            $convocati = [];
            foreach (($form[$id] ?? []) as $f) $convocati[(int) $f["id_giocatore"]] = true;
            $cand = [];
            foreach (rs_rosa_squadra($stagione, $id) as $gid => $periodi) {
                if (isset($convocati[$gid]) || !rs_in_rosa($periodi, $giornata)) continue;
                $ruolo = (int) ($info["ruolo"][$gid] ?? 0);
                if (!arn_big($info, (int) $gid, $ruolo)) continue;
                $cand[$gid] = ["id" => (int) $gid, "ruolo" => $ruolo, "crediti" => (int) $info["crediti"][$gid]];
            }
            if ($cand) {
                $nomiG = arn_nomi_giocatori($stagione, array_keys($cand));
                foreach ($cand as $gid => &$c) {
                    $c["nome"] = $nomiG[$gid]["nome"] ?? ("giocatore $gid");
                    $v = $ctx["vsa"][$gid] ?? null;
                    $c["ha_giocato"] = $v !== null;
                    $c["rimpianto"] = $v !== null && ((int) $v["gf"] > 0 || (int) $v["ass"] > 0 || (int) $v["rp"] > 0
                                      || ($v["voto"] !== null && (float) $v["voto"] >= 7));
                    $c["voto_sa"] = $v !== null && $v["voto"] !== null ? (float) $v["voto"] : null;
                    $c["bonus"] = $v !== null ? arn_bonus_testo([
                        "reti" => (int) $v["gf"], "assist" => (int) $v["ass"], "rig_par" => (int) $v["rp"], "rig_seg" => (int) $v["rf"],
                        "rig_sba" => (int) $v["rs"], "autogol" => (int) $v["au"], "esp" => (int) $v["esp"], "amm" => (int) $v["amm"],
                    ]) : "";
                }
                unset($c);
                // prima i rimpianti (hanno giocato e prodotto), poi gli altri per crediti
                uasort($cand, fn($x, $y) => [(int) $y["rimpianto"], $y["crediti"], $x["id"]] <=> [(int) $x["rimpianto"], $x["crediti"], $y["id"]]);
                $assenti[$id] = array_slice(array_values($cand), 0, $maxAss);
            }
        }
    }

    // Serie di assenze e minuti giocati (solo per i casi segnalati)
    $idsAssenza = [];
    foreach ([$idA, $idB] as $id) {
        foreach ($titolariSenzaVoto[$id] as $t) if ($t["big"]) $idsAssenza[] = $t["id"];
        foreach ($assenti[$id] as $c) if (!$c["ha_giocato"]) $idsAssenza[] = $c["id"];
    }
    $serieAss = arn_serie_assenze($stagione, $giornata, $idsAssenza);
    $idsMinuti = [];
    foreach ([$idA, $idB] as $id) {
        foreach ($giocatori[$id] as $p) if ($p["subentrato"] || ($p["big"] && $p["giocata"] && !$p["ufficio"])) $idsMinuti[] = $p["id"];
        foreach ($titolariSenzaVoto[$id] as $t) $idsMinuti[] = $t["id"];
    }
    $minuti = arn_minuti_api($conn, $stagione, $ctx["g_serie_a"], $idsMinuti);

    // ---- SEQUENZA in ordine di calendario di Serie A ----
    $fatti = [];
    $slotsOrd = [];   // key => ["label","partita","a"=>[eventi],"b"=>[eventi],"ta"=>tot,"tb"=>tot,"impatto"=>x]
    $sommaA = $sommaB = 0.0;
    foreach ([$idA, $idB] as $id) {
        foreach ($giocatori[$id] as $p) {
            if (!$p["giocata"] && !$p["ufficio"]) continue;                   // titolare sostituito / a zero
            $slot = $p["slot"];
            $k = $slot["key"] ?? "9999-99-99 99:99 999";
            if (!isset($slotsOrd[$k])) {
                $slotsOrd[$k] = ["label" => $slot["label"] ?? "senza partita di Serie A nota (riserve d'ufficio e altri)",
                                 "partita" => $slot["partita"] ?? "", "ev" => [$idA => [], $idB => []],
                                 "tot" => [$idA => 0.0, $idB => 0.0], "impatto" => 0.0];
            }
            $slotsOrd[$k]["tot"][$id] += $p["totale"];
            $slotsOrd[$k]["impatto"]  += abs($p["totale"] - 6);
            if ($id === $idA) $sommaA += $p["totale"]; else $sommaB += $p["totale"];
            $slotsOrd[$k]["ev"][$id][] = $p;
        }
    }
    ksort($slotsOrd);
    $modA = (float) $inc["fa"] - $sommaA;
    $modB = (float) $inc["fb"] - $sommaB;

    // segno del vantaggio dopo ogni slot e sorpassi
    $cumA = $cumB = 0.0; $segnoPrec = 0; $sorpassi = 0; $ultimoSorpasso = null;
    $righeSeq = [];
    foreach ($slotsOrd as $k => &$s) {
        $cumA += $s["tot"][$idA]; $cumB += $s["tot"][$idB];
        $diff = round($cumA - $cumB, 2);
        $segno = $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
        $s["cumA"] = $cumA; $s["cumB"] = $cumB; $s["diff"] = $diff;
        $s["sorpasso"] = $segnoPrec !== 0 && $segno !== 0 && $segno !== $segnoPrec;
        if ($s["sorpasso"]) { $sorpassi++; $ultimoSorpasso = $s["label"] . " " . $s["partita"]; }
        if ($segno !== 0) $segnoPrec = $segno;
    }
    unset($s);

    // limite di slot mostrati: tengo i più "ricchi" (e sempre primo, ultimo e quelli con sorpasso)
    $maxSlot = $dett >= 2 ? max(3, (int) art_cfg("sequenza_max_slot", 8)) : max(2, (int) art_cfg("sequenza_max_slot_compatta", 4));
    $chiavi = array_keys($slotsOrd);
    if (count($chiavi) > $maxSlot) {
        $obbl = [$chiavi[0] => true, $chiavi[count($chiavi) - 1] => true];
        foreach ($slotsOrd as $k => $s) if ($s["sorpasso"]) $obbl[$k] = true;
        $altri = array_diff($chiavi, array_keys($obbl));
        usort($altri, fn($x, $y) => $slotsOrd[$y]["impatto"] <=> $slotsOrd[$x]["impatto"]);
        $tieni = $obbl + array_flip(array_slice($altri, 0, max(0, $maxSlot - count($obbl))));
        $slotsOrd = array_intersect_key($slotsOrd, $tieni);
    }

    $maxEv = $dett >= 2 ? 3 : 2;
    foreach ($slotsOrd as $s) {
        $ev = [];
        foreach ([$idA, $idB] as $id) {
            $lista = $s["ev"][$id];
            usort($lista, function ($x, $y) {
                // prima i fatti notevoli (subentri, big, bonus), poi per distanza dalla sufficienza
                $px = ($x["subentrato"] ? 3 : 0) + ($x["big"] ? 1 : 0) + abs($x["totale"] - 6);
                $py = ($y["subentrato"] ? 3 : 0) + ($y["big"] ? 1 : 0) + abs($y["totale"] - 6);
                return $py <=> $px;
            });
            foreach (array_slice($lista, 0, $maxEv) as $p) {
                if (abs($p["totale"] - 6) < 1 && !$p["subentrato"] && !$p["big"] && arn_bonus_testo($p) === "") continue;
                $t = $p["nome"] . " (" . $nomi[$id] . ($p["ufficio"] ? ", riserva d'ufficio" : "") . ($p["big"] ? ", big " . $p["crediti"] . " cr" : "") . ") " . arn_n($p["totale"]);
                $b = arn_bonus_testo($p);
                if ($p["subentrato"]) $b = "subentrato" . ($p["sostituisce"] ? " a " . $p["sostituisce"] : "") . ($b !== "" ? ", " . $b : "");
                if ($b !== "") $t .= " [" . $b . "]";
                $ev[] = $t;
            }
        }
        $riga = "  - " . $s["label"] . ($s["partita"] !== "" ? " " . $s["partita"] : "") . " → parziale " . $nomeA . " " . arn_n($s["cumA"], 1)
              . " - " . $nomeB . " " . arn_n($s["cumB"], 1)
              . ($s["diff"] != 0 ? " (avanti " . ($s["diff"] > 0 ? $nomeA : $nomeB) . " di " . arn_n(abs($s["diff"]), 1) . ")" : " (parità)")
              . ($s["sorpasso"] ? " ★SORPASSO" : "");
        if ($ev) $riga .= " | " . implode("; ", $ev);
        $righeSeq[] = $riga;
    }

    // ---- fatti di fortuna / sfortuna ----
    $soglFlop = (float) art_cfg("soglia_big_flop", 5.5);
    $soglTop  = (float) art_cfg("soglia_big_top", 8.5);
    $righeFatti = [];
    $nSub = $nFlop = $nAss = 0;
    foreach ([$idA, $idB] as $id) {
        foreach ($giocatori[$id] as $p) {
            $m = $minuti[$p["id"]] ?? null;
            if ($p["subentrato"]) {
                $esito = arn_esito_subentro($p);
                if ($esito === "neutro") continue;
                $nSub++;
                $t = ($esito === "positivo" ? "+ COLPO DI FORTUNA" : "- COLPO DI SFORTUNA") . " (subentro): " . arn_chi($p, $nomi[$id])
                   . " entra dalla panchina" . ($p["sostituisce"] ? " al posto di " . $p["sostituisce"] : "")
                   . ", fantavoto " . arn_n($p["totale"]);
                $b = arn_bonus_testo($p); if ($b !== "") $t .= " [" . $b . "]";
                if ($m && $m["minuti"] > 0 && $m["minuti"] <= 35) $t .= ", in campo solo " . $m["minuti"] . " minuti";
                $righeFatti[] = "  " . $t;
                $fatti[] = ["tipo" => $esito === "positivo" ? "fortuna" : "sfortuna", "peso" => abs($p["totale"] - 6), "testo" => $t . " — $etichetta"];
                continue;
            }
            if ($p["ufficio"] || !$p["giocata"] || $p["maglia"] < 1 || $p["maglia"] > 11 || !$p["big"]) continue;
            if ($p["totale"] <= $soglFlop || $p["esp"] > 0 || $p["autogol"] > 0) {
                $nFlop++;
                $t = "- BIG CHE NON RENDE: " . arn_chi($p, $nomi[$id]) . " fantavoto " . arn_n($p["totale"]);
                $b = arn_bonus_testo($p); if ($b !== "") $t .= " [" . $b . "]";
                if ($m && $m["titolare"] && $m["minuti"] > 0 && $m["minuti"] < 70 && $p["esp"] === 0) $t .= ", uscito dopo " . $m["minuti"] . " minuti";
                $righeFatti[] = "  " . $t;
                $fatti[] = ["tipo" => "big_flop", "peso" => 6 - $p["totale"], "testo" => $t . " — $etichetta"];
            } elseif ($p["totale"] >= $soglTop) {
                $t = "+ BIG CHE RENDE: " . arn_chi($p, $nomi[$id]) . " fantavoto " . arn_n($p["totale"]);
                $b = arn_bonus_testo($p); if ($b !== "") $t .= " [" . $b . "]";
                $righeFatti[] = "  " . $t;
                $fatti[] = ["tipo" => "big_top", "peso" => $p["totale"] - 6, "testo" => $t . " — $etichetta"];
            }
        }
        foreach ($titolariSenzaVoto[$id] as $t0) {
            $m = $minuti[$t0["id"]] ?? null;
            $uscitoPresto = $m && $m["titolare"] && $m["minuti"] > 0 && $m["minuti"] < 70;
            if (!$t0["big"] && !$uscitoPresto) continue;
            $nFlop++;
            $ass = $serieAss[$t0["id"]] ?? 0;
            $t = "- TITOLARE" . ($t0["big"] ? " BIG" : "") . " SENZA VOTO: " . $t0["nome"] . " (" . $nomi[$id] . ", " . (ARN_RUOLI[$t0["ruolo"]] ?? "?")
               . ($t0["crediti"] > 0 ? ", " . $t0["crediti"] . " crediti" : "") . ") " . $t0["stato_sa"]
               . ($t0["serie_a"] !== "" ? " [Serie A: " . $t0["serie_a"] . "]" : "");
            if ($uscitoPresto) $t .= ", uscito dopo " . $m["minuti"] . " minuti";
            if ($ass >= 1) $t .= "; " . arn_giornate_di_fila($ass + 1) . " senza giocare";
            $t .= $t0["sostituito_da"] ? "; sostituito da " . $t0["sostituito_da"] : "; nessun sostituto utile";
            $t .= " (causa non indicata nei dati: non attribuire un infortunio se non è nelle note)";
            $righeFatti[] = "  " . $t;
            $fatti[] = ["tipo" => "assenza", "peso" => 3 + $t0["crediti"] / 50, "testo" => $t . " — $etichetta"];
        }
        foreach ($assenti[$id] as $c) {
            $nAss++;
            $ass = $serieAss[$c["id"]] ?? 0;
            $t = "- ASSENTE ILLUSTRE NON SCHIERATO: " . $c["nome"] . " (" . $nomi[$id] . ", " . (ARN_RUOLI[$c["ruolo"]] ?? "?") . ", " . $c["crediti"] . " crediti, big) ";
            if ($c["ha_giocato"]) {
                $t .= "in Serie A " . ($c["voto_sa"] !== null ? "ha giocato (voto " . arn_n($c["voto_sa"], 1) . ")" : "è entrato senza voto")
                    . ($c["bonus"] !== "" ? " con " . $c["bonus"] : "") . ($c["rimpianto"] ? ": RIMPIANTO per chi lo ha lasciato fuori" : "");
            } else {
                $t .= "non è sceso in campo neppure in Serie A" . ($ass >= 1 ? " (" . arn_giornate_di_fila($ass + 1) . ")" : "");
            }
            $righeFatti[] = "  " . $t;
            $fatti[] = ["tipo" => $c["rimpianto"] ? "rimpianto" : "assenza", "peso" => ($c["rimpianto"] ? 4 : 1) + $c["crediti"] / 50, "testo" => $t . " — $etichetta"];
        }
    }

    // ---- testo della scheda ----
    $intest = $etichetta . ($champ ? " (Champions" . (!empty($inc["girone"]) ? ", girone " . $inc["girone"] : "") . ")" : " (campionato)")
            . ": " . $nomeA . " [" . ($ctx["sq"][$idA]["allenatore"] ?? "") . "] - " . $nomeB . " [" . ($ctx["sq"][$idB]["allenatore"] ?? "") . "]"
            . " | finale " . arn_n($inc["fa"], 1) . " - " . arn_n($inc["fb"], 1) . ", gol " . (int) $inc["ga"] . "-" . (int) $inc["gb"];
    $t = $intest . "\n";
    $sc = arn_scontri($conn, $stagione, $giornata, $idA, $idB, $ctx["sq"]);
    $t .= "  Scontri diretti: " . arn_testo_scontri($sc, $nomeA, $nomeB) . "\n";
    foreach ($ctx["collegati"][$etichetta] ?? [] as $riga) $t .= "  " . $riga . "\n";
    $crA = $crB = 0;
    foreach (($form[$idA] ?? []) as $f) if ($f["maglia"] >= 1 && $f["maglia"] <= 11) $crA += (int) ($info["crediti"][$f["id_giocatore"]] ?? 0);
    foreach (($form[$idB] ?? []) as $f) if ($f["maglia"] >= 1 && $f["maglia"] <= 11) $crB += (int) ($info["crediti"][$f["id_giocatore"]] ?? 0);
    if ($crA > 0 || $crB > 0) $t .= "  Crediti spesi per gli 11 titolari: $nomeA $crA, $nomeB $crB (rendimento: " . arn_n($inc["fa"], 1) . " e " . arn_n($inc["fb"], 1) . " punti)\n";
    if ($dett >= 1 && $righeSeq) {
        $t .= "  Sequenza in ordine di calendario di Serie A (parziali = somma dei fantavoti dei giocatori; i modificatori di squadra non sono inclusi):\n";
        $t .= implode("\n", $righeSeq) . "\n";
        $t .= "  Fine: modificatori di squadra $nomeA " . arn_n($modA, 1) . ", $nomeB " . arn_n($modB, 1) . " → finale " . arn_n($inc["fa"], 1) . " - " . arn_n($inc["fb"], 1);
        $t .= $sorpassi > 0 ? "; sorpassi durante la giornata: $sorpassi (ultimo: $ultimoSorpasso)\n" : "; nessun sorpasso: sempre davanti la stessa squadra\n";
    }
    if ($righeFatti) $t .= "  Fortuna e sfortuna:\n" . implode("\n", $righeFatti) . "\n";

    return ["testo" => rtrim($t), "fatti" => $fatti, "n_slot" => count($slotsOrd), "n_subentri" => $nSub,
            "n_big_flop" => $nFlop, "n_assenti" => $nAss, "storico" => $sc ? 1 : 0, "sorpassi" => $sorpassi];
}

// ------------------------------------------------------------
// PROFILI E EPISODI COLLEGATI ALLE PARTITE
// ------------------------------------------------------------

/** Frammenti di un campo libero (tormentoni): separati da a capo, ";" o "|". */
function arn_frammenti(string $testo): array
{
    $out = [];
    foreach (preg_split('/\R+|;|\|/u', $testo) as $f) {
        $f = trim($f);
        if (mb_strlen($f) >= 3) $out[] = $f;
    }
    return $out;
}

/** Il testo cita (per nome) un altro partecipante tra $altri (liste di nomi)? */
function arn_cita_nomi(string $testo, array $nomi): bool
{
    $t = " " . arn_nt($testo) . " ";
    foreach ($nomi as $n) {
        $n = arn_nt((string) $n);
        if (strlen($n) >= 3 && strpos($t, " " . $n . " ") !== false) return true;
    }
    return false;
}

/**
 * Righe di profilo (compatte, con tormentoni ruotati) e collegamenti per
 * partita: rivalità/note che citano l'avversario diretto.
 * Ritorna ["righe" => [], "senza_profilo" => [], "collegati" => [etichetta => [righe]], "tormentoni" => n].
 */
function arn_profili($conn, array $partecipanti, array $incontri, array $etichette, string $seed, string $memoriaNorm): array
{
    $utenze = array_map(fn($p) => $p["utenza"], $partecipanti);
    $profili = art_profili($conn, $utenze);
    $nTorm = max(0, (int) art_cfg("tormentoni_per_profilo", 2));
    $maxCar = max(80, (int) art_cfg("profilo_carattere_max", 240));

    // nomi con cui un partecipante può essere citato
    $alias = [];
    foreach ($partecipanti as $p) {
        $alias[(int) $p["id"]] = array_filter([$p["allenatore"], $p["utenza"], $p["nome"]]);
    }
    $righe = [];
    $senza = [];
    $nScelti = 0;
    foreach ($partecipanti as $sq) {
        $p = $profili[mb_strtolower($sq["utenza"])] ?? null;
        if (!$p) { $senza[] = $sq["allenatore"]; continue; }
        $parti = [];
        if (($p["soprannome"] ?? "") !== "")    $parti[] = "soprannome «" . art_pulisci($p["soprannome"], 100) . "»";
        if (($p["carattere"] ?? "") !== "")     $parti[] = "carattere: " . art_pulisci($p["carattere"], $maxCar);
        if (($p["squadra_cuore"] ?? "") !== "") $parti[] = "squadra del cuore: " . art_pulisci($p["squadra_cuore"], 100);
        if (($p["tormentoni"] ?? "") !== "" && $nTorm > 0) {
            $fr = arn_frammenti((string) $p["tormentoni"]);
            usort($fr, fn($a, $b) => [(int) arn_citato($a, $memoriaNorm), arn_rot($seed . $sq["utenza"], $a)]
                                 <=> [(int) arn_citato($b, $memoriaNorm), arn_rot($seed . $sq["utenza"], $b)]);
            $scelti = array_slice($fr, 0, $nTorm);
            $nScelti += count($scelti);
            $parti[] = "tormentoni (scelta a rotazione, ne basta UNO e solo se calza): " . art_pulisci(implode(" / ", $scelti), 400);
        }
        // rivalità e note solo se chiamano in causa un'altra squadra in gioco oggi
        $altri = [];
        foreach ($partecipanti as $o) if ($o["id"] !== $sq["id"]) $altri = array_merge($altri, $alias[(int) $o["id"]]);
        foreach (["rivalita" => "rivalità", "note" => "note"] as $campo => $etich) {
            $v = trim((string) ($p[$campo] ?? ""));
            if ($v !== "" && arn_cita_nomi($v, $altri)) $parti[] = $etich . " (riguarda chi gioca oggi): " . art_pulisci($v, 300);
        }
        $righe[] = "- " . $sq["allenatore"] . " (squadra «" . $sq["nome"] . "»): " . implode("; ", $parti);
    }

    // collegamenti per partita: rivalità/note che citano l'avversario diretto
    $collegati = [];
    foreach ($incontri as $i => $inc) {
        $a = (int) $inc["id_a"]; $b = (int) $inc["id_b"];
        $et = $etichette[$i];
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            $sq = null;
            foreach ($partecipanti as $pp) if ((int) $pp["id"] === $x) $sq = $pp;
            $p = $sq ? ($profili[mb_strtolower($sq["utenza"])] ?? null) : null;
            if (!$p) continue;
            foreach (["rivalita" => "rivalità", "note" => "nota"] as $campo => $etich) {
                $v = trim((string) ($p[$campo] ?? ""));
                if ($v !== "" && arn_cita_nomi($v, $alias[$y] ?? [])) {
                    $collegati[$et][] = "Collegamento concreto (" . $etich . " di " . $sq["allenatore"] . " che cita l'avversario): " . art_pulisci($v, 260);
                }
            }
        }
    }
    return ["righe" => $righe, "senza_profilo" => $senza, "collegati" => $collegati, "tormentoni" => $nScelti];
}

/**
 * Episodi storici pertinenti alla giornata: ogni episodio è associato alla
 * partita in cui sono coinvolti più allenatori; si richiamano per primi quelli
 * che riguardano ENTRAMBI gli allenatori di una partita, poi quelli che
 * riguardano uno solo di chi gioca; gli episodi generici (nessun allenatore)
 * vanno solo in coda e al massimo uno. Esclusi gli episodi usati nelle ultime
 * puntate (pausa); quelli richiamati di recente nel testo scalano in fondo.
 * Ordine deterministico (rotazione crc32).
 */
function arn_episodi($conn, array $partecipanti, array $incontri, array $etichette, array $sq, array $pausa, string $memoriaNorm, int $max, string $seed): array
{
    if ($max <= 0) return [];
    $utenzaDi = [];
    foreach ($partecipanti as $p) $utenzaDi[(int) $p["id"]] = mb_strtolower(trim((string) $p["utenza"]));
    $gioco = array_flip(array_values($utenzaDi));
    $consentiGenerici = (bool) art_cfg("episodi_generici", true);

    $cand = [];
    foreach (query_all("SELECT id, data_episodio, titolo, descrizione, tag, utenze, ultimo_utilizzo
                        FROM NEW_EPISODI_LEGA WHERE attivo = 'Y'") as $e) {
        if (isset($pausa[(int) $e["id"]])) continue;                                   // usato nelle ultime puntate
        $lista = array_values(array_filter(array_map(fn($u) => mb_strtolower(trim($u)), explode(",", (string) $e["utenze"]))));
        $coinvolti = count(array_filter($lista, fn($u) => isset($gioco[$u])));
        if ($lista && $coinvolti === 0) continue;                                      // riguarda solo chi non gioca
        if (!$lista && !$consentiGenerici) continue;

        // partita con il massimo di allenatori dell'episodio
        $best = 0; $dove = "";
        foreach ($incontri as $i => $inc) {
            $n = 0;
            foreach ([(int) $inc["id_a"], (int) $inc["id_b"]] as $id) if ($lista && in_array($utenzaDi[$id] ?? "", $lista, true)) $n++;
            if ($n > $best) {
                $best = $n;
                $dove = $etichette[$i] . ": " . ($sq[(int) $inc["id_a"]]["nome"] ?? "") . " - " . ($sq[(int) $inc["id_b"]]["nome"] ?? "");
            }
        }
        if (!$lista)                          { $score = 0.3; $come = "nessun collegamento specifico: usalo solo se i fatti lo richiamano davvero"; }
        elseif ($best >= 2)                   { $score = 3.0; $come = "riguarda ENTRAMBI gli allenatori di $dove"; }
        elseif ($best === 1 && count($lista) === 1) { $score = 1.5; $come = "riguarda uno degli allenatori di $dove"; }
        elseif ($best === 1)                  { $score = 1.0; $come = "riguarda più allenatori, uno solo gioca in $dove"; }
        else                                  { $score = 0.8; $come = "riguarda allenatori che giocano oggi in partite diverse"; }

        $e["_score"] = $score; $e["_come"] = $come;
        $e["_citato"] = arn_citato((string) $e["titolo"], $memoriaNorm) ? 1 : 0;
        $e["_rot"] = arn_rot($seed, "ep" . $e["id"]);
        $cand[] = $e;
    }
    usort($cand, function ($a, $b) {
        return [$a["_citato"], -$a["_score"], (string) ($a["ultimo_utilizzo"] ?? ""), $a["_rot"], (int) $a["id"]]
           <=> [$b["_citato"], -$b["_score"], (string) ($b["ultimo_utilizzo"] ?? ""), $b["_rot"], (int) $b["id"]];
    });
    $out = [];
    $generici = 0;
    foreach ($cand as $e) {
        if ($e["_score"] < 0.5) { if ($generici >= 1) continue; $generici++; }
        $out[] = $e;
        if (count($out) >= $max) break;
    }
    return $out;
}

// ------------------------------------------------------------
// ORCHESTRATORE
// ------------------------------------------------------------

/**
 * Costruisce tutte le sezioni aggiuntive del prompt.
 *   $incontri: elenco di partite (vedi art_dati_giornata, chiave "_incontri")
 *   $partecipanti: squadre in gioco (id, nome, allenatore, utenza)
 *   $sq: tutte le squadre della stagione (art_squadre)
 * Ritorna: forma, schede, fatti, memoria, profili, senza_profilo, episodi,
 * stat (contatori per il riepilogo) e errori (sezione => messaggio).
 */
function arn_costruisci($conn, int $stagione, int $giornata, array $incontri, array $partecipanti, array $sq, string $formato): array
{
    $out = ["forma" => "", "schede" => "", "fatti" => "", "memoria" => "", "profili" => [], "senza_profilo" => [],
            "episodi" => [], "errori" => [],
            "stat" => ["schede" => 0, "slot" => 0, "subentri" => 0, "big_flop" => 0, "assenti" => 0, "scontri_con_storico" => 0,
                       "memoria_articoli" => 0, "memoria_giornate" => [], "tormentoni_proposti" => 0, "episodi_in_pausa" => 0]];
    $seed = "S$stagione-G$giornata";

    // etichette delle partite, nello stesso ordine delle pagine dell'edizione
    $conChamp = (bool) art_cfg("champions_in_edizione", true) && $formato === "edizione";
    $etichette = [];
    $n = 0; $nc = 0;
    foreach ($incontri as $inc) {
        if ($inc["comp"] === "campionato" || $conChamp) { $n++; $etichette[] = "PARTITA $n"; }
        else { $nc++; $etichette[] = "CHAMPIONS $nc"; }
    }

    // memoria delle puntate precedenti (serve anche a profili ed episodi)
    $mem = ["righe" => "", "norm" => "", "episodi" => [], "n" => 0, "giornate" => []];
    try {
        $mem = arn_memoria($conn, $stagione, $giornata);
        $out["memoria"] = $mem["righe"];
        $out["stat"]["memoria_articoli"] = $mem["n"];
        $out["stat"]["memoria_giornate"] = $mem["giornate"];
        $out["stat"]["episodi_in_pausa"] = count($mem["episodi"]);
    } catch (Throwable $e) {
        $out["errori"]["memoria"] = $e->getMessage();
        error_log("ArticoloNarrativa/memoria: " . $e->getMessage());
    }

    // profili ed episodi
    $prof = ["righe" => [], "senza_profilo" => [], "collegati" => [], "tormentoni" => 0];
    try {
        $prof = arn_profili($conn, $partecipanti, $incontri, $etichette, $seed, $mem["norm"]);
        $out["profili"] = $prof["righe"];
        $out["senza_profilo"] = $prof["senza_profilo"];
        $out["stat"]["tormentoni_proposti"] = $prof["tormentoni"];
    } catch (Throwable $e) {
        $out["errori"]["profili"] = $e->getMessage();
        error_log("ArticoloNarrativa/profili: " . $e->getMessage());
    }
    try {
        $out["episodi"] = arn_episodi($conn, $partecipanti, $incontri, $etichette, $sq, $mem["episodi"], $mem["norm"],
                                      (int) art_cfg("max_episodi", 3), $seed);
    } catch (Throwable $e) {
        $out["errori"]["episodi"] = $e->getMessage();
        error_log("ArticoloNarrativa/episodi: " . $e->getMessage());
    }

    // andamento delle squadre
    try {
        $f = arn_forma($stagione, $giornata, $partecipanti, $sq);
        $out["forma"] = $f["testo"];
    } catch (Throwable $e) {
        $out["errori"]["forma"] = $e->getMessage();
        error_log("ArticoloNarrativa/forma: " . $e->getMessage());
    }

    // schede partita (con tentativi a dettaglio decrescente se il testo è troppo lungo)
    try {
        $ctx = [
            "stagione" => $stagione, "giornata" => $giornata, "sq" => $sq,
            "info" => arn_info_giocatori($stagione),
            "vsa" => arn_voti_serie_a($stagione, $giornata),
            "g_serie_a" => arn_giornata_serie_a($stagione, $giornata),
            "collegati" => $prof["collegati"],
        ];
        $ctx["slots"] = arn_slots($stagione, $ctx["g_serie_a"]);
        $maxCar = max(2000, (int) art_cfg("max_caratteri_schede", 14000));
        $testo = "";
        $schede = [];
        foreach ([2, 1, 0] as $dett) {
            $schede = [];
            foreach ($incontri as $i => $inc) $schede[] = arn_scheda($conn, $inc, $etichette[$i], $ctx, $dett);
            $testo = implode("\n\n", array_map(fn($s) => $s["testo"], $schede));
            if (mb_strlen($testo) <= $maxCar) break;
        }
        $out["schede"] = $testo;
        $out["stat"]["schede"] = count($schede);
        foreach ($schede as $s) {
            $out["stat"]["slot"] += $s["n_slot"];
            $out["stat"]["subentri"] += $s["n_subentri"];
            $out["stat"]["big_flop"] += $s["n_big_flop"];
            $out["stat"]["assenti"] += $s["n_assenti"];
            $out["stat"]["scontri_con_storico"] += $s["storico"];
        }
        // riepilogo dei fatti più forti della giornata (per il commento generale)
        $tutti = [];
        foreach ($schede as $s) foreach ($s["fatti"] as $f) $tutti[] = $f;
        $righe = [];
        $quanti = ["fortuna" => 3, "sfortuna" => 2, "big_flop" => 3, "big_top" => 2, "assenza" => 2, "rimpianto" => 2];
        $titoli = ["fortuna" => "Colpi di fortuna", "sfortuna" => "Colpi di sfortuna", "big_flop" => "Big che non rendono",
                   "big_top" => "Big che rendono", "assenza" => "Assenze e titolari senza voto", "rimpianto" => "Rimpianti (costosi lasciati fuori)"];
        foreach ($quanti as $tipo => $max) {
            $lista = array_values(array_filter($tutti, fn($f) => $f["tipo"] === $tipo));
            usort($lista, fn($x, $y) => $y["peso"] <=> $x["peso"]);
            foreach (array_slice($lista, 0, $max) as $f) $righe[] = "[" . $titoli[$tipo] . "] " . ltrim($f["testo"], "+- ");
        }
        $out["fatti"] = implode("\n", $righe);
    } catch (Throwable $e) {
        $out["errori"]["schede"] = $e->getMessage();
        error_log("ArticoloNarrativa/schede: " . $e->getMessage());
    }
    return $out;
}
