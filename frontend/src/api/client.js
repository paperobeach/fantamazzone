// ============================================================
// src/api/client.js
// Tutte le chiamate all'API PHP backend
// ============================================================

const BASE_URL = import.meta.env.VITE_API_URL ?? '/api'

// ── Fetch helper ─────────────────────────────────────────────

// ── Gestione errori ──────────────────────────────────────────
// Il backend PHP restituisce, in caso di errore, il body:
//   { "error": { "code": "VALIDATION_ERROR", "message": "...", "status": 400 } }
// ApiError estende Error, quindi resta compatibile con tutto il codice
// esistente che legge semplicemente e.message; in più espone e.code e
// e.status per chi in futuro volesse reagire in modo specifico
// (es. AUTH_ERROR -> logout automatico).

class ApiError extends Error {
  constructor(message, code, status, details) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    // Dettaglio facoltativo: array di errori riga-per-riga
    // (es. validazione import Excel) { riga, campo, messaggio }[]
    this.details = details
  }
}

function parseErrorBody(body, resStatus) {
  const err = body?.error
  // Nuovo formato: { error: { code, message, status, details? } }
  if (err && typeof err === 'object') {
    return new ApiError(err.message ?? `HTTP ${resStatus}`, err.code ?? 'UNKNOWN', err.status ?? resStatus, err.details)
  }
  // Fallback di compatibilità: vecchio formato { error: "messaggio" }
  // o risposta non conforme.
  return new ApiError(err ?? `HTTP ${resStatus}`, 'UNKNOWN', resStatus)
}

async function get(endpoint, params = {}) {
  const url = new URL(`${BASE_URL}/${endpoint}`, window.location.origin)
  Object.entries(params).forEach(([k, v]) => {
    if (v !== null && v !== undefined) url.searchParams.set(k, v)
  })
  const res = await fetch(url.toString())
  if (!res.ok) {
    const body = await res.json().catch(() => null)
    throw parseErrorBody(body, res.status)
  }
  return res.json()
}

async function post(endpoint, body = {}) {
  const res = await fetch(`${BASE_URL}/${endpoint}`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(body),
  })
  const data = await res.json().catch(() => null)
  if (!res.ok) throw parseErrorBody(data, res.status)
  return data
}

// Upload multipart/form-data (es. import Excel). Niente header
// Content-Type: il browser imposta da solo il boundary corretto.
async function postFile(endpoint, formData) {
  const res = await fetch(`${BASE_URL}/${endpoint}`, {
    method: 'POST',
    body:   formData,
  })
  const data = await res.json().catch(() => null)
  if (!res.ok) throw parseErrorBody(data, res.status)
  return data
}

// ── Sistema ──────────────────────────────────────────────────

export const getStagioni    = ()          => get('sistema.php', { tipo: 'stagioni' })
export const getSistema     = (stagione)  => get('sistema.php', { stagione })
export const updateSistema  = (stagione, label, valore) =>
  post('sistema.php', { stagione, label, valore })

// ── Classifica Generale ──────────────────────────────────────

export const getGenerale = (stagione) => get('generale.php', { stagione })

// ── Calendario ───────────────────────────────────────────────

export const getCalendario = (stagione) => get('calendario.php', { stagione })

// Giornata di riferimento per l'inserimento delle formazioni: la prima
// giornata successiva all'ultima giornata già chiusa della stagione.
export const getGiornataCorrente = (stagione) => get('giornata_corrente.php', { stagione })

// ── LIVE Giornata in corso ───────────────────────────────────
// Partite della giornata non ancora chiusa, con risultato "reale"
// (NEW_RISULTATI, non definitivo) e risultato della "simulazione"
// (NEW_SIMULAZIONE_RISULTATI).
export const getLiveGiornata = (stagione) => get('live_giornata.php', { stagione })

// La risposta contiene anche "champions" (partite del turno di Champions della
// giornata, stessa struttura di "partite" + girone), se la giornata ne prevede uno.
//
// Dettaglio voti di una partita live (id_squadra = squadra di casa);
// fonte: 'auto' (default: NEW_RISULTATI se presente, altrimenti simulazione) | 'reale' | 'simulazione'
// competizione: 'CAMP' (default) | 'CHAMP' (partita di Champions)
export const getLiveDettaglio = (stagione, id_squadra, fonte = 'auto', competizione = 'CAMP') =>
  get('live_dettaglio.php', { stagione, id_squadra, fonte, ...(competizione !== 'CAMP' ? { competizione } : {}) })

// Giocatori in formazione di una squadra con il dato usato dalla simulazione
// (competizione 'CHAMP': formazione effettiva di Champions)
export const getLiveGiocatori = (stagione, id_squadra, competizione = 'CAMP') =>
  get('live_simulazione.php', { stagione, id_squadra, ...(competizione !== 'CAMP' ? { competizione } : {}) })

