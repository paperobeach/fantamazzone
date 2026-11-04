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

/** Nome (o nazione) normalizzato per i confronti: spazi compattati, maiuscolo. */
function rg_norm(?string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', (string) $s));
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
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
 *               omonimi, da_verificare, rimossi
 */
function rg_allinea(int $stagione): array
{
    $giocatori = rg_giocatori_stagione($stagione);
    $esito = ["stagione" => $stagione, "giocatori" => count($giocatori), "gia_collegati" => 0,
              "collegati" => 0, "nuove_persone" => 0, "omonimi" => 0, "da_verificare" => 0, "rimossi" => 0];

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

    // 5. Nuovi collegamenti
    $righe = [];
    foreach ($giocatori as $id => $g) {
        if (isset($collegati[$id])) continue;

        $chiave = rg_norm($g["nome"]);
        $stPrec = $ultimo[$chiave]["stagione"] ?? null;    // stagione in cui il nome compare l'ultima volta
        $cand   = $ultimo[$chiave]["ids"] ?? [];
        $pPers  = $stPrec !== null ? $persona[$stPrec] : [];
        $nS     = count($perNomeS[$chiave]);
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
            foreach ($perNomeS[$chiave] as $altro) {
                if ($altro !== $id
                    && $giocatori[$altro]["ruolo"] === $g["ruolo"]
                    && rg_norm($giocatori[$altro]["nazione"]) === rg_norm($g["nazione"])) $trasf = true;
            }
            $idPers = rg_nuova_persona($g["nome"]);
            $verif  = "Y";
            $nota   = "Omonimia: $nS in $stagione" . ($trasf ? " (stesso ruolo e nazione: forse cessione/acquisto)" : "")
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
                if (RG_VERIFICA_SALTO && $stPrec !== $prec) {
                    // Il giocatore manca in una o piu' stagioni intermedie
                    $verif = "Y";
                    $nota  = "Salto di stagione: ultima presenza nel $stPrec" . ($nota !== null ? "; " . $nota : "");
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
 * Con $azzeraAuto = true i collegamenti AUTO vengono rifatti da zero; quelli
 * MANUALE (correzioni dell'amministratore) restano.
 */
function rg_ricostruisci(bool $azzeraAuto = true): array
{
    if ($azzeraAuto) {
        rg_esegui("DELETE FROM " . RG_T_RACC . " WHERE metodo = 'AUTO'");
        rg_pulisci_orfane();
    }
    $out = [];
    foreach (rg_stagioni() as $s) $out[] = rg_allinea($s);
    return $out;
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
    $out = [];
    foreach ($righe as $r) {
        $idPers = (int) $r["id_anagrafica"];
        $cand = [];
        foreach (query_all("SELECT id_anagrafica FROM " . RG_T_ANAG . "
                            WHERE TRIM(nome) = '" . rg_esc(trim((string) $r["nome"])) . "'
                              AND id_anagrafica <> $idPers
                            ORDER BY id_anagrafica LIMIT 8") as $c) {
            // Persona con tutte le edizioni (e i loro dati): servono per scegliere
            // stagione per stagione quali collegare
            $s = rg_persona_dettaglio((int) $c["id_anagrafica"]);
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
