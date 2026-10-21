<?php
// ============================================================
// backend/lib/ChampionsSupplementari.php
//
// Supplementari e calci di rigore delle partite di Champions.
//
// QUANDO SI GIOCANO
//   Il turno deve avere il flag "supplementari e rigori" attivo nella
//   configurazione del calendario Champions (parametri CHAMP_*_SUPPL, vedi
//   lib/ChampionsCalendario.php): disponibile su tutte le giornate, di default
//   attivo solo su semifinale di ritorno, finale e replay della finale.
//   - Turno andata/ritorno (semifinali): dopo la SECONDA partita, solo se la
//     somma dei gol delle due partite è in parità (il flag sull'andata non ha
//     effetto).
//   - Turno a partita singola (gironi, finale, replay): dopo la partita stessa,
//     solo se è finita in parità. Nei gironi la classifica continua a usare il
//     risultato dei tempi regolamentari.
//   I supplementari decidono la sfida se cambiano la somma dei gol; se la
//   parità resiste si va ai calci di rigore.
//
// CALCOLO SUPPLEMENTARI
//   Per ciascuna squadra si prende, per ognuno dei ruoli difensore,
//   centrocampista e attaccante, il PRIMO giocatore in panchina (in ordine di
//   maglia) e si sommano voto + bonus/malus:
//     - se il giocatore è entrato in campo per sostituire un titolare il suo
//       contributo è 0 (e non si guarda il successivo);
//     - se non è entrato ed è senza voto (s.v.) si considera il successivo
//       dello stesso ruolo, con la stessa regola;
//     - se non ci sono più giocatori nel ruolo il contributo è 0.
//   La somma dei 3 contributi diventa gol con le fasce della configurazione
//   "calcolo supplementari" (CalcolatoreVoti::golSupplementari).
//
// CALCOLO RIGORI
//   Ogni squadra ha una lista di rigoristi: l'ordine inserito dall'allenatore
//   (NEW_RIGORISTI_CHAMP: titolari da 1 a 11, poi i panchinari) oppure, se non
//   consegnato, l'ordine dei numeri di maglia (si parte dal portiere). La serie
//   iniziale considera SOLO i primi 5 rigoristi di ciascuna squadra, un rigore
//   per squadra a turno, in ordine. Il rigore è realizzato se il VOTO del
//   giocatore nella partita è >= 6 (un giocatore senza voto non realizza).
//   In caso di ulteriore parità si prosegue ad oltranza, un rigorista per
//   squadra per volta, nell'ordine della lista (6°, 7°, ...; terminata la
//   lista si riparte dal primo), finché in un giro una squadra segna e
//   l'altra no.
// ============================================================

require_once __DIR__ . "/ChampionsCalendario.php";
require_once __DIR__ . "/CalcolatoreVoti.php";

/** Voto minimo (incluso) perché il rigore si consideri realizzato. */
const CHAMP_RIGORE_VOTO_MIN = 6.0;

/** Numero di rigoristi per squadra nella serie iniziale (poi si va ad oltranza). */
const CHAMP_RIGORI_SERIE = 5;

/** Giri di oltranza oltre i quali, in parità assoluta, si decide con il punteggio. */
const CHAMP_RIGORI_MAX_OLTRANZA = 200;

/** Ruoli dei panchinari dei supplementari: difensore, centrocampista, attaccante. */
const CHAMP_SUPPL_RUOLI = [2 => "Difensore", 3 => "Centrocampista", 4 => "Attaccante"];

