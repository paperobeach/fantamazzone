// ============================================================
// Barra di navigazione fissa della Dashboard squadra, comune a tutte le aree.
//   - pulsante "torna in alto" sempre disponibile (in alto ci sono i tab delle aree)
//   - pulsanti delle sezioni dell'area, con evidenziazione di quella in vista
//   - rotellina di configurazione: competizione e giocatori considerati
// Resta agganciata sotto l'intestazione della squadra (--barra-squadra-h, vedi HeaderSquadra).
// ============================================================
import { useEffect, useRef, useState } from 'react'
import { ArrowUp, Settings, Trophy, Crown, Users, UserCheck, X } from 'lucide-react'

/** Sezioni di ogni area: `id` è l'id dell'elemento nella pagina (con classe `sezione-scroll`). */
export const SEZIONI_AREA = {
  statistiche: [
    { id: 'stat-risultati', label: 'Risultati' },
    { id: 'stat-gol',       label: 'Gol' },
    { id: 'stat-reparti',   label: 'Reparti' },
    { id: 'stat-giocatori', label: 'Top / Flop' },
  ],
  prepara: [
    { id: 'prep-avversario', label: "L'avversario" },
    { id: 'prep-top11',      label: 'Top 11' },
    { id: 'prep-serie_a',    label: 'Serie A' },
  ],
  andamento: [
    { id: 'and-selezione', label: 'Statistiche' },
    { id: 'and-grafici',   label: 'Andamento' },
  ],
  confronta: [
    { id: 'conf-selezione', label: 'Statistiche' },
    { id: 'conf-grafici',   label: 'Confronto' },
  ],
  ultima: [], storia: [], fortuna: [], finanza: [],
}

export const CONFIG_DEFAULT = { competizione: 'CAMP', vista: 'ROSA' }

const vaiA = id => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
const inAlto = () => window.scrollTo({ top: 0, behavior: 'smooth' })

function Scelta({ titolo, icona: Icona, value, onChange, opzioni }) {
  return (
    <div className="space-y-1.5">
      <p className="text-[10px] uppercase tracking-widest text-slate-500 flex items-center gap-1.5">
        <Icona className="w-3 h-3" /> {titolo}
      </p>
      <div className="flex gap-1 p-1 bg-pitch-950 rounded-xl border border-white/5" role="radiogroup" aria-label={titolo}>
        {opzioni.map(o => (
          <button key={o.value} type="button" role="radio" aria-checked={value === o.value}
                  onClick={() => onChange(o.value)}
                  className={`flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors whitespace-nowrap ${
                    value === o.value
                      ? o.value === 'CHAMP' ? 'bg-sky-500 text-pitch-950' : 'bg-grass-500 text-pitch-950'
                      : 'text-slate-400 hover:text-slate-200'}`}>
            {o.icona && <o.icona className="w-3.5 h-3.5" />} {o.label}
          </button>
        ))}
      </div>
    </div>
  )
}

function PannelloConfig({ config, onChange, onClose }) {
  return (
    <div role="dialog" aria-label="Personalizza la visualizzazione"
         className="absolute right-3 sm:right-6 lg:right-8 top-full mt-1 w-[min(20rem,calc(100vw-2rem))] rounded-2xl border border-white/10 bg-pitch-900 shadow-xl shadow-black/50 p-4 space-y-4 z-30">
      <div className="flex items-center">
        <p className="text-sm font-semibold text-slate-200">Visualizzazione</p>
        <button type="button" onClick={onClose} aria-label="Chiudi" className="ml-auto p-1 rounded-md text-slate-500 hover:text-slate-200"><X className="w-4 h-4" /></button>
      </div>
      <Scelta titolo="Competizione" icona={Trophy} value={config.competizione}
              onChange={v => onChange({ ...config, competizione: v })}
              opzioni={[{ value: 'CAMP', label: 'Campionato', icona: Trophy }, { value: 'CHAMP', label: 'Champions', icona: Crown }]} />
      <Scelta titolo="Giocatori" icona={Users} value={config.vista}
              onChange={v => onChange({ ...config, vista: v })}
              opzioni={[{ value: 'SCHIERATI', label: 'Solo schierati', icona: UserCheck }, { value: 'ROSA', label: 'Intera rosa', icona: Users }]} />
      <div className="flex items-center justify-between text-[11px] text-slate-600">
        <span>Vale per tutte le sezioni.</span>
        <button type="button" onClick={() => onChange(CONFIG_DEFAULT)} className="text-slate-400 hover:text-white">Ripristina</button>
      </div>
    </div>
  )
}

