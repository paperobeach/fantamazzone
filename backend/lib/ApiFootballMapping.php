<?php
// ============================================================
// backend/lib/ApiFootballMapping.php
//
// Associazione tra squadre/giocatori di API-Football e quelli del
// progetto:
//   - squadre:   NEW_API_MAPPING_SQUADRE.squadra_serie_a = nome usato in
//                NEW_CALENDARIO_SERIE_A / NEW_GIOCATORI_BASE_ASTA;
//   - giocatori: NEW_API_MAPPING_GIOCATORI.id_giocatore = "Cod." del
//                listone (NEW_GIOCATORI_BASE_ASTA.id_giocatore).
//
// Le funzioni afm_norm / afm_punteggio_* / afm_proponi_* sono PURE (nessun
// accesso al DB) e quindi verificabili isolatamente. Le funzioni che
// leggono il DB vanno usate DOPO connect.php (query_all / query_one).
// ============================================================

// Hosting senza estensione mbstring: versioni minime di sostituzione
if (!function_exists("mb_substr")) {
    function mb_substr($s, $start, $length = null) { return $length === null ? substr($s, $start) : substr($s, $start, $length); }
}
if (!function_exists("mb_strlen")) {
    function mb_strlen($s) { return strlen($s); }
}

/** Maiuscolo, senza accenti né punteggiatura (apostrofi eliminati, trattini → spazio). */
function afm_norm(?string $s): string
{
    $s = trim((string) $s);
    if ($s === "") return "";
    $s = function_exists("mb_strtoupper") ? mb_strtoupper($s, "UTF-8") : strtoupper($s);
    $s = strtr($s, [
        "À" => "A", "Á" => "A", "Â" => "A", "Ä" => "A", "Ã" => "A", "Å" => "A", "Ā" => "A", "Ą" => "A", "Æ" => "AE",
        "Ç" => "C", "Ć" => "C", "Č" => "C", "Ď" => "D", "Đ" => "D",
        "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "Ē" => "E", "Ę" => "E", "Ě" => "E",
        "Ğ" => "G", "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "İ" => "I",
        "Ł" => "L", "Ñ" => "N", "Ń" => "N", "Ň" => "N",
        "Ò" => "O", "Ó" => "O", "Ô" => "O", "Ö" => "O", "Õ" => "O", "Ø" => "O", "Ō" => "O", "Œ" => "OE",
        "Ř" => "R", "Š" => "S", "Ś" => "S", "Ş" => "S", "ß" => "SS", "Ť" => "T", "Ţ" => "T",
        "Ù" => "U", "Ú" => "U", "Û" => "U", "Ü" => "U", "Ū" => "U", "Ů" => "U",
        "Ý" => "Y", "Ÿ" => "Y", "Ž" => "Z", "Ź" => "Z", "Ż" => "Z",
        "'" => "", "’" => "", "`" => "", "´" => "",
    ]);
    $s = preg_replace("/[^A-Z0-9]+/", " ", $s);
    return trim((string) $s);
}

/** @return string[] */
function afm_tokens(?string $s): array
{
    $n = afm_norm($s);
    return $n === "" ? [] : explode(" ", $n);
}

// ------------------------------------------------------------
// Squadre
// ------------------------------------------------------------

/** Token "decorativi" dei nomi di club, ininfluenti per il confronto. */
function afm_stop_squadra(): array
{
    return ["FC", "AC", "AS", "SS", "SSC", "US", "ACF", "USD", "CALCIO", "BC", "SPA", "SRL", "1913", "1909", "1927", "1928", "1919", "1920", "1900"];
}

/** Chiave di confronto di un nome di squadra (senza token decorativi, con alias). */
function afm_chiave_squadra(?string $nome): string
{
    $alias = ["INTERNAZIONALE" => "INTER", "INTERNAZIONALE MILANO" => "INTER", "INTER MILAN" => "INTER"];
    $tok = array_values(array_diff(afm_tokens($nome), afm_stop_squadra()));
    $k = implode(" ", $tok);
    return $alias[$k] ?? $k;
}

