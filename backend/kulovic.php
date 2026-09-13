<?php
// ============================================================
// api/kulovic.php
// GET ?stagione=2024
// GET ?stagione=2024&id_squadra=3
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione   = param_int("stagione");
$id_squadra = param_int("id_squadra", false);

$where = ["STAGIONE = $stagione"];
if ($id_squadra !== null) $where[] = "ID_SQUADRA = $id_squadra";
$where_sql = implode(" AND ", $where);

$sql = "SELECT
            ID_SQUADRA    AS id_squadra,
            SQUADRA       AS squadra,
            LOGO          AS logo,
            PRIMO_RANGE, SECONDO_RANGE, TERZO_RANGE, QUARTO_RANGE, QUINTO_RANGE,
            PRIMO_RANGE_A, SECONDO_RANGE_A, TERZO_RANGE_A, QUARTO_RANGE_A, QUINTO_RANGE_A,
            PIUTRE, PIUDUECINQUE, MENOTRE, MENODUECINQUE,
            CULO, SFIGA
        FROM KULOVIC
        WHERE $where_sql
        ORDER BY SQUADRA";

api_success(query_all($sql));
