<?php
// ============================================================
// backend/lib/ListaGiocatori.php
//
// AGGIORNAMENTO DELLA LISTA GIOCATORI (listone) della stagione in corso.
//
// Il file caricato e' il listone ufficiale delle quotazioni (foglio "Tutti":
// Id, R, RM, Nome, Squadra, ...). L'Id e' lo stesso "Cod." gia' usato come
// NEW_GIOCATORI_BASE_ASTA.id_giocatore / NEW_GIOCATORI.id.
//
// Il confronto con il DB individua, per la stagione:
//   - NUOVI        id del file mai visti in stagione. Vengono inseriti come
//                  giocatori svincolati (NEW_GIOCATORI_SVINCOLATI) e nel
//                  listone (NEW_GIOCATORI_BASE_ASTA, in_lista = 'Y').
//   - RIENTRATI    id gia' marcati "non in lista" che ricompaiono nel file:
//                  tornano in lista (in_lista = 'Y').
//   - FUORI LISTA  giocatori in rosa o svincolati assenti dal file. Restano
//                  dove sono (le statistiche restano consultabili) ma
//                  NEW_GIOCATORI_BASE_ASTA.in_lista passa a 'N':
//                    * svincolati  -> non acquistabili da nessuna squadra;
//                    * in una rosa -> "giocatore ceduto": non utilizzabili
//                      in formazione (fissi in tribuna). Vengono tolti
//                      anche dalle formazioni gia' inserite per le giornate
//                      non ancora chiuse.
//
// Il flag e' in un'unica tabella (non in NEW_GIOCATORI/SVINCOLATI) cosi'
// segue il giocatore anche se un mercato lo sposta tra rosa e svincolati.
// Richiede la migrazione Script DB/migrazione_aggiorna_lista_giocatori.sql.
// ============================================================

require_once __DIR__ . "/StatoGiornata.php";

const LG_RUOLI = ["P" => 1, "D" => 2, "C" => 3, "A" => 4];

/** La colonna NEW_GIOCATORI_BASE_ASTA.in_lista esiste? (risultato in cache) */
function lg_colonna_pronta(): bool
{
    static $pronta = null;
    if ($pronta === null) {
        global $conn;
        try {
            $r = mysqli_query($conn, "SHOW COLUMNS FROM NEW_GIOCATORI_BASE_ASTA LIKE 'in_lista'");
            $pronta = $r && mysqli_num_rows($r) > 0;
        } catch (Throwable $e) {
            $pronta = false;   // tabella/colonna assenti: la funzione non e' attiva
        }
    }
    return $pronta;
}

/**
 * Giocatori NON piu' in lista della stagione: id => true.
 * Vuoto se la migrazione non e' ancora stata eseguita (nessun effetto sul resto dell'app).
 * @return array<int,true>
 */
function lg_fuori_lista(int $stagione): array
{
    if (!lg_colonna_pronta()) return [];
    $out = [];
    foreach (query_all("SELECT id_giocatore FROM NEW_GIOCATORI_BASE_ASTA
                        WHERE stagione = $stagione AND in_lista = 'N'") as $r) {
        $out[(int) $r["id_giocatore"]] = true;
    }
    return $out;
}

// ------------------------------------------------------------
// Lettura del file .xlsx (parser puro PHP: ZipArchive + SimpleXML)
// ------------------------------------------------------------

/** Minuscolo / lunghezza con fallback se mbstring non e' disponibile. */
function lg_minuscolo(string $s): string { return function_exists("mb_strtolower") ? mb_strtolower($s, "UTF-8") : strtolower($s); }
function lg_lunghezza(string $s): int { return function_exists("mb_strlen") ? mb_strlen($s, "UTF-8") : strlen($s); }

function lg_col_indice(string $col): int
{
    $col = strtoupper($col);
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) $n = $n * 26 + (ord($col[$i]) - 64);
    return $n - 1;
}