/** JSON compatto e sicuro (ASCII) per la colonna dett_extra. */
function champions_json_extra(array $dati): string
{
    $j = json_encode($dati, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $j === false ? "{}" : $j;
}

// ------------------------------------------------------------
// Supplementari
// ------------------------------------------------------------

/**
 * Punti dei supplementari di UNA squadra (somma dei 3 panchinari).
 *
 * @param string   $tabella    tabella della formazione usata per la partita
 * @param int[]    $entrati    id dei panchinari entrati per sostituire un titolare
 * @param callable $caricaStat fn(int $idGiocatore): array  statistiche Serie A
 *                             (formato di carica_stat_giocatore in calcolo_giornata.php)
 * @param array    $bonus      NEW_REGOLE_BONUS (codice => valore)
 * @return array ['punti' => float, 'giocatori' => [ [ruolo, id, nome, voto, punti, nota], ... ]]
 */
function champions_punti_supplementari_squadra(
    string $tabella, int $stagione, int $giornata, int $idSquadra,
    array $entrati, callable $caricaStat, array $bonus
): array {
    $panchina = query_all("SELECT f.ID_GIOCATORE AS id, f.MAGLIA AS maglia, g.ruolo, g.descrizione AS nome
                           FROM $tabella f
                           JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                           WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idSquadra AND f.GIORNATA = $giornata
                             AND f.MAGLIA > 11 AND g.ruolo IN (2, 3, 4)
                           ORDER BY f.MAGLIA");
    $perRuolo = [];
    foreach ($panchina as $p) $perRuolo[(int) $p["ruolo"]][] = $p;
    $entrati = array_map("intval", $entrati);

    $totale = 0.0;
    $dettaglio = [];
    foreach (CHAMP_SUPPL_RUOLI as $ruolo => $etichetta) {
        $trovato = false;
        foreach ($perRuolo[$ruolo] ?? [] as $p) {
            $id = (int) $p["id"];

            // Entrato in campo al posto di un titolare: voto 0 e ci si ferma
            if (in_array($id, $entrati, true)) {
                $dettaglio[] = ["ruolo" => $ruolo, "id" => $id, "nome" => $p["nome"], "voto" => null,
                                "punti" => 0.0, "nota" => "entrato in campo: 0 punti"];
                $trovato = true;
                break;
            }

            $stat = $caricaStat($id);
            if (!empty($stat["sv"]) || $stat["voto"] === null) {
                // Non entrato e senza voto: si passa al successivo dello stesso ruolo
                $dettaglio[] = ["ruolo" => $ruolo, "id" => $id, "nome" => $p["nome"], "voto" => null,
                                "punti" => 0.0, "nota" => "s.v.: si considera il successivo"];
                continue;
            }

            $punti = (float) CalcolatoreVoti::puntiGiocatore($stat, $bonus);
            $totale += $punti;
            $dettaglio[] = ["ruolo" => $ruolo, "id" => $id, "nome" => $p["nome"], "voto" => (float) $stat["voto"],
                            "punti" => $punti, "nota" => ""];
            $trovato = true;
            break;
        }
        if (!$trovato) {
            $dettaglio[] = ["ruolo" => $ruolo, "id" => null, "nome" => null, "voto" => null,
                            "punti" => 0.0, "nota" => "nessun panchinario disponibile: 0 punti"];
        }
    }
    return ["punti" => round($totale, 2), "giocatori" => $dettaglio];
}

// ------------------------------------------------------------
// Rigori
// ------------------------------------------------------------

/**
 * Lista ordinata dei rigoristi di UNA squadra.
 * Ordine inserito dall'allenatore (NEW_RIGORISTI_CHAMP) se presente: prima i
 * titolari (maglia 1-11) poi i panchinari; chi non è nell'ordine inserito
 * (formazione cambiata dopo) segue per numero di maglia. Senza lista:
 * ordine di maglia, cioè si parte dal portiere.
 *
 * @return array [ ['id', 'nome', 'ruolo', 'maglia'], ... ]
 */
function champions_lista_rigoristi(string $tabella, int $stagione, int $giornata, int $idSquadra): array
{
    $righe = query_all("SELECT f.ID_GIOCATORE AS id, f.MAGLIA AS maglia, g.ruolo, g.descrizione AS nome,
                               r.ORDINE AS ordine
                        FROM $tabella f
                        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                        LEFT JOIN NEW_RIGORISTI_CHAMP r ON r.STAGIONE = f.STAGIONE AND r.GIORNATA = f.GIORNATA
                                                       AND r.ID_SQUADRA = f.ID_SQUADRA AND r.ID_GIOCATORE = f.ID_GIOCATORE
                        WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idSquadra AND f.GIORNATA = $giornata
                        ORDER BY f.MAGLIA");
    $chiave = function (array $r): array {
        $panchina = (int) $r["maglia"] > 11 ? 1 : 0;   // titolari prima dei panchinari
        $ordine   = $r["ordine"] !== null ? (int) $r["ordine"] : PHP_INT_MAX;
        return [$panchina, $ordine, (int) $r["maglia"]];
    };
    usort($righe, fn($a, $b) => $chiave($a) <=> $chiave($b));
    return array_map(fn($r) => [
        "id" => (int) $r["id"], "nome" => $r["nome"], "ruolo" => (int) $r["ruolo"], "maglia" => (int) $r["maglia"],
    ], $righe);
}

/**
 * Arricchisce la lista con l'esito di ogni rigore: realizzato se il voto
 * (puro, Serie A) è >= CHAMP_RIGORE_VOTO_MIN; senza voto = non realizzato.
 */
function champions_esito_rigoristi(array $lista, callable $caricaStat): array
{
    $out = [];
    foreach ($lista as $r) {
        $stat = $caricaStat((int) $r["id"]);
        $voto = (empty($stat["sv"]) && $stat["voto"] !== null) ? (float) $stat["voto"] : null;
        $out[] = $r + ["voto" => $voto, "segna" => $voto !== null && $voto + 1e-9 >= CHAMP_RIGORE_VOTO_MIN];
    }
    return $out;
}

/**
 * Simula la serie dei rigori. Una squadra tira per prima (casa), poi l'altra,
 * a turno. Serie iniziale: SOLO i primi CHAMP_RIGORI_SERIE (5) rigoristi di
 * ciascuna squadra. In caso di parità si prosegue ad oltranza, un rigorista
 * per squadra per volta, nell'ordine della lista (dal 6° in poi; terminata la
 * lista si riparte dal primo).
 *
 * @param array $casa, $ospite  liste con 'segna' (vedi champions_esito_rigoristi)
 * @return array ['casa' => int, 'ospite' => int, 'tiri' => [...], 'oltranza' => bool,
 *                'vincitore' => 'casa'|'ospite'|null]
 */
function champions_simula_rigori(array $casa, array $ospite, int $maxOltranza = CHAMP_RIGORI_MAX_OLTRANZA): array
{
    $vuoto = [["id" => null, "nome" => "—", "ruolo" => 0, "maglia" => 0, "voto" => null, "segna" => false]];
    if (!$casa)   $casa   = $vuoto;
    if (!$ospite) $ospite = $vuoto;

    $nC = count($casa);
    $nO = count($ospite);
    $n  = min(CHAMP_RIGORI_SERIE, $nC, $nO);
    $gc = 0;
    $go = 0;
    $tiri = [];
    $sintesi = fn(array $r) => ["id" => $r["id"], "nome" => $r["nome"], "voto" => $r["voto"], "segna" => (bool) $r["segna"]];

    // Serie iniziale: primi 5 rigoristi, un rigore per squadra, nell'ordine
    for ($i = 0; $i < $n; $i++) {
        $gc += $casa[$i]["segna"] ? 1 : 0;
        $go += $ospite[$i]["segna"] ? 1 : 0;
        $tiri[] = ["n" => $i + 1, "fase" => "lista", "casa" => $sintesi($casa[$i]), "ospite" => $sintesi($ospite[$i])];
    }

    // Oltranza: si prosegue con il rigorista successivo di ciascuna lista
    $oltranza = false;
    if ($gc === $go) {
        $oltranza = true;
        for ($r = 0; $r < $maxOltranza; $r++) {
            $a = $casa[($n + $r) % $nC];
            $b = $ospite[($n + $r) % $nO];
            $gc += $a["segna"] ? 1 : 0;
            $go += $b["segna"] ? 1 : 0;
            $tiri[] = ["n" => $n + $r + 1, "fase" => "oltranza", "casa" => $sintesi($a), "ospite" => $sintesi($b)];
            if ((bool) $a["segna"] !== (bool) $b["segna"]) break;
        }
    }

    return [
        "casa" => $gc, "ospite" => $go, "tiri" => $tiri, "oltranza" => $oltranza,
        "vincitore" => $gc > $go ? "casa" : ($go > $gc ? "ospite" : null),
    ];
}

// ------------------------------------------------------------
// Orchestrazione
// ------------------------------------------------------------

/**
 * Gol (90') delle due squadre nella partita di andata di una semifinale.
 * @return array|null [golCasaRitorno, golOspiteRitorno] riferiti alle squadre
 *                    del ritorno (casa, ospite), null se l'andata non è calcolata
 */
function champions_golf_andata(int $stagione, string $gruppo, int $idCasa, int $idOspite): ?array
{
    $g = preg_replace('/[^A-Z0-9]/', '', strtoupper($gruppo));
    $cal = query_one("SELECT giornata_camp FROM NEW_CALENDARIO_CHAMP
                      WHERE stagione = $stagione AND TRIM(girone) = '$g' AND giornata = 1 LIMIT 1");
    if (!$cal) return null;
    $camp = (int) $cal["giornata_camp"];
    $rows = query_all("SELECT squadra, golf FROM NEW_RISULTATI_CHAMP
                       WHERE stagione = $stagione AND giornata = $camp AND squadra IN ($idCasa, $idOspite)");
    $per = [];
    foreach ($rows as $r) $per[(int) $r["squadra"]] = (int) $r["golf"];
    if (!isset($per[$idCasa], $per[$idOspite])) return null;
    return [$per[$idCasa], $per[$idOspite]];
}

/**
 * Valuta supplementari e rigori di una partita di Champions il cui turno li
 * prevede (flag attivo). Da chiamare dopo il calcolo dei 90'.
 *
 * @param array $f  formazioni effettive di carica_formazione() con la chiave
 *                  'sostituti_entrati' (id dei panchinari entrati)
 * @return array|null  null se per questa partita non c'è nulla da valutare
 *                     (es. andata di una semifinale, o andata non calcolata);
 *                     altrimenti:
 *   'passa'     id squadra che supera il turno (null se la sfida è ancora aperta)
 *   'supp'      null | ['casa'=>['punti','gol','giocatori'], 'ospite'=>[...]]
 *   'rig'       null | ['casa'=>int,'ospite'=>int,'tiri'=>[],'oltranza'=>bool,'fallback'=>bool]
 *   'andata'    null | ['casa'=>gol andata della squadra di casa del ritorno,'ospite'=>...]
 */
function champions_valuta_extra(
    int $stagione, int $giornata, string $gruppo, int $giornataChamp,
    int $idCasa, int $idOspite,
    int $golfCasa, int $golfOspite, float $ftotCasa, float $ftotOspite,
    array $fCasa, array $fOspite, string $tabCasa, string $tabOspite,
    array $config, callable $caricaStat, array &$warning
): ?array {
    $gruppo = strtoupper(trim($gruppo));
    $andata = null;
    $prevC = 0;
    $prevO = 0;

    if (in_array($gruppo, ["S1", "S2"], true)) {
        // Turno andata/ritorno: solo il ritorno può avere supplementari e rigori
        if ($giornataChamp < 2) return null;
        $andata = champions_golf_andata($stagione, $gruppo, $idCasa, $idOspite);
        if ($andata === null) {
            $warning[] = "Champions - $gruppo (giornata $giornata): partita di andata non calcolata, "
                       . "impossibile valutare supplementari e rigori";
            return null;
        }
        [$prevC, $prevO] = $andata;
    }

    $out = [
        "passa"  => null,
        "supp"   => null,
        "rig"    => null,
        "andata" => $andata === null ? null : ["casa" => $prevC, "ospite" => $prevO],
    ];
    $aggC = $prevC + $golfCasa;
    $aggO = $prevO + $golfOspite;

    // 1) deciso nei tempi regolamentari (somma dei gol)
    if ($aggC !== $aggO) {
        $out["passa"] = $aggC > $aggO ? $idCasa : $idOspite;
        return $out;
    }

    // 2) supplementari
    $parametriSupp = $config["supplementari"] ?? CalcolatoreVoti::ALGORITMO_SUPPLEMENTARI_DEFAULT["parametri"];
    $bonus = $config["bonus"] ?? [];
    $sc = champions_punti_supplementari_squadra($tabCasa,   $stagione, $giornata, $idCasa,   $fCasa["sostituti_entrati"]   ?? [], $caricaStat, $bonus);
    $so = champions_punti_supplementari_squadra($tabOspite, $stagione, $giornata, $idOspite, $fOspite["sostituti_entrati"] ?? [], $caricaStat, $bonus);
    $gC = CalcolatoreVoti::golSupplementari($sc["punti"], $parametriSupp);
    $gO = CalcolatoreVoti::golSupplementari($so["punti"], $parametriSupp);
    $out["supp"] = [
        "casa"   => ["punti" => $sc["punti"], "gol" => $gC, "giocatori" => $sc["giocatori"]],
        "ospite" => ["punti" => $so["punti"], "gol" => $gO, "giocatori" => $so["giocatori"]],
    ];
    $warning[] = "Champions - squadre $idCasa-$idOspite (giornata $giornata): parità, si giocano i supplementari "
               . "({$sc['punti']} punti = $gC gol, {$so['punti']} punti = $gO gol)";

    $aggC += $gC;
    $aggO += $gO;
    if ($aggC !== $aggO) {
        $out["passa"] = $aggC > $aggO ? $idCasa : $idOspite;
        return $out;
    }

    // 3) calci di rigore
    $lc = champions_esito_rigoristi(champions_lista_rigoristi($tabCasa,   $stagione, $giornata, $idCasa),   $caricaStat);
    $lo = champions_esito_rigoristi(champions_lista_rigoristi($tabOspite, $stagione, $giornata, $idOspite), $caricaStat);
    $rig = champions_simula_rigori($lc, $lo);
    $rig["fallback"] = false;

    if ($rig["vincitore"] === null) {
        // Parità assoluta dopo l'oltranza massima (caso quasi impossibile):
        // passa la squadra con più punti (90' + supplementari), poi quella di casa.
        $pC = $ftotCasa + $sc["punti"];
        $pO = $ftotOspite + $so["punti"];
        $rig["vincitore"] = $pO > $pC ? "ospite" : "casa";
        $rig["fallback"]  = true;
        $warning[] = "Champions - squadre $idCasa-$idOspite (giornata $giornata): rigori ancora in parità dopo "
                   . CHAMP_RIGORI_MAX_OLTRANZA . " giri di oltranza, passa la squadra con più punti";
    }
    $out["rig"]   = $rig;
    $out["passa"] = $rig["vincitore"] === "casa" ? $idCasa : $idOspite;
    $warning[] = "Champions - squadre $idCasa-$idOspite (giornata $giornata): calci di rigore {$rig['casa']}-{$rig['ospite']}"
               . ($rig["oltranza"] ? " (ad oltranza)" : "");
    return $out;
}

/**
 * Valori delle colonne extra di NEW_RISULTATI_CHAMP per la riga di UNA squadra
 * (prospettiva di $idSquadra), a partire da champions_valuta_extra().
 *
 * @param array|null $extra  esito di champions_valuta_extra() (null = nessun extra)
 * @return array ['supp_giocati','supp_ftotale','supp_golf','supp_gols','rig_giocati',
 *                'rig_golf','rig_gols','passa','dett_extra']
 */
function champions_colonne_extra(?array $extra, int $idCasa, int $idOspite, int $idSquadra): array
{
    $vuoto = ["supp_giocati" => 0, "supp_ftotale" => 0.0, "supp_golf" => 0, "supp_gols" => 0,
              "rig_giocati" => 0, "rig_golf" => 0, "rig_gols" => 0, "passa" => 0, "dett_extra" => null];
    if ($extra === null) return $vuoto;

    $lato  = $idSquadra === $idCasa ? "casa" : "ospite";
    $altro = $lato === "casa" ? "ospite" : "casa";
    $col = $vuoto;
    $col["passa"] = $extra["passa"] !== null && (int) $extra["passa"] === $idSquadra ? 1 : 0;

    if ($extra["supp"] !== null) {
        $col["supp_giocati"] = 1;
        $col["supp_ftotale"] = (float) $extra["supp"][$lato]["punti"];
        $col["supp_golf"]    = (int) $extra["supp"][$lato]["gol"];
        $col["supp_gols"]    = (int) $extra["supp"][$altro]["gol"];
    }
    if ($extra["rig"] !== null) {
        $col["rig_giocati"] = 1;
        $col["rig_golf"]    = (int) $extra["rig"][$lato];
        $col["rig_gols"]    = (int) $extra["rig"][$altro];
    }

    // Dettaglio comune alle due righe (prospettiva neutra casa/ospite)
    $col["dett_extra"] = champions_json_extra([
        "casa_id"   => $idCasa,
        "ospite_id" => $idOspite,
        "andata"    => $extra["andata"],
        "supp"      => $extra["supp"],
        "rig"       => $extra["rig"],
        "passa"     => $extra["passa"],
    ]);
    return $col;
}
