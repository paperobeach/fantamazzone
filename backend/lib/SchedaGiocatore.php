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

    $out  = [];
    $visti = [];
    foreach ($righe as $r) {
        $id = (int) $r["id"];
        if (isset($visti[$id])) continue;
        $visti[$id] = true;
        $out[] = [
            "id"              => $id,
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
 * Edizioni del giocatore in tutte le stagioni: [stagione => id_giocatore].
 * Con $idPersona (raccordo per id) le edizioni sono quelle della persona;
 * altrimenti il collegamento tra stagioni e' per nome (vedi intestazione).
 */
function sg_edizioni(string $nome, int $stagione, int $id, int $ruolo, ?int $idPersona = null): array
{
    global $conn;

    // Raccordo per id: edizioni della persona
    if ($idPersona !== null) {
        $ed = rg_edizioni($idPersona);
        $ed[$stagione] = $id;
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
    foreach ($scelte as $s => $v) $out[$s] = $v[0];
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

/** Dettaglio giornata per giornata della stagione (NEW_VOTI, no riserve d'ufficio). */
function sg_giornate(int $stagione, int $id, int $ruolo): array
{
    $portiere = $ruolo === 1;
    $righe = query_all("SELECT giornata, voto, totale, giocata, reti, retis, assist,
                               ammonizioni, espulsioni, autogol, rigores, rigorep, id_squadra
                        FROM NEW_VOTI
                        WHERE stagione = $stagione AND id_giocatore = $id AND rufficio = 0
                        ORDER BY giornata");
    $out = [];
    foreach ($righe as $r) {
        $giocata = (int) $r["giocata"] === 1;
        $out[] = [
            "giornata"         => (int) $r["giornata"],
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

/** Percorso in lega nella stagione (NEW_ROSA_STORICO), dal piu' recente. */
function sg_percorso(int $stagione, int $id): array
{
    if (!rs_tabella_esiste()) return [];
    $righe = query_all("SELECT r.id_squadra, s.nome AS squadra, s.logo, r.da_giornata, r.a_giornata
                        FROM NEW_ROSA_STORICO r
                        LEFT JOIN NEW_SQUADRE s
                               ON s.stagione = r.stagione AND s.id = r.id_squadra
                        WHERE r.stagione = $stagione AND r.id_giocatore = $id
                        ORDER BY r.da_giornata DESC");
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

/** Statistiche di tutte le stagioni delle edizioni indicate (+ squadra Serie A). */
function sg_storico(array $edizioni): array
{
    if (!$edizioni) return [];

    $cond = [];
    foreach ($edizioni as $s => $i) {
        $cond[] = "(stagione = " . (int) $s . " AND id_giocatore = " . (int) $i . ")";
    }
    $where = implode(" OR ", $cond);

    $righe = query_all("SELECT stagione, id_squadra, squadra, logo, id_giocatore, ruolo,
                               giocate, media, golf, gols, ammonizioni, espulsioni,
                               rigores, rigorep, autogol, assist
                        FROM NEW_STATISTICHE
                        WHERE $where
                        ORDER BY stagione DESC");

    $serieA = [];
    foreach (query_all("SELECT stagione, squadra_serie_a FROM NEW_GIOCATORI_BASE_ASTA
                        WHERE $where") as $b) {
        $serieA[(int) $b["stagione"]] = $b["squadra_serie_a"];
    }

    $out = [];
    foreach ($righe as $r) {
        $riga = sg_riga_statistiche($r);
        $riga["squadra_serie_a"] = $serieA[$riga["stagione"]] ?? null;
        $out[] = $riga;
    }
    return $out;
}

/** Totali di carriera (media ponderata sulle presenze). */
function sg_carriera(array $storico): array
{
    $t = [
        "stagioni" => count($storico), "giocate" => 0, "media" => 0.0,
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
    $anag["id_anagrafica"] = $idPersona;

    $edizioni = sg_edizioni($anag["nome"], $stagione, $id, $anag["ruolo"], $idPersona);
    $storico  = sg_storico($edizioni);

    $totali = null;
    foreach ($storico as $r) {
        if ($r["stagione"] === $stagione) { $totali = $r; break; }
    }

    return [
        "anagrafica" => $anag,
        "stagione_corrente" => [
            "stagione" => $stagione,
            "totali"   => $totali,
            "giornate" => sg_giornate($stagione, $id, $anag["ruolo"]),
            "percorso" => sg_percorso($stagione, $id),
        ],
        "storico" => [
            "stagioni"      => $storico,
            "carriera"      => sg_carriera($storico),
            "collegamento"  => $idPersona !== null ? "raccordo" : "nome",
            "da_verificare" => $idPersona !== null && rg_persona_da_verificare($idPersona),
        ],
    ];
}