/** Affinità 0-100 tra un nome squadra del progetto e uno di API-Football. */
function afm_punteggio_squadra(string $nomeDb, string $nomeApi): int
{
    $a = afm_chiave_squadra($nomeDb);
    $b = afm_chiave_squadra($nomeApi);
    if ($a === "" || $b === "") return 0;
    if ($a === $b) return 100;
    $ta = explode(" ", $a);
    $tb = explode(" ", $b);
    if (!array_diff($ta, $tb) || !array_diff($tb, $ta)) return 90;   // es. VERONA ⊂ HELLAS VERONA
    $max = max(strlen($a), strlen($b));
    if ($max >= 5 && levenshtein($a, $b) <= 2) return 70;
    return 0;
}

/**
 * Proposte di abbinamento squadre.
 * @param array<int,string> $apiSquadre  api_team_id => nome API
 * @param string[]          $squadreDb   nomi del progetto
 * @return array<int,array{squadra:string,punteggio:int}>  api_team_id => proposta (solo ≥ 90, uno a uno)
 */
function afm_proponi_squadre(array $apiSquadre, array $squadreDb): array
{
    $coppie = [];
    foreach ($apiSquadre as $idApi => $nomeApi) {
        foreach ($squadreDb as $nomeDb) {
            $p = afm_punteggio_squadra($nomeDb, $nomeApi);
            if ($p >= 90) $coppie[] = [$p, (int) $idApi, $nomeDb];
        }
    }
    usort($coppie, fn($x, $y) => $y[0] <=> $x[0]);
    $out = [];
    $usateDb = [];
    foreach ($coppie as [$p, $idApi, $nomeDb]) {
        if (isset($out[$idApi]) || isset($usateDb[$nomeDb])) continue;
        $out[$idApi] = ["squadra" => $nomeDb, "punteggio" => $p];
        $usateDb[$nomeDb] = true;
    }
    return $out;
}

// ------------------------------------------------------------
// Giocatori
// ------------------------------------------------------------

/** $t è coperto da un token di $altri: uguale, oppure (se breve, es. "LU") ne è il prefisso. */
function afm_copre(string $t, array $altri): bool
{
    foreach ($altri as $u) {
        if ($u === $t) return true;
        if (strlen($t) <= 3 && $t !== "" && strncmp($u, $t, strlen($t)) === 0) return true;
    }
    return false;
}

/**
 * Affinità 0-100 tra il nome sul listone ("MARTINEZ L.", "Dimarco", "PELLEGRINI LU.")
 * e quello di API-Football ("L. Martínez", "Federico Dimarco", "Luca Pellegrini").
 *   100 stessi token · 95 reciprocamente coperti · 90 il nome del listone è contenuto
 *   in quello API · 85 il contrario · 75 differenza di 1-2 caratteri · 20 iniziali
 *   incompatibili · 0 nessuna affinità.
 */
function afm_punteggio_giocatore(string $nomeDb, string $nomeApi): int
{
    $a = afm_tokens($nomeDb);
    $b = afm_tokens($nomeApi);
    if (!$a || !$b) return 0;

    $fortiA = array_values(array_filter($a, fn($t) => strlen($t) >= 2));
    $iniA   = array_values(array_filter($a, fn($t) => strlen($t) === 1));
    $fortiB = array_values(array_filter($b, fn($t) => strlen($t) >= 2));
    $iniB   = array_values(array_filter($b, fn($t) => strlen($t) === 1));
    if (!$fortiA || !$fortiB) return 0;

    $sa = $a; sort($sa);
    $sb = $b; sort($sb);
    if ($sa === $sb) return 100;

    // Iniziali: devono corrispondere al nome di battesimo dell'altro lato (se c'è)
    $controllo = function (array $ini, array $forti, array $altriTok) {
        if (!$ini) return true;
        $resto = array_values(array_filter($altriTok, fn($u) => !afm_copre_inverso($u, $forti)));
        if (!$resto) return true;   // niente da verificare
        foreach ($ini as $i) {
            $trovato = false;
            foreach ($resto as $u) if ($u[0] === $i) { $trovato = true; break; }
            if (!$trovato) return false;
        }
        return true;
    };
    if (!$controllo($iniA, $fortiA, $b) || !$controllo($iniB, $fortiB, $a)) return 20;

    $coperteA = true;
    foreach ($fortiA as $t) if (!afm_copre($t, $b)) { $coperteA = false; break; }
    $coperteB = true;
    foreach ($fortiB as $t) if (!afm_copre($t, $a)) { $coperteB = false; break; }

    if ($coperteA && $coperteB) return 95;
    if ($coperteA) return 90;
    if ($coperteB) return 85;

    $x = implode(" ", $fortiA);
    $y = implode(" ", $fortiB);
    $min = min(strlen($x), strlen($y));
    if ($min >= 5) {
        $dist = levenshtein($x, $y);
        if ($dist <= (max(strlen($x), strlen($y)) >= 9 ? 2 : 1)) return 75;
    }
    return 0;
}

