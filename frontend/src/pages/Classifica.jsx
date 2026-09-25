import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getGenerale } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, SignBadge, StatCard } from '../components/ui'
import { TrendingUp, TrendingDown, Minus, ChevronUp, ChevronDown } from 'lucide-react'
import { Link } from 'react-router-dom'
import TeamLogo from '../components/TeamLogo'

function TrendIcon({ segno }) {
  if (segno === 'W') return <TrendingUp className="w-3.5 h-3.5 text-green-400" />
  if (segno === 'L') return <TrendingDown className="w-3.5 h-3.5 text-red-400" />
  return <Minus className="w-3.5 h-3.5 text-slate-600" />
}

// Badge di posizione: campione (1°), secondo posto (2°), maglia nera (ultimo)
function posizioneInfo(i, totale) {
  const isLast = totale > 2 && i === totale - 1
  if (i === 0) {
    return {
      label: 'Campione',
      badgeClass: 'bg-gradient-to-br from-gold-300 to-gold-500 text-pitch-900 ring-1 ring-gold-300/60',
      rowBorderClass: 'border-l-2 border-l-gold-400',
    }
  }
  if (i === 1) {
    return {
      label: 'Secondo posto',
      badgeClass: 'bg-gradient-to-br from-slate-200 to-slate-400 text-pitch-900 ring-1 ring-slate-100/60',
      rowBorderClass: 'border-l-2 border-l-slate-300',
    }
  }
  if (isLast) {
    return {
      label: 'Maglia nera',
      badgeClass: 'bg-black text-white ring-1 ring-white/30',
      rowBorderClass: 'border-l-2 border-l-white/20 bg-black/20',
    }
  }
  return null
}

export default function Classifica() {
  const { stagione } = useApp()
  const [sortBy, setSortBy] = useState('punti')

  const { data, loading, error, refetch } = useFetch(
    () => getGenerale(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Caricamento classifica..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.length) return <EmptyState label="Nessun dato per questa stagione" />

  const squadre = [...data].sort((a, b) => {
    if (sortBy === 'punti') return (b.punti - a.punti) || (b.golf - a.golf)
    if (sortBy === 'media') return b.media - a.media
    if (sortBy === 'golf')  return b.golf - a.golf
    return 0
  })

  const leader = squadre[0]

  const SortBtn = ({ col, label }) => (
    <button
      onClick={() => setSortBy(col)}
      className={`flex items-center gap-1 text-xs font-mono tracking-widest uppercase transition-colors ${
        sortBy === col ? 'text-grass-400' : 'text-slate-600 hover:text-slate-400'
      }`}
    >
      {label}
      {sortBy === col
        ? <ChevronDown className="w-3 h-3" />
        : <ChevronUp className="w-3 h-3 opacity-30" />}
    </button>
  )

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Campionato"
        title="Classifica Generale"
        subtitle={`Stagione ${stagione} / ${stagione + 1}`}
      />

      {/* Summary cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <StatCard
          label="Capolista"
          value={leader?.squadra ?? '—'}
          sub={`${leader?.punti} punti`}
          accent
        />
        <StatCard
          label="Miglior media"
          value={[...data].sort((a,b) => b.media - a.media)[0]?.squadra ?? '—'}
          sub={`Media ${[...data].sort((a,b) => b.media - a.media)[0]?.media}`}
        />
        <StatCard
          label="Giornata"
          value={data[0]?.partiteg ?? '—'}
          sub="partite giocate"
        />
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-4 py-3 border-b border-white/5">
          <h2 className="text-sm font-semibold text-slate-300">Squadre</h2>
          <div className="flex items-center gap-4 flex-wrap">
            <span className="text-xs text-slate-600">Ordina per:</span>
            <SortBtn col="punti" label="Punti" />
            <SortBtn col="media" label="Media" />
            <SortBtn col="golf"  label="Gol" />
          </div>
        </div>

        <div className="overflow-x-auto">
        <table className="fanta-table min-w-[1080px]">
          <thead>
            <tr>
              <th className="w-10">#</th>
              <th>Squadra</th>
              <th className="text-center">PG</th>
              <th className="text-center">Pts</th>
              <th className="text-center">V</th>
              <th className="text-center">P</th>
              <th className="text-center">S</th>
              <th className="text-center">GF</th>
              <th className="text-center">GS</th>
              <th className="text-center">Media</th>
              <th className="text-center">Media A</th>
              <th className="text-center">Max</th>
              <th className="text-center">Min</th>
              <th className="text-center">Mod D</th>
              <th className="text-center">Mod C</th>
              <th className="text-center">Mod A</th>
              <th className="text-center">Trend</th>
            </tr>
          </thead>
          <tbody>
            {squadre.map((sq, i) => {
              const pos = posizioneInfo(i, squadre.length)
              return (
                <tr
                  key={sq.id_squadra}
                  className={`border-b border-white/[0.03] transition-colors hover:bg-white/[0.02] ${
                    pos ? pos.rowBorderClass : ''
                  }`}
                >
                  <td className="px-4 py-3 text-center">
                    {pos ? (
                      <span
                        title={pos.label}
                        className={`inline-flex items-center justify-center w-7 h-7 rounded-full text-sm font-bold text-display ${pos.badgeClass}`}
                      >
                        {i + 1}
                      </span>
                    ) : (
                      <span className="text-display font-bold text-lg text-slate-600">{i + 1}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <Link
                      to={`/squadre/${sq.id_squadra}`}
                      className="flex items-center gap-3 group"
                    >
                      <TeamLogo logo={sq.logo} nome={sq.squadra} size="sm" />
                      <span className="font-medium text-slate-200 group-hover:text-grass-400 transition-colors text-sm">
                        {sq.squadra}
                      </span>
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-center text-slate-400 text-sm">{sq.partiteg}</td>
                  <td className="px-4 py-3 text-center">
                    <span className="text-display font-bold text-lg text-white">{sq.punti}</span>
                  </td>
                  <td className="px-4 py-3 text-center text-green-400 text-sm font-medium">{sq.vinte}</td>
                  <td className="px-4 py-3 text-center text-yellow-400 text-sm">{sq.nulle}</td>
                  <td className="px-4 py-3 text-center text-red-400 text-sm">{sq.perse}</td>
                  <td className="px-4 py-3 text-center text-slate-300 text-sm">{sq.golf}</td>
                  <td className="px-4 py-3 text-center text-slate-500 text-sm">{sq.gols}</td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.media).toFixed(1)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.media_a).toFixed(1)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.maxp).toFixed(1)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.minp).toFixed(1)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.media_mod_dif).toFixed(2)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.media_mod_cc).toFixed(2)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-xs text-slate-400">{Number(sq.media_mod_att).toFixed(2)}</span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <div className="flex justify-center">
                      <TrendIcon segno={sq.segno} />
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        </div>
      </div>

      {/* Legend */}
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 mt-4 px-1">
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-gradient-to-br from-gold-300 to-gold-500 ring-1 ring-gold-300/60" />
          <span className="text-xs text-slate-600">Campione</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-gradient-to-br from-slate-200 to-slate-400 ring-1 ring-slate-100/60" />
          <span className="text-xs text-slate-600">Secondo posto</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-black ring-1 ring-white/30" />
          <span className="text-xs text-slate-600">Maglia nera</span>
        </div>
      </div>
    </div>
  )
}