/**
 * Righe di un foglio: array di [indice_colonna => valore_stringa].
 * Lancia Exception se il file non e' valido o il foglio manca.
 */
function lg_leggi_foglio(string $percorso, string $foglio): array
{
    if (!class_exists("ZipArchive")) throw new Exception("Estensione PHP ZipArchive non disponibile sul server");
    $zip = new ZipArchive();
    if ($zip->open($percorso) !== true) throw new Exception("Impossibile aprire il file: non sembra un .xlsx valido");

    $wbXml   = $zip->getFromName("xl/workbook.xml");
    $relsXml = $zip->getFromName("xl/_rels/workbook.xml.rels");
    if ($wbXml === false || $relsXml === false) {
        $zip->close();
        throw new Exception("Struttura del file .xlsx non valida");
    }

    $wb = simplexml_load_string($wbXml);
    $rId = null;
    $presenti = [];
    foreach ($wb->sheets->sheet as $s) {
        $nome = (string) $s->attributes()["name"];
        $presenti[] = $nome;
        if ($rId === null && strcasecmp($nome, $foglio) === 0) {
            $rId = (string) $s->attributes("http://schemas.openxmlformats.org/officeDocument/2006/relationships")["id"];
        }
    }
    if ($rId === null) {
        $zip->close();
        throw new Exception("Il foglio \"$foglio\" non e' presente nel file (fogli trovati: " . implode(", ", $presenti) . ")");
    }

    $target = null;
    foreach (simplexml_load_string($relsXml)->Relationship as $rel) {
        if ((string) $rel["Id"] === $rId) { $target = (string) $rel["Target"]; break; }
    }
    if ($target === null) {
        $zip->close();
        throw new Exception("Impossibile risolvere il foglio \"$foglio\" nel workbook");
    }
    $pathFoglio = $target[0] === "/" ? ltrim($target, "/") : "xl/" . $target;

    $shared = [];
    $sharedXml = $zip->getFromName("xl/sharedStrings.xml");
    if ($sharedXml !== false) {
        foreach (simplexml_load_string($sharedXml)->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $t = "";
                foreach ($si->r as $r) $t .= (string) $r->t;
                $shared[] = $t;
            }
        }
    }

    $sheetXml = $zip->getFromName($pathFoglio);
    $zip->close();
    if ($sheetXml === false) throw new Exception("Impossibile leggere il contenuto del foglio \"$foglio\"");

    $righe = [];
    foreach (simplexml_load_string($sheetXml)->sheetData->row as $rowXml) {
        $numero = (int) $rowXml["r"];
        $dati = [];
        $pos = 0;
        foreach ($rowXml->c as $c) {
            $idx = $pos;
            if (preg_match('/^([A-Z]+)\d+$/', (string) $c["r"], $m)) $idx = lg_col_indice($m[1]);
            $pos = $idx + 1;
            $tipo = (string) $c["t"];
            if ($tipo === "s")              $v = $shared[(int) $c->v] ?? "";
            elseif ($tipo === "inlineStr")  $v = (string) ($c->is->t ?? "");
            else                            $v = (string) $c->v;
            $dati[$idx] = $v;
        }
        $righe[$numero ?: count($righe) + 1] = $dati;   // chiave = numero di riga del file
    }
    return $righe;
}

/** Intestazioni accettate -> campo interno. */
function lg_mappa_intestazioni(): array
{
    return [
        "id" => "id", "cod" => "id", "cod." => "id", "id_giocatore" => "id",
        "r" => "ruolo", "ruolo" => "ruolo",
        "nome" => "nome",
        "squadra" => "squadra", "squadra_serie_a" => "squadra",
    ];
}

/**
 * Legge il listone dal foglio "Tutti".
 * @return array{titolo:string, giocatori:array<int,array>, errori:array}
 *   giocatori: id => [id, nome, ruolo(1-4), squadra, riga]
 */
