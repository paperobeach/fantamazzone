// ============================================================
// Utilità condivise dai grafici della dashboard squadra:
//   GraficoZoom          contenitore con pulsante "Ingrandisci" (finestra a tutto schermo)
//   useEvidenzia         stato della serie evidenziata
//   LegendaInterattiva   legenda cliccabile: la serie scelta resta in primo piano,
//                        le altre vengono attenuate (non nascoste, così restano un riferimento)
// ============================================================
import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Maximize2, X } from 'lucide-react'

/** Proprietà comuni degli assi; nella vista ingrandita i caratteri sono più grandi. */
export const asse = zoom => ({ stroke: '#64748b', tick: { fontSize: zoom ? 13 : 11 } })

// ── Evidenziazione ───────────────────────────────────────────

/**
 * `chiaviValide` = chiavi delle serie attualmente nel grafico. Se la serie evidenziata
 * non c'è più (es. squadra tolta dal confronto) l'evidenziazione si azzera da sola.
 */
export function useEvidenzia(chiaviValide) {
  const [scelta, setScelta] = useState(null)
  const evid = scelta !== null && chiaviValide.includes(scelta) ? scelta : null
  const toggle = useCallback(k => setScelta(c => (c === k ? null : k)), [])
  const reset = useCallback(() => setScelta(null), [])
  return { evid, toggle, reset }
}

/** voci = [{ key, nome, colore, tratteggio? }] */
export function LegendaInterattiva({ voci, evid, onToggle, onReset, zoom = false }) {
  if (!voci?.length) return null
  return (
    <div className={`flex flex-wrap items-center gap-1.5 ${zoom ? 'text-sm' : 'text-[11px]'}`}>
      {voci.map(v => {
        const on = evid === v.key
        const spenta = evid !== null && !on
        return (
          <button key={v.key} type="button" onClick={() => onToggle(v.key)} aria-pressed={on}
                  title={on ? 'Tocca per mostrare di nuovo tutte le serie' : 'Tocca per evidenziare questa serie'}
                  className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full border transition-all ${
                    on ? 'bg-white/10 border-white/40 text-white font-semibold'
                      : spenta ? 'border-white/5 text-slate-600'
                      : 'border-white/10 text-slate-300 hover:border-white/25'}`}>
            <span className="inline-block w-4 border-t-2 flex-shrink-0"
                  style={{ borderColor: spenta ? '#475569' : v.colore, borderTopStyle: v.tratteggio ? 'dashed' : 'solid' }} />
            {v.nome}
          </button>
        )
      })}
      {evid !== null && (
        <button type="button" onClick={onReset}
                className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-slate-500 hover:text-slate-200 underline-offset-2 hover:underline">
          <X className="w-3 h-3" /> Mostra tutte
        </button>
      )}
    </div>
  )
}

// ── Ingrandimento ────────────────────────────────────────────

function FinestraZoom({ titolo, onClose, children }) {
  useEffect(() => {
    const onKey = e => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    const prec = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prec
    }
  }, [onClose])

  return createPortal(
    <div className="fixed inset-0 z-[70] flex items-stretch sm:items-center justify-center bg-black/80 backdrop-blur-sm sm:p-4"
         role="dialog" aria-modal="true" aria-label={titolo} onClick={onClose}>
      <div className="w-full sm:max-w-6xl max-h-full flex flex-col bg-pitch-900 sm:rounded-2xl border border-white/10 shadow-2xl overflow-hidden"
           onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-3 px-4 py-3 border-b border-white/5 flex-shrink-0">
          <h2 className="flex-1 min-w-0 truncate font-semibold text-slate-100 text-sm sm:text-base">{titolo}</h2>
          <button type="button" onClick={onClose} aria-label="Chiudi" title="Chiudi (Esc)"
                  className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/[0.06] transition-colors">
            <X className="w-5 h-5" />
          </button>
        </div>
        <div className="flex-1 min-h-0 overflow-auto p-4 space-y-3">{children}</div>
      </div>
    </div>,
    document.body
  )
}

/**
 * Contenitore di un grafico con il pulsante "Ingrandisci".
 *   titolo   titolo mostrato nella finestra ingrandita
 *   altezza  classe Tailwind dell'altezza nel layout normale (es. 'h-64')
 *   fluido   senza altezza fissa (per contenuti non-recharts, come le barre di confronto)
 *   legenda  nodo o funzione (zoom) => nodo, mostrato sotto il grafico (anche nella finestra)
 *   children nodo o funzione (zoom) => nodo: `zoom` è true nella finestra ingrandita
 * Nella finestra il grafico è largo almeno 640px: su telefono si scorre in orizzontale
 * invece di comprimere le etichette.
 */
export function GraficoZoom({ titolo, altezza = 'h-64', fluido = false, legenda, children }) {
  const [aperto, setAperto] = useState(false)
  const chiudi = useCallback(() => setAperto(false), [])
  const render = typeof children === 'function' ? children : () => children
  const renderLegenda = typeof legenda === 'function' ? legenda : () => legenda

  return (
    <>
      <div className="flex justify-end px-4 pt-3">
        <button type="button" onClick={() => setAperto(true)} aria-label={`Ingrandisci: ${titolo}`}
                className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md border border-white/10 text-[11px] text-slate-500 hover:text-slate-200 hover:border-white/25 transition-colors">
          <Maximize2 className="w-3 h-3" /> Ingrandisci
        </button>
      </div>
      <div className={`px-4 pt-2 pb-4 ${fluido ? '' : altezza}`}>{render(false)}</div>
      {legenda && <div className="px-4 pb-4 -mt-1">{renderLegenda(false)}</div>}

      {aperto && (
        <FinestraZoom titolo={titolo} onClose={chiudi}>
          {fluido ? render(true) : (
            <div className="overflow-x-auto">
              <div className="min-w-[640px] h-[55vh] sm:h-[62vh]">{render(true)}</div>
            </div>
          )}
          {legenda && <div>{renderLegenda(true)}</div>}
        </FinestraZoom>
      )}
    </>
  )
}