/** $u è il token (o la sua versione estesa) di uno dei $forti: serve a isolare i "nomi di battesimo". */
function afm_copre_inverso(string $u, array $forti): bool
{
    foreach ($forti as $t) {
        if ($u === $t) return true;
        if (strlen($t) <= 3 && strncmp($u, $t, strlen($t)) === 0) return true;
    }
    return false;
}

/** Ruolo del listone (1 P, 2 D, 3 C, 4 A) compatibile con la posizione di API-Football? */
function afm_ruolo_compatibile(int $ruoloDb, ?string $posizioneApi): bool
{
    $mappa = ["GOALKEEPER" => 1, "DEFENDER" => 2, "MIDFIELDER" => 3, "ATTACKER" => 4];
    $p = $mappa[strtoupper(trim((string) $posizioneApi))] ?? null;
    return $p === null || $ruoloDb === 0 || $p === $ruoloDb;
}

/**
 * Proposte di abbinamento dei giocatori.
 *
 * @param array<int,array{nome:string,ruolo:int,squadra:string}> $db   id_giocatore => dati listone
 *        (solo giocatori ANCORA da associare)
 * @param array<int,array{nome:string,team_id:?int,ruolo:string}> $api api_player_id => dati API
 *        (solo giocatori API ANCORA liberi)
 * @param array<string,int> $teamPerSquadra  chiave afm_chiave_squadra(squadra) => api_team_id
 * @param int $sogliaAuto  punteggio minimo per l'abbinamento automatico (default 85)
 * @return array<int,array{api_player_id:?int,punteggio:int,auto:bool,candidati:array}>
 *         id_giocatore => proposta; "auto" = abbinamento da applicare senza revisione
 */
function afm_proponi_giocatori(array $db, array $api, array $teamPerSquadra, int $sogliaAuto = 85): array
{
    // Indice dei giocatori API per squadra e per token del nome (ricerca fuori squadra)
    $perTeam = [];
    $perToken = [];
    foreach ($api as $idApi => $g) {
        $perTeam[(int) ($g["team_id"] ?? 0)][] = (int) $idApi;
        foreach (afm_tokens($g["nome"]) as $t) {
            if (strlen($t) >= 3) $perToken[$t][(int) $idApi] = true;
        }
    }

    $punteggia = function (array $g, int $idApi, int $penalita) use ($api): int {
        $a = $api[$idApi];
        $p = afm_punteggio_giocatore($g["nome"], $a["nome"]);
        if ($p === 0) return 0;
        if (!afm_ruolo_compatibile((int) $g["ruolo"], $a["ruolo"])) $p -= 3;   // i ruoli del listone e di API-Football spesso divergono: solo spareggio
        return max(0, $p - $penalita);
    };

    $proposte = [];
    foreach ($db as $idDb => $g) {
        $teamApi = $teamPerSquadra[afm_chiave_squadra($g["squadra"])] ?? null;
        $cand = [];
        if ($teamApi !== null) {
            foreach ($perTeam[$teamApi] ?? [] as $idApi) {
                $p = $punteggia($g, $idApi, 0);
                if ($p >= 60) $cand[$idApi] = $p;
            }
        }
        $migliore = $cand ? max($cand) : 0;
        // Nessun buon candidato nella squadra attesa: cerca ovunque (trasferimenti,
        // squadra del listone non aggiornata), con una penalità di 10 punti.
        if ($migliore < $sogliaAuto) {
            $altri = [];
            foreach (afm_tokens($g["nome"]) as $t) {
                if (strlen($t) >= 3 && isset($perToken[$t])) $altri += $perToken[$t];
            }
            foreach (array_keys($altri) as $idApi) {
                if ((int) ($api[$idApi]["team_id"] ?? 0) === (int) $teamApi) continue;
                $p = $punteggia($g, (int) $idApi, 10);
                if ($p >= 60 && (!isset($cand[$idApi]) || $cand[$idApi] < $p)) $cand[(int) $idApi] = $p;
            }
        }
        arsort($cand);
        $top = array_slice($cand, 0, 3, true);
        $candidati = [];
        foreach ($top as $idApi => $p) $candidati[] = ["api_player_id" => (int) $idApi, "punteggio" => $p];

        $primo   = $candidati[0] ?? null;
        $secondo = $candidati[1] ?? null;
        $auto = $primo !== null && $primo["punteggio"] >= $sogliaAuto
             && ($secondo === null || $primo["punteggio"] - $secondo["punteggio"] >= 10);

        $proposte[(int) $idDb] = [
            "api_player_id" => $primo["api_player_id"] ?? null,
            "punteggio"     => $primo["punteggio"] ?? 0,
            "auto"          => $auto,
            "candidati"     => $candidati,
        ];
    }

    // Un giocatore API può essere assegnato a un solo giocatore del listone:
    // in caso di conflitto vince il punteggio più alto, gli altri tornano "da rivedere".
    $perApi = [];
    foreach ($proposte as $idDb => $p) {
        if ($p["auto"]) $perApi[$p["api_player_id"]][] = $idDb;
    }
    foreach ($perApi as $idApi => $lista) {
        if (count($lista) < 2) continue;
        usort($lista, fn($x, $y) => $proposte[$y]["punteggio"] <=> $proposte[$x]["punteggio"]);
        $primoPunt = $proposte[$lista[0]]["punteggio"];
        $pari = isset($lista[1]) && $proposte[$lista[1]]["punteggio"] === $primoPunt;
        foreach ($lista as $i => $idDb) {
            if ($i > 0 || $pari) $proposte[$idDb]["auto"] = false;
        }
    }
    return $proposte;
}

