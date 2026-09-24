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

// ── Squadre ──────────────────────────────────────────────────

export const getSquadre = (stagione)      => get('squadre.php', { stagione })
export const getSquadra = (stagione, id)  => get('squadre.php', { stagione, id })

// ── Dettaglio partita ────────────────────────────────────────
// (endpoint già "incontri.php": rinominato quando è stata rimossa
//  la pagina "Incontri" dal front end — il dettaglio è ora
//  consultabile dalla pagina "Calendario")

export const getDettaglioPartita = (stagione, giornata, id_squadra = null) =>
  get('dettaglio_partita.php', { stagione, giornata, id_squadra })

// ── Statistiche ──────────────────────────────────────────────

export const getStatistiche = (stagione, params = {}) =>
  get('statistiche.php', { stagione, ...params })

// ── Marcatori ────────────────────────────────────────────────

export const getMarcatori = (stagione, tipo = 'marcatori', limit = 20) =>
  get('marcatori.php', { stagione, tipo, limit })

// ── Top / Flop 11 ────────────────────────────────────────────

export const getTopFlop = (stagione, tipo = null) =>
  get('top_flop.php', { stagione, ...(tipo ? { tipo } : {}) })

// ── Kulovic ──────────────────────────────────────────────────

export const getKulovic = (stagione, id_squadra = null) =>
  get('kulovic.php', { stagione, id_squadra })

// ── Champions ────────────────────────────────────────────────

export const getChampions = (stagione, sezione = 'classifica', girone = null) =>
  get('champions.php', { stagione, sezione, ...(girone ? { girone } : {}) })

// ── Formazioni ───────────────────────────────────────────────

export const getFormazione = (stagione, giornata, id_squadra) =>
  get('formazioni.php', { stagione, giornata, id_squadra })

export const saveFormazione = (stagione, giornata, id_squadra, giocatori) =>
  post('formazioni.php', { stagione, giornata, id_squadra, giocatori })

// ── Schedina ─────────────────────────────────────────────────

export const getSchedina = (stagione, giornata, id = null) =>
  get('schedina.php', { stagione, giornata, ...(id ? { id } : {}) })

export const getClassificaSchedina = (stagione) =>
  get('schedina.php', { stagione, tipo: 'classifica' })

export const saveSchedina = (stagione, giornata, id, pronostici) =>
  post('schedina.php', { stagione, giornata, id, pronostici })

// ── Messaggi ─────────────────────────────────────────────────

export const getMessaggi = (stagione, id, tipo = 'ricevuti') =>
  get('messaggi.php', { stagione, id, tipo })

export const sendMessaggio = (stagione, mittente, destinatario, messaggio) =>
  post('messaggi.php', { stagione, mittente, destinatario, messaggio })

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

export const adminChiudiGiornata   = (stagione, giornata) =>
  post('admin/chiudi_giornata.php', { stagione, giornata })

export const adminAggiornaStatistiche = (stagione) =>
  post('admin/aggiorna_statistiche.php', { stagione })

export const adminAggiornaGenerale = (stagione, giornata) =>
  post('admin/aggiorna_generale.php', { stagione, giornata })

export const adminAggiornaTopFlop  = (stagione) =>
  post('admin/aggiorna_top_flop.php', { stagione })

export const adminAggiornaKulovic  = (stagione) =>
  post('admin/aggiorna_kulovic.php', { stagione })

// ── Inizializzazione stagione (pagina admin) ──────────────────
// GET  → dati già presenti per la stagione, una riga per squadra
//        (join Squadre + Allenatori + Utenze), per precompilare
//        la tabella; la password non viene mai restituita.
// POST → consolida in un'unica chiamata NEW_SQUADRE + NEW_ALLENATORI
//        + NEW_UTENZE, con cancellazione preventiva per stagione
//        (operazione ripetibile). Body: { stagione, righe: [...] }.
export const getInizializzazioneStagione = (stagione) =>
  get('admin/inizializza_stagione.php', { stagione })

export const adminInizializzaStagione = (stagione, payload) =>
  post('admin/inizializza_stagione.php', { stagione, ...payload })

// ── Inserimento rose ─────────────────────────────────────────
// Carica il file Excel (sheet "Giocatori") ed esegue i 3 step
// di caricamento (BASE_ASTA -> GIOCATORI / GIOCATORI_SVINCOLATI).
export const adminInserimentoRose = (stagione, file) => {
  const formData = new FormData()
  formData.append('stagione', stagione)
  formData.append('file', file)
  return postFile('admin/inserimento_rose.php', formData)
}

/**
 * Chiude una giornata ed esegue in sequenza tutti gli aggiornamenti admin.
 * Restituisce un array di { step, ok, error? }
 */
export async function adminChiudiEAggiorna(stagione, giornata) {
  const steps = [
    { label: 'Chiusura giornata',    fn: () => adminChiudiGiornata(stagione, giornata) },
    { label: 'Statistiche',          fn: () => adminAggiornaStatistiche(stagione) },
    { label: 'Classifica generale',  fn: () => adminAggiornaGenerale(stagione, giornata) },
    { label: 'Top / Flop 11',        fn: () => adminAggiornaTopFlop(stagione) },
    { label: 'Kulovic',              fn: () => adminAggiornaKulovic(stagione) },
  ]
  const results = []
  for (const step of steps) {
    try {
      await step.fn()
      results.push({ step: step.label, ok: true })
    } catch (e) {
      results.push({ step: step.label, ok: false, error: e.message })
      break // interrompe se un passaggio fallisce
    }
  }
  return results
}
