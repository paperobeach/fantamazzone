<?php
// ============================================================
// backend/lib/SchedaGiocatore.php
//
// Logica della scheda giocatore (api/giocatore.php) e della ricerca
// rapida (api/giocatori_cerca.php).
//
// ATTENZIONE: l'id di un giocatore NON e' lo stesso tra una stagione
// e l'altra (NEW_GIOCATORI.id e' valido solo insieme alla stagione).
// Lo storico si ricostruisce con il RACCORDO PER ID
// (NEW_GIOCATORI_RACCORDO, vedi lib/RaccordoGiocatori.php): ogni
// (stagione, id) e' collegato a una persona stabile.
// Nella stessa stagione la persona puo' avere PIU' id (cessione da una
// squadra e acquisto da un'altra): i dati della stagione (totali, giornate,
// percorso) sono aggregati su tutti gli id; l'id piu' basso e' il primo in
// ordine cronologico, quello piu' alto l'ultimo (usato per l'intestazione).
// Se le tabelle del raccordo non esistono, o il giocatore non e' ancora
// raccordato, si ripiega sul collegamento per NOME (descrizione), preferendo
// a parita' di nome l'edizione con lo stesso ruolo; in quel caso due omonimi
// con lo stesso ruolo nella stessa stagione non sono distinguibili.
//
// Mapping dei rigori (come api/squadre.php):
//   rigores = Rp + Rf  -> portiere: parati, altri ruoli: segnati
//   rigorep = Rs       -> sbagliati
// Gol: per i portieri "gol subiti" (gols), per gli altri "gol fatti" (golf).
// ============================================================

require_once __DIR__ . "/RosaStorico.php";
require_once __DIR__ . "/RaccordoGiocatori.php";

/** Normalizza un campo numerico letto dal DB (stringhe -> int/float). */
function sg_num($v, bool $decimale = false)
{
    if ($v === null || $v === "") return $decimale ? 0.0 : 0;
    return $decimale ? (float) $v : (int) $v;
}

/** Escape per LIKE: neutralizza % _ \ dopo l'escape di mysqli. */
function sg_escape_like(string $s): string
{
    global $conn;
    $s = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $s);
    return mysqli_real_escape_string($conn, $s);
}

/**
 * Ricerca rapida tra i giocatori della stagione (in rosa + svincolati).
 * Restituisce al massimo $limite giocatori: i nomi che INIZIANO con il testo
 * cercato vengono prima di quelli che lo contengono soltanto.
 */
