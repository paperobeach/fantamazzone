<?php
// ============================================================
// backend/lib/AiAdmin.php
//
// Boilerplate comune agli endpoint admin della sezione "AI" che
// ricevono sempre POST con credenziali (ai_articoli.php,
// ai_contesto.php): metodo, lettura del body, validazione della
// stagione e verifica dell'abilitazione NEW_UTENZE.abilita_ai = 'Y'
// (password verificata lato server).
//
// Richiede connect.php e lib/AnthropicClient.php già inclusi.
//
// Funzioni:
//   aiadmin_input($conn)                         → array con il body, o termina con errore
//   aiadmin_testo($input,$campo,$max,$obbl)      → stringa validata (UTF-8, max caratteri)
//   aiadmin_id($input,$campo)                    → intero > 0, o termina con errore
// ============================================================

function aiadmin_input($conn): array
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        api_error("Metodo non consentito. Usare POST.", 405);
    }
    $input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $stagione = (int) ($input["stagione"] ?? 0);
    $utenza   = trim((string) ($input["utenza"] ?? ""));
    $password = (string) ($input["password"] ?? "");

    if ($stagione < 2000 || $stagione > 2100) {
        api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
    }
    if (!ai_verifica_abilitato($conn, $stagione, $utenza, $password)) {
        api_error("Credenziali non valide oppure utenza non abilitata alle funzioni AI", 403);
    }
    $input["stagione"] = $stagione;
    $input["utenza"]   = $utenza;
    return $input;
}

/** Testo libero validato: trim, lunghezza massima in caratteri, obbligatorio opzionale. */
function aiadmin_testo(array $input, string $campo, int $max, bool $obbligatorio = false): string
{
    $v = trim((string) ($input[$campo] ?? ""));
    if ($obbligatorio && $v === "") {
        api_error("Il campo '$campo' è obbligatorio", 400);
    }
    if (mb_strlen($v) > $max) {
        api_error("Il campo '$campo' supera la lunghezza massima di $max caratteri", 400);
    }
    return $v;
}

function aiadmin_id(array $input, string $campo = "id"): int
{
    $id = (int) ($input[$campo] ?? 0);
    if ($id < 1) api_error("Parametro '$campo' obbligatorio", 400);
    return $id;
}
