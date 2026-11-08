<?php
// ============================================================
// backend/lib/RaccordoGiocatori.php
//
// Raccordo per id dei giocatori tra le stagioni.
//
// NEW_GIOCATORI.id vale solo dentro la propria stagione. Il raccordo
// assegna ad ogni (stagione, id_giocatore) una PERSONA stabile
// (NEW_GIOCATORI_ANAGRAFICA.id_anagrafica) tramite
// NEW_GIOCATORI_RACCORDO, cosi' lo storico non dipende piu' dal nome.
//
// NON usa NEW_API_MAPPING_GIOCATORI (ne' alcun dato API-Football).
//
// REGOLE DI COLLEGAMENTO AUTOMATICO (stagione S rispetto a P, cioe' la
// stagione PIU' RECENTE precedente a S in cui compare lo stesso nome: non
// serve che sia consecutiva, cosi' si agganciano anche i giocatori assenti
// per una o piu' stagioni):
//   - nome non presente in nessuna stagione precedente -> nuova persona
//   - nome presente piu' volte in S o in P (omonimi)   -> nuova persona,
//                                                         da_verificare = 'Y'
//   - nome unico in S e in P, stessa nazione           -> collegato
//   - nome unico in S e in P, nazione diversa/assente  -> collegato,
//                                                         da_verificare = 'Y'
//   - P non e' la stagione immediatamente precedente   -> collegato,
//     (salto di stagione)                                 da_verificare = 'Y'
//                                                         se RG_VERIFICA_SALTO
// NORMALIZZAZIONE DEL NOME (rg_norm): maiuscolo, senza accenti (MARTINEZ =
// MARTINEZ con accento), apostrofi eliminati (D'AMBROSIO = D’AMBROSIO =
// DAMBROSIO), punti e trattini trattati come spazi.
// INIZIALI: se in una stagione il nome e' abbreviato ("MARTINEZ L.") e in
// un'altra e' esteso ("MARTINEZ LAUTARO"), i due nomi si collegano quando il
// cognome coincide e l'iniziale corrisponde, ma SEMPRE con da_verificare = 'Y'
// (RG_VERIFICA_INIZIALI). Il confronto per iniziali scatta solo se il nome
// esatto non e' presente in nessuna stagione precedente e almeno uno dei due
// nomi e' abbreviato (due nomi estesi diversi non si collegano mai).
// VARIANTI DI SCRITTURA: se il nome esatto non e' presente in nessuna stagione
// precedente, si confrontano i nomi "compattati" (rg_chiave_compatta: senza
// spazi, apostrofi, punti e trattini): "DI MARCO" = "DIMARCO", "N'DRI" = "NDRI" =
// "N DRI". Il collegamento e' proposto con da_verificare = 'Y' e nota
// "Variante nome: ..." (RG_VERIFICA_VARIANTI), cosi' l'utente lo valuta.
// OMONIMI CERTIFICATI: rg_omonimi_certificati() ripropone le persone diverse,
// gia' certificate dall'utente (MANUALE e non da verificare), con nome uguale
// o molto simile; rg_rinomina() cambia il NOME del giocatore (NEW_GIOCATORI,
// NEW_GIOCATORI_SVINCOLATI, NEW_GIOCATORI_BASE_ASTA e nome canonico della
// persona), in una sola stagione o in tutte, per distinguere gli omonimi.
// Il ruolo e la squadra Serie A NON bloccano il collegamento (cambiano
// spesso): sono solo informazioni per chi verifica a mano.
// Un giocatore assente per una stagione intera, o che cambia nome
// (es. "MARTINEZ L." -> "MARTINEZ LAUTARO"), non si aggancia da solo:
// si risolve con "unisci" (admin/raccordo_giocatori.php).
//
// RACCORDO MULTIPLO PER STAGIONE: nelle stagioni passate lo stesso giocatore
// puo' comparire con PIU' id nella stessa stagione (ceduto da una squadra e
// acquistato da un'altra). Una persona puo' quindi avere piu' righe di
// raccordo nella stessa stagione; l'id PIU' BASSO e' il primo in ordine
// cronologico. L'allineamento automatico non li riunisce (stesso nome due
// volte = omonimia, da verificare): si collegano a mano con "unisci"/"collega".
//
// Le righe con metodo = 'MANUALE' (correzioni dell'amministratore) non
// vengono mai toccate dall'allineamento automatico.
// Nazione: NEW_GIOCATORI_BASE_ASTA.nazione, in mancanza quella di
// NEW_GIOCATORI / NEW_GIOCATORI_SVINCOLATI.
// ============================================================

require_once __DIR__ . "/RosaStorico.php";

const RG_T_ANAG = "NEW_GIOCATORI_ANAGRAFICA";
const RG_T_RACC = "NEW_GIOCATORI_RACCORDO";

// true = i collegamenti con salto di stagione (giocatore assente in una o piu'
// stagioni intermedie) sono segnati da verificare; false = collegati e basta.
const RG_VERIFICA_SALTO = true;

// true = i collegamenti trovati tramite iniziale (nome abbreviato in una delle
// due stagioni) sono segnati da verificare; false = collegati e basta.
const RG_VERIFICA_INIZIALI = true;

// true = i collegamenti trovati tramite variante di scrittura (spazi, apostrofi,
// punteggiatura diversi) sono segnati da verificare; false = collegati e basta.
const RG_VERIFICA_VARIANTI = true;

// Omonimi certificati: due nomi diversi si considerano "molto simili" se la
// distanza di Levenshtein tra i nomi compattati e' <= RG_SIMILI_DISTANZA e
// entrambi hanno almeno RG_SIMILI_LUNGHEZZA_MIN caratteri.
const RG_SIMILI_DISTANZA = 1;
const RG_SIMILI_LUNGHEZZA_MIN = 7;

// ── Utilita' ─────────────────────────────────────────────────