function lg_leggi_listone(string $percorso, int $stagione): array
{
    $righe = lg_leggi_foglio($percorso, "Tutti");
    if (!$righe) throw new Exception("Il foglio \"Tutti\" non contiene dati");

    // Titolo (prima riga non vuota, es. "Quotazioni Fantacalcio Stagione 2026 27")
    $titolo = "";
    foreach ($righe as $r) {
        $t = trim(implode(" ", array_map("strval", $r)));
        if ($t !== "") { $titolo = $t; break; }
    }

    // Riga di intestazione: la prima (entro le prime 10) con Id, Nome, Squadra
    $mappa = lg_mappa_intestazioni();
    $col = [];
    $rigaInt = null;
    $n = 0;
    foreach ($righe as $numero => $r) {
        if (++$n > 10) break;
        $trovati = [];
        foreach ($r as $i => $label) {
            $k = lg_minuscolo(trim((string) $label));
            if (isset($mappa[$k]) && !isset($trovati[$mappa[$k]])) $trovati[$mappa[$k]] = $i;
        }
        if (isset($trovati["id"], $trovati["nome"], $trovati["squadra"], $trovati["ruolo"])) {
            $col = $trovati;
            $rigaInt = $numero;
            break;
        }
    }
    if ($rigaInt === null) {
        throw new Exception("Intestazioni non trovate nel foglio \"Tutti\": servono le colonne Id, R, Nome, Squadra");
    }

    $errori = [];
    $giocatori = [];
    foreach ($righe as $numero => $r) {
        if ($numero <= $rigaInt) continue;
        $get = fn(string $k) => isset($r[$col[$k]]) ? trim((string) $r[$col[$k]]) : "";
        $id = $get("id"); $nome = $get("nome"); $ruolo = strtoupper($get("ruolo")); $squadra = $get("squadra");
        if ($id === "" && $nome === "" && $ruolo === "" && $squadra === "") continue;   // riga vuota

        // L'Id puo' arrivare come "5841" o "5841.0"
        if ($id !== "" && preg_match('/^\d+\.0+$/', $id)) $id = (string) (int) $id;

        if ($id === "" || !ctype_digit($id) || (int) $id <= 0) {
            $errori[] = ["riga" => $numero, "campo" => "Id", "messaggio" => "Campo obbligatorio: deve essere un codice numerico"];
        } elseif (isset($giocatori[(int) $id])) {
            $errori[] = ["riga" => $numero, "campo" => "Id", "messaggio" => "Valore duplicato nel file (gia' presente alla riga {$giocatori[(int) $id]["riga"]})"];
        }
        if (!isset(LG_RUOLI[$ruolo])) {
            $errori[] = ["riga" => $numero, "campo" => "R", "messaggio" => "Ruolo non valido: deve essere P, D, C o A"];
        }
        if ($nome === "") {
            $errori[] = ["riga" => $numero, "campo" => "Nome", "messaggio" => "Campo obbligatorio"];
        } elseif (lg_lunghezza($nome) > 50) {
            $errori[] = ["riga" => $numero, "campo" => "Nome", "messaggio" => "Massimo 50 caratteri"];
        }
        if ($squadra === "") {
            $errori[] = ["riga" => $numero, "campo" => "Squadra", "messaggio" => "Campo obbligatorio"];
        }
        if ($id !== "" && ctype_digit($id) && !isset($giocatori[(int) $id])) {
            $giocatori[(int) $id] = [
                "id" => (int) $id, "nome" => $nome, "ruolo" => LG_RUOLI[$ruolo] ?? 0,
                "squadra" => $squadra, "riga" => $numero,
            ];
        }
    }
    if (!$giocatori && !$errori) $errori[] = ["riga" => 0, "campo" => "file", "messaggio" => "Nessun giocatore trovato nel foglio \"Tutti\""];

    // La stagione del file deve coincidere con quella in corso (titolo "... Stagione 2026 27")
    if (preg_match('/\b(20\d{2})\s*[\/\-\s]\s*(\d{2}|20\d{2})\b/', $titolo, $m) && (int) $m[1] !== $stagione) {
        $errori[] = ["riga" => 1, "campo" => "titolo",
                     "messaggio" => "Il file e' relativo alla stagione {$m[1]}, ma la stagione in corso e' $stagione"];
    }
    return ["titolo" => $titolo, "giocatori" => $giocatori, "errori" => $errori];
}

