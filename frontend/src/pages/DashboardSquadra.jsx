import { useState } from 'react'
import {
  BarChart3, Target, Clock3, TrendingUp, Swords, Dices, History, Wallet, Trophy, Crown,
} from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getDashboardSquadra } from '../api/client'
import { LoadingState, ErrorState } from '../components/ui'
import HeaderSquadra from '../components/dashboard/HeaderSquadra'
import AreaStatistiche from '../components/dashboard/AreaStatistiche'
import AreaPrepara from '../components/dashboard/AreaPrepara'
import {
  AreaAndamento, AreaConfronta, AreaStoria, AreaUltimaPartita, AreaInArrivo,
} from '../components/dashboard/AreeMinori'

// ── Competizione (filtro valido per tutte le aree) ───────────

const COMPETIZIONI = [
  { value: 'CAMP',  label: 'Campionato', icon: Trophy },
  { value: 'CHAMP', label: 'Champions',  icon: Crown },
]

function FiltroCompetizione({ value, onChange }) {
  return (
    <div className="inline-flex gap-1 p-1 bg-pitch-900 rounded-xl border border-white/5" role="tablist" aria-label="Competizione">
      {COMPETIZIONI.map(({ value: v, label, icon: Icon }) => (
        <button
          key={v}
          role="tab"
          aria-selected={value === v}
          onClick={() => onChange(v)}
          className={`inline-flex items-center gap-2 px-4 py-1.5 rounded-lg text-sm font-medium transition-all duration-150 ${
            value === v
              ? v === 'CHAMP' ? 'bg-sky-500 text-pitch-950' : 'bg-grass-500 text-pitch-950'
              : 'text-slate-400 hover:text-slate-200'
          }`}
        >
          <Icon className="w-4 h-4" /> {label}
        </button>
      ))}
    </div>
  )
}

// ── Aree ─────────────────────────────────────────────────────

const AREE = [
  { value: 'statistiche', label: 'Statistiche',               icon: BarChart3 },
  { value: 'prepara',     label: 'Prepara la prossima partita', icon: Target },
  { value: 'ultima',      label: "Analizza l'ultima partita", icon: Clock3 },
  { value: 'andamento',   label: 'Analizza andamento',        icon: TrendingUp },
  { value: 'confronta',   label: 'Confronta',                 icon: Swords },
  { value: 'fortuna',     label: 'Analizza fortuna/sfortuna', icon: Dices },
  { value: 'storia',      label: 'Storia nella lega',         icon: History },
  { value: 'finanza',     label: 'Analisi finanziaria',       icon: Wallet },
]

function NavAree({ value, onChange }) {
  return (
    <div className="overflow-x-auto -mx-1 px-1 pb-1">
      <div className="flex gap-2 w-max">
        {AREE.map(({ value: v, label, icon: Icon }) => (
          <button
            key={v}
            onClick={() => onChange(v)}
            className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border text-sm whitespace-nowrap transition-all duration-150 ${
              value === v
                ? 'bg-grass-500/15 border-grass-500/40 text-grass-300'
                : 'bg-pitch-900 border-white/5 text-slate-400 hover:text-slate-200 hover:border-white/15'
            }`}
          >
            <Icon className="w-4 h-4" /> {label}
          </button>
        ))}
      </div>
    </div>
  )
}

// ── Pagina ───────────────────────────────────────────────────

export default function DashboardSquadra() {
  const { utente, ultimaStagione } = useApp()
  const idSquadra = utente?.id ?? null
  const [area, setArea] = useState('statistiche')
  const [competizione, setCompetizione] = useState('CAMP')

  const { data: d, loading, error, refetch } = useFetch(
    ultimaStagione && idSquadra ? () => getDashboardSquadra(ultimaStagione, idSquadra) : null,
    [ultimaStagione, idSquadra]
  )

  if (loading || (!d && !error)) return <LoadingState label="Caricamento dashboard..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />

  const titoloArea = AREE.find(a => a.value === area)?.label

  return (
    <div className="animate-fade-up min-w-0 max-w-full [&_.grid>*]:min-w-0">
      <HeaderSquadra d={d} />

      <div className="mt-4 flex flex-col lg:flex-row lg:items-center gap-3 mb-6">
        <FiltroCompetizione value={competizione} onChange={setCompetizione} />
        <div className="lg:flex-1 min-w-0">
          <NavAree value={area} onChange={setArea} />
        </div>
      </div>

      {area === 'statistiche' && (
        <AreaStatistiche d={d} stagione={d.stagione} idSquadra={idSquadra} competizione={competizione} />
      )}
      {area === 'prepara'   && <AreaPrepara d={d} stagione={d.stagione} idSquadra={idSquadra} competizione={competizione} />}
      {area === 'ultima'    && <AreaUltimaPartita d={d} competizione={competizione} />}
      {area === 'andamento' && <AreaAndamento d={d} competizione={competizione} />}
      {area === 'confronta' && <AreaConfronta d={d} competizione={competizione} />}
      {area === 'storia'    && <AreaStoria d={d} />}
      {(area === 'fortuna' || area === 'finanza') && <AreaInArrivo titolo={titoloArea} />}
    </div>
  )
}
