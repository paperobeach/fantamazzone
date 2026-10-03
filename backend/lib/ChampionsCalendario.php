<?php
// ============================================================
// lib/ChampionsCalendario.php
//
// Configurazione delle giornate di campionato in cui si gioca ogni
// turno della Champions. La Champions si gioca in contemporanea al
// campionato: la giornata configurata per un turno vale per entrambe
// le competizioni.
//
// La configurazione si gestisce in "Gestisci regole di calcolo" →
// "Parametri stagione" → "Struttura stagione" e vive in
// NEW_PARAMETRI_STAGIONE (un parametro per cella, valore = numero di
// giornata di fantacampionato). Chi crea il calendario
// (admin/inizializza_stagione.php) la legge e non la modifica.
//
// Nelle fasi a gironi ogni girone (A, B) ha le proprie giornate: la
// giornata 1 del girone A può cadere in una giornata di campionato
// diversa dalla giornata 1 del girone B.
//
// Codici parametro:
//   CHAMP_F1_<girone>_T<n>   Fase 1 (6 giornate), es. CHAMP_F1_A_T1
//   CHAMP_F2_<girone>_T<n>   Fase 2 (4 giornate), es. CHAMP_F2_B_T3
//   CHAMP_SF_T1/T2           Semifinali andata/ritorno (senza girone)
//   CHAMP_FIN_T1             Finale
//   CHAMP_FIN_REPLAY         Replay finale (opzionale)
// ============================================================

/** Gironi delle fasi a gironi. */
const CHAMPIONS_GIRONI = ['A', 'B'];

/**
 * Struttura della Champions nell'ordine cronologico in cui si gioca.
 * Ogni fase ha dei turni; ogni turno ha una "cella" per girone
 * (una sola cella con girone null nella fase finale):
 *   Fase 1:      2 gironi da 4 squadre, andata/ritorno = 6 giornate
 *   Fase 2:      2 gironi da 3 squadre, andata/ritorno = 4 giornate
 *   Fase finale: semifinali A/R (2 turni), finale, replay (opzionale)
 *
 * @return array [ ['id','label','gironi'=>[...],'turni'=>[['n','label','celle'=>[['codice','girone','opzionale']]]]] ]
 */
function champions_fasi(): array
{
    $fasi = [];
    $defs = [
        ['id' => 'fase1', 'label' => 'Fase 1 · Gironi', 'sigla' => 'F1', 'n' => 6, 'andata' => 3],
        ['id' => 'fase2', 'label' => 'Fase 2 · Gironi', 'sigla' => 'F2', 'n' => 4, 'andata' => 2],
    ];
    foreach ($defs as $d) {
        $turni = [];
        for ($i = 1; $i <= $d['n']; $i++) {
            $celle = [];
            foreach (CHAMPIONS_GIRONI as $g) {
                $celle[] = ["codice" => "CHAMP_{$d['sigla']}_{$g}_T$i", "girone" => $g, "opzionale" => false];
            }
            $turni[] = [
                "n"     => $i,
                "label" => "Giornata $i" . ($i <= $d['andata'] ? " (andata)" : " (ritorno)"),
                "celle" => $celle,
            ];
        }
        $fasi[] = ["id" => $d['id'], "label" => $d['label'], "gironi" => CHAMPIONS_GIRONI, "turni" => $turni];
    }

    $finale = [
        ["CHAMP_SF_T1",      "Semifinali - andata",       false],
        ["CHAMP_SF_T2",      "Semifinali - ritorno",      false],
        ["CHAMP_FIN_T1",     "Finale",                    false],
        ["CHAMP_FIN_REPLAY", "Replay finale (se serve)",  true],
    ];
    $turni = [];
    foreach ($finale as $n => [$codice, $label, $opz]) {
        $turni[] = [
            "n"     => $n + 1,
            "label" => $label,
            "celle" => [["codice" => $codice, "girone" => null, "opzionale" => $opz]],
        ];
    }
    $fasi[] = ["id" => "finale", "label" => "Fase finale", "gironi" => [], "turni" => $turni];

    return $fasi;
}

/** Elenco piatto delle celle (ordine cronologico per fase/turno), con nome descrittivo. */
function champions_turni_piatti(): array
{
    $out = [];
    foreach (champions_fasi() as $f) {
        foreach ($f["turni"] as $t) {
            foreach ($t["celle"] as $c) {
                $nome = $f["label"] . " - " . ($c["girone"] !== null ? "Girone {$c['girone']} - " : "") . $t["label"];
                $out[] = $c + ["nome" => $nome, "fase_id" => $f["id"]];
            }
        }
    }
    return $out;
}

/** Codici delle celle opzionali (es. replay finale). */
function champions_codici_opzionali(): array
{
    $out = [];
    foreach (champions_turni_piatti() as $c) if ($c["opzionale"]) $out[] = $c["codice"];
    return $out;
}

/** Turni in ordine cronologico (fase per fase), ciascuno con le sue celle (una per girone). */
function champions_turni_cronologici(): array
{
    $out = [];
    foreach (champions_fasi() as $f) {
        foreach ($f["turni"] as $t) $out[] = $t + ["fase_id" => $f["id"]];
    }
    return $out;
}

