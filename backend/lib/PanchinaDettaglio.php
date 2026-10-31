<?php
// ============================================================
// api/lib/PanchinaDettaglio.php
//
// Panchina e sostituzioni del dettaglio partita, condivise da
// dettaglio_partita.php e live_dettaglio.php.
//
// Il calcolo giornata (calcolo_giornata.php / SimulazioneGiornata.php)
// scrive in NEW_VOTI solo i giocatori che contano per il punteggio:
//   - titolari con voto;
//   - panchinari subentrati (maglia > 11, giocata = 1);
//   - riserve d'ufficio;
//   - titolari a zero (riserva d'ufficio o nessun sostituto).
// Un titolare sostituito da un panchinaro NON ha riga, e i panchinari
// rimasti in panchina nemmeno: per mostrarli si parte quindi dalla
// formazione (NEW_FORMAZIONI / formazione effettiva di Champions).
//
// Abbinamento sostituito -> sostituto (come in carica_formazione): per
// ogni ruolo, i titolari senza voto in ordine di maglia sono sostituiti
// prima dai panchinari subentrati (ordine di maglia), poi dalle riserve
// d'ufficio con ID fittizio (ordine di n progressivo); i titolari che
// restano senza coppia sono "senza voto, non sostituiti".
// ============================================================

require_once __DIR__ . "/RosaStorico.php";   // anagrafica storica (giocatori ceduti)

// Formazione (titolari + panchina) delle squadre, raggruppata per squadra.
// $tForm = tabella o sotto-query con STAGIONE, ID_SQUADRA, ID_GIOCATORE,
// GIORNATA, MAGLIA (NEW_FORMAZIONI oppure formazione_champions_sql_effettiva).
function pd_carica_formazione(int $stagione, int $giornata, string $tForm, array $idSquadre): array
{
    $ids = implode(",", array_unique(array_map("intval", $idSquadre)));
    if ($ids === "") return [];
    $righe = query_all("SELECT f.ID_SQUADRA AS id_squadra, f.ID_GIOCATORE AS id_giocatore,
            g.descrizione AS giocatore, g.ruolo, f.MAGLIA AS maglia
        FROM $tForm f
        JOIN " . rs_sql_anagrafica($stagione) . " g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
        WHERE f.STAGIONE = $stagione AND f.GIORNATA = $giornata
          AND f.ID_SQUADRA IN ($ids)
        ORDER BY f.ID_SQUADRA, f.MAGLIA");
    $per = [];
    foreach ($righe as $r) {
        $r["id_giocatore"] = (int) $r["id_giocatore"];
        $r["ruolo"]        = (int) $r["ruolo"];
        $r["maglia"]       = (int) $r["maglia"];
        $per[(int) $r["id_squadra"]][] = $r;
    }
    return $per;
}

// Costruisce la panchina di UNA squadra.
//  $form  : righe di formazione della squadra (pd_carica_formazione)
//  &$voti : righe voti della squadra (stesso formato di "giocatori");
//           vengono arricchite con "sostituisce" (nome del titolare
//           rilevato) e "in_panchina" (titolare sostituito che ha
//           comunque una riga a zero: il front end lo mostra solo in
//           panchina, per non duplicarlo).
// Ritorna l'elenco panchina: panchinari (subentrati / non entrati, in
// ordine di maglia) seguiti dai titolari sostituiti.
function pd_costruisci_panchina(array $form, array &$voti): array
{
    if (!$form) return [];

    $idxVoti = [];
    foreach ($voti as $i => $v) $idxVoti[(int) $v["id_giocatore"]] = $i;

    $titolari = [];
    $bench    = [];
    foreach ($form as $f) {
        if ($f["maglia"] >= 1 && $f["maglia"] <= 11) $titolari[] = $f;
        elseif ($f["maglia"] > 11)                   $bench[]    = $f;
    }

    // Titolari senza voto utile (nessuna riga, oppure giocata = 0), per ruolo
    $senzaVoto = [];
    foreach ($titolari as $t) {
        $i = $idxVoti[$t["id_giocatore"]] ?? null;
        $haVoto = $i !== null && (int) $voti[$i]["giocata"] === 1;
        if (!$haVoto) $senzaVoto[$t["ruolo"]][] = $t;
    }

    // Sostituti per ruolo: prima i panchinari entrati, poi le riserve d'ufficio fittizie
    $sostituti = [];
    foreach ($voti as $i => $v) {
        $ruolo = (int) $v["ruolo"];
        if (!empty($v["subentrato"])) {
            $sostituti[$ruolo][] = ["ord" => (int) $v["maglia"], "i" => $i];
        } elseif (!empty($v["riserva_ufficio"]) && !empty($v["ufficio_fittizio"])) {
            $sostituti[$ruolo][] = ["ord" => 1000 + abs((int) $v["id_giocatore"]), "i" => $i];
        }
    }
    foreach ($sostituti as &$lista) usort($lista, fn($a, $b) => $a["ord"] <=> $b["ord"]);
    unset($lista);

    // Abbinamento per ruolo
    $sostituitoDa = []; // id titolare => nome del sostituto
    foreach ($senzaVoto as $ruolo => $lista) {
        $rep = $sostituti[$ruolo] ?? [];
        foreach ($lista as $k => $t) {
            if (!isset($rep[$k])) break; // i restanti non sono stati sostituiti
            $voti[$rep[$k]["i"]]["sostituisce"] = $t["giocatore"];
            $sostituitoDa[$t["id_giocatore"]] = $voti[$rep[$k]["i"]]["giocatore"];
        }
    }

    $panchina = [];

    // Panchinari: entrati o rimasti in panchina
    foreach ($bench as $b) {
        $i = $idxVoti[$b["id_giocatore"]] ?? null;
        $entrato = $i !== null && !empty($voti[$i]["subentrato"]);
        $panchina[] = [
            "id_giocatore" => $b["id_giocatore"],
            "giocatore"    => $b["giocatore"],
            "ruolo"        => $b["ruolo"],
            "maglia"       => $b["maglia"],
            "stato"        => $entrato ? "subentrato" : "non_entrato",
            "sostituisce"  => $entrato ? ($voti[$i]["sostituisce"] ?? null) : null,
        ];
    }

    // Titolari sostituiti (da panchinaro o da riserva d'ufficio)
    foreach ($titolari as $t) {
        if (!isset($sostituitoDa[$t["id_giocatore"]])) continue;
        $i = $idxVoti[$t["id_giocatore"]] ?? null;
        if ($i !== null) $voti[$i]["in_panchina"] = true;
        $panchina[] = [
            "id_giocatore" => $t["id_giocatore"],
            "giocatore"    => $t["giocatore"],
            "ruolo"        => $t["ruolo"],
            "maglia"       => $t["maglia"],
            "stato"        => "sostituito",
            "sostituito_da" => $sostituitoDa[$t["id_giocatore"]],
        ];
    }

    return $panchina;
}