export function BarraSezioni({ sezioni, config, onConfig }) {
  const barraRef = useRef(null)
  const [attiva, setAttiva] = useState(sezioni[0]?.id ?? null)
  const [aperto, setAperto] = useState(false)
  const chiave = sezioni.map(s => s.id).join('|')

  // Sezione in vista (scroll-spy)
  useEffect(() => {
    let raf = 0
    const calcola = () => {
      raf = 0
      const soglia = (barraRef.current?.getBoundingClientRect().bottom ?? 0) + 24
      let corrente = sezioni[0]?.id ?? null
      sezioni.forEach(s => {
        const el = document.getElementById(s.id)
        if (el && el.getBoundingClientRect().top <= soglia) corrente = s.id
      })
      // In fondo alla pagina l'ultima sezione può non raggiungere la soglia
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
        const ultima = [...sezioni].reverse().find(s => document.getElementById(s.id))
        if (ultima) corrente = ultima.id
      }
      setAttiva(corrente)
    }
    const richiedi = () => { if (!raf) raf = requestAnimationFrame(calcola) }
    window.addEventListener('scroll', richiedi, { passive: true })
    window.addEventListener('resize', richiedi)
    calcola()
    return () => {
      window.removeEventListener('scroll', richiedi)
      window.removeEventListener('resize', richiedi)
      if (raf) cancelAnimationFrame(raf)
    }
  }, [chiave]) // eslint-disable-line react-hooks/exhaustive-deps

  // Chiusura del pannello: Esc o clic fuori
  useEffect(() => {
    if (!aperto) return undefined
    const esc = e => { if (e.key === 'Escape') setAperto(false) }
    const fuori = e => { if (barraRef.current && !barraRef.current.contains(e.target)) setAperto(false) }
    document.addEventListener('keydown', esc)
    document.addEventListener('pointerdown', fuori)
    return () => { document.removeEventListener('keydown', esc); document.removeEventListener('pointerdown', fuori) }
  }, [aperto])

  const champ = config.competizione === 'CHAMP'

  return (
    <div ref={barraRef}
         className="nav-sezioni-sticky sticky z-[15] -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-2 mb-6
                    bg-pitch-950/90 backdrop-blur supports-[backdrop-filter]:bg-pitch-950/70">
      <div className="flex items-center gap-2">
        <button type="button" onClick={inAlto} title="Torna in alto" aria-label="Torna in alto"
                className="flex-shrink-0 p-1.5 rounded-full border border-white/10 text-slate-300 hover:text-white hover:border-white/25 transition-colors">
          <ArrowUp className="w-4 h-4" />
        </button>

        <div className="flex-1 min-w-0 overflow-x-auto">
          <div className="flex gap-1.5 w-max">
            {sezioni.map(s => (
              <button key={s.id} type="button" onClick={() => vaiA(s.id)} aria-current={attiva === s.id ? 'true' : undefined}
                      className={`px-3 py-1.5 rounded-full border text-xs transition-colors whitespace-nowrap ${
                        attiva === s.id
                          ? 'border-grass-500/50 text-grass-300 bg-grass-500/10'
                          : 'border-white/10 text-slate-400 hover:text-white hover:border-white/25'}`}>
                {s.label}
              </button>
            ))}
          </div>
        </div>

        <button type="button" onClick={() => setAperto(a => !a)} aria-expanded={aperto} aria-label="Personalizza la visualizzazione"
                title="Personalizza la visualizzazione"
                className={`flex-shrink-0 inline-flex items-center gap-1.5 px-2 py-1.5 rounded-full border text-xs transition-colors ${
                  aperto ? 'border-grass-500/50 text-grass-300 bg-grass-500/10' : 'border-white/10 text-slate-300 hover:text-white hover:border-white/25'}`}>
          {champ ? <Crown className="w-4 h-4 text-sky-400" /> : <Trophy className="w-4 h-4 text-grass-400" />}
          <span className="hidden sm:inline">{champ ? 'Champions' : 'Campionato'} · {config.vista === 'ROSA' ? 'Rosa' : 'Schierati'}</span>
          <Settings className="w-4 h-4" />
        </button>
      </div>
      {aperto && <PannelloConfig config={config} onChange={onConfig} onClose={() => setAperto(false)} />}
    </div>
  )
}