/** True se le due tabelle del raccordo esistono (migrazione eseguita). */
function rg_tabelle_esistono(): bool
{
    static $esiste = null;
    if ($esiste === null) {
        try {
            $r = query_one("SELECT COUNT(*) AS n FROM information_schema.tables
                            WHERE table_schema = DATABASE()
                              AND table_name IN ('" . RG_T_ANAG . "', '" . RG_T_RACC . "')");
            $esiste = $r && (int) $r["n"] === 2;
        } catch (Throwable $e) {
            $esiste = false;
        }
    }
    return $esiste;
}

/** Esegue una scrittura; in caso di errore lancia un'eccezione. */
function rg_esegui(string $sql): void
{
    global $conn;
    if (!mysqli_query($conn, $sql)) {
        throw new RuntimeException("Raccordo giocatori: " . mysqli_error($conn));
    }
}

function rg_esc(string $s): string
{
    global $conn;
    return mysqli_real_escape_string($conn, $s);
}

/** Nome (o nazione) normalizzato per i confronti: maiuscolo, senza accenti,
 *  apostrofi eliminati, punti e trattini come spazi, spazi compattati. */
function rg_norm(?string $s): string
{
    $s = (string) $s;
    // Testo non UTF-8 (es. latin1 letto senza conversione): lo porto in UTF-8
    if ($s !== "" && !preg_match('//u', $s)) {
        $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1') : utf8_encode($s);
    }
    $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    // Accenti e lettere speciali piu' comuni nei nomi dei calciatori
    $s = strtr($s, [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
        'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C',
        'Ď' => 'D', 'Đ' => 'D', 'Ð' => 'D',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ę' => 'E', 'Ě' => 'E',
        'Ğ' => 'G',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'İ' => 'I', 'Ī' => 'I',
        'Ł' => 'L', 'Ľ' => 'L',
        'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ő' => 'O',
        'Ř' => 'R',
        'Ś' => 'S', 'Š' => 'S', 'Ş' => 'S', 'Ș' => 'S',
        'Ť' => 'T', 'Ţ' => 'T', 'Ț' => 'T',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ů' => 'U', 'Ű' => 'U',
        'Ý' => 'Y', 'Ÿ' => 'Y',
        'Ź' => 'Z', 'Ž' => 'Z', 'Ż' => 'Z',
        'à' => 'A',
        'á' => 'A',
        'â' => 'A',
        'ã' => 'A',
        'ä' => 'A',
        'å' => 'A',
        'ā' => 'A',
        'ă' => 'A',
        'ą' => 'A',
        'ç' => 'C',
        'ć' => 'C',
        'č' => 'C',
        'ď' => 'D',
        'đ' => 'D',
        'ð' => 'D',
        'è' => 'E',
        'é' => 'E',
        'ê' => 'E',
        'ë' => 'E',
        'ē' => 'E',
        'ę' => 'E',
        'ě' => 'E',
        'ğ' => 'G',
        'ì' => 'I',
        'í' => 'I',
        'î' => 'I',
        'ï' => 'I',
        'i̇' => 'I',
        'ī' => 'I',
        'ł' => 'L',
        'ľ' => 'L',
        'ñ' => 'N',
        'ń' => 'N',
        'ň' => 'N',
        'ò' => 'O',
        'ó' => 'O',
        'ô' => 'O',
        'õ' => 'O',
        'ö' => 'O',
        'ø' => 'O',
        'ő' => 'O',
        'ř' => 'R',
        'ś' => 'S',
        'š' => 'S',
        'ş' => 'S',
        'ș' => 'S',
        'ť' => 'T',
        'ţ' => 'T',
        'ț' => 'T',
        'ù' => 'U',
        'ú' => 'U',
        'û' => 'U',
        'ü' => 'U',
        'ů' => 'U',
        'ű' => 'U',
        'ý' => 'Y',
        'ÿ' => 'Y',
        'ź' => 'Z',
        'ž' => 'Z',
        'ż' => 'Z',
        'æ' => 'AE',
        'œ' => 'OE',
        'ß' => 'SS', 'Æ' => 'AE', 'Œ' => 'OE',
    ]);
    // Apostrofi (di ogni tipo): eliminati
    $s = str_replace(["'", "’", "‘", "`", "´", "ʼ"], "", $s);
    // Punti, trattini e altra punteggiatura: spazio
    $s = preg_replace('/[.\-–—_,;:]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Chiave "abbreviata" del nome: tutti i token tranne l'ultimo + la sola
 * iniziale dell'ultimo. "MARTINEZ L" e "MARTINEZ LAUTARO" -> "MARTINEZ L".
 * $iniziale = true se l'ultimo token del nome e' gia' una sola lettera.
 * Nomi di un solo token: nessuna chiave (stringa vuota).
 */
function rg_chiave_abbreviata(string $nome, ?bool &$iniziale = null): string
{
    $t = explode(" ", rg_norm($nome));
    if (count($t) < 2) { $iniziale = false; return ""; }
    $ultimo = array_pop($t);
    $iniziale = (strlen($ultimo) === 1);
    return implode(" ", $t) . " " . substr($ultimo, 0, 1);
}

/**
 * Chiave "compatta" del nome: rg_norm senza alcuno spazio.
 * "Di Marco", "DIMARCO" -> "DIMARCO"; "N'Dri", "Ndri", "N Dri" -> "NDRI".
 */
function rg_chiave_compatta(?string $nome): string
{
    return str_replace(" ", "", rg_norm($nome));
}

/** Stagioni con giocatori, in ordine crescente. */
function rg_stagioni(): array
{
    $righe = query_all("SELECT DISTINCT stagione FROM (
                            SELECT stagione FROM NEW_GIOCATORI
                            UNION
                            SELECT stagione FROM NEW_GIOCATORI_SVINCOLATI
                        ) x ORDER BY stagione");
    return array_map(fn($r) => (int) $r["stagione"], $righe);
}

/** Giocatori della stagione (in rosa + svincolati) con nazione e Serie A. */
function rg_giocatori_stagione(int $stagione): array
{
    $righe = query_all("SELECT a.id, a.descrizione, a.ruolo,
                               COALESCE(NULLIF(b.nazione, ''), a.nazione) AS nazione,
                               b.squadra_serie_a
                        FROM " . rs_sql_anagrafica($stagione) . " a
                        LEFT JOIN NEW_GIOCATORI_BASE_ASTA b
                               ON b.stagione = a.stagione AND b.id_giocatore = a.id
                        ORDER BY a.id");
    $out = [];
    foreach ($righe as $r) {
        $id = (int) $r["id"];
        if (isset($out[$id])) continue;
        $out[$id] = [
            "id"              => $id,
            "nome"            => trim((string) $r["descrizione"]),
            "ruolo"           => (int) $r["ruolo"],
            "nazione"         => trim((string) $r["nazione"]),
            "squadra_serie_a" => $r["squadra_serie_a"] ?? null,
        ];
    }
    return $out;
}

/** Stagione precedente a $stagione (la piu' recente con giocatori), o null. */
function rg_stagione_precedente(int $stagione): ?int
{
    $prec = null;
    foreach (rg_stagioni() as $s) {
        if ($s < $stagione) $prec = $s;
    }
    return $prec;
}

/** Elimina le persone senza alcun giocatore collegato. */
function rg_pulisci_orfane(): int
{
    global $conn;
    rg_esegui("DELETE FROM " . RG_T_ANAG . "
               WHERE id_anagrafica NOT IN (SELECT DISTINCT id_anagrafica FROM " . RG_T_RACC . ")");
    return (int) mysqli_affected_rows($conn);
}

function rg_nuova_persona(string $nome): int
{
    global $conn;
    rg_esegui("INSERT INTO " . RG_T_ANAG . " (nome, creato_il) VALUES ('" . rg_esc($nome) . "', NOW())");
    return (int) mysqli_insert_id($conn);
}

// ── Allineamento automatico ──────────────────────────────────

/**
 * Collega ai raccordi i giocatori della stagione non ancora collegati.
 * Idempotente: non tocca le righe esistenti (AUTO o MANUALE); elimina solo
 * quelle che puntano a giocatori non piu' presenti nella stagione.
 *
 * @return array contatori: giocatori, gia_collegati, collegati, nuove_persone,
 *               omonimi, varianti, da_verificare, rimossi
 */
function rg_allinea(int $stagione): array
{
    $giocatori = rg_giocatori_stagione($stagione);
    $esito = ["stagione" => $stagione, "giocatori" => count($giocatori), "gia_collegati" => 0,
              "collegati" => 0, "nuove_persone" => 0, "omonimi" => 0, "varianti" => 0, "da_verificare" => 0, "rimossi" => 0];

    // 1. Righe orfane (giocatore non piu' in anagrafica della stagione)
    global $conn;
    if ($giocatori) {
        rg_esegui("DELETE FROM " . RG_T_RACC . " WHERE stagione = $stagione
                   AND id_giocatore NOT IN (" . implode(",", array_keys($giocatori)) . ")");
    } else {
        rg_esegui("DELETE FROM " . RG_T_RACC . " WHERE stagione = $stagione");
    }
    $esito["rimossi"] = (int) mysqli_affected_rows($conn);

    // 2. Stagioni precedenti (tutte, non solo la consecutiva): allineate per prime se serve
    $precStagioni = [];
    foreach (rg_stagioni() as $sp) if ($sp < $stagione) $precStagioni[] = $sp;
    $prec = $precStagioni ? end($precStagioni) : null;   // stagione immediatamente precedente
    $gioc = [];          // stagione => giocatori
    $persona = [];       // stagione => [id_giocatore => id_anagrafica]
    foreach ($precStagioni as $sp) {
        $gioc[$sp] = rg_giocatori_stagione($sp);
        $n = query_one("SELECT COUNT(*) AS n FROM " . RG_T_RACC . " WHERE stagione = $sp");
        if ($gioc[$sp] && (int) $n["n"] < count($gioc[$sp])) rg_allinea($sp);
        $persona[$sp] = [];
        foreach (query_all("SELECT id_giocatore, id_anagrafica FROM " . RG_T_RACC . " WHERE stagione = $sp") as $r) {
            $persona[$sp][(int) $r["id_giocatore"]] = (int) $r["id_anagrafica"];
        }
    }

    // 3. Gia' collegati nella stagione
    $collegati = [];     // id_giocatore => id_anagrafica
    foreach (query_all("SELECT id_giocatore, id_anagrafica FROM " . RG_T_RACC . " WHERE stagione = $stagione") as $r) {
        $collegati[(int) $r["id_giocatore"]] = (int) $r["id_anagrafica"];
    }
    $esito["gia_collegati"] = count($collegati);
    $personeUsate = array_flip($collegati);   // id_anagrafica gia' presenti nella stagione

    // 4. Indici dei nomi: nella stagione e, per ogni nome, l'ultima stagione precedente in cui compare
    $perNomeS = [];
    foreach ($giocatori as $g) $perNomeS[rg_norm($g["nome"])][] = $g["id"];
    $ultimo = [];        // nome => ["stagione" => S, "ids" => [id, ...]]  (stagione piu' recente)
    foreach ($precStagioni as $sp) {                       // ordine crescente: l'ultima sovrascrive
        $tmp = [];
        foreach ($gioc[$sp] as $g) $tmp[rg_norm($g["nome"])][] = $g["id"];
        foreach ($tmp as $k => $ids) $ultimo[$k] = ["stagione" => $sp, "ids" => $ids];
    }

    // Indice per nome abbreviato: chiave => [[stagione, id, e_iniziale], ...]
    $perAbbr = [];
    foreach ($precStagioni as $sp) {
        foreach ($gioc[$sp] as $g) {
            $kk = rg_chiave_abbreviata($g["nome"], $ini);
            if ($kk !== "") $perAbbr[$kk][] = [$sp, $g["id"], $ini];
        }
    }

    // Indice per nome compatto (senza spazi/apostrofi): nella stagione e nelle precedenti
    $perCompS = [];      // chiave compatta => [id, ...] nella stagione
    foreach ($giocatori as $g) {
        $kc = rg_chiave_compatta($g["nome"]);
        if ($kc !== "") $perCompS[$kc][] = $g["id"];
    }
    $perComp = [];       // chiave compatta => [[stagione, id], ...] nelle stagioni precedenti
    foreach ($precStagioni as $sp) {
        foreach ($gioc[$sp] as $g) {
            $kc = rg_chiave_compatta($g["nome"]);
            if ($kc !== "") $perComp[$kc][] = [$sp, $g["id"]];
        }
    }

    // 5. Nuovi collegamenti
    $righe = [];
    foreach ($giocatori as $id => $g) {
        if (isset($collegati[$id])) continue;

        $chiave = rg_norm($g["nome"]);
        $stPrec = $ultimo[$chiave]["stagione"] ?? null;    // stagione in cui il nome compare l'ultima volta
        $cand   = $ultimo[$chiave]["ids"] ?? [];
        $viaIniziali = false;
        $viaVariante = false;
        $kcG = rg_chiave_compatta($g["nome"]);
        if ($kcG !== "" && isset($perComp[$kcG])) {
            // Stesso nome scritto in modo diverso (DI MARCO <-> DIMARCO, N'DRI <-> NDRI): stessi
            // caratteri, spazi/punteggiatura diversi. Si usa se non c'e' un nome identico oppure se la
            // variante compare in una stagione PIU' RECENTE dell'ultimo nome identico (cosi' non si
            // segnala un falso salto di stagione).
            $trovati = [];   // stagione => [id, ...]
            foreach ($perComp[$kcG] as [$spC, $idC]) $trovati[$spC][] = $idC;
            $stC = max(array_keys($trovati));
            if (!$cand || $stC > $stPrec) {
                $candC = $trovati[$stC];
                $pC = array_values(array_unique(array_map(fn($i) => $persona[$stC][$i] ?? 0, $candC)));
                $pE = $cand ? array_values(array_unique(array_map(fn($i) => $persona[$stPrec][$i] ?? 0, $cand))) : [];
                // Variante "vera" solo se non e' gia' la stessa persona del nome identico
                $viaVariante = !($cand && count($pC) === 1 && $pC === $pE);
                $stPrec = $stC;
                $cand   = $candC;
            }
        }
        if (!$cand) {
            // Nessun nome identico ne' variante: provo con le iniziali (MARTINEZ L. <-> MARTINEZ LAUTARO).
            // Serve che almeno uno dei due nomi sia abbreviato.
            $kk = rg_chiave_abbreviata($g["nome"], $iniG);
            if ($kk !== "" && isset($perAbbr[$kk])) {
                $trovati = [];   // stagione => [id, ...]
                foreach ($perAbbr[$kk] as [$spA, $idA, $iniA]) {
                    if ($iniG || $iniA) $trovati[$spA][] = $idA;
                }
                if ($trovati) {
                    $stPrec = max(array_keys($trovati));
                    $cand   = $trovati[$stPrec];
                    $viaIniziali = true;
                }
            }
        }
        $pPers  = $stPrec !== null ? $persona[$stPrec] : [];
        // Giocatori della stagione con lo stesso nome (o, per le varianti, con lo stesso nome compatto)
        $stessi = $viaVariante ? ($perCompS[$kcG] ?? [$id]) : $perNomeS[$chiave];
        $nS     = count($stessi);
        // Persone distinte tra i candidati della stagione precedente (piu' id
        // della stessa persona, es. cessione/acquisto, contano una volta sola)
        $personeCand = array_values(array_unique(array_map(fn($i) => $pPers[$i] ?? 0, $cand)));
        $idPers = 0;
        $verif  = "N";
        $nota   = null;

        if (!$cand) {
            $idPers = rg_nuova_persona($g["nome"]);
            $esito["nuove_persone"]++;
        } elseif ($nS > 1 || count($personeCand) > 1) {
            // Stesso nome piu' volte nella stagione (omonimi o cessione/acquisto)
            // o persone diverse nella precedente: nessun collegamento automatico
            $trasf = false;
            foreach ($stessi as $altro) {
                if ($altro !== $id
                    && $giocatori[$altro]["ruolo"] === $g["ruolo"]
                    && rg_norm($giocatori[$altro]["nazione"]) === rg_norm($g["nazione"])) $trasf = true;
            }
            $idPers = rg_nuova_persona($g["nome"]);
            $verif  = "Y";
            $nota   = ($viaIniziali ? "Iniziale ambigua" : ($viaVariante ? "Variante ambigua" : "Omonimia")) . ": $nS in $stagione" . ($trasf ? " (stesso ruolo e nazione: forse cessione/acquisto)" : "")
                    . ", " . count($personeCand) . " persone in $stPrec";
            $esito["nuove_persone"]++;
            $esito["omonimi"]++;
        } else {
            $idPrec = $personeCand[0];
            $rif    = $gioc[$stPrec][max($cand)];
            if ($idPrec <= 0 || isset($personeUsate[$idPrec])) {
                // Persona precedente non raccordata o gia' collegata a un altro giocatore
                $idPers = rg_nuova_persona($g["nome"]);
                $verif  = "Y";
                $nota   = $idPrec <= 0 ? "Persona della stagione $stPrec non raccordata"
                                       : "Persona gia' collegata a un altro giocatore della stagione";
                $esito["nuove_persone"]++;
            } else {
                $idPers = $idPrec;
                $nazS = rg_norm($g["nazione"]);
                $nazP = rg_norm($rif["nazione"]);
                if ($nazS === "" || $nazP === "" || $nazS !== $nazP) {
                    $verif = "Y";
                    $nota  = "Nazione diversa o mancante (" . ($rif["nazione"] ?: "?") . " -> " . ($g["nazione"] ?: "?") . ")";
                }
                if ($viaIniziali) {
                    // Nome abbreviato in una delle due stagioni
                    $nomeP = $rif["nome"];
                    if (RG_VERIFICA_INIZIALI) $verif = "Y";
                    $nota  = "Iniziale: " . $nomeP . " (" . $stPrec . ")" . ($nota !== null ? "; " . $nota : "");
                }
                if ($viaVariante) {
                    // Stesso nome scritto in modo diverso (spazi, apostrofi, punteggiatura)
                    if (RG_VERIFICA_VARIANTI) $verif = "Y";
                    $nota  = "Variante nome: " . $rif["nome"] . " (" . $stPrec . ")" . ($nota !== null ? "; " . $nota : "");
                    $esito["varianti"]++;
                }
                if (RG_VERIFICA_SALTO && $stPrec !== $prec) {
                    // Il giocatore manca in una o piu' stagioni intermedie
                    $verif = "Y";
                    $nota  = "Salto: ultima presenza " . $stPrec . ($nota !== null ? "; " . $nota : "");
                }
                $esito["collegati"]++;
            }
        }

        if ($verif === "Y") $esito["da_verificare"]++;
        $personeUsate[$idPers] = true;
        $righe[] = "($stagione, $id, $idPers, 'AUTO', '$verif', " . ($nota === null ? "NULL" : "'" . rg_esc(substr($nota, 0, 100)) . "'") . ", NOW())";
    }

    foreach (array_chunk($righe, 200) as $blocco) {
        rg_esegui("INSERT INTO " . RG_T_RACC . "
                   (stagione, id_giocatore, id_anagrafica, metodo, da_verificare, nota, aggiornato_il)
                   VALUES " . implode(",", $blocco));
    }
    rg_pulisci_orfane();
    return $esito;
}

/**
 * Ricostruzione completa, dalla stagione piu' vecchia alla piu' recente.
 *
 * - $azzeraAuto = true : i collegamenti AUTO vengono rifatti da zero; quelli
 *   MANUALE (correzioni dell'amministratore) restano com'e'.
 * - $riesaminaManuali = true : a ricostruzione finita, i collegamenti MANUALE
 *   NON vengono toccati, ma quelli per cui la logica automatica propone una
 *   persona diversa (es. nuovo possibile collegamento con salto di stagione)
 *   vengono segnati da verificare. Vedi rg_riesamina_manuali().
 *
 * @param array|null $esitoManuali (out) riepilogo del riesame dei manuali,
 *                                 null se $riesaminaManuali = false
 * @return array esiti per stagione (come rg_allinea)
 */
function rg_ricostruisci(bool $azzeraAuto = true, bool $riesaminaManuali = true, ?array &$esitoManuali = null): array
{
    $esitoManuali = null;
    if ($azzeraAuto) {
        rg_esegui("DELETE FROM " . RG_T_RACC . " WHERE metodo = 'AUTO'");
        rg_pulisci_orfane();
    }
    $out = [];
    foreach (rg_stagioni() as $s) $out[] = rg_allinea($s);
    if ($riesaminaManuali) $esitoManuali = rg_riesamina_manuali();
    return $out;
}

/**
 * Riesame dei collegamenti MANUALE, senza modificarli.
 *
 * Per ogni riga MANUALE (non gia' da verificare) calcola cosa proporrebbe la
 * logica automatica se il giocatore non fosse collegato: stesso nome (o, in
 * mancanza, stesso cognome + iniziale) nell'ultima stagione precedente in cui
 * compare (o, in mancanza, stessa scrittura senza spazi/apostrofi), anche con salti di stagione. La riga viene segnata
 * da_verificare = 'Y' (nota "Auto propone persona #N (AAAA: NOME)") solo se:
 *   - esiste una proposta e la persona proposta e' diversa da quella attuale;
 *   - la persona attuale non ha gia' una presenza precedente con lo stesso
 *     nome/iniziale (cioe' il collegamento manuale e' coerente con i nomi);
 *   - il nome non e' ambiguo (omonimi nella stagione) e la persona proposta
 *     non e' gia' presente nella stessa stagione con un altro giocatore.
 * Le persone omonime nell'ultima stagione utile vengono segnalate come
 * "Auto: omonimi in AAAA (persone #a, #b)".
 * I manuali senza differenze restano esattamente come sono.
 *
 * @return array manuali (esaminati), con_differenze (righe segnate),
 *               gruppi (persone interessate)
 */
function rg_riesamina_manuali(): array
{
    global $conn;
    $es = ["manuali" => 0, "con_differenze" => 0, "gruppi" => 0];

    $righe = [];     // stagione => [id_giocatore => [persona, metodo, verif]]
    foreach (query_all("SELECT stagione, id_giocatore, id_anagrafica, metodo, da_verificare FROM " . RG_T_RACC) as $r) {
        $righe[(int) $r["stagione"]][(int) $r["id_giocatore"]] = [(int) $r["id_anagrafica"], $r["metodo"], $r["da_verificare"]];
    }

    $idxNome = [];   // nome normalizzato => [[stagione, id], ...]   (stagioni gia' viste)
    $idxAbbr = [];   // chiave abbreviata => [[stagione, id, e_iniziale], ...]
    $idxComp = [];   // chiave compatta => [[stagione, id], ...]
    $gruppi  = [];
    foreach (rg_stagioni() as $s) {
        $giocatori = rg_giocatori_stagione($s);
        $perNomeS = [];
        foreach ($giocatori as $g) $perNomeS[rg_norm($g["nome"])] = ($perNomeS[rg_norm($g["nome"])] ?? 0) + 1;
        $personeS = [];   // persone gia' presenti nella stagione
        foreach ($righe[$s] ?? [] as $v) $personeS[$v[0]] = true;

        foreach ($righe[$s] ?? [] as $id => [$pers, $metodo, $verif]) {
            if ($metodo !== "MANUALE" || $verif === "Y" || !isset($giocatori[$id])) continue;
            $es["manuali"]++;
            $g      = $giocatori[$id];
            $chiave = rg_norm($g["nome"]);
            if (($perNomeS[$chiave] ?? 0) > 1) continue;              // omonimi nella stagione: ambiguo

            // Presenze precedenti con lo stesso nome; in mancanza, con la stessa iniziale
            $voci = $idxNome[$chiave] ?? [];
            if (!$voci) {                                             // variante di scrittura (DI MARCO / DIMARCO)
                $kc = rg_chiave_compatta($g["nome"]);
                foreach ($idxComp[$kc] ?? [] as [$spC, $idC]) {
                    if ($kc !== "") $voci[] = [$spC, $idC];
                }
            }
            if (!$voci) {
                $kk = rg_chiave_abbreviata($g["nome"], $iniG);
                foreach ($idxAbbr[$kk] ?? [] as [$spA, $idA, $iniA]) {
                    if ($kk !== "" && ($iniG || $iniA)) $voci[] = [$spA, $idA];
                }
            }
            if (!$voci) continue;

            $tutte = [];                                              // persone di tutte le presenze
            $ultSt = 0;
            foreach ($voci as [$sp, $idp]) {
                $pp = $righe[$sp][$idp][0] ?? 0;
                if ($pp > 0) { $tutte[$pp] = true; $ultSt = max($ultSt, $sp); }
            }
            if (!$tutte || isset($tutte[$pers])) continue;            // nessuna proposta, o manuale coerente col nome

            $propo = [];                                              // persone dell'ultima stagione utile
            foreach ($voci as [$sp, $idp]) {
                $pp = $righe[$sp][$idp][0] ?? 0;
                if ($sp === $ultSt && $pp > 0) { $propo[$pp] = true; }
            }
            $propo = array_keys($propo);
            if (count($propo) === 1) {
                if (isset($personeS[$propo[0]])) continue;            // gia' presente in questa stagione con un altro giocatore
                $nota = "Auto propone persona #" . $propo[0] . " (" . $ultSt . ")";
            } else {
                sort($propo);
                $nota = "Auto: omonimi in " . $ultSt . " (persone #" . implode(", #", $propo) . ")";
            }
            rg_esegui("UPDATE " . RG_T_RACC . " SET da_verificare = 'Y', nota = '" . rg_esc(substr($nota, 0, 100)) . "',
                       aggiornato_il = NOW() WHERE stagione = $s AND id_giocatore = $id");
            $es["con_differenze"]++;
            $gruppi[$pers] = true;
        }

        // Indici aggiornati con la stagione appena esaminata
        foreach ($giocatori as $g) {
            $idxNome[rg_norm($g["nome"])][] = [$s, $g["id"]];
            $kk = rg_chiave_abbreviata($g["nome"], $ini);
            if ($kk !== "") $idxAbbr[$kk][] = [$s, $g["id"], $ini];
            $kc = rg_chiave_compatta($g["nome"]);
            if ($kc !== "") $idxComp[$kc][] = [$s, $g["id"]];
        }
    }
    $es["gruppi"] = count($gruppi);
    return $es;
}

// ── Lettura ──────────────────────────────────────────────────

/** id_anagrafica del giocatore nella stagione, o null se non raccordato. */
function rg_persona_di(int $stagione, int $idGiocatore): ?int
{
    $r = query_one("SELECT id_anagrafica FROM " . RG_T_RACC . "
                    WHERE stagione = $stagione AND id_giocatore = $idGiocatore LIMIT 1");
    return $r ? (int) $r["id_anagrafica"] : null;
}

/**
 * Edizioni della persona: [stagione => [id_giocatore, ...]], dalla stagione
 * piu' recente. In una stagione possono esserci piu' id (cessione/acquisto):
 * sono in ordine crescente, cioe' cronologico (l'id piu' basso e' il primo).
 */
function rg_edizioni(int $idAnagrafica): array
{
    $out = [];
    foreach (query_all("SELECT stagione, id_giocatore FROM " . RG_T_RACC . "
                        WHERE id_anagrafica = $idAnagrafica
                        ORDER BY stagione DESC, id_giocatore ASC") as $r) {
        $out[(int) $r["stagione"]][] = (int) $r["id_giocatore"];
    }
    return $out;
}

/** True se almeno un collegamento della persona e' da verificare. */
function rg_persona_da_verificare(int $idAnagrafica): bool
{
    $r = query_one("SELECT COUNT(*) AS n FROM " . RG_T_RACC . "
                    WHERE id_anagrafica = $idAnagrafica AND da_verificare = 'Y'");
    return $r && (int) $r["n"] > 0;
}

/** Dati descrittivi di un giocatore in una stagione. */
function rg_dettaglio_edizione(int $stagione, int $idGiocatore): ?array
{
    $r = query_one("SELECT a.id, a.descrizione, a.ruolo,
                           COALESCE(NULLIF(b.nazione, ''), a.nazione) AS nazione,
                           b.squadra_serie_a, s.nome AS squadra
                    FROM " . rs_sql_anagrafica($stagione) . " a
                    LEFT JOIN NEW_GIOCATORI_BASE_ASTA b
                           ON b.stagione = a.stagione AND b.id_giocatore = a.id
                    LEFT JOIN NEW_GIOCATORI g
                           ON g.stagione = a.stagione AND g.id = a.id
                    LEFT JOIN NEW_SQUADRE s
                           ON s.stagione = a.stagione AND s.id = g.squadra AND g.squadra > 0
                    WHERE a.id = $idGiocatore LIMIT 1");
    if (!$r) return null;
    return [
        "stagione"        => $stagione,
        "id_giocatore"    => (int) $r["id"],
        "nome"            => trim((string) $r["descrizione"]),
        "ruolo"           => (int) $r["ruolo"],
        "nazione"         => trim((string) $r["nazione"]),
        "squadra_serie_a" => $r["squadra_serie_a"] ?? null,
        "squadra"         => $r["squadra"] ?? null,
    ];
}

/** Riassunto di una persona: nome, stagioni, dati dell'ultima edizione. */
function rg_persona_riassunto(int $idAnagrafica): ?array
{
    $p = query_one("SELECT id_anagrafica, nome FROM " . RG_T_ANAG . " WHERE id_anagrafica = $idAnagrafica");
    if (!$p) return null;
    $ed = rg_edizioni($idAnagrafica);
    $ultima = null;
    // Ultima edizione: stagione piu' recente, e in essa l'id piu' alto (l'ultimo in ordine cronologico)
    foreach ($ed as $s => $ids) { $ultima = rg_dettaglio_edizione($s, max($ids)); break; }
    return [
        "id_anagrafica" => (int) $p["id_anagrafica"],
        "nome"          => trim((string) $p["nome"]),
        "stagioni"      => array_keys(array_reverse($ed, true)),
        "ultima"        => $ultima,
    ];
}

/** Persona con tutte le sue edizioni (per la pagina di verifica). */
function rg_persona_dettaglio(int $idAnagrafica): ?array
{
    $p = query_one("SELECT id_anagrafica, nome FROM " . RG_T_ANAG . " WHERE id_anagrafica = $idAnagrafica");
    if (!$p) return null;
    $edizioni = [];
    $conteggio = [];   // stagione => quanti id ha la persona in quella stagione
    $righe = query_all("SELECT stagione, id_giocatore, metodo, da_verificare, nota FROM " . RG_T_RACC . "
                        WHERE id_anagrafica = $idAnagrafica ORDER BY stagione DESC, id_giocatore ASC");
    foreach ($righe as $r) $conteggio[(int) $r["stagione"]] = ($conteggio[(int) $r["stagione"]] ?? 0) + 1;
    $progressivo = [];
    foreach ($righe as $r) {
        $st = (int) $r["stagione"];
        $progressivo[$st] = ($progressivo[$st] ?? 0) + 1;
        $d = rg_dettaglio_edizione($st, (int) $r["id_giocatore"]) ?? [
            "stagione" => $st, "id_giocatore" => (int) $r["id_giocatore"],
            "nome" => "(giocatore non piu' presente)", "ruolo" => 0, "nazione" => "",
            "squadra_serie_a" => null, "squadra" => null,
        ];
        $d["metodo"]           = $r["metodo"];
        $d["da_verificare"]    = $r["da_verificare"] === "Y";
        $d["nota"]             = $r["nota"];
        // Piu' id nella stessa stagione: il numero 1 (id piu' basso) e' il primo cronologicamente
        $d["ordine_stagione"]  = $progressivo[$st];
        $d["totale_stagione"]  = $conteggio[$st];
        $edizioni[] = $d;
    }
    return ["id_anagrafica" => (int) $p["id_anagrafica"], "nome" => trim((string) $p["nome"]), "edizioni" => $edizioni];
}

/** Persone il cui nome contiene il testo cercato (max 20). */
function rg_cerca_persone(string $q): array
{
    $q = trim($q);
    if (strlen($q) < 2) return [];
    $like = rg_esc(str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $q));
    $out = [];
    foreach (query_all("SELECT id_anagrafica FROM " . RG_T_ANAG . "
                        WHERE nome LIKE '%$like%' ORDER BY nome, id_anagrafica LIMIT 20") as $r) {
        $s = rg_persona_riassunto((int) $r["id_anagrafica"]);
        if ($s) $out[] = $s;
    }
    return $out;
}

/** Collegamenti da verificare, con le altre persone omonime (e le loro edizioni) come candidate. */
function rg_elenco_da_verificare(int $limite = 100): array
{
    $righe = query_all("SELECT r.stagione, r.id_giocatore, r.id_anagrafica, r.nota, a.nome
                        FROM " . RG_T_RACC . " r
                        JOIN " . RG_T_ANAG . " a ON a.id_anagrafica = r.id_anagrafica
                        WHERE r.da_verificare = 'Y'
                        ORDER BY r.stagione DESC, a.nome, r.id_giocatore
                        LIMIT " . max(1, min(500, $limite)));
    // Persone raggruppate per nome compatto: i candidati sono le persone con lo
    // stesso nome oppure con una variante di scrittura (DI MARCO / DIMARCO, N'DRI / NDRI)
    $perCompatto = [];
    foreach (query_all("SELECT id_anagrafica, nome FROM " . RG_T_ANAG . " ORDER BY id_anagrafica") as $a) {
        $kc = rg_chiave_compatta((string) $a["nome"]);
        if ($kc !== "") $perCompatto[$kc][] = (int) $a["id_anagrafica"];
    }
    $out = [];
    foreach ($righe as $r) {
        $idPers = (int) $r["id_anagrafica"];
        $cand = [];
        $ids = array_values(array_filter($perCompatto[rg_chiave_compatta((string) $r["nome"])] ?? [], fn($i) => $i !== $idPers));
        foreach (array_slice($ids, 0, 8) as $idC) {
            // Persona con tutte le edizioni (e i loro dati): servono per scegliere
            // stagione per stagione quali collegare
            $s = rg_persona_dettaglio($idC);
            if ($s) $cand[] = $s;
        }
        $out[] = [
            "giocatore"  => rg_dettaglio_edizione((int) $r["stagione"], (int) $r["id_giocatore"]),
            "persona"    => rg_persona_riassunto($idPers),
            "nota"       => $r["nota"],
            "candidati"  => $cand,
        ];
    }
    return $out;
}

/** Contatori per stagione e totali (pagina admin). */
function rg_stato(): array
{
    $stagioni = [];
    foreach (rg_stagioni() as $s) {
        $g = query_one("SELECT COUNT(*) AS n FROM " . rs_sql_anagrafica($s) . " x");
        $r = query_one("SELECT COUNT(*) AS n,
                               SUM(da_verificare = 'Y') AS verifica,
                               SUM(metodo = 'MANUALE') AS manuali
                        FROM " . RG_T_RACC . " WHERE stagione = $s");
        $stagioni[] = [
            "stagione"      => $s,
            "giocatori"     => (int) $g["n"],
            "raccordati"    => (int) $r["n"],
            "da_verificare" => (int) $r["verifica"],
            "manuali"       => (int) $r["manuali"],
        ];
    }
    $p = query_one("SELECT COUNT(*) AS n FROM " . RG_T_ANAG);
    return ["stagioni" => $stagioni, "persone" => (int) $p["n"]];
}

// ── Correzioni manuali ───────────────────────────────────────

/** Segna il collegamento come verificato (diventa MANUALE: l'automatico non lo tocca). */
function rg_conferma(int $stagione, int $idGiocatore): void
{
    if (rg_persona_di($stagione, $idGiocatore) === null) throw new InvalidArgumentException("Collegamento non trovato");
    rg_esegui("UPDATE " . RG_T_RACC . " SET metodo = 'MANUALE', da_verificare = 'N', nota = NULL, aggiornato_il = NOW()
               WHERE stagione = $stagione AND id_giocatore = $idGiocatore");
}

/**
 * Unisce due persone: tutti i giocatori di $idAssorbi passano a $idTieni.
 * Se entrambe hanno un giocatore nella stessa stagione, la persona risultante
 * avra' piu' id in quella stagione (cessione/acquisto): l'id piu' basso e' il
 * primo in ordine cronologico.
 */
function rg_unisci(int $idTieni, int $idAssorbi): void
{
    if ($idTieni === $idAssorbi) throw new InvalidArgumentException("Le due persone coincidono");
    foreach ([$idTieni, $idAssorbi] as $i) {
        if (!query_one("SELECT 1 AS x FROM " . RG_T_ANAG . " WHERE id_anagrafica = $i")) {
            throw new InvalidArgumentException("Persona $i non trovata");
        }
    }
    rg_esegui("UPDATE " . RG_T_RACC . " SET id_anagrafica = $idTieni, metodo = 'MANUALE',
                      da_verificare = 'N', nota = NULL, aggiornato_il = NOW()
               WHERE id_anagrafica = $idAssorbi");
    rg_pulisci_orfane();
}

/**
 * Unisce in un colpo solo piu' persone (tutte le loro stagioni) in una persona
 * da mantenere. Tutte le persone da assorbire vengono verificate prima e il
 * passaggio avviene con un unico UPDATE (o tutte o nessuna).
 *
 * @param int[] $idAssorbi persone che confluiscono in $idTieni
 * @return int numero di persone assorbite
 */
function rg_unisci_molti(int $idTieni, array $idAssorbi): int
{
    $ids = [];
    foreach ($idAssorbi as $i) {
        $i = (int) $i;
        if ($i > 0 && $i !== $idTieni) $ids[$i] = $i;
    }
    $ids = array_values($ids);
    if (!$ids) throw new InvalidArgumentException("Nessuna persona da unire");
    foreach (array_merge([$idTieni], $ids) as $i) {
        if (!query_one("SELECT 1 AS x FROM " . RG_T_ANAG . " WHERE id_anagrafica = $i")) {
            throw new InvalidArgumentException("Persona $i non trovata");
        }
    }
    rg_esegui("UPDATE " . RG_T_RACC . " SET id_anagrafica = $idTieni, metodo = 'MANUALE',
                      da_verificare = 'N', nota = NULL, aggiornato_il = NOW()
               WHERE id_anagrafica IN (" . implode(",", $ids) . ")");
    rg_pulisci_orfane();
    return count($ids);
}

/**
 * Collega UN SOLO giocatore (una stagione) a una persona esistente, senza
 * toccare le altre stagioni della persona di provenienza.
 * La persona di destinazione puo' avere gia' un altro giocatore nella stessa
 * stagione (cessione/acquisto): avra' piu' id, il piu' basso e' il primo.
 */
function rg_collega(int $stagione, int $idGiocatore, int $idAnagrafica): void
{
    $attuale = rg_persona_di($stagione, $idGiocatore);
    if ($attuale === null) throw new InvalidArgumentException("Collegamento non trovato");
    if ($attuale === $idAnagrafica) throw new InvalidArgumentException("Il giocatore e' gia' collegato a questa persona");
    if (!query_one("SELECT 1 AS x FROM " . RG_T_ANAG . " WHERE id_anagrafica = $idAnagrafica")) {
        throw new InvalidArgumentException("Persona $idAnagrafica non trovata");
    }
    rg_esegui("UPDATE " . RG_T_RACC . " SET id_anagrafica = $idAnagrafica, metodo = 'MANUALE',
                      da_verificare = 'N', nota = NULL, aggiornato_il = NOW()
               WHERE stagione = $stagione AND id_giocatore = $idGiocatore");
    rg_pulisci_orfane();
}

/** Stacca un giocatore dalla sua persona e lo assegna a una persona nuova. */
function rg_separa(int $stagione, int $idGiocatore): int
{
    $d = rg_dettaglio_edizione($stagione, $idGiocatore);
    if (rg_persona_di($stagione, $idGiocatore) === null) throw new InvalidArgumentException("Collegamento non trovato");
    $nuova = rg_nuova_persona($d["nome"] ?? "");
    rg_esegui("UPDATE " . RG_T_RACC . " SET id_anagrafica = $nuova, metodo = 'MANUALE',
                      da_verificare = 'N', nota = NULL, aggiornato_il = NOW()
               WHERE stagione = $stagione AND id_giocatore = $idGiocatore");
    rg_pulisci_orfane();
    return $nuova;
}

// ── Omonimi tra i raccordi certificati ───────────────────────

/** Distanza di Levenshtein tra due chiavi compatte, solo se entro la soglia (altrimenti > soglia). */
function rg_simili(string $a, string $b): bool
{
    if ($a === $b) return true;
    if (strlen($a) < RG_SIMILI_LUNGHEZZA_MIN || strlen($b) < RG_SIMILI_LUNGHEZZA_MIN) return false;
    if (abs(strlen($a) - strlen($b)) > RG_SIMILI_DISTANZA) return false;
    return levenshtein($a, $b) <= RG_SIMILI_DISTANZA;
}

/**
 * Omonimi (o nomi molto simili) tra le persone gia' CERTIFICATE dall'utente.
 *
 * Persona certificata: almeno un collegamento MANUALE non da verificare e
 * nessun collegamento ancora da verificare (quelli stanno nell'elenco
 * "da verificare"). Si ripropongono i gruppi di PERSONE DIVERSE il cui nome:
 *   - e' uguale una volta normalizzato (maiuscolo, accenti, apostrofi...);
 *   - oppure differisce solo per spazi/punteggiatura (DI MARCO / DIMARCO);
 *   - oppure e' molto simile (Levenshtein <= RG_SIMILI_DISTANZA, nomi lunghi).
 * Un gruppo sparisce da solo quando i nomi (rinominati con rg_rinomina) non
 * sono piu' uguali ne' simili.
 *
 * @return array gruppi: [tipo (uguale|variante|simile), persone[] con edizioni]
 */
function rg_omonimi_certificati(int $limite = 100): array
{
    $righe = query_all("SELECT a.id_anagrafica, a.nome,
                               SUM(r.metodo = 'MANUALE' AND r.da_verificare = 'N') AS certificati,
                               SUM(r.da_verificare = 'Y') AS da_verificare
                        FROM " . RG_T_ANAG . " a
                        JOIN " . RG_T_RACC . " r ON r.id_anagrafica = a.id_anagrafica
                        GROUP BY a.id_anagrafica, a.nome");
    $persone = [];       // id => [nome, chiave]
    $perChiave = [];     // chiave compatta => [id, ...]
    foreach ($righe as $r) {
        if ((int) $r["certificati"] < 1 || (int) $r["da_verificare"] > 0) continue;
        $kc = rg_chiave_compatta((string) $r["nome"]);
        if ($kc === "") continue;
        $id = (int) $r["id_anagrafica"];
        $persone[$id] = ["nome" => trim((string) $r["nome"]), "chiave" => $kc];
        $perChiave[$kc][] = $id;
    }

    // Unione delle chiavi uguali o molto simili (union-find sulle chiavi compatte)
    $padre = [];
    foreach (array_keys($perChiave) as $k) $padre[$k] = $k;
    $trova = function (string $k) use (&$padre): string {
        while ($padre[$k] !== $k) { $padre[$k] = $padre[$padre[$k]]; $k = $padre[$k]; }
        return $k;
    };
    // Confronto solo tra chiavi con lo stesso inizio (3 lettere) o la stessa fine (3 lettere)
    $blocchi = [];
    foreach (array_keys($perChiave) as $k) {
        if (strlen($k) < RG_SIMILI_LUNGHEZZA_MIN) continue;
        $blocchi["i" . substr($k, 0, 3)][] = $k;
        $blocchi["f" . substr($k, -3)][] = $k;
    }
    foreach ($blocchi as $chiavi) {
        $n = count($chiavi);
        if ($n < 2 || $n > 400) continue;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $ri = $trova($chiavi[$i]);
                $rj = $trova($chiavi[$j]);
                if ($ri !== $rj && rg_simili($chiavi[$i], $chiavi[$j])) $padre[$rj] = $ri;
            }
        }
    }
    $cluster = [];       // radice => [id, ...]
    foreach ($perChiave as $k => $ids) {
        foreach ($ids as $id) $cluster[$trova($k)][] = $id;
    }

    $out = [];
    foreach ($cluster as $ids) {
        if (count($ids) < 2) continue;
        sort($ids);
        $nomi = array_unique(array_map(fn($i) => rg_norm($persone[$i]["nome"]), $ids));
        $chiavi = array_unique(array_map(fn($i) => $persone[$i]["chiave"], $ids));
        $tipo = count($nomi) === 1 ? "uguale" : (count($chiavi) === 1 ? "variante" : "simile");
        $out[] = ["tipo" => $tipo, "ids" => $ids, "ordine" => $persone[$ids[0]]["nome"]];
    }
    usort($out, fn($a, $b) => strcmp($a["ordine"], $b["ordine"]) ?: ($a["ids"][0] <=> $b["ids"][0]));

    $gruppi = [];
    foreach (array_slice($out, 0, max(1, min(300, $limite))) as $g) {
        $gruppi[] = [
            "tipo"    => $g["tipo"],
            "persone" => array_values(array_filter(array_map("rg_persona_dettaglio", $g["ids"]))),
        ];
    }
    return ["gruppi" => $gruppi, "totale" => count($out)];
}

/**
 * Rinomina il giocatore: il nuovo nome sostituisce quello usato in tutta
 * l'applicazione (NEW_GIOCATORI.descrizione, NEW_GIOCATORI_SVINCOLATI.descrizione,
 * NEW_GIOCATORI_BASE_ASTA.nome). Le associazioni con i voti Serie A, le formazioni
 * ecc. sono per id e non cambiano.
 *
 *  - $stagione/$idGiocatore null  -> TUTTE le stagioni della persona e nome
 *    canonico (NEW_GIOCATORI_ANAGRAFICA.nome);
 *  - $stagione e $idGiocatore     -> solo quel giocatore in quella stagione
 *    (il nome canonico della persona non cambia).
 *
 * @return int numero di giocatori (stagione, id) rinominati
 */
function rg_rinomina(int $idAnagrafica, string $nuovoNome, ?int $stagione = null, ?int $idGiocatore = null): int
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nuovoNome));
    if ($nome === "") throw new InvalidArgumentException("Il nome non puo' essere vuoto");
    $len = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
    if ($len > 50) throw new InvalidArgumentException("Il nome supera i 50 caratteri");
    if (!query_one("SELECT 1 AS x FROM " . RG_T_ANAG . " WHERE id_anagrafica = $idAnagrafica")) {
        throw new InvalidArgumentException("Persona $idAnagrafica non trovata");
    }

    $coppie = [];        // [stagione, id_giocatore]
    if ($stagione !== null || $idGiocatore !== null) {
        if (rg_persona_di((int) $stagione, (int) $idGiocatore) !== $idAnagrafica) {
            throw new InvalidArgumentException("Il giocatore non appartiene a questa persona");
        }
        $coppie[] = [(int) $stagione, (int) $idGiocatore];
    } else {
        foreach (query_all("SELECT stagione, id_giocatore FROM " . RG_T_RACC . " WHERE id_anagrafica = $idAnagrafica") as $r) {
            $coppie[] = [(int) $r["stagione"], (int) $r["id_giocatore"]];
        }
    }
    if (!$coppie) throw new InvalidArgumentException("Nessun giocatore collegato alla persona");

    $e = rg_esc($nome);
    foreach ($coppie as [$s, $id]) {
        $dove = "WHERE stagione = $s AND id = $id";
        rg_esegui("UPDATE NEW_GIOCATORI SET descrizione = '$e' $dove");
        rg_esegui("UPDATE NEW_GIOCATORI_SVINCOLATI SET descrizione = '$e' $dove");
        rg_esegui("UPDATE NEW_GIOCATORI_BASE_ASTA SET nome = '$e' WHERE stagione = $s AND id_giocatore = $id");
    }
    if ($stagione === null && $idGiocatore === null) {
        rg_esegui("UPDATE " . RG_T_ANAG . " SET nome = '$e' WHERE id_anagrafica = $idAnagrafica");
    }
    return count($coppie);
}
