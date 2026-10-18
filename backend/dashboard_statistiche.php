<?php
// ============================================================
// api/dashboard_statistiche.php
// GET ?stagione=2026&id_squadra=3&competizione=CAMP|CHAMP
//
// Area "Statistiche" della Dashboard squadra, sezione "Risultati e
// classifica", per UNA competizione (campionato = default, Champions).
//
//   competizione   "CAMP" | "CHAMP"
//   riepilogo      giocate, V/N/P, punti, punti per partita, gol fatti e
//                  subiti, differenza reti, medie gol
//   posizione      posizione in classifica (campionato: generale; Champions:
//                  girone dell'ultima fase in cui la squadra compare)
//   casa_trasferta rendimento in casa e in trasferta
//   forma          ultime 10 partite (la UI mostra le ultime 5 o 10)
//   gol            clean sheet, partite senza segnare, con gol segnato e/o
//                  subito, Over/Under 1.5 - 2.5 - 3.5 (gol totali della partita)
//   serie          serie corrente e record (vittorie, imbattibilità,
//                  sconfitte, senza vittorie)
//   matrice        risultati contro ogni altra squadra
//   punteggi       distribuzione dei risultati per punteggio finale
//
// Regole: i risultati sono pubblici solo a giornata chiusa (o stagioni
// storiche). In Champions i gol sono quelli dei 90' (supplementari e
// rigori non rientrano nel punteggio). Vedi lib/DashboardSquadra.php.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");
$comp      = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Competizione non valida (CAMP o CHAMP)", 400);
$champ = $comp === "CHAMP";

// ── Contesto: giornate pubbliche ─────────────────────────────

$storica = $stagione !== sg_ultima_stagione();
$chiuse  = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

if (!query_one("SELECT id FROM NEW_SQUADRE WHERE id = $idSquadra AND stagione = $stagione")) {
    api_error("Squadra non trovata", 404);
}
$tutteSquadre = query_all("SELECT id, nome, logo FROM NEW_SQUADRE WHERE stagione = $stagione ORDER BY nome");

// ── Partite ──────────────────────────────────────────────────

$partite = $champ
    ? ds_partite_champ($stagione, $idSquadra, $pub)
    : ds_partite_camp($stagione, $idSquadra, $pub);

/** Riepilogo di un insieme di partite. */
function dst_riepilogo(array $partite): array
{
    $r = ["giocate" => 0, "vinte" => 0, "nulle" => 0, "perse" => 0,
          "punti" => 0, "golf" => 0, "gols" => 0];
    foreach ($partite as $p) {
        $r["giocate"]++;
        if ($p["segno"] === "V") $r["vinte"]++;
        elseif ($p["segno"] === "N") $r["nulle"]++;
        else $r["perse"]++;
        $r["punti"] += $p["punti"];
        $r["golf"]  += $p["golf"];
        $r["gols"]  += $p["gols"];
    }
    $n = $r["giocate"];
    $r["diff_reti"]         = $r["golf"] - $r["gols"];
    $r["punti_per_partita"] = $n > 0 ? round($r["punti"] / $n, 2) : null;
    $r["media_golf"]        = $n > 0 ? round($r["golf"] / $n, 2) : null;
    $r["media_gols"]        = $n > 0 ? round($r["gols"] / $n, 2) : null;
    return $r;
}

// ── Posizione in classifica ──────────────────────────────────

