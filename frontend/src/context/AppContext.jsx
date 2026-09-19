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

  const isAdmin = utente?.abilitazione === 'Y'

  return (
    <AppContext.Provider value={{
      stagioni, stagione, changeStagione,
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
