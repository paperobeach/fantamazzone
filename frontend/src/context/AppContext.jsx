import { createContext, useContext, useState, useEffect, useCallback } from 'react'
import { getStagioni, getSistema } from '../api/client'

const AppContext = createContext(null)

export function AppProvider({ children }) {
  const [stagioni,        setStagioni]        = useState([])
  const [stagione,        setStagione]        = useState(null)
  const [sistemaParams,   setSistemaParams]   = useState({})
  const [utente,          setUtente]          = useState(() => {
    try { return JSON.parse(localStorage.getItem('lfm_utente')) ?? null }
    catch { return null }
  })
  const [loading, setLoading] = useState(true)

  // Carica stagioni al mount
  useEffect(() => {
    getStagioni()
      .then(list => {
        // Il backend PHP restituisce i valori come stringhe: normalizziamo in numeri qui,
        // così tutto il resto dell'app (select, confronti, somme) lavora su numeri veri.
        const stagioniNum = list.map(Number)
        setStagioni(stagioniNum)
        // Stagione corrente = la più recente
        const saved = localStorage.getItem('lfm_stagione')
        const cur   = saved && stagioniNum.includes(Number(saved)) ? Number(saved) : stagioniNum[0]
        setStagione(cur)
      })
      .catch(console.error)
      .finally(() => setLoading(false))
  }, [])

  // Carica parametri di sistema quando cambia la stagione
  useEffect(() => {
    if (!stagione) return
    getSistema(stagione)
      .then(setSistemaParams)
      .catch(console.error)
  }, [stagione])

  const changeStagione = useCallback((s) => {
    setStagione(s)
    localStorage.setItem('lfm_stagione', s)
  }, [])

  const doLogin = useCallback((u) => {
    setUtente(u)
    localStorage.setItem('lfm_utente', JSON.stringify(u))
  }, [])

  const doLogout = useCallback(() => {
    setUtente(null)
    localStorage.removeItem('lfm_utente')
  }, [])

  // "amministratore" è un campo dedicato di NEW_UTENZE, distinto da
  // "abilitazione" (che indica solo se l'utenza è attiva): permette
  // di riconoscere gli utenti amministratori dell'applicazione.
  // Il confronto è tollerante rispetto a maiuscole/minuscole e spazi,
  // per non dipendere dal fatto che il valore sia stato scritto dalla
  // UI (che usa sempre 'Y'/'N') oppure inserito a mano nel DB.
  const isAdmin = String(utente?.amministratore ?? '').trim().toUpperCase() === 'Y'

  // Stagione più recente tra quelle disponibili (stagioni è già ordinata
  // DESC dal backend): serve per il login (che non fa più scegliere la
  // stagione) e per la sezione "Gestione squadra", che deve sempre
  // riferirsi all'ultima stagione indipendentemente da quella scelta
  // per la navigazione generale (classifica, calendario, ecc.).
  const ultimaStagione = stagioni[0] ?? null

  return (
    <AppContext.Provider value={{
      stagioni, stagione, changeStagione, ultimaStagione,
      sistemaParams,
      utente, doLogin, doLogout, isAdmin,
      loading,
    }}>
      {children}
    </AppContext.Provider>
  )
}

export const useApp = () => {
  const ctx = useContext(AppContext)
  if (!ctx) throw new Error('useApp must be used inside AppProvider')
  return ctx
}
