# API Backend — LegaFantaMazzone
## Guida al deploy su Altervista

---

## Struttura cartelle da caricare

```
/                          ← root pubblica Altervista
├── .htaccess              ← copia di htaccess_root.txt (rinomina!)
├── index.html             ← generato da: npm run build
├── assets/                ← generato da: npm run build
└── api/
    ├── .htaccess          ← questo file
    ├── connect.php        ← NON esposto direttamente
    ├── generale.php
    ├── calendario.php
    ├── squadre.php
    ├── dettaglio_partita.php
    ├── top_flop.php
    ├── statistiche.php
    ├── marcatori.php
    ├── login.php
    ├── kulovic.php
    ├── messaggi.php
    ├── champions.php
    ├── formazioni.php
    ├── schedina.php
    ├── sistema.php
    ├── penalita.php
    └── admin/
        ├── aggiorna_generale.php
        ├── aggiorna_statistiche.php
        ├── aggiorna_top_flop.php
        ├── aggiorna_kulovic.php
        ├── chiudi_giornata.php
        ├── risultati.php
        └── voti.php
```

---

## Prima di caricare: configura connect.php

Apri `api/connect.php` e aggiorna:

```php
$allowed_origin = "https://tuonome.altervista.org";  // il tuo dominio esatto
$host     = "localhost";
$username = "tuonome";          // username Altervista = nome sito
$password = "la_tua_password";
$database = "my_tuonome";       // database Altervista = my_ + nome sito
```

---

## Mappa completa degli endpoint

### Lettura (GET)

| Endpoint | Parametri | Descrizione |
|---|---|---|
| `api/sistema.php?tipo=stagioni` | — | Lista stagioni disponibili |
| `api/sistema.php?stagione=` | stagione | Parametri configurazione |
| `api/generale.php?stagione=` | stagione | Classifica generale |
| `api/calendario.php?stagione=` | stagione | Calendario + risultati |
| `api/squadre.php?stagione=` | stagione | Tutte le squadre |
| `api/squadre.php?stagione=&id=` | stagione, id | Squadra + rosa |
| `api/dettaglio_partita.php?stagione=&giornata=` | stagione, giornata, [id_squadra] | Dettaglio partite (voti giocatori, modificatori) |
| `api/statistiche.php?stagione=` | stagione, [id_squadra], [ruolo], [ordine] | Statistiche giocatori |
| `api/marcatori.php?stagione=&tipo=` | stagione, tipo=(marcatori\|assist\|migliori\|peggiori) | Classifiche individuali |
| `api/top_flop.php?stagione=` | stagione, [tipo=top\|flop] | Top11 / Flop11 |
| `api/kulovic.php?stagione=` | stagione, [id_squadra] | Stats fortuna/sfortuna |
| `api/messaggi.php?stagione=&id=` | stagione, id, [tipo=inviati] | Messaggi utente |
| `api/champions.php?stagione=&sezione=` | stagione, sezione=(classifica\|gironi\|note) | Coppa Champions |
| `api/formazioni.php?stagione=&giornata=&id_squadra=` | stagione, giornata, id_squadra | Formazione inserita |
| `api/schedina.php?stagione=&giornata=` | stagione, giornata, [id] | Pronostici |
| `api/schedina.php?stagione=&tipo=classifica` | stagione | Classifica schedina |
| `api/penalita.php?stagione=` | stagione | Penalità squadre |

### Autenticazione (POST)

| Endpoint | Body | Descrizione |
|---|---|---|
| `api/login.php` | `stagione, utenza, password` | Login utente |

### Inserimento dati utente (POST)

| Endpoint | Body | Descrizione |
|---|---|---|
| `api/formazioni.php` | `stagione, giornata, id_squadra, giocatori[]` | Inserisce formazione |
| `api/messaggi.php` | `stagione, mittente, destinatario, messaggio` | Invia messaggio |
| `api/schedina.php` | `stagione, giornata, id, pronostici[]` | Inserisce pronostici |

### Admin — aggiornamenti (POST)

> ⚠️ Questi endpoint modificano dati aggregati. Chiamarli nell'ordine indicato dopo ogni giornata.

| Ordine | Endpoint | Body | Descrizione |
|---|---|---|---|
| 1 | `api/admin/voti.php` | `stagione, giornata, id_squadra, voti[]` | Inserisce voti giocatori |
| 2 | `api/admin/risultati.php` | `stagione, giornata, id_squadra, id_squadra_a, ...` | Inserisce risultato partita |
| 3 | `api/admin/chiudi_giornata.php` | `stagione, giornata` | Marca giornata come chiusa |
| 4 | `api/admin/aggiorna_statistiche.php` | `stagione` | Ricalcola STATISTICHE |
| 5 | `api/admin/aggiorna_generale.php` | `stagione, giornata` | Ricalcola classifica |
| 6 | `api/admin/aggiorna_top_flop.php` | `stagione` | Ricalcola TOP11/FLOP11 |
| 7 | `api/admin/aggiorna_kulovic.php` | `stagione` | Ricalcola KULOVIC |

---

## Workflow deploy dopo ogni giornata

```
1. Apri il pannello admin React
2. Inserisci i voti per ogni squadra   → POST admin/voti.php
3. Inserisci i risultati               → POST admin/risultati.php
4. Chiudi la giornata                  → POST admin/chiudi_giornata.php
5. Il frontend chiama in sequenza gli aggiorna_* automaticamente
```

---

## Build e deploy React

```bash
# In locale
npm run build

# Carica su Altervista via FTP:
# - Cartella dist/* → root pubblica Altervista
# - htaccess_root.txt → rinomina in .htaccess nella root
# - Cartella api/ → /api/ nella root pubblica
```

---

## Nota sicurezza password

Le password nel DB sono attualmente in chiaro.
Quando vuoi migrarle a hash sicuro:

1. Esegui questo script UNA VOLTA per aggiornare il DB:
```php
// migrate_passwords.php (esegui e poi cancella!)
require "api/connect.php";
$utenti = query_all("SELECT id, PASSWORD FROM UTENZE");
foreach ($utenti as $u) {
    $hash = password_hash($u["PASSWORD"], PASSWORD_DEFAULT);
    mysqli_query($conn, "UPDATE UTENZE SET PASSWORD='$hash' WHERE id={$u['id']}");
}
echo "Done";
```

2. In `api/login.php` sostituisci la riga:
```php
$ok = ($utente["PASSWORD"] === $pass);
// con:
$ok = password_verify($pass, $utente["PASSWORD"]);
```
