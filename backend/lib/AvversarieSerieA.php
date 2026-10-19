<?php
// ============================================================
// backend/lib/AvversarieSerieA.php
//
// Per l'inserimento formazione: per ogni giocatore della rosa di una
// squadra indica la squadra di Serie A in cui gioca, l'avversaria della
// giornata e se l'incontro è in casa o in trasferta.
//
// Fonti (stesse regole di dashboard_prepara.php):
//   - squadra di Serie A del giocatore: staging dell'asta
//     (NEW_GIOCATORI_BASE_ASTA.squadra_serie_a); l'ultima squadra con cui
//     compare in NEW_VOTI_SERIE_A prevale (gestisce i trasferimenti);
//   - incontri: NEW_CALENDARIO_SERIE_A, alla giornata di Serie A
//     corrispondente = giornata fanta + GIORNATA_SERIE_A_INIZIO - 1
//     (vedi ds_inizio_giornata in lib/DashboardSquadra.php).
//
// Va incluso DOPO connect.php (usa query_all).
// ============================================================
require_once __DIR__ . "/DashboardSquadra.php";

/** Nome squadra normalizzato per il confronto tra fonti diverse. */
function av_norm(?string $s): string
{
    $s = trim((string) $s);
    return function_exists("mb_strtoupper") ? mb_strtoupper($s, "UTF-8") : strtoupper($s);
}

/**
 * @return array{giornata_serie_a:int, giocatori:array<int,array>}
 *   giocatori: un elemento per ogni giocatore della rosa, con
 *   id_giocatore, serie_a (null se ignota), avversaria (null se la squadra
 *   non gioca nella giornata), casa (true/false/null), data, ora.
 */
function av_avversarie_rosa(int $stagione, int $giornata, int $idSquadra): array
{
    [$giornataSerieA] = ds_inizio_giornata($stagione, $giornata);

    // Squadra di Serie A di ogni giocatore
    $serieA = [];
    foreach (query_all("SELECT id_giocatore, squadra_serie_a AS squadra
                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione") as $r) {
        $serieA[(int) $r["id_giocatore"]] = $r["squadra"];
    }
    foreach (query_all("SELECT id_giocatore, squadra FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione ORDER BY giornata") as $r) {
        $serieA[(int) $r["id_giocatore"]] = $r["squadra"];
    }

    // Incontri della giornata di Serie A, indicizzati per squadra
    $perSquadra = [];
    foreach (query_all("SELECT data_partita, ora_partita, squadra_casa, squadra_ospite
                        FROM NEW_CALENDARIO_SERIE_A
                        WHERE stagione = $stagione AND giornata = $giornataSerieA") as $p) {
        $base = ["data" => $p["data_partita"], "ora" => $p["ora_partita"]];
        $perSquadra[av_norm($p["squadra_casa"])]   = $base + ["avversaria" => $p["squadra_ospite"], "casa" => true];
        $perSquadra[av_norm($p["squadra_ospite"])] = $base + ["avversaria" => $p["squadra_casa"],   "casa" => false];
    }

    $out = [];
    foreach (query_all("SELECT id FROM NEW_GIOCATORI
                        WHERE stagione = $stagione AND squadra = $idSquadra") as $g) {
        $id  = (int) $g["id"];
        $sa  = $serieA[$id] ?? null;
        $sa  = ($sa !== null && trim($sa) !== "") ? $sa : null;
        $inc = $sa !== null ? ($perSquadra[av_norm($sa)] ?? null) : null;
        $out[] = [
            "id_giocatore" => $id,
            "serie_a"      => $sa,
            "avversaria"   => $inc["avversaria"] ?? null,
            "casa"         => $inc["casa"] ?? null,
            "data"         => $inc["data"] ?? null,
            "ora"          => $inc["ora"] ?? null,
        ];
    }

    return ["giornata_serie_a" => $giornataSerieA, "giocatori" => $out];
}
