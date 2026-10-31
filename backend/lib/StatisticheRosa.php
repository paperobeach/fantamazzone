<?php
// ============================================================
// backend/lib/StatisticheRosa.php
//
// Voti dei giocatori di una squadra INDIPENDENTEMENTE dallo schieramento
// (vista "Tutta la rosa" della Dashboard squadra).
//
// La vista "Schierati" legge NEW_VOTI / NEW_VOTI_CHAMP (giocata = 1), che
// contengono solo chi ha concorso al punteggio: titolari con voto,
// panchinari subentrati, riserve d'ufficio. Chi è rimasto in panchina, è
// stato sostituito o era in tribuna non ha una riga.
//
// Qui, per ogni giornata richiesta e per ogni giocatore che in quella
// giornata era nella rosa della squadra (storico: lib/RosaStorico.php):
//   - se ha una riga con giocata = 1 in NEW_VOTI/NEW_VOTI_CHAMP (era tra
//     chi ha concorso al punteggio) si usano i valori già salvati, così
//     la vista rosa contiene sempre TUTTO ciò che c'è nella vista schierati;
//   - altrimenti si usa il voto Serie A (NEW_VOTI_SERIE_A, stessa chiave
//     stagione/giornata/id_giocatore) e il fantavoto si calcola con le
//     stesse regole bonus/malus della stagione
//     (CalcolatoreVoti::puntiGiocatore + NEW_REGOLE_BONUS), cioè la stessa
//     formula usata dal calcolo giornata per il punteggio dei giocatori.
// Contano solo i giocatori con un voto reale: i "senza voto" (SV) o senza
// riga nei voti Serie A non compaiono, come nella vista schierati.
// Le riserve d'ufficio non sono giocatori della rosa e restano escluse.
//
// Va incluso DOPO connect.php.
// ============================================================

require_once __DIR__ . "/RosaStorico.php";
require_once __DIR__ . "/RegoleCalcolo.php";

/** La vista "Tutta la rosa" non è calcolabile per la stagione (mancano voti Serie A o regole). */
class StatisticheRosaNonDisponibili extends Exception {}

/** Bonus/malus della stagione, nel formato di CalcolatoreVoti::puntiGiocatore. */
function sr_bonus(int $stagione): array
{
    global $conn;
    try {
        $config = caricaConfigurazioneCalcolo($conn, $stagione);
    } catch (Throwable $e) {
        throw new StatisticheRosaNonDisponibili(
            "Regole di calcolo non configurate per la stagione $stagione: la vista \"Tutta la rosa\" non è disponibile."
        );
    }
    return $config["bonus"];
}

/**
 * Voti dei giocatori della rosa nelle giornate indicate.
 *
 * @param int[] $giornate giornate (già pubbliche) della competizione in cui la squadra ha giocato
 * @return array righe nello stesso formato letto da dashboard_ruoli.php:
 *   giornata, id_giocatore, voto, totale, reti, retis, nome, ruolo_g
 * @throws StatisticheRosaNonDisponibili
 */
