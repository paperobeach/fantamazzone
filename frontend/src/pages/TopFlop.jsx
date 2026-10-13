import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getTopFlop } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../components/ui'
import { PitchFormation } from '../components/PitchFormation'

const RUOLO_ORDER = { '1': 0, '2': 1, '3': 2, '4': 3 }

function ElevenGrid({ players, isTop }) {
  if (!players?.length) return null
  const sorted = [...players].sort((a, b) =>
    (RUOLO_ORDER[a.ruolo] - RUOLO_ORDER[b.ruolo]) || (isTop ? b.media - a.media : a.media - b.media)
  )
  return (
    <div className="space-y-1">
      {sorted.map((p, i) => (
        <div key={i} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0 hover:bg-white/[0.02] transition-colors">
          <RoleBadge ruolo={p.ruolo} />
          <span className="flex-1 text-sm text-slate-300 font-medium">{p.giocatore}</span>
          <span className="text-xs text-slate-600">{p.squadra}</span>
          <span className={`font-mono text-sm font-bold w-12 text-right ${
            isTop ? 'text-green-400' : 'text-red-400'
          }`}>{Number(p.media).toFixed(2)}</span>
        </div>
      ))}
    </div>
  )
}

// competizione: 'CAMP' (Top/Flop 11 del campionato) | 'CHAMP' (solo Champions)
export default function TopFlop({ competizione = 'CAMP' }) {
  const { stagione } = useApp()
  const champ = competizione === 'CHAMP'
  const { data, loading, error, refetch } = useFetch(
    () => getTopFlop(stagione, null, competizione),
    [stagione, competizione]
  )
  const [view, setView] = useState('campo')

  if (loading) return <LoadingState label="Caricamento Top/Flop..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />

  const VIEW_TABS = [
    { value: 'campo', label: 'Campo' },
    { value: 'lista', label: 'Lista' },
  ]

  return (
    <div className="animate-fade-up">
      <PageHeader label={champ ? 'Champions' : 'Stagione'} title={champ ? 'Top 11 / Flop 11 Champions' : 'Top 11 / Flop 11'}>
        <TabBar tabs={VIEW_TABS} active={view} onChange={setView} />
      </PageHeader>

      {champ && !data?.top11?.length && !data?.flop11?.length && (
        <EmptyState label="Nessun dato Champions disponibile per questa stagione" />
      )}

      {(!champ || data?.top11?.length > 0 || data?.flop11?.length > 0) && (
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="card overflow-hidden">
          <div className="px-4 py-3 border-b border-white/5 flex items-center gap-2">
            <span className="text-lg">⭐</span>
            <h2 className="font-semibold text-green-400">Top 11</h2>
            <span className="text-xs text-slate-600 font-mono ml-auto">
              {view === 'campo' ? 'modulo 3-4-3' : 'per media voto'}
            </span>
          </div>
          {view === 'campo'
            ? <div className="p-4"><PitchFormation players={data?.top11} isTop={true} /></div>
            : <ElevenGrid players={data?.top11} isTop={true} />}
        </div>

        <div className="card overflow-hidden">
          <div className="px-4 py-3 border-b border-white/5 flex items-center gap-2">
            <span className="text-lg">📉</span>
            <h2 className="font-semibold text-red-400">Flop 11</h2>
            <span className="text-xs text-slate-600 font-mono ml-auto">
              {view === 'campo' ? 'modulo 3-4-3' : 'per media voto'}
            </span>
          </div>
          {view === 'campo'
            ? <div className="p-4"><PitchFormation players={data?.flop11} isTop={false} /></div>
            : <ElevenGrid players={data?.flop11} isTop={false} />}
        </div>
      </div>
      )}

      {champ && (
        <p className="text-xs text-slate-600 mt-4">
          Calcolate sulle sole partite di Champions: media fantapunti dei giocatori che hanno raggiunto il numero
          minimo di presenze, calcolato come per il campionato sulle partite Champions disputate.
        </p>
      )}
    </div>
  )
}