/** Cella di un turno per il girone indicato (se il turno non ha gironi, l'unica cella). */
function champions_cella_turno(array $turno, string $girone): array
{
    foreach ($turno["celle"] as $c) if ($c["girone"] === $girone) return $c;
    return $turno["celle"][0];
}

/**
 * Associa le giornate del calendario Champions di una stagione ai turni
 * previsti: le giornate distinte, in ordine cronologico, corrispondono
 * per posizione ai turni (Fase 1, Fase 2, Fase finale).
 *
 * @return array  giornata sorgente => indice del turno in champions_turni_cronologici()
 */
function champions_assegna_sorgente(int $stagione): array
{
    $rows = query_all("SELECT DISTINCT giornata FROM NEW_CALENDARIO_CHAMP
                       WHERE stagione = $stagione ORDER BY giornata");
    $mappa = [];
    $n = 0;
    foreach ($rows as $r) $mappa[(int) $r["giornata"]] = $n++;
    return $mappa;
}

/**
 * Giornate del calendario Champions esistente, come valori suggeriti
 * per ogni cella di configurazione: codice => giornata.
 */
function champions_suggerite(int $stagione): array
{
    $turni = champions_turni_cronologici();
    $out = [];
    foreach (champions_assegna_sorgente($stagione) as $giornata => $idx) {
        if (!isset($turni[$idx])) continue;
        foreach ($turni[$idx]["celle"] as $c) $out[$c["codice"]] = $giornata;
    }
    return $out;
}

/**
 * Parametri CHAMP_* salvati per la stagione: codice => valore (stringa).
 * Compatibilità: i vecchi codici senza girone (CHAMP_F1_T1, CHAMP_F2_T1...)
 * valgono per entrambi i gironi finché non sono salvati quelli nuovi.
 */
function champions_parametri_stagione(int $stagione): array
{
    $rows = query_all("SELECT codice, valore FROM NEW_PARAMETRI_STAGIONE
                       WHERE stagione = $stagione AND codice LIKE 'CHAMP\\_%'");
    $out = [];
    foreach ($rows as $r) $out[$r["codice"]] = trim((string) $r["valore"]);
    foreach ($out as $cod => $val) {
        if (preg_match('/^CHAMP_(F[12])_T(\d+)$/', $cod, $m)) {
            foreach (CHAMPIONS_GIRONI as $g) {
                $nuovo = "CHAMP_{$m[1]}_{$g}_T{$m[2]}";
                if (!isset($out[$nuovo]) || $out[$nuovo] === "") $out[$nuovo] = $val;
            }
        }
    }
    return $out;
}

/**
 * Valida i valori configurati (codice => valore grezzo).
 *   $max          giornata massima ammessa (null = nessun limite superiore)
 *   $obbligatori  elenco di codici che devono essere valorizzati
 * Regole:
 *   - intero >= 1 (<= $max);
 *   - nello stesso girone le giornate sono strettamente crescenti
 *     nell'ordine dei turni (i due gironi sono indipendenti: possono
 *     anche coincidere o alternarsi);
 *   - ogni fase inizia dopo l'ultima giornata (di qualunque girone)
 *     della fase precedente.
 *
 * @return array  elenco di [codice, messaggio]; vuoto se tutto ok
 */
function champions_valida_giornate(array $valori, ?int $max, array $obbligatori = []): array
{
    $errori  = [];
    $maxPrec = 0; // ultima giornata della fase precedente
    $nomi    = [];
    foreach (champions_turni_piatti() as $c) $nomi[$c["codice"]] = $c["nome"];

    foreach (champions_fasi() as $fase) {
        $maxFase = 0;
        $nCol = max(1, count($fase["gironi"]));
        for ($col = 0; $col < $nCol; $col++) {
            $ultima = 0;
            foreach ($fase["turni"] as $turno) {
                $cella = $turno["celle"][$col];
                $cod   = $cella["codice"];
                $nome  = $nomi[$cod];
                $raw   = trim((string) ($valori[$cod] ?? ""));
                if ($raw === "") {
                    if (in_array($cod, $obbligatori, true)) {
                        $errori[] = [$cod, "$nome: giornata di campionato non configurata"];
                    }
                    continue;
                }
                if (!ctype_digit($raw) || (int) $raw < 1 || ($max !== null && (int) $raw > $max)) {
                    $errori[] = [$cod, "$nome: la giornata deve essere un intero tra 1 e " . ($max ?? "il numero di giornate")];
                    continue;
                }
                $g = (int) $raw;
                if ($g <= $ultima) {
                    $errori[] = [$cod, "$nome: la giornata deve essere successiva a quella del turno precedente dello stesso girone ($ultima)"];
                } elseif ($g <= $maxPrec) {
                    $errori[] = [$cod, "$nome: la giornata deve essere successiva a tutte quelle della fase precedente ($maxPrec)"];
                }
                $ultima  = max($ultima, $g);
                $maxFase = max($maxFase, $g);
            }
        }
        $maxPrec = max($maxPrec, $maxFase);
    }
    return $errori;
}
