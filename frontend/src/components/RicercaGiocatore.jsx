import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search, X } from 'lucide-react'
import { cercaGiocatori } from '../api/client'
import { RoleBadge } from './ui'

const stagioneLabel = s => `${s}/${String(Number(s) + 1).slice(-2)}`

// Ricerca rapida giocatore: campo di testo con suggerimenti (da 2 caratteri,
// max 10 risultati) su TUTTE le stagioni; ogni giocatore compare una volta, con
// la sua edizione piu' recente. Selezionando un risultato si apre la scheda del
// giocatore in quella stagione (/giocatori/:id?stagione=...), che mostra anche lo storico.
//
// inline=false  elenco a tendina sotto il campo (sidebar desktop)
// inline=true   elenco nel flusso della pagina (pannello mobile)
// onSelect      chiamata dopo la selezione (es. per chiudere un pannello)
export default function RicercaGiocatore({ onSelect, autoFocus = false, inline = false, className = '' }) {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [risultati, setRisultati] = useState([])
  const [loading, setLoading] = useState(false)
  const [aperto, setAperto] = useState(false)
  const [attivo, setAttivo] = useState(-1)
  const [errore, setErrore] = useState(false)
  const rootRef = useRef(null)
  const reqRef = useRef(0)

  const testo = q.trim()
  const valido = testo.length >= 2

  // Ricerca con debounce; il contatore evita che una risposta lenta
  // sovrascriva una più recente.
  useEffect(() => {
    if (!valido) {
      setRisultati([])
      setLoading(false)
      setErrore(false)
      return
    }
    const n = ++reqRef.current
    setLoading(true)
    const t = setTimeout(async () => {
      try {
        const r = await cercaGiocatori(testo)
        if (n !== reqRef.current) return
        setRisultati(Array.isArray(r) ? r : [])
        setErrore(false)
        setAttivo(-1)
      } catch {
        if (n !== reqRef.current) return
        setRisultati([])
        setErrore(true)
      } finally {
        if (n === reqRef.current) setLoading(false)
      }
    }, 250)
    return () => clearTimeout(t)
  }, [testo, valido])

  // Chiude l'elenco con un click fuori dal componente
  useEffect(() => {
    const fuori = e => { if (rootRef.current && !rootRef.current.contains(e.target)) setAperto(false) }
    document.addEventListener('mousedown', fuori)
    document.addEventListener('touchstart', fuori)
    return () => {
      document.removeEventListener('mousedown', fuori)
      document.removeEventListener('touchstart', fuori)
    }
  }, [])

  const apri = g => {
    setQ('')
    setRisultati([])
    setAperto(false)
    navigate(`/giocatori/${g.id}?stagione=${g.stagione}`)
    onSelect?.(g)
  }

  const onKeyDown = e => {
    if (e.key === 'Escape') { setAperto(false); return }
    if (!risultati.length) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setAttivo(a => (a + 1) % risultati.length) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setAttivo(a => (a <= 0 ? risultati.length - 1 : a - 1)) }
    else if (e.key === 'Enter') { e.preventDefault(); apri(risultati[attivo >= 0 ? attivo : 0]) }
  }

  const mostraElenco = aperto && valido
  const elenco = (
    <div
      role="listbox"
      className={inline
        ? 'mt-2 max-h-[60vh] overflow-y-auto'
        : 'absolute left-0 right-0 top-full mt-1 z-50 max-h-80 overflow-y-auto rounded-lg border border-white/10 bg-pitch-800 shadow-xl shadow-black/40'}
    >
      {loading && !risultati.length ? (
        <p className="px-3 py-3 text-xs text-slate-500">Ricerca in corso…</p>
      ) : errore ? (
        <p className="px-3 py-3 text-xs text-red-400">Ricerca non disponibile.</p>
      ) : !risultati.length ? (
        <p className="px-3 py-3 text-xs text-slate-500">Nessun giocatore trovato.</p>
      ) : risultati.map((g, i) => (
        <button
          key={`${g.stagione}-${g.id}`}
          type="button"
          role="option"
          aria-selected={i === attivo}
          onMouseEnter={() => setAttivo(i)}
          onClick={() => apri(g)}
          className={`w-full flex items-center gap-2.5 px-3 py-2 text-left border-b border-white/[0.04] last:border-0 transition-colors ${
            i === attivo ? 'bg-white/[0.06]' : 'hover:bg-white/[0.04]'
          }`}
        >
          <RoleBadge ruolo={g.ruolo} />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-sm text-slate-200">{g.descrizione}</span>
            <span className="block truncate text-[11px] text-slate-600">
              {[g.squadra_serie_a, g.squadra].filter(Boolean).join(' · ') || 'Svincolato'}
              {g.stagione ? ` · ${stagioneLabel(g.stagione)}` : ''}
            </span>
          </span>
        </button>
      ))}
    </div>
  )

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <div className="relative">
        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-600 pointer-events-none" />
        <input
          type="search"
          value={q}
          autoFocus={autoFocus}
          onChange={e => { setQ(e.target.value); setAperto(true) }}
          onFocus={() => setAperto(true)}
          onKeyDown={onKeyDown}
          placeholder="Cerca giocatore…"
          aria-label="Cerca giocatore"
          autoComplete="off"
          className="w-full bg-pitch-800 border border-white/10 rounded-lg pl-8 pr-8 py-2 text-sm text-slate-300
                     placeholder:text-slate-600 focus:outline-none focus:border-grass-500/40"
        />
        {q && (
          <button
            type="button"
            onClick={() => { setQ(''); setRisultati([]) }}
            aria-label="Cancella ricerca"
            className="absolute right-1.5 top-1/2 -translate-y-1/2 p-1 rounded text-slate-600 hover:text-slate-300"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        )}
      </div>
      {mostraElenco && elenco}
    </div>
  )
}