// ------------------------------------------------------------
// Confronto file <-> DB
// ------------------------------------------------------------

/**
 * Giocatori della stagione presenti nel DB applicativo.
 * @return array<int,array{id:int,nome:string,ruolo:int,nazione:string,dove:string,squadra_lega:?int,serie_a:string,in_lista:string}>
 *   dove: "ROSA" (NEW_GIOCATORI) | "SVINCOLATO" | "LISTONE" (solo in BASE_ASTA)
 */
function lg_giocatori_db(int $stagione): array
{
    $out = [];
    foreach (query_all("SELECT id_giocatore, nome, ruolo, squadra_serie_a, nazione, in_lista
                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione") as $r) {
        $out[(int) $r["id_giocatore"]] = [
            "id" => (int) $r["id_giocatore"], "nome" => (string) $r["nome"], "ruolo" => (int) $r["ruolo"],
            "nazione" => (string) $r["nazione"], "dove" => "LISTONE", "squadra_lega" => null,
            "serie_a" => (string) $r["squadra_serie_a"], "in_lista" => (string) $r["in_lista"],
        ];
    }
    foreach (query_all("SELECT id, descrizione, ruolo, nazione FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione") as $r) {
        $id = (int) $r["id"];
        $out[$id] = array_merge($out[$id] ?? ["serie_a" => "", "in_lista" => "Y"], [
            "id" => $id, "nome" => (string) $r["descrizione"], "ruolo" => (int) $r["ruolo"],
            "nazione" => (string) $r["nazione"], "dove" => "SVINCOLATO", "squadra_lega" => null,
        ]);
    }
    foreach (query_all("SELECT id, descrizione, ruolo, nazione, squadra FROM NEW_GIOCATORI WHERE stagione = $stagione") as $r) {
        $id = (int) $r["id"];
        $out[$id] = array_merge($out[$id] ?? ["serie_a" => "", "in_lista" => "Y"], [
            "id" => $id, "nome" => (string) $r["descrizione"], "ruolo" => (int) $r["ruolo"],
            "nazione" => (string) $r["nazione"], "dove" => "ROSA", "squadra_lega" => (int) $r["squadra"],
        ]);
    }
    return $out;
}

/** Nomi delle squadre di lega della stagione: id => nome. */
function lg_nomi_squadre(int $stagione): array
{
    $out = [];
    foreach (query_all("SELECT id, nome FROM NEW_SQUADRE WHERE stagione = $stagione") as $r) $out[(int) $r["id"]] = (string) $r["nome"];
    return $out;
}

/**
 * Confronta il listone con il DB. Non scrive nulla.
 * @return array{nuovi:array,rientrati:array,fuori_lista:array,gia_fuori_lista:int,invariati:int,totale_file:int,totale_db:int,avvisi:string[]}
 */
function lg_confronta(int $stagione, array $listone): array
{
    $db = lg_giocatori_db($stagione);
    $squadre = lg_nomi_squadre($stagione);

    $nuovi = []; $rientrati = []; $fuori = []; $giaFuori = 0; $invariati = 0;

    foreach ($listone as $id => $g) {
        // Un id presente solo nel listone (BASE_ASTA) ma ne' in rosa ne' svincolato non e' visibile nell'app: si tratta da nuovo
        if (!isset($db[$id]) || $db[$id]["dove"] === "LISTONE") {
            $nuovi[] = ["id" => $id, "nome" => $g["nome"], "ruolo" => $g["ruolo"], "squadra_serie_a" => $g["squadra"]];
        } elseif ($db[$id]["in_lista"] === "N") {
            $rientrati[] = ["id" => $id, "nome" => $db[$id]["nome"], "ruolo" => $db[$id]["ruolo"], "squadra_serie_a" => $g["squadra"],
                            "dove" => $db[$id]["dove"]];
        } else {
            $invariati++;
        }
    }

    foreach ($db as $id => $g) {
        if (isset($listone[$id]) || $g["dove"] === "LISTONE") continue;   // solo in BASE_ASTA: non e' un giocatore dell'app
        if ($g["in_lista"] === "N") { $giaFuori++; continue; }
        $fuori[] = [
            "id" => $id, "nome" => $g["nome"], "ruolo" => $g["ruolo"], "squadra_serie_a" => $g["serie_a"],
            "dove" => $g["dove"],
            "id_squadra_lega" => $g["squadra_lega"],
            "squadra_lega" => $g["squadra_lega"] !== null ? ($squadre[$g["squadra_lega"]] ?? "Squadra {$g["squadra_lega"]}") : null,
        ];
    }
    $ordina = fn($a, $b) => [$a["ruolo"], $a["nome"]] <=> [$b["ruolo"], $b["nome"]];
    usort($nuovi, $ordina); usort($rientrati, $ordina); usort($fuori, $ordina);

    // Controlli di buon senso: un file sbagliato farebbe uscire di lista mezza lega
    $avvisi = [];
    $attivi = $invariati + count($fuori);
    if ($attivi > 0 && count($fuori) > 0.3 * $attivi) {
        $avvisi[] = "Il " . round(100 * count($fuori) / $attivi) . "% dei giocatori attualmente in lista risulta assente dal file: verifica di aver caricato il file giusto.";
    }
    if (count($nuovi) > 0.3 * max(1, count($db))) {
        $avvisi[] = "Il file contiene molti giocatori nuovi (" . count($nuovi) . "): verifica che sia relativo alla stagione $stagione.";
    }

    return [
        "nuovi" => $nuovi, "rientrati" => $rientrati, "fuori_lista" => $fuori,
        "gia_fuori_lista" => $giaFuori, "invariati" => $invariati,
        "totale_file" => count($listone),
        "totale_db" => count(array_filter($db, fn($g) => $g["dove"] !== "LISTONE")),
        "avvisi" => $avvisi,
    ];
}

// ------------------------------------------------------------
// Applicazione
// ------------------------------------------------------------

/**
 * Applica l'aggiornamento. Tocca solo tabelle InnoDB (BASE_ASTA, SVINCOLATI) in transazione;
 * NEW_GIOCATORI (MyISAM) non viene mai modificata. Poi ripulisce le formazioni dei giocatori
 * di rosa usciti di lista e allinea il raccordo storico per i nuovi.
 * @return array esito (contatori)
 */
function lg_applica(int $stagione, array $listone, array $diff): array
{
    global $conn;
    $esc = fn($s) => mysqli_real_escape_string($conn, (string) $s);

    $db = lg_giocatori_db($stagione);
    mysqli_begin_transaction($conn);
    try {
        // 1) NUOVI -> svincolati + listone
        foreach ($diff["nuovi"] as $n) {
            $id = (int) $n["id"];
            $nome = $esc($n["nome"]);
            $sa = $esc($n["squadra_serie_a"]);
            $ruolo = (int) $n["ruolo"];
            if (!mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_SVINCOLATI (id, stagione, descrizione, ruolo, nazione)
                                      VALUES ($id, $stagione, '$nome', $ruolo, '')")) {
                throw new Exception("Inserimento svincolato $id: " . mysqli_error($conn));
            }
            mysqli_query($conn, "DELETE FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione AND id_giocatore = $id");
            if (!mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_BASE_ASTA
                                      (id_giocatore, stagione, nome, ruolo, squadra_serie_a, nazione, id_squadra_lega, crediti, in_lista)
                                      VALUES ($id, $stagione, '$nome', $ruolo, '$sa', '', NULL, 0, 'Y')")) {
                throw new Exception("Inserimento listone $id: " . mysqli_error($conn));
            }
        }

        // 2) RIENTRATI -> di nuovo in lista
        foreach ($diff["rientrati"] as $r) {
            $id = (int) $r["id"];
            if (!mysqli_query($conn, "UPDATE NEW_GIOCATORI_BASE_ASTA SET in_lista = 'Y' WHERE stagione = $stagione AND id_giocatore = $id")) {
                throw new Exception("Ripristino $id: " . mysqli_error($conn));
            }
        }

        // 3) FUORI LISTA -> in_lista = 'N' (creando la riga di listone se mancasse)
        foreach ($diff["fuori_lista"] as $f) {
            $id = (int) $f["id"];
            $g = $db[$id];
            $q = mysqli_query($conn, "UPDATE NEW_GIOCATORI_BASE_ASTA SET in_lista = 'N' WHERE stagione = $stagione AND id_giocatore = $id");
            if (!$q) throw new Exception("Aggiornamento $id: " . mysqli_error($conn));
            $esiste = query_one("SELECT COUNT(*) AS n FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione AND id_giocatore = $id");
            if ((int) $esiste["n"] === 0) {
                $idl = $g["squadra_lega"] === null ? "NULL" : (int) $g["squadra_lega"];
                if (!mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_BASE_ASTA
                                          (id_giocatore, stagione, nome, ruolo, squadra_serie_a, nazione, id_squadra_lega, crediti, in_lista)
                                          VALUES ($id, $stagione, '" . $esc($g["nome"]) . "', " . (int) $g["ruolo"] . ", '" . $esc($g["serie_a"]) . "',
                                                  '" . $esc($g["nazione"]) . "', $idl, 0, 'N')")) {
                    throw new Exception("Inserimento listone $id: " . mysqli_error($conn));
                }
            }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    // 4) I giocatori di rosa usciti di lista non devono restare nelle formazioni delle giornate aperte
    $idRosaCeduti = [];
    foreach ($diff["fuori_lista"] as $f) if ($f["dove"] === "ROSA") $idRosaCeduti[] = (int) $f["id"];
    $formazioniPulite = 0;
    if ($idRosaCeduti) {
        $ult = query_one("SELECT COALESCE(MAX(giornata), 0) AS g FROM NEW_CALENDARIO_CK WHERE stagione = $stagione AND ck_giocata = 'S'");
        $gUlt = (int) $ult["g"];
        $in = implode(",", $idRosaCeduti);
        foreach (["NEW_FORMAZIONI", "NEW_FORMAZIONI_CHAMP", "NEW_RIGORISTI_CHAMP"] as $tab) {
            mysqli_query($conn, "DELETE FROM $tab WHERE STAGIONE = $stagione AND ID_GIOCATORE IN ($in) AND GIORNATA > $gUlt");
            $formazioniPulite += max(0, mysqli_affected_rows($conn));
        }
    }

    // 5) Raccordo storico per id: collega i nuovi giocatori alle stagioni precedenti (non blocca l'aggiornamento)
    $raccordo = null;
    if ($diff["nuovi"]) {
        try {
            require_once __DIR__ . "/RaccordoGiocatori.php";
            if (rg_tabelle_esistono()) $raccordo = rg_allinea($stagione);
        } catch (Throwable $e) {
            error_log("Raccordo giocatori ($stagione): " . $e->getMessage());
        }
    }

    return [
        "nuovi" => count($diff["nuovi"]),
        "rientrati" => count($diff["rientrati"]),
        "fuori_lista" => count($diff["fuori_lista"]),
        "fuori_lista_in_rosa" => count($idRosaCeduti),
        "fuori_lista_svincolati" => count($diff["fuori_lista"]) - count($idRosaCeduti),
        "formazioni_ripulite" => $formazioniPulite,
        "raccordo" => $raccordo,
    ];
}