function sr_voti_rosa(int $stagione, int $idSquadra, bool $champ, array $giornate): array
{
    $giornate = array_values(array_unique(array_map("intval", $giornate)));
    if (!$giornate) return [];
    $listaG = implode(",", $giornate);

    // Chi ha concorso al punteggio (valori già salvati)
    $tabVoti = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
    $schierati = [];   // giornata => id => riga
    foreach (query_all("SELECT giornata, id_giocatore, voto, totale, reti, retis FROM $tabVoti
                        WHERE stagione = $stagione AND id_squadra = $idSquadra AND giocata = 1
                          AND id_giocatore > 0 AND giornata IN ($listaG)") as $v) {
        $schierati[(int) $v["giornata"]][(int) $v["id_giocatore"]] = $v;
    }

    // Giocatori della rosa nel tempo (i giocatori schierati ne fanno comunque parte)
    $periodi = rs_rosa_squadra($stagione, $idSquadra);
    $ids = array_keys($periodi);
    foreach ($schierati as $perGiocatore) {
        foreach (array_keys($perGiocatore) as $id) $ids[$id] = $id;
    }
    $ids = array_values(array_unique(array_map("intval", $ids)));
    if (!$ids) return [];
    $listaId = implode(",", $ids);

    // Voti Serie A
    $serieA = [];      // giornata => id => riga
    foreach (query_all("SELECT giornata, id_giocatore, voto, sv, gf, gs, rp, rs, rf, au, amm, esp, ass
                        FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND giornata IN ($listaG) AND id_giocatore IN ($listaId)") as $v) {
        $serieA[(int) $v["giornata"]][(int) $v["id_giocatore"]] = $v;
    }
    if (!$serieA && !$schierati) {
        throw new StatisticheRosaNonDisponibili(
            "Voti Serie A non disponibili per la stagione $stagione: la vista \"Tutta la rosa\" non è disponibile."
        );
    }

    // Anagrafica (anche dei ceduti)
    $anagrafica = [];
    foreach (query_all("SELECT g.id, g.descrizione, g.ruolo FROM " . rs_sql_anagrafica($stagione) . " g
                        WHERE g.id IN ($listaId)") as $a) {
        $anagrafica[(int) $a["id"]] = $a;
    }

    $bonus = null;     // caricato solo se serve
    $out = [];
    foreach ($giornate as $g) {
        foreach ($ids as $id) {
            $anag = $anagrafica[$id] ?? null;
            if ($anag === null) continue;   // giocatore non in anagrafica

            if (isset($schierati[$g][$id])) {
                $v = $schierati[$g][$id];
                $out[] = [
                    "giornata" => $g, "id_giocatore" => $id,
                    "voto" => (float) $v["voto"], "totale" => (float) $v["totale"],
                    "reti" => (int) $v["reti"], "retis" => (int) $v["retis"],
                    "nome" => $anag["descrizione"], "ruolo_g" => (int) $anag["ruolo"],
                ];
                continue;
            }

            if (!rs_in_rosa($periodi[$id] ?? [], $g)) continue;
            $s = $serieA[$g][$id] ?? null;
            if ($s === null || $s["sv"] === "Y" || $s["voto"] === null) continue;

            if ($bonus === null) $bonus = sr_bonus($stagione);
            $totale = CalcolatoreVoti::puntiGiocatore([
                "voto" => (float) $s["voto"], "sv" => false,
                "gf" => (int) $s["gf"], "gs" => (int) $s["gs"], "rp" => (int) $s["rp"],
                "rf" => (int) $s["rf"], "rs" => (int) $s["rs"], "au" => (int) $s["au"],
                "amm" => (int) $s["amm"], "esp" => (int) $s["esp"], "ass" => (int) $s["ass"],
            ], $bonus);
            if ($totale === null) continue;

            $out[] = [
                "giornata" => $g, "id_giocatore" => $id,
                "voto" => (float) $s["voto"], "totale" => $totale,
                "reti" => (int) $s["gf"], "retis" => (int) $s["gs"],
                "nome" => $anag["descrizione"], "ruolo_g" => (int) $anag["ruolo"],
            ];
        }
    }
    return $out;
}

/**
 * Presenze e medie per giocatore: id_giocatore => [presenze, media_netto, media_lordo].
 * Usata dal Top 11 di "Prepara la prossima partita".
 */
function sr_medie_per_giocatore(array $righe): array
{
    $acc = [];
    foreach ($righe as $r) {
        $id = (int) $r["id_giocatore"];
        $acc[$id]["voti"][]   = (float) $r["voto"];
        $acc[$id]["totali"][] = (float) $r["totale"];
    }
    $out = [];
    foreach ($acc as $id => $a) {
        $n = count($a["voti"]);
        $out[$id] = [
            "presenze"    => $n,
            "media_netto" => round(array_sum($a["voti"]) / $n, 2),
            "media_lordo" => round(array_sum($a["totali"]) / $n, 2),
        ];
    }
    return $out;
}