$posizione = null;
if (!$champ) {
    // stesso ordinamento di generale.php / dashboard_squadra.php
    $gen = query_all("SELECT id_squadra FROM NEW_GENERALE WHERE stagione = $stagione
                      ORDER BY punti DESC, golf DESC, gols DESC");
    foreach ($gen as $i => $g) {
        if ((int) $g["id_squadra"] === $idSquadra) {
            $posizione = ["posizione" => $i + 1, "squadre" => count($gen), "etichetta" => "Classifica generale"];
            break;
        }
    }
} else {
    // Classifica del girone: si prende il girone della squadra (se ne ha più
    // d'uno, l'ultimo nell'ordine alfabetico = fase più avanzata)
    $mioG = query_all("SELECT girone FROM NEW_GENERALE_CHAMP
                       WHERE stagione = $stagione AND squadra = $idSquadra ORDER BY girone DESC LIMIT 1");
    if ($mioG) {
        $gir = ds_esc((string) $mioG[0]["girone"]);
        $rows = query_all("SELECT squadra FROM NEW_GENERALE_CHAMP
                           WHERE stagione = $stagione AND girone = '$gir'
                           ORDER BY punti DESC, golf DESC, gols ASC");
        foreach ($rows as $i => $g) {
            if ((int) $g["squadra"] === $idSquadra) {
                $posizione = ["posizione" => $i + 1, "squadre" => count($rows),
                              "etichetta" => "Girone " . $mioG[0]["girone"]];
                break;
            }
        }
    }
}

// ── Casa / trasferta ─────────────────────────────────────────

$inCasa = array_values(array_filter($partite, fn($p) => $p["casa"] === true));
$inTras = array_values(array_filter($partite, fn($p) => $p["casa"] === false));

// ── Forma, gol, Over/Under, punteggi ─────────────────────────

$forma = array_map(fn($p) => [
    "giornata" => $p["giornata"], "segno" => $p["segno"], "golf" => $p["golf"], "gols" => $p["gols"],
    "avversario" => $p["avversario"], "logo_avv" => $p["logo_avv"], "casa" => $p["casa"], "turno" => $p["turno"],
], array_slice(array_reverse($partite), 0, 10));

$gol = ["clean_sheet" => 0, "senza_segnare" => 0, "con_gol_segnato" => 0,
        "con_gol_subito" => 0, "segnato_e_subito" => 0];
$ou  = ["1.5" => ["over" => 0, "under" => 0], "2.5" => ["over" => 0, "under" => 0],
        "3.5" => ["over" => 0, "under" => 0]];
$punteggi = [];
foreach ($partite as $p) {
    if ($p["gols"] === 0) $gol["clean_sheet"]++;
    if ($p["golf"] === 0) $gol["senza_segnare"]++;
    if ($p["golf"] > 0)   $gol["con_gol_segnato"]++;
    if ($p["gols"] > 0)   $gol["con_gol_subito"]++;
    if ($p["golf"] > 0 && $p["gols"] > 0) $gol["segnato_e_subito"]++;

    $tot = $p["golf"] + $p["gols"];
    foreach (["1.5" => 1.5, "2.5" => 2.5, "3.5" => 3.5] as $k => $soglia) {
        $ou[$k][$tot > $soglia ? "over" : "under"]++;
    }

    $k = $p["golf"] . "-" . $p["gols"];
    if (!isset($punteggi[$k])) $punteggi[$k] = ["golf" => $p["golf"], "gols" => $p["gols"], "n" => 0, "segno" => $p["segno"]];
    $punteggi[$k]["n"]++;
}
$punteggi = array_values($punteggi);
usort($punteggi, fn($a, $b) => [$b["n"], $b["golf"] - $b["gols"]] <=> [$a["n"], $a["golf"] - $a["gols"]]);

api_success([
    "stagione"       => $stagione,
    "competizione"   => $comp,
    "riepilogo"      => dst_riepilogo($partite),
    "posizione"      => $posizione,
    "casa_trasferta" => [
        "casa"      => dst_riepilogo($inCasa),
        "trasferta" => dst_riepilogo($inTras),
        "non_definite" => count($partite) - count($inCasa) - count($inTras),
    ],
    "forma"          => $forma,
    "gol"            => $gol,
    "over_under"     => $ou,
    "serie"          => ds_serie(array_map(fn($p) => $p["segno"], $partite)),
    "matrice"        => ds_matrice($tutteSquadre, $idSquadra, $partite),
    "punteggi"       => $punteggi,
]);
