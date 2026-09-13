# LegaFantaMazzone — Frontend React

Stack: **React 18** + **Vite** + **Tailwind CSS** + **React Router v6** + **Recharts**

---

## Setup locale

```bash
# 1. Installa dipendenze
npm install

# 2. Configura l'ambiente
cp .env.example .env
# Modifica VITE_API_URL in .env se necessario

# 3. Avvia in sviluppo
npm run dev
# → http://localhost:5173
```

Il proxy Vite (configurato in `vite.config.js`) redirige `/api/*`
verso `http://localhost/legafanta/api/*` (XAMPP).
Assicurati che i file PHP siano in `C:\xampp\htdocs\legafanta\api\`.

---

## Build e deploy su Altervista

```bash
# Build produzione
npm run build
# → genera la cartella dist/

# Carica su Altervista via FTP:
# dist/*              → root pubblica (htdocs/)
# htaccess_root.txt   → rinomina in .htaccess nella root
# api/                → htdocs/api/
```

In produzione, imposta nel file `.env`:
```
VITE_API_URL=https://tuonome.altervista.org/api
```

---

## Struttura del progetto

```
src/
├── api/
│   └── client.js          # Tutte le chiamate API
├── components/
│   ├── layout/
│   │   └── Sidebar.jsx    # Navigazione laterale
│   └── ui/
│       └── index.jsx      # Componenti riutilizzabili
├── context/
│   └── AppContext.jsx      # Stato globale (stagione, utente)
├── hooks/
│   └── useFetch.js        # Hook fetch generico
├── pages/
│   ├── Classifica.jsx
│   ├── Calendario.jsx
│   ├── Squadre.jsx
│   ├── SquadraDetail.jsx
│   ├── Incontri.jsx
│   ├── Statistiche.jsx
│   ├── Marcatori.jsx
│   ├── TopFlop.jsx
│   ├── Kulovic.jsx
│   ├── Champions.jsx
│   ├── Schedina.jsx
│   ├── Messaggi.jsx
│   ├── Login.jsx
│   └── Admin.jsx
├── App.jsx                 # Router + layout shell
├── main.jsx                # Entry point
└── index.css               # Design system + Tailwind
```

---

## Pagine e routing

| Route           | Pagina         | Accesso   |
|-----------------|----------------|-----------|
| `/`             | Classifica     | Pubblico  |
| `/calendario`   | Calendario     | Pubblico  |
| `/squadre`      | Lista squadre  | Pubblico  |
| `/squadre/:id`  | Dettaglio sq.  | Pubblico  |
| `/incontri`     | Incontri       | Pubblico  |
| `/statistiche`  | Statistiche    | Pubblico  |
| `/marcatori`    | Marcatori      | Pubblico  |
| `/top-flop`     | Top/Flop 11    | Pubblico  |
| `/kulovic`      | Kulovic        | Pubblico  |
| `/champions`    | Champions      | Pubblico  |
| `/schedina`     | Schedina       | Pubblico  |
| `/messaggi`     | Messaggi       | Login     |
| `/login`        | Login          | Pubblico  |
| `/admin`        | Pannello admin | Admin     |

---

## Aggiungere una nuova pagina

1. Crea `src/pages/NuovaPagina.jsx`
2. Aggiungi la route in `App.jsx`
3. Aggiungi la voce in `Sidebar.jsx`
4. Se serve un endpoint, aggiungilo in `src/api/client.js`

---

## Note Altervista

- Il file `.htaccess` nella root è **obbligatorio** per il routing SPA
- PHP deve essere impostato a **8.0** dal pannello Altervista
- CORS è già configurato nel backend PHP (`connect.php`)
- Il build Vite usa hash nei nomi file: la cache del browser si aggiorna automaticamente
