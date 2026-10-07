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
// "Parametri stagione" e vive in NEW_PARAMETRI_STAGIONE (un parametro
// per turno, codice = CHAMP_*, valore = numero di giornata). Chi
// crea il calendario (admin/inizializza_stagione.php) la legge e
// non la modifica.
// ============================================================

/**
 * Turni della Champions nell'ordine cronologico in cui si giocano.
 *   Fase 1:      2 gironi da 4 squadre, andata/ritorno = 6 turni
 *   Fase 2:      2 gironi da 3 squadre, andata/ritorno = 4 turni
 *   Fase finale: semifinali A/R (2 turni), finale, replay (opzionale)
 */
function champions_turni(): array
{
    $f1 = []; $f2 = [];
    for ($i = 1; $i <= 6; $i++) {
        $f1[] = ["codice" => "CHAMP_F1_T$i", "label" => "Turno $i" . ($i <= 3 ? " (andata)" : " (ritorno)"), "opzionale" => false];
    }
    for ($i = 1; $i <= 4; $i++) {
        $f2[] = ["codice" => "CHAMP_F2_T$i", "label" => "Turno $i" . ($i <= 2 ? " (andata)" : " (ritorno)"), "opzionale" => false];
    }
    return [
        ["id" => "fase1",  "label" => "Fase 1 · Gironi", "turni" => $f1],
        ["id" => "fase2",  "label" => "Fase 2 · Gironi", "turni" => $f2],
        ["id" => "finale", "label" => "Fase finale", "turni" => [
            ["codice" => "CHAMP_SF_T1",      "label" => "Semifinali - andata",       "opzionale" => false],
            ["codice" => "CHAMP_SF_T2",      "label" => "Semifinali - ritorno",      "opzionale" => false],
            ["codice" => "CHAMP_FIN_T1",     "label" => "Finale",                    "opzionale" => false],
            ["codice" => "CHAMP_FIN_REPLAY", "label" => "Replay finale (se serve)",  "opzionale" => true],
        ]],
    ];
}

/** Elenco piatto dei turni (stesso ordine di champions_turni()), con fase di appartenenza. */
function champions_turni_piatti(): array
{
    $out = [];
    foreach (champions_turni() as $f) {
        foreach ($f["turni"] as $t) {
            $t["fase_id"] = $f["id"];
            $t["fase_label"] = $f["label"];
            $out[] = $t;
        }
    }
    return $out;
}

/** Giornate di campionato distinte (ordine cronologico) del calendario Champions di una stagione. */
function champions_giornate_calendario(int $stagione): array
{
    $rows = query_all("SELECT DISTINCT giornata FROM NEW_CALENDARIO_CHAMP
                       WHERE stagione = $stagione ORDER BY giornata");
    return array_map(function ($r) { return (int) $r["giornata"]; }, $rows);
}

/** Parametri CHAMP_* salvati per la stagione: codice => valore (stringa). */
function champions_parametri_stagione(int $stagione): array
{
    $rows = query_all("SELECT codice, valore FROM NEW_PARAMETRI_STAGIONE
                       WHERE stagione = $stagione AND codice LIKE 'CHAMP\\_%'");
    $out = [];
    foreach ($rows as $r) $out[$r["codice"]] = trim((string) $r["valore"]);
    return $out;
}

/**
 * Valida i valori configurati (codice => valore grezzo).
 *   $max          giornata massima ammessa (null = nessun limite superiore)
 *   $obbligatori  elenco di codici che devono essere valorizzati
 * Regole: intero >= 1 (<= $max), mai la stessa giornata per due turni,
 * giornate crescenti nell'ordine dei turni.
 *
 * @return array  elenco di [codice, messaggio]; vuoto se tutto ok
 */
function champions_valida_giornate(array $valori, ?int $max, array $obbligatori = []): array
{
    $errori = [];
    $ultima = 0;
    $usate  = [];
    foreach (champions_turni_piatti() as $t) {
        $cod = $t["codice"];
        $nome = "{$t['fase_label']} - {$t['label']}";
        $raw = trim((string) ($valori[$cod] ?? ""));
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
        if (isset($usate[$g])) {
            $errori[] = [$cod, "$nome: la giornata $g è già assegnata a un altro turno"];
        } elseif ($g <= $ultima) {
            $errori[] = [$cod, "$nome: la giornata deve essere successiva a quella del turno precedente ($ultima)"];
        }
        $usate[$g] = true;
        $ultima = max($ultima, $g);
    }
    return $errori;
}