function sg_cerca(int $stagione, string $q, int $limite = 10): array
{
    global $conn;
    $q = trim($q);
    if (strlen($q) < 2) return [];

    $like       = sg_escape_like($q);
    $limite     = max(1, min(25, $limite));
    $anagrafica = rs_sql_anagrafica($stagione);

    $righe = query_all("SELECT a.id, a.descrizione, a.ruolo, a.nazione,
                               b.squadra_serie_a,
                               s.id AS id_squadra, s.nome AS squadra, s.logo
                        FROM $anagrafica a
                        LEFT JOIN NEW_GIOCATORI g
                               ON g.stagione = a.stagione AND g.id = a.id
                        LEFT JOIN NEW_SQUADRE s
                               ON s.stagione = a.stagione AND s.id = g.squadra AND g.squadra > 0
                        LEFT JOIN NEW_GIOCATORI_BASE_ASTA b
                               ON b.stagione = a.stagione AND b.id_giocatore = a.id
                        WHERE a.descrizione LIKE '%$like%'
                        ORDER BY (a.descrizione LIKE '$like%') DESC, a.descrizione
                        LIMIT " . ($limite * 2));

    // Piu' id della stessa persona nella stagione (cessione/acquisto): in elenco
    // compare una volta sola, con l'id piu' alto (l'ultimo in ordine cronologico)
    $persona = [];
    if ($righe && rg_tabelle_esistono()) {
        $ids = implode(",", array_map(fn($r) => (int) $r["id"], $righe));
        foreach (query_all("SELECT id_giocatore, id_anagrafica FROM " . RG_T_RACC . "
                            WHERE stagione = $stagione AND id_giocatore IN ($ids)") as $r) {
            $persona[(int) $r["id_giocatore"]] = (int) $r["id_anagrafica"];
        }
    }
    $chiave = fn($r) => isset($persona[(int) $r["id"]]) ? "p" . $persona[(int) $r["id"]] : "g" . (int) $r["id"];
    $migliore = [];
    foreach ($righe as $r) {
        $k = $chiave($r);
        if (!isset($migliore[$k]) || (int) $r["id"] > (int) $migliore[$k]["id"]) $migliore[$k] = $r;
    }

    $out   = [];
    $visti = [];
    foreach ($righe as $r0) {
        $k = $chiave($r0);
        if (isset($visti[$k])) continue;
        $visti[$k] = true;
        $r = $migliore[$k];
        $out[] = [
            "id"              => (int) $r["id"],
            "descrizione"     => $r["descrizione"],
            "ruolo"           => (int) $r["ruolo"],
            "squadra_serie_a" => $r["squadra_serie_a"] ?? null,
            "id_squadra"      => $r["id_squadra"] !== null ? (int) $r["id_squadra"] : null,
            "squadra"         => $r["squadra"] ?? null,
            "logo"            => $r["logo"] ?? null,
        ];
        if (count($out) >= $limite) break;
    }
    return $out;
}

/**
 * Anagrafica del giocatore nella stagione richiesta (null se non esiste).
 * Cerca in NEW_GIOCATORI / NEW_GIOCATORI_SVINCOLATI e, in mancanza,
 * in NEW_STATISTICHE.
 */
function sg_anagrafica(int $stagione, int $id): ?array
{
    $a = query_one("SELECT a.id, a.descrizione, a.ruolo, a.nazione
                    FROM " . rs_sql_anagrafica($stagione) . " a
                    WHERE a.id = $id
                    LIMIT 1");
    if (!$a) {
        $s = query_one("SELECT id_giocatore AS id, giocatore AS descrizione, ruolo, '' AS nazione
                        FROM NEW_STATISTICHE
                        WHERE stagione = $stagione AND id_giocatore = $id
                        LIMIT 1");
        if (!$s) return null;
        $a = $s;
    }

    $g = query_one("SELECT squadra, crediti FROM NEW_GIOCATORI
                    WHERE stagione = $stagione AND id = $id LIMIT 1");
    $idSquadra = $g ? (int) $g["squadra"] : 0;

    $sq = null;
    if ($idSquadra > 0) {
        $sq = query_one("SELECT id, nome, logo FROM NEW_SQUADRE
                         WHERE stagione = $stagione AND id = $idSquadra LIMIT 1");
    }

    $b = query_one("SELECT squadra_serie_a, nazione FROM NEW_GIOCATORI_BASE_ASTA
                    WHERE stagione = $stagione AND id_giocatore = $id LIMIT 1");

    $nazione = $b && trim((string) $b["nazione"]) !== "" ? $b["nazione"] : ($a["nazione"] ?? "");

    return [
        "id"              => (int) $a["id"],
        "stagione"        => $stagione,
        "nome"            => trim($a["descrizione"]),
        "ruolo"           => (int) $a["ruolo"],
        "nazione"         => $nazione,
        "squadra_serie_a" => $b["squadra_serie_a"] ?? null,
        "crediti"         => $g ? (int) $g["crediti"] : null,
        "in_rosa"         => $idSquadra > 0,
        "id_squadra"      => $sq ? (int) $sq["id"] : null,
        "squadra"         => $sq["nome"] ?? null,
        "logo"            => $sq["logo"] ?? null,
    ];
}

/**
 * Edizioni del giocatore in tutte le stagioni: [stagione => [id_giocatore, ...]],
 * dalla stagione piu' recente; gli id di una stagione sono in ordine crescente,
 * cioe' cronologico (l'id piu' basso e' il primo).
 * Con $idPersona (raccordo per id) le edizioni sono quelle della persona, anche
 * piu' di una per stagione; altrimenti il collegamento tra stagioni e' per nome
 * (vedi intestazione) e si considera un solo id per stagione.
 */
function sg_edizioni(string $nome, int $stagione, int $id, int $ruolo, ?int $idPersona = null): array
{
    global $conn;

    // Raccordo per id: edizioni della persona
    if ($idPersona !== null) {
        $ed = rg_edizioni($idPersona);
        $ed[$stagione] = array_values(array_unique(array_merge($ed[$stagione] ?? [], [$id])));
        sort($ed[$stagione]);
        krsort($ed);
        return $ed;
    }

    // Ripiego: collegamento per nome
    $n = mysqli_real_escape_string($conn, trim($nome));

    $righe = query_all("SELECT x.stagione, x.id, x.ruolo FROM (
                            SELECT stagione, id, descrizione, ruolo FROM NEW_GIOCATORI
                            UNION ALL
                            SELECT stagione, id, descrizione, ruolo FROM NEW_GIOCATORI_SVINCOLATI
                            UNION ALL
                            SELECT stagione, id_giocatore AS id, giocatore AS descrizione, ruolo FROM NEW_STATISTICHE
                        ) x
                        WHERE TRIM(x.descrizione) = '$n'");

    // Per ogni stagione si sceglie un solo id: prima quello gia' noto della
    // stagione richiesta, poi quello con lo stesso ruolo, poi il primo trovato.
    $scelte = [];   // stagione => [id, priorita']
    foreach ($righe as $r) {
        $s = (int) $r["stagione"];
        $i = (int) $r["id"];
        $p = ($s === $stagione && $i === $id) ? 0 : ((int) $r["ruolo"] === $ruolo ? 1 : 2);
        if (!isset($scelte[$s]) || $p < $scelte[$s][1]) $scelte[$s] = [$i, $p];
    }
    if (!isset($scelte[$stagione])) $scelte[$stagione] = [$id, 0];

    $out = [];
    foreach ($scelte as $s => $v) $out[$s] = [$v[0]];
    krsort($out);
    return $out;
}

/** Riga di statistiche normalizzata (stagione intera). */
function sg_riga_statistiche(array $r): array
{
    $portiere = (int) $r["ruolo"] === 1;
    $rigoriRis = (int) $r["rigores"];
    return [
        "stagione"         => (int) $r["stagione"],
        "id_giocatore"     => (int) $r["id_giocatore"],
        "ruolo"            => (int) $r["ruolo"],
        "id_squadra"       => (int) $r["id_squadra"],
        "squadra"          => $r["squadra"],
        "logo"             => $r["logo"],
        "giocate"          => (int) $r["giocate"],
        "media"            => sg_num($r["media"], true),
        "gol"              => (int) $r["golf"],
        "gol_subiti"       => (int) $r["gols"],
        "assist"           => (int) $r["assist"],
        "ammonizioni"      => (int) $r["ammonizioni"],
        "espulsioni"       => (int) $r["espulsioni"],
        "autogol"          => (int) $r["autogol"],
        "rigori_segnati"   => $portiere ? 0 : $rigoriRis,
        "rigori_parati"    => $portiere ? $rigoriRis : 0,
        "rigori_sbagliati" => (int) $r["rigorep"],
    ];
}

/** Elenco id come lista SQL sicura (solo interi). */
function sg_in(array $ids): string
{
    return implode(",", array_map("intval", $ids));
}

/** Dettaglio giornata per giornata della stagione su tutti gli id (NEW_VOTI, no riserve d'ufficio). */
function sg_giornate(int $stagione, array $ids, int $ruolo): array
{
    $portiere = $ruolo === 1;
    $righe = query_all("SELECT giornata, id_giocatore, voto, totale, giocata, reti, retis, assist,
                               ammonizioni, espulsioni, autogol, rigores, rigorep, id_squadra
                        FROM NEW_VOTI
                        WHERE stagione = $stagione AND id_giocatore IN (" . sg_in($ids) . ") AND rufficio = 0
                        ORDER BY giornata, id_giocatore");
    $out = [];
    foreach ($righe as $r) {
        $giocata = (int) $r["giocata"] === 1;
        $out[] = [
            "giornata"         => (int) $r["giornata"],
            "id_giocatore"     => (int) $r["id_giocatore"],
            "giocata"          => $giocata,
            "voto"             => $giocata ? sg_num($r["voto"], true) : null,
            "totale"           => $giocata ? sg_num($r["totale"], true) : null,
            "gol"              => (int) $r["reti"],
            "gol_subiti"       => (int) $r["retis"],
            "assist"           => (int) $r["assist"],
            "ammonizioni"      => (int) $r["ammonizioni"],
            "espulsioni"       => (int) $r["espulsioni"],
            "autogol"          => (int) $r["autogol"],
            "rigori_segnati"   => $portiere ? 0 : (int) $r["rigores"],
            "rigori_parati"    => $portiere ? (int) $r["rigores"] : 0,
            "rigori_sbagliati" => (int) $r["rigorep"],
            "id_squadra"       => (int) $r["id_squadra"],
        ];
    }
    return $out;
}

/** Percorso in lega nella stagione su tutti gli id (NEW_ROSA_STORICO), dal piu' recente. */
function sg_percorso(int $stagione, array $ids): array
{
    if (!rs_tabella_esiste()) return [];
    $righe = query_all("SELECT r.id_squadra, s.nome AS squadra, s.logo, r.da_giornata, r.a_giornata
                        FROM NEW_ROSA_STORICO r
                        LEFT JOIN NEW_SQUADRE s
                               ON s.stagione = r.stagione AND s.id = r.id_squadra
                        WHERE r.stagione = $stagione AND r.id_giocatore IN (" . sg_in($ids) . ")
                        ORDER BY r.da_giornata DESC, r.id_giocatore DESC");
    $out = [];
    foreach ($righe as $r) {
        $out[] = [
            "id_squadra"  => (int) $r["id_squadra"],
            "squadra"     => $r["squadra"],
            "logo"        => $r["logo"],
            "da_giornata" => (int) $r["da_giornata"],
            "a_giornata"  => $r["a_giornata"] !== null ? (int) $r["a_giornata"] : null,
        ];
    }
    return $out;
}

/**
 * Riunisce le righe di statistiche della stessa stagione (uno per id, in ordine
 * di id crescente = cronologico): somme dei contatori, media ponderata sulle
 * presenze, elenco delle squadre in ordine cronologico.
 */
function sg_unisci_righe(array $rr): array
{
    $out = end($rr);   // l'ultima edizione fornisce ruolo e squadra "attuale"

    $squadre = [];
    foreach ($rr as $r) {
        $ultima = $squadre ? $squadre[count($squadre) - 1] : null;
        if ($ultima && $ultima["id_squadra"] === $r["id_squadra"]) continue;
        $squadre[] = ["id_squadra" => $r["id_squadra"], "squadra" => $r["squadra"], "logo" => $r["logo"]];
    }
    $out["squadre"] = $squadre;

    if (count($rr) > 1) {
        foreach (["giocate", "gol", "gol_subiti", "assist", "ammonizioni", "espulsioni",
                  "autogol", "rigori_segnati", "rigori_parati", "rigori_sbagliati"] as $k) {
            $out[$k] = array_sum(array_column($rr, $k));
        }
        $somma = 0.0;
        foreach ($rr as $r) $somma += $r["media"] * $r["giocate"];
        $out["media"] = $out["giocate"] > 0 ? round($somma / $out["giocate"], 2) : $out["media"];
    }
    return $out;
}

/**
 * Statistiche di tutte le stagioni delle edizioni indicate: UNA RIGA PER ID
 * (stesso giocatore con due id nella stessa stagione = due righe, ciascuna
 * con la propria squadra), da stagione piu' recente; nella stagione l'id piu'
 * alto (ultimo in ordine cronologico) viene prima.
 *
 * I numeri sono calcolati da NEW_VOTI con le stesse formule di
 * agg_statistiche(): NEW_STATISTICHE esclude i giocatori con
 * NEW_GIOCATORI.flag = 1 (ceduti), che quindi mancherebbero. NEW_STATISTICHE
 * resta come ripiego per gli id senza voti. Un id esistente ma senza alcun
 * dato compare comunque, con presenze a 0.
 */
function sg_storico(array $edizioni, int $ruoloDefault = 0): array
{
    if (!$edizioni) return [];

    $cond = [];
    foreach ($edizioni as $s => $ids) {
        $cond[] = "(stagione = " . (int) $s . " AND id_giocatore IN (" . sg_in($ids) . "))";
    }
    $where = implode(" OR ", $cond);

    // 1. Aggregato di NEW_VOTI per (stagione, id) + squadra dell'ultima giornata
    $righe = [];   // "stagione-id" => riga
    $voti = query_all("SELECT a.stagione, a.id_giocatore, a.giocate, a.totale, a.reti, a.retis,
                              a.ammonizioni, a.espulsioni, a.rigores, a.rigorep, a.autogol, a.assist,
                              u.id_squadra
                       FROM (
                         SELECT stagione, id_giocatore,
                                MAX(giornata)    AS ultima,
                                SUM(giocata)     AS giocate,
                                SUM(totale)      AS totale,
                                SUM(reti)        AS reti,
                                SUM(retis)       AS retis,
                                SUM(ammonizioni) AS ammonizioni,
                                SUM(espulsioni)  AS espulsioni,
                                SUM(rigores)     AS rigores,
                                SUM(rigorep)     AS rigorep,
                                SUM(autogol)     AS autogol,
                                SUM(assist)      AS assist
                         FROM NEW_VOTI
                         WHERE ($where) AND rufficio = 0
                         GROUP BY stagione, id_giocatore
                       ) a
                       JOIN NEW_VOTI u ON u.stagione = a.stagione AND u.id_giocatore = a.id_giocatore
                                      AND u.giornata = a.ultima AND u.rufficio = 0");

    // Ruolo e squadra (nome, logo) per (stagione, id)
    $whereG = [];
    foreach ($edizioni as $s => $ids) {
        $whereG[] = "(stagione = " . (int) $s . " AND id IN (" . sg_in($ids) . "))";
    }
    $ruoli = [];     // "stagione-id" => ruolo
    $inLega = [];    // "stagione-id" => id squadra di lega (0 = svincolato)
    foreach (query_all("SELECT stagione, id, ruolo, squadra FROM NEW_GIOCATORI WHERE " . implode(" OR ", $whereG)) as $g) {
        $k = (int) $g["stagione"] . "-" . (int) $g["id"];
        $ruoli[$k]  = (int) $g["ruolo"];
        $inLega[$k] = (int) $g["squadra"];
    }
    foreach (query_all("SELECT stagione, id, ruolo FROM NEW_GIOCATORI_SVINCOLATI WHERE " . implode(" OR ", $whereG)) as $g) {
        $k = (int) $g["stagione"] . "-" . (int) $g["id"];
        if (!isset($ruoli[$k])) { $ruoli[$k] = (int) $g["ruolo"]; $inLega[$k] = 0; }
    }
    $squadre = [];
    foreach (query_all("SELECT stagione, id, nome, logo FROM NEW_SQUADRE") as $q) {
        $squadre[(int) $q["stagione"] . "-" . (int) $q["id"]] = $q;
    }

    foreach ($voti as $v) {
        $st = (int) $v["stagione"];
        $id = (int) $v["id_giocatore"];
        $k  = "$st-$id";
        if (isset($righe[$k])) continue;
        $ruolo = $ruoli[$k] ?? $ruoloDefault;
        $gi = (int) $v["giocate"];
        $sq = $squadre[$st . "-" . (int) $v["id_squadra"]] ?? null;
        $righe[$k] = [
            "stagione"         => $st,
            "id_giocatore"     => $id,
            "ruolo"            => $ruolo,
            "id_squadra"       => (int) $v["id_squadra"],
            "squadra"          => $sq["nome"] ?? null,
            "logo"             => $sq["logo"] ?? null,
            "giocate"          => $gi,
            "media"            => $gi > 0 ? round((float) $v["totale"] / $gi, 2) : 0.0,
            "gol"              => (int) $v["reti"],
            "gol_subiti"       => (int) $v["retis"],
            "assist"           => (int) $v["assist"],
            "ammonizioni"      => (int) $v["ammonizioni"],
            "espulsioni"       => (int) $v["espulsioni"],
            "autogol"          => (int) $v["autogol"],
            "rigori_segnati"   => $ruolo === 1 ? 0 : (int) $v["rigores"],
            "rigori_parati"    => $ruolo === 1 ? (int) $v["rigores"] : 0,
            "rigori_sbagliati" => (int) $v["rigorep"],
        ];
    }

    // 2. Ripiego: id senza voti ma con riga in NEW_STATISTICHE
    foreach (query_all("SELECT stagione, id_squadra, squadra, logo, id_giocatore, ruolo,
                               giocate, media, golf, gols, ammonizioni, espulsioni,
                               rigores, rigorep, autogol, assist
                        FROM NEW_STATISTICHE WHERE $where") as $r) {
        $k = (int) $r["stagione"] . "-" . (int) $r["id_giocatore"];
        if (!isset($righe[$k])) $righe[$k] = sg_riga_statistiche($r);
    }

    // 2b. Edizioni senza alcun dato (nessun voto, nessuna statistica): la stagione
    // compare comunque, con presenze a 0 (es. giocatore appena acquistato o svincolato)
    foreach ($edizioni as $st => $ids) {
        foreach ($ids as $id) {
            $k = "$st-$id";
            if (isset($righe[$k]) || !isset($ruoli[$k])) continue;   // solo id realmente esistenti
            $sq = ($inLega[$k] ?? 0) > 0 ? ($squadre[$st . "-" . $inLega[$k]] ?? null) : null;
            $righe[$k] = [
                "stagione"         => (int) $st,
                "id_giocatore"     => (int) $id,
                "ruolo"            => $ruoli[$k],
                "id_squadra"       => $sq ? (int) $inLega[$k] : 0,
                "squadra"          => $sq["nome"] ?? null,
                "logo"             => $sq["logo"] ?? null,
                "giocate"          => 0,
                "media"            => 0.0,
                "gol"              => 0,
                "gol_subiti"       => 0,
                "assist"           => 0,
                "ammonizioni"      => 0,
                "espulsioni"       => 0,
                "autogol"          => 0,
                "rigori_segnati"   => 0,
                "rigori_parati"    => 0,
                "rigori_sbagliati" => 0,
            ];
        }
    }

    // 3. Squadra Serie A per stagione: quella dell'id piu' alto che la riporta
    $serieA = [];
    foreach (query_all("SELECT stagione, id_giocatore, squadra_serie_a FROM NEW_GIOCATORI_BASE_ASTA
                        WHERE $where ORDER BY stagione, id_giocatore") as $b) {
        if (trim((string) $b["squadra_serie_a"]) !== "") $serieA[(int) $b["stagione"]] = $b["squadra_serie_a"];
    }

    // 4. Ordinamento (stagione desc, id desc) e posizione cronologica nella stagione
    $out = array_values($righe);
    usort($out, fn($a, $b) => [$b["stagione"], $b["id_giocatore"]] <=> [$a["stagione"], $a["id_giocatore"]]);
    $totale = [];
    foreach ($out as $r) $totale[$r["stagione"]] = ($totale[$r["stagione"]] ?? 0) + 1;
    $posizione = [];
    foreach (array_reverse($out) as $i => $r) {   // dal piu' vecchio: id piu' basso = 1
        $posizione[$r["stagione"]] = ($posizione[$r["stagione"]] ?? 0) + 1;
        $out[count($out) - 1 - $i]["ordine_stagione"] = $posizione[$r["stagione"]];
    }
    foreach ($out as &$r) {
        $r["totale_stagione"]  = $totale[$r["stagione"]];
        $r["squadra_serie_a"]  = $serieA[$r["stagione"]] ?? null;
    }
    unset($r);
    return $out;
}

/** Totali di carriera (media ponderata sulle presenze). */
function sg_carriera(array $storico): array
{
    $t = [
        "stagioni" => count(array_unique(array_column($storico, "stagione"))), "giocate" => 0, "media" => 0.0,
        "gol" => 0, "gol_subiti" => 0, "assist" => 0, "ammonizioni" => 0,
        "espulsioni" => 0, "autogol" => 0,
        "rigori_segnati" => 0, "rigori_parati" => 0, "rigori_sbagliati" => 0,
    ];
    $somma = 0.0;
    foreach ($storico as $r) {
        foreach (["giocate", "gol", "gol_subiti", "assist", "ammonizioni", "espulsioni",
                  "autogol", "rigori_segnati", "rigori_parati", "rigori_sbagliati"] as $k) {
            $t[$k] += $r[$k];
        }
        $somma += $r["media"] * $r["giocate"];
    }
    $t["media"] = $t["giocate"] > 0 ? round($somma / $t["giocate"], 2) : 0.0;
    return $t;
}

/** Scheda completa del giocatore. Null se il giocatore non esiste nella stagione. */
function sg_scheda(int $stagione, int $id): ?array
{
    $anag = sg_anagrafica($stagione, $id);
    if (!$anag) return null;

    // Persona del raccordo (null se tabelle assenti o giocatore non raccordato)
    $idPersona = rg_tabelle_esistono() ? rg_persona_di($stagione, $id) : null;

    $edizioni    = sg_edizioni($anag["nome"], $stagione, $id, $anag["ruolo"], $idPersona);
    $idsStagione = $edizioni[$stagione] ?? [$id];

    // Piu' id nella stagione (cessione/acquisto): l'intestazione (squadra,
    // crediti, ...) riflette l'ultimo in ordine cronologico, cioe' l'id piu' alto
    $idUltimo = max($idsStagione);
    if ($idUltimo !== $id) {
        $ultimo = sg_anagrafica($stagione, $idUltimo);
        if ($ultimo) $anag = $ultimo;
    }
    $anag["id_anagrafica"] = $idPersona;
    $anag["ids_stagione"]  = $idsStagione;

    $storico = sg_storico($edizioni, $anag["ruolo"]);

    // Totali della stagione richiesta: somma di tutti gli id (in ordine cronologico)
    $righeStagione = array_values(array_filter($storico, fn($r) => $r["stagione"] === $stagione));
    usort($righeStagione, fn($a, $b) => $a["id_giocatore"] <=> $b["id_giocatore"]);
    $totali = $righeStagione ? sg_unisci_righe($righeStagione) : null;

    return [
        "anagrafica" => $anag,
        "stagione_corrente" => [
            "stagione" => $stagione,
            "totali"   => $totali,
            "giornate" => sg_giornate($stagione, $idsStagione, $anag["ruolo"]),
            "percorso" => sg_percorso($stagione, $idsStagione),
        ],
        "storico" => [
            "stagioni"      => $storico,
            "carriera"      => sg_carriera($storico),
            "collegamento"  => $idPersona !== null ? "raccordo" : "nome",
            "da_verificare" => $idPersona !== null && rg_persona_da_verificare($idPersona),
        ],
    ];
}
