import { useEffect, useRef, useState } from 'react'
import { Search, X, ImageOff } from 'lucide-react'
import { Spinner } from './ui'
import { Avviso } from './AiUi'

// ============================================================
// components/SelettoreImmagine.jsx
//
// Finestra per scegliere un'immagine dalla libreria della Gazzetta
// (solo immagini attive). Usata nella gestione articoli per cambiare
// o togliere l'immagine di copertina e quelle delle pagine.
//
// Props:
//   carica({ pagina, cerca }) → Promise<{ immagini[], totale, per_pagina }>
//        (il chiamante aggiunge credenziali e azione 'elenco')
//   onScegli(immagine | null)  immagine = riga della libreria; null = nessuna
//   onChiudi()
// ============================================================

export default function SelettoreImmagine({ carica, onScegli, onChiudi, titolo = 'Scegli un\'immagine' }) {
  const [pagina, setPagina] = useState(1)
  const [cerca, setCerca]   = useState('')
  const [cercaAttiva, setCercaAttiva] = useState('')
  const [dati, setDati]     = useState(null)
  const [errore, setErrore] = useState(null)
  const [busy, setBusy]     = useState(false)
  const chiudiRef = useRef(null)

  useEffect(() => {
    let vivo = true
    setBusy(true); setErrore(null)
    carica({ pagina, cerca: cercaAttiva })
      .then(r => { if (vivo) setDati(r) })
      .catch(e => { if (vivo) setErrore(e.message) })
      .finally(() => { if (vivo) setBusy(false) })
    return () => { vivo = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pagina, cercaAttiva])

  useEffect(() => {
    chiudiRef.current?.focus()
    const onKey = (e) => { if (e.key === 'Escape') onChiudi() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onChiudi])

  const pagine = dati ? Math.max(1, Math.ceil(dati.totale / dati.per_pagina)) : 1

  return (
    <div className="fixed inset-0 z-50 bg-black/70 flex items-end sm:items-center justify-center p-0 sm:p-6"
         role="dialog" aria-modal="true" aria-label={titolo} onClick={onChiudi}>
      <div className="card w-full sm:max-w-3xl max-h-[90vh] overflow-y-auto p-5 rounded-b-none sm:rounded-b-xl"
           onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between gap-3 mb-4">
          <h3 className="font-semibold text-slate-200">{titolo}</h3>
          <button ref={chiudiRef} type="button" onClick={onChiudi} aria-label="Chiudi"
            className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/5">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form className="flex gap-2 mb-4" onSubmit={e => { e.preventDefault(); setPagina(1); setCercaAttiva(cerca.trim()) }}>
          <input type="search" value={cerca} onChange={e => setCerca(e.target.value)}
            placeholder="Cerca per descrizione, tag o persona" aria-label="Cerca immagini" className="fanta-input" />
          <button type="submit" className="px-3 rounded-lg border border-white/10 text-slate-300 hover:text-white" aria-label="Cerca">
            <Search className="w-4 h-4" />
          </button>
        </form>

        {errore && <Avviso tono="red">{errore}</Avviso>}
        {busy && !dati && <div className="flex justify-center py-8"><Spinner /></div>}

        {dati && dati.immagini.length === 0 && (
          <p className="text-sm text-slate-400 py-6 text-center">Nessuna immagine attiva trovata.</p>
        )}

        {dati && dati.immagini.length > 0 && (
          <ul className={`grid grid-cols-2 sm:grid-cols-4 gap-3 ${busy ? 'opacity-60' : ''}`}>
            {dati.immagini.map(im => (
              <li key={im.id}>
                <button type="button" onClick={() => onScegli(im)}
                  className="w-full text-left rounded-lg border border-white/10 hover:border-grass-500/50 overflow-hidden transition-colors">
                  {im.miniatura
                    ? <img src={im.miniatura} alt={im.descrizione || `Immagine ${im.id}`} className="w-full aspect-square object-cover" />
                    : <div className="w-full aspect-square flex items-center justify-center text-slate-600"><ImageOff className="w-6 h-6" /></div>}
                  <span className="block p-2 text-[11px] leading-snug text-slate-400 line-clamp-2">
                    {im.descrizione || <em>senza descrizione</em>}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className="flex items-center justify-between gap-3 mt-4">
          <button type="button" onClick={() => onScegli(null)}
            className="text-sm text-slate-400 hover:text-slate-200 underline-offset-2 hover:underline">
            Nessuna immagine
          </button>
          {pagine > 1 && (
            <div className="flex items-center gap-2 text-xs text-slate-500">
              <button type="button" disabled={pagina <= 1 || busy} onClick={() => setPagina(p => p - 1)}
                className="px-3 py-1.5 rounded-lg border border-white/10 hover:text-white disabled:opacity-40">Prec.</button>
              <span>{pagina} / {pagine}</span>
              <button type="button" disabled={pagina >= pagine || busy} onClick={() => setPagina(p => p + 1)}
                className="px-3 py-1.5 rounded-lg border border-white/10 hover:text-white disabled:opacity-40">Succ.</button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
