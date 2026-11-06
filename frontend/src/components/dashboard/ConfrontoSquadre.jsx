// ============================================================
// Scelta delle squadre da affiancare alla propria in Confronta e Andamento:
// benchmark di lega (media) e/o una o più squadre.
// ============================================================
import { useState } from 'react'
import { Users, Gauge, ChevronDown, ChevronUp } from 'lucide-react'
import { PALETTE_SQUADRE } from './metriche'

function Chip({ on, onClick, children, colore }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={on}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs whitespace-nowrap transition-colors ${
              on ? 'bg-white/10 border-white/30 text-white' : 'border-white/10 text-slate-500 hover:text-slate-300 hover:border-white/25'}`}>
      {colore && <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: on ? colore : '#475569' }} />}
      {children}
    </button>
  )
}

/**
 * Stato: { bench: bool, ids: number[] }. `squadre` = tutte le squadre della lega.
 * Il colore di ogni squadra confrontata è stabile (dipende dall'ordine in lega).
 */
export function coloreSquadra(squadre, me, id) {
  const altre = squadre.filter(s => s.id !== me)
  const i = altre.findIndex(s => s.id === id)
  return PALETTE_SQUADRE[(i < 0 ? 0 : i) % PALETTE_SQUADRE.length]
}

export default function ConfrontoSquadre({ squadre, me, conf, setConf, mostraTutte = true, comprimibile = false, apertoIniziale = true }) {
  const [aperto, setAperto] = useState(apertoIniziale)
  const altre = squadre.filter(s => s.id !== me)
  const tutte = altre.length > 0 && altre.every(s => conf.ids.includes(s.id))
  const toggleId = id => setConf(c => ({ ...c, ids: c.ids.includes(id) ? c.ids.filter(x => x !== id) : [...c.ids, id] }))

  const sintesi = [conf.bench && 'benchmark di lega', conf.ids.length > 0 && `${conf.ids.length} squadr${conf.ids.length === 1 ? 'a' : 'e'}`].filter(Boolean).join(' · ') || 'nessuno'
  const visibile = !comprimibile || aperto

  return (
    <div className="rounded-xl border border-white/5 bg-pitch-900 p-3 space-y-2.5">
      {comprimibile ? (
        <button type="button" onClick={() => setAperto(a => !a)} aria-expanded={aperto}
                className="w-full flex items-center gap-2 text-left">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">Confronta la tua squadra con</span>
          {!aperto && <span className="text-[11px] text-slate-400 font-mono truncate">{sintesi}</span>}
          <span className="ml-auto text-slate-500">{aperto ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}</span>
        </button>
      ) : (
        <p className="text-[10px] uppercase tracking-widest text-slate-500">Confronta la tua squadra con</p>
      )}
      {visibile && <>
      <div className="flex flex-wrap gap-1.5">
        <Chip on={conf.bench} onClick={() => setConf(c => ({ ...c, bench: !c.bench }))} colore="#fbbf24">
          <Gauge className="w-3 h-3" /> Benchmark di lega
        </Chip>
        {mostraTutte && (
          <Chip on={tutte} onClick={() => setConf(c => ({ ...c, ids: tutte ? [] : altre.map(s => s.id) }))}>
            <Users className="w-3 h-3" /> Tutta la lega
          </Chip>
        )}
      </div>
      <div className="flex flex-wrap gap-1.5">
        {altre.map(s => (
          <Chip key={s.id} on={conf.ids.includes(s.id)} onClick={() => toggleId(s.id)} colore={coloreSquadra(squadre, me, s.id)}>
            {s.nome}
          </Chip>
        ))}
      </div>
      </>}
    </div>
  )
}