// Simulazione libera (nessun ruolo richiesto). Senza id_squadra simula
// l'intera giornata (campionato + Champions); con id_squadra (casa o ospite)
// SOLO quella partita, di campionato o di Champions (competizione 'CHAMP').
export const simulaGiornata = (stagione, id_squadra, utente, competizione = 'CAMP') =>
  post('live_simulazione.php', {
    stagione, azione: 'simula', utente,
    ...(id_squadra ? { id_squadra, ...(competizione !== 'CAMP' ? { competizione } : {}) } : {}),
  })

// Editing manuale di un giocatore la cui partita non si è ancora giocata;
// il backend ricalcola e salva la sola partita del giocatore
// (dati.utente = chi opera, registrato come autore della simulazione)
export const salvaEditLive = (stagione, dati) =>
  post('live_simulazione.php', { stagione, azione: 'salva_edit', ...dati })

export const eliminaEditLive = (stagione, id_giocatore, utente) =>
  post('live_simulazione.php', { stagione, azione: 'elimina_edit', id_giocatore, utente })

// Cancella la simulazione di una singola partita (voti, risultati ed editing manuali)
export const eliminaSimulazionePartita = (stagione, id_squadra, competizione = 'CAMP') =>
  post('live_simulazione.php', {
    stagione, azione: 'elimina_simulazione', id_squadra,
    ...(competizione !== 'CAMP' ? { competizione } : {}),
  })

// ── Squadre ──────────────────────────────────────────────────

export const getSquadre = (stagione)      => get('squadre.php', { stagione })
export const getSquadra = (stagione, id)  => get('squadre.php', { stagione, id })

// ── Dettaglio partita ────────────────────────────────────────
// (endpoint già "incontri.php": rinominato quando è stata rimossa
//  la pagina "Incontri" dal front end — il dettaglio è ora
//  consultabile dalla pagina "Calendario")

// competizione: 'CAMP' (default) | 'CHAMP' (id_squadra = squadra di casa, obbligatorio)
// id_ospite: se la partita non ha risultati, il backend restituisce la sola formazione
export const getDettaglioPartita = (stagione, giornata, id_squadra = null, competizione = 'CAMP', id_ospite = null) =>
  get('dettaglio_partita.php', {
    stagione, giornata, id_squadra,
    ...(competizione !== 'CAMP' ? { competizione } : {}),
    ...(id_ospite ? { id_ospite } : {}),
  })

// ── Statistiche ──────────────────────────────────────────────

export const getStatistiche = (stagione, params = {}) =>
  get('statistiche.php', { stagione, ...params })

// ── Top / Flop 11 ────────────────────────────────────────────

export const getTopFlop = (stagione, tipo = null) =>
  get('top_flop.php', { stagione, ...(tipo ? { tipo } : {}) })

// ── Champions ────────────────────────────────────────────────

export const getChampions = (stagione, sezione = 'classifica', girone = null) =>
  get('champions.php', { stagione, sezione, ...(girone ? { girone } : {}) })

// ── Formazioni ───────────────────────────────────────────────

// competizione: 'CAMP' (campionato, default) | 'CHAMP' (formazione Champions distinta)
export const getFormazione = (stagione, giornata, id_squadra, competizione = 'CAMP') =>
  get('formazioni.php', { stagione, giornata, id_squadra, ...(competizione !== 'CAMP' ? { competizione } : {}) })

// Indica se per squadra+giornata è ammessa una formazione Champions diversa da quella di campionato:
// { champions_in_giornata, separata_attiva, separata_ammessa }
export const getFormazioneContesto = (stagione, giornata, id_squadra) =>
  get('formazioni.php', { stagione, giornata, id_squadra, modo: 'contesto' })

// competizione: 'CAMP' | 'CHAMP'; entrambe = true salva la stessa formazione in entrambe le competizioni
export const saveFormazione = (stagione, giornata, id_squadra, giocatori, competizione = 'CAMP', entrambe = false) =>
  post('formazioni.php', { stagione, giornata, id_squadra, giocatori, competizione, entrambe })

// ── Penalità ─────────────────────────────────────────────────

export const getPenalita = (stagione) => get('penalita.php', { stagione })

// ── Auth ─────────────────────────────────────────────────────

export const login = (stagione, utenza, password) =>
  post('login.php', { stagione, utenza, password })

// ── Admin ────────────────────────────────────────────────────

export const adminInsertVoti       = (stagione, giornata, id_squadra, voti) =>
  post('admin/voti.php', { stagione, giornata, id_squadra, voti })

export const adminInsertRisultato  = (stagione, body) =>
  post('admin/risultati.php', { stagione, ...body })

// Fase 3 gestione voti: stato e chiusura giornata (admin/chiusura_giornata.php)
export const getStatoChiusuraGiornata = (stagione, giornata) =>
  get('admin/chiusura_giornata.php', { stagione, giornata })

