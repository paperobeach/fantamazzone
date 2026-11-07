<?php
// ============================================================
// api/record_storici.php
// GET ?competizione=CAMP|CHAMP  (default CAMP)
//     &ruolo=0..4               (0 = tutti i reparti, default 0)
//     &max=3..50                (righe per classifica, default 50)
//
// Record storici dei giocatori (Dashboard lega > Record storici), su tutte
// le stagioni. Risposta:
// {
//   competizione, ruolo, stagioni: [..], min_presenze: { carriera, stagione },
//   classifiche: [ { chiave, gruppo, titolo, descrizione, unita, decimali,
//                    record: [righe a pari merito al 1° posto],
//                    righe:  [ { pos, id, stagione, nome, ruolo, valore, squadra,
//                                logo, giocate, media, stagione_rif, giornata? } ] } ]
// }
// "id" + "stagione" individuano la scheda giocatore (/giocatori/:id?stagione=).
// Dettagli sulle regole in lib/RecordStorici.php.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/RecordStorici.php";

$comp  = strtoupper((string) param_str("competizione", false)) === "CHAMP" ? "CHAMP" : "CAMP";
$ruolo = (int) (param_int("ruolo", false) ?? 0);
if ($ruolo < 0 || $ruolo > 4) $ruolo = 0;
$max   = (int) (param_int("max", false) ?? REC_MAX_RIGHE);

api_success([
    "competizione" => $comp,
    "ruolo"        => $ruolo,
    "stagioni"     => rec_stagioni(),
    "min_presenze" => ["carriera" => REC_MIN_PRESENZE_MEDIA_CARRIERA, "stagione" => REC_MIN_PRESENZE_MEDIA_STAGIONE],
    "classifiche"  => rec_classifiche($comp, $ruolo, $max),
]);
