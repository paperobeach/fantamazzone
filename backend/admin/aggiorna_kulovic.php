<?php
// ============================================================
// api/admin/aggiorna_kulovic.php
// POST { stagione }
//
// Ricalcola la tabella KULOVIC dai RISULTATI.
// Equivale al vecchio web_aggiorna_kulovic.php
// Range punteggi (adatta i valori alle regole della tua lega):
//   QUINTO_RANGE  : >= 100
//   QUARTO_RANGE  : 90-99
//   TERZO_RANGE   : 80-89
//   SECONDO_RANGE : 70-79
//   PRIMO_RANGE   : < 70  (basso)
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") api_error("Usare POST", 405);

$stagione = post_int("stagione");

$squadre = query_all("SELECT id, nome, logo FROM SQUADRE WHERE stagione = $stagione");
if (empty($squadre)) api_error("Nessuna squadra per questa stagione", 404);

// Soglie range (punteggio totale partita = ftotale)
// Adatta questi valori alle regole della tua lega
define("R1_MAX", 69.9);   // primo range (basso)  < 70
define("R2_MIN", 70.0);   define("R2_MAX", 79.9);
define("R3_MIN", 80.0);   define("R3_MAX", 89.9);
define("R4_MIN", 90.0);   define("R4_MAX", 99.9);
define("R5_MIN", 100.0);  // quinto range (alto) >= 100

mysqli_query($conn, "DELETE FROM KULOVIC WHERE STAGIONE = $stagione");

foreach ($squadre as $sq) {
    $id   = (int)$sq["id"];
    $nome = mysqli_real_escape_string($conn, $sq["nome"]);
    $logo = mysqli_real_escape_string($conn, $sq["logo"]);

    // Punteggi fatti (casa + ospite)
    $pf_casa = query_all("SELECT ftotale AS pt FROM RISULTATI
                          WHERE stagione = $stagione AND id_squadra = $id");
    $pf_osp  = query_all("SELECT ftotale_a AS pt FROM RISULTATI
                          WHERE stagione = $stagione AND id_squadra_a = $id");
    $punteggi_fatti = array_merge($pf_casa, $pf_osp);

    // Punteggi subiti
    $ps_casa = query_all("SELECT ftotale_a AS pt FROM RISULTATI
                          WHERE stagione = $stagione AND id_squadra = $id");
    $ps_osp  = query_all("SELECT ftotale AS pt FROM RISULTATI
                          WHERE stagione = $stagione AND id_squadra_a = $id");
    $punteggi_subiti = array_merge($ps_casa, $ps_osp);

    $count_range = function(array $rows, float $min, float $max) {
        return count(array_filter($rows, fn($r) => (float)$r["pt"] >= $min && (float)$r["pt"] <= $max));
    };
    $count_above = function(array $rows, float $min) {
        return count(array_filter($rows, fn($r) => (float)$r["pt"] >= $min));
    };
    $count_below = function(array $rows, float $max) {
        return count(array_filter($rows, fn($r) => (float)$r["pt"] <= $max));
    };

    // Range fatti
    $r1  = $count_below($punteggi_fatti, R1_MAX);
    $r2  = $count_range($punteggi_fatti, R2_MIN, R2_MAX);
    $r3  = $count_range($punteggi_fatti, R3_MIN, R3_MAX);
    $r4  = $count_range($punteggi_fatti, R4_MIN, R4_MAX);
    $r5  = $count_above($punteggi_fatti, R5_MIN);

    // Range subiti
    $r1a = $count_below($punteggi_subiti, R1_MAX);
    $r2a = $count_range($punteggi_subiti, R2_MIN, R2_MAX);
    $r3a = $count_range($punteggi_subiti, R3_MIN, R3_MAX);
    $r4a = $count_range($punteggi_subiti, R4_MIN, R4_MAX);
    $r5a = $count_above($punteggi_subiti, R5_MIN);

    // Culo/Sfiga: vinte/perse con punteggio inferiore all'avversario
    $partite = query_all("SELECT ftotale, ftotale_a, golf, gols FROM RISULTATI
                          WHERE stagione = $stagione AND id_squadra = $id");
    $partite_osp = query_all("SELECT ftotale_a AS ftotale, ftotale AS ftotale_a, gols AS golf, golf AS gols
                              FROM RISULTATI
                              WHERE stagione = $stagione AND id_squadra_a = $id");
    $tutte = array_merge($partite, $partite_osp);

    $culo = $sfiga = 0;
    $piutre = $piuduecinque = $menotre = $menoduecinque = 0;
    foreach ($tutte as $p) {
        $ft  = (float)$p["ftotale"];
        $fta = (float)$p["ftotale_a"];
        $g   = (int)$p["golf"];
        $gs  = (int)$p["gols"];
        $diff = $ft - $fta;

        if ($diff >= 3)           $piutre++;
        if ($diff >= 2.5)         $piuduecinque++;
        if ($diff <= -3)          $menotre++;
        if ($diff <= -2.5)        $menoduecinque++;
        if ($g > $gs && $ft < $fta) $culo++;   // vinto con punteggio inferiore
        if ($g < $gs && $ft > $fta) $sfiga++;  // perso con punteggio superiore
    }

    mysqli_query($conn, "INSERT INTO KULOVIC
        (STAGIONE, ID_SQUADRA, SQUADRA, LOGO,
         PRIMO_RANGE, SECONDO_RANGE, TERZO_RANGE, QUARTO_RANGE, QUINTO_RANGE,
         PRIMO_RANGE_A, SECONDO_RANGE_A, TERZO_RANGE_A, QUARTO_RANGE_A, QUINTO_RANGE_A,
         PIUTRE, PIUDUECINQUE, MENOTRE, MENODUECINQUE, CULO, SFIGA)
        VALUES ($stagione, $id, '$nome', '$logo',
                $r1, $r2, $r3, $r4, $r5,
                $r1a, $r2a, $r3a, $r4a, $r5a,
                $piutre, $piuduecinque, $menotre, $menoduecinque, $culo, $sfiga)");
}

api_success(["ok" => true, "squadre_elaborate" => count($squadre)]);