export const adminChiudiGiornata = (stagione, giornata) =>
  post('admin/chiusura_giornata.php', { stagione, giornata })

// Riapertura dell'ultima giornata chiusa (admin/riapertura_giornata.php)
export const getStatoRiapertura = (stagione) =>
  get('admin/riapertura_giornata.php', { stagione })

export const adminRiapriGiornata = (stagione, giornata) =>
  post('admin/riapertura_giornata.php', { stagione, giornata })

// ── Reset stagione (admin/reset_stagione.php) ─────────────────
// GET  → stato: stagione resettabile (o motivi di blocco), righe per
//        tabella (gruppo 'backup' = salvate in BK_* prima della
//        cancellazione, 'derivati' = solo cancellate), avvisi, backup
//        già eseguiti, frase di conferma da digitare.
// POST → Body { stagione, utenza, password, conferma }: credenziali di
//        un amministratore (verificate lato server) e frase "RESET <stagione>".
//        Esegue backup verificato e poi cancella tutti i dati della stagione.
export const getStatoResetStagione = (stagione) =>
  get('admin/reset_stagione.php', { stagione })

export const adminResetStagione = (stagione, { utenza, password, conferma }) =>
  post('admin/reset_stagione.php', { stagione, utenza, password, conferma })

// ── Ripristino stagione (admin/ripristino_stagione.php) ───────
// GET            → elenco backup (tutte le stagioni) con stato, righe salvate,
//                  se ripristinabili e perché no.
// GET ?bk_id=N   → dettaglio: righe nel backup vs righe attuali per tabella,
//                  avvisi, motivi di blocco, frase di conferma.
// POST           → Body { bk_id, utenza, password, conferma }: credenziali di un
//                  amministratore (verificate lato server) e frase "RIPRISTINA <stagione>".
//                  Se la stagione contiene già dati, li salva prima in un backup
//                  di sicurezza (annullabile) e li sostituisce con quelli del backup.
export const getBackupStagioni = () =>
  get('admin/ripristino_stagione.php')

export const getDettaglioBackup = (bk_id) =>
  get('admin/ripristino_stagione.php', { bk_id })

export const adminRipristinaStagione = (bk_id, { utenza, password, conferma }) =>
  post('admin/ripristino_stagione.php', { bk_id, utenza, password, conferma })

// POST azione 'elimina' → elimina definitivamente uno o più backup (bk_ids).
//   Body { azione: 'elimina', bk_ids, utenza, password, conferma, accetta_perdita }
//   conferma = "ELIMINA <n> BACKUP". accetta_perdita è richiesto se si elimina l'ultima
//   copia ripristinabile di una stagione che oggi non ha più dati.
//   Risposta { ok, eliminati: [{bk_id, stagione, righe}], errori: [{bk_id, errore}], avvisi }
export const adminEliminaBackup = (bk_ids, { utenza, password, conferma, accetta_perdita = false }) =>
  post('admin/ripristino_stagione.php', { azione: 'elimina', bk_ids, utenza, password, conferma, accetta_perdita })

// ── AI: test connessione Anthropic (admin/ai_test.php) ────────
// POST → Body { stagione, utenza, password, azione }: credenziali di una
//        utenza con NEW_UTENZE.abilita_ai = 'Y' (verificate lato server).
//        azione 'diagnostica' → controlli locali, nessun costo
//          { ambiente, config_presente, chiave_configurata, modello_test,
//            modelli, chiamate_in_uscita_possibili }
//        azione 'test' (default) → conteggio token (gratuito) + risposta
//        brevissima del modello (costo trascurabile)
//          { modello, conteggio_token, generazione, tutto_ok, ambiente }
export const aiConnessione = (stagione, { utenza, password, azione = 'test' }) =>
  post('admin/ai_test.php', { stagione, utenza, password, azione })

// ── Inizializzazione stagione (pagina admin) ──────────────────
// GET  → dati per la stagione, una riga per squadra (join Squadre +
//        Allenatori + Utenze). Se la stagione non ha ancora dati,
//        risponde con quelli dell'ultima stagione disponibile
//        (campo "fonte_stagione" nella risposta). "formazione_presente"
//        indica se la stagione ha già formazioni inserite (in tal
//        caso il salvataggio è limitato a utenza/password/abilitazione).
//        La password non viene mai restituita.
// POST → Passo 1 (fase "utenze"): Body { stagione, fase: 'utenze', righe: [...] }.
//        Se non esistono ancora formazioni per la stagione, consolida
//        NEW_SQUADRE + NEW_ALLENATORI + NEW_UTENZE (cancellazione
//        preventiva per stagione, operazione ripetibile). Se esistono
//        già formazioni, aggiorna solo utenza/password/abilitazione/
//        amministratore/email delle squadre esistenti. Non tocca i
//        calendari.
//        Passo 2 (fase "calendari"): Body { stagione, fase: 'calendari' }.
//        Rigenera NEW_CALENDARIO e NEW_CALENDARIO_CHAMP leggendo numero
//        di giornate e giornate Champions dalla configurazione di stagione.
// GET  ?fase=calendari → stato/verifica del passo 2.
export const getInizializzazioneStagione = (stagione) =>
  get('admin/inizializza_stagione.php', { stagione })