// ------------------------------------------------------------
// Accesso al DB
// ------------------------------------------------------------

/** Nomi delle squadre di Serie A della stagione (da calendario e listone). @return string[] */
function afm_squadre_db(int $stagione): array
{
    $nomi = [];
    foreach (query_all("SELECT squadra_casa AS s FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione
                        UNION SELECT squadra_ospite FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione") as $r) {
        $nomi[$r["s"]] = true;
    }
    if (!$nomi) {
        foreach (query_all("SELECT DISTINCT squadra_serie_a AS s FROM NEW_GIOCATORI_BASE_ASTA
                            WHERE stagione = $stagione AND squadra_serie_a <> ''") as $r) {
            $nomi[$r["s"]] = true;
        }
    }
    $out = array_keys($nomi);
    sort($out);
    return $out;
}

/**
 * Giocatori del listone con la squadra attuale: quella del listone, sostituita dall'ultima
 * squadra con cui compaiono in NEW_VOTI_SERIE_A (gestisce i trasferimenti, come
 * AvversarieSerieA.php).
 * @return array<int,array{nome:string,ruolo:int,squadra:string}>
 */
function afm_giocatori_db(int $stagione): array
{
    $out = [];
    foreach (query_all("SELECT id_giocatore, nome, ruolo, squadra_serie_a FROM NEW_GIOCATORI_BASE_ASTA
                        WHERE stagione = $stagione") as $r) {
        $out[(int) $r["id_giocatore"]] = ["nome" => (string) $r["nome"], "ruolo" => (int) $r["ruolo"],
                                          "squadra" => (string) $r["squadra_serie_a"]];
    }
    foreach (query_all("SELECT id_giocatore, squadra FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND ruolo <> 'ALL' ORDER BY giornata") as $r) {
        $id = (int) $r["id_giocatore"];
        if (isset($out[$id]) && $r["squadra"] !== "") $out[$id]["squadra"] = (string) $r["squadra"];
    }
    return $out;
}

/** Mappa afm_chiave_squadra(squadra del progetto) => api_team_id, per le squadre già associate. */
function afm_team_per_squadra(int $stagione): array
{
    $m = [];
    foreach (query_all("SELECT api_team_id, squadra_serie_a FROM NEW_API_MAPPING_SQUADRE
                        WHERE stagione = $stagione AND squadra_serie_a IS NOT NULL") as $r) {
        $m[afm_chiave_squadra($r["squadra_serie_a"])] = (int) $r["api_team_id"];
    }
    return $m;
}