export const adminInizializzaStagione = (stagione, payload) =>
  post('admin/inizializza_stagione.php', { stagione, fase: 'utenze', ...payload })

export const getStatoCalendariStagione = (stagione) =>
  get('admin/inizializza_stagione.php', { stagione, fase: 'calendari' })

export const adminCreaCalendariStagione = (stagione) =>
  post('admin/inizializza_stagione.php', { stagione, fase: 'calendari' })

// ── Inserimento rose ─────────────────────────────────────────
// Carica il file Excel (sheet "Giocatori") ed esegue i 3 step
// di caricamento (BASE_ASTA -> GIOCATORI / GIOCATORI_SVINCOLATI).
export const adminInserimentoRose = (stagione, file) => {
  const formData = new FormData()
  formData.append('stagione', stagione)
  formData.append('file', file)
  return postFile('admin/inserimento_rose.php', formData)
}

// ── Gestisci regole di calcolo (pagina admin) ────────────────
// Configurazione usata dal motore di calcolo dei voti fantacalcio
// (bonus/malus del giocatore + parametri degli algoritmi di
// modificatore difesa/centrocampo/attacco). Se la stagione non ha
// ancora una configurazione, il GET la crea da solo (ereditandola
// dalla stagione precedente o usando i valori di default) e la
// restituisce già pronta.
export const getRegoleCalcolo = (stagione) =>
  get('admin/regole_calcolo.php', { stagione })

// payload: { bonus?: [{codice, etichetta, valore, ordine, attivo}], algoritmi?: { DIFESA|CENTROCAMPO|ATTACCO: {algoritmo, parametri, descrizione} } }
export const adminSalvaRegoleCalcolo = (stagione, payload) =>
  post('admin/regole_calcolo.php', { stagione, ...payload })

// ── Gestione voti (pagina admin) ─────────────────────────────
// GET  → giornata di riferimento (ultima non chiusa), elenco giornate
//        del calendario con stato di chiusura e n. di voti Serie A
//        già caricati.
// POST → Fase 1: upload del file Excel (sheet "Italia") con i voti
//        della Serie A per la giornata indicata (tabella
//        NEW_VOTI_SERIE_A). Con forza=true si carica anche se la
//        giornata nel titolo del file differisce da quella scelta
//        (altrimenti errore GIORNATA_MISMATCH).
export const getVotiSerieAInfo = (stagione) =>
  get('admin/voti_serie_a.php', { stagione })

export const adminCaricaVotiSerieA = (stagione, giornata, file, forza = false) => {
  const formData = new FormData()
  formData.append('stagione', stagione)
  // giornata (fantacampionato) opzionale: se omessa il backend la ricava dalla
  // giornata di Serie A indicata nel file, tramite GIORNATA_SERIE_A_INIZIO
  if (giornata != null) formData.append('giornata', giornata)
  if (forza) formData.append('forza', '1')
  formData.append('file', file)
  return postFile('admin/voti_serie_a.php', formData)
}

// Fase 2: calcola i voti fantacalcio della giornata (NEW_VOTI + NEW_RISULTATI)
// a partire dai voti Serie A caricati in fase 1, con CalcolatoreVoti.php e
// le regole di NEW_REGOLE_BONUS / NEW_REGOLE_ALGORITMI. Ripetibile finché
// la giornata non è chiusa.
export const adminCalcolaGiornata = (stagione, giornata) =>
  post('admin/calcolo_giornata.php', { stagione, giornata })

// ── Importa calendario Serie A (pagina admin) ────────────────
// GET  → riepilogo del calendario Serie A già caricato per la stagione
//        (totale partite, giornate con date, n. partite e n. orari
//        da definire).
// POST → upload del file Excel (sheet "Tutte le partite") con tutte le
//        partite della Serie A (tabella NEW_CALENDARIO_SERIE_A). Il
//        calendario della stagione viene sostituito; in caso di errori
//        di validazione (VALIDATION_ERROR) non viene scritto nulla.
export const getCalendarioSerieAInfo = (stagione) =>
  get('admin/calendario_serie_a.php', { stagione })

export const adminImportaCalendarioSerieA = (stagione, file) => {
  const formData = new FormData()
  formData.append('stagione', stagione)
  formData.append('file', file)
  return postFile('admin/calendario_serie_a.php', formData)
}
