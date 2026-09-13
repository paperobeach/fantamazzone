import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getCalendario } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

function ScoreBox({ risultato }) {
  if (!risultato) return (
    <div className="flex items-center gap-2">
      <span className="w-7 h-7 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-slate-600 text-sm">—</span>
      <span className="text-slate-700 text-xs">:</span>
      <span className="w-7 h-7 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-slate-600 text-sm">—</span>
    </div>
  )
  const { golf, gols, segno } = risultato
  return (
    <div className="flex items-center gap-2">
      <span className={`w-7 h-7 rounded-lg flex items-center justify-center text-sm font-bold ${
        segno === 'W' ? 'bg-green-500/15 text-green-400' :
        segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                        'bg-pitch-800 text-slate-400'
      }`}>{golf}</span>
      <span className="text-slate-600 text-xs font-mono">:</span>
      <span className={`w-7 h-7 rounded-lg flex items-center justify-center text-sm font-bold ${
        segno === 'L' ? 'bg-green-500/15 text-green-400' :
        segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                        'bg-pitch-800 text-slate-400'
      }`}>{gols}</span>
    </div>
  )
}

function MatchRow({ partita }) {
  const { casa, ospite, risultato } = partita
  const giocata = risultato !== null
  return (
    <div className={`flex items-center gap-4 py-3 px-4 border-b border-white/[0.03] last:border-0 transition-colors hover:bg-white/[0.02] ${!giocata ? 'opacity-60' : ''}`}>
      {/* Casa */}
      <Link to={`/squadre/${casa?.id}`} className="flex items-center gap-2 flex-1 justify-end group">
        <span className="text-sm text-slate-300 group-hover:text-grass-400 transition-colors font-medium text-right">{casa?.nome}</span>
        <div className="w-7 h-7 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-[10px] font-bold text-slate-500 flex-shrink-0">
          {casa?.nome?.[0]}
        </div>
      </Link>

      {/* Score */}
      <div className="flex-shrink-0">
        <ScoreBox risultato={risultato} />
      </div>

      {/* Ospite */}
      <Link to={`/squadre/${ospite?.id}`} className="flex items-center gap-2 flex-1 group">
        <div className="w-7 h-7 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-[10px] font-bold text-slate-500 flex-shrink-0">
          {ospite?.nome?.[0]}
        </div>
        <span className="text-sm text-slate-300 group-hover:text-grass-400 transition-colors font-medium">{ospite?.nome}</span>
      </Link>

      {/* Punteggi */}
      {giocata && (
        <div className="hidden md:flex items-center gap-3 text-xs font-mono text-slate-600">
          <span>{Number(risultato.ftotale_casa).toFixed(1)}</span>
          <span className="text-slate-700">vs</span>
          <span>{Number(risultato.ftotale_ospite).toFixed(1)}</span>
        </div>
      )}
    </div>
  )
}

export default function Calendario() {
  const { stagione } = useApp()
  const [giornataIdx, setGiornataIdx] = useState(0)

  const { data, loading, error, refetch } = useFetch(
    () => getCalendario(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Caricamento calendario..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.length) return <EmptyState label="Nessun dato per questa stagione" />

  // Inizializza sull'ultima giornata giocata
  const lastPlayed = data.reduce((acc, g, i) =>
    g.partite.some(p => p.risultato !== null) ? i : acc, 0)

  const idx     = giornataIdx === 0 ? lastPlayed : giornataIdx - 1
  const cur     = data[idx]
  const total   = data.length
  const played  = data.filter(g => g.partite.every(p => p.risultato !== null)).length

  const prev = () => setGiornataIdx(i => Math.max(1, (i === 0 ? lastPlayed + 1 : i) - 1))
  const next = () => setGiornataIdx(i => Math.min(total, (i === 0 ? lastPlayed + 1 : i) + 1))

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Campionato"
        title="Calendario"
        subtitle={`${played} giornate giocate su ${total}`}
      />

      {/* Giornata selector */}
      <div className="flex items-center justify-between mb-6">
        <button onClick={prev} disabled={idx === 0} className="btn-ghost disabled:opacity-30">
          <ChevronLeft className="w-4 h-4" /> Precedente
        </button>

        <div className="text-center">
          <p className="text-display font-bold text-2xl text-white">Giornata {cur?.giornata}</p>
          <p className="text-xs text-slate-600 font-mono mt-0.5">
            {cur?.partite?.every(p => p.risultato !== null) ? '✓ Completata' : 'In attesa'}
          </p>
        </div>

        <button onClick={next} disabled={idx === total - 1} className="btn-ghost disabled:opacity-30">
          Successiva <ChevronRight className="w-4 h-4" />
        </button>
      </div>

      {/* Giornate rapide */}
      <div className="flex flex-wrap gap-1.5 mb-6">
        {data.map((g, i) => {
          const done = g.partite.every(p => p.risultato !== null)
          const active = i === idx
          return (
            <button
              key={g.giornata}
              onClick={() => setGiornataIdx(i + 1)}
              className={`w-8 h-8 rounded-lg text-xs font-mono font-medium transition-all ${
                active  ? 'bg-grass-500 text-pitch-950' :
                done    ? 'bg-pitch-800 text-slate-400 hover:bg-pitch-700' :
                          'bg-pitch-900 border border-white/5 text-slate-600 hover:border-white/10'
              }`}
            >
              {g.giornata}
            </button>
          )
        })}
      </div>

      {/* Partite */}
      <div className="card overflow-hidden">
        <div className="px-4 py-3 border-b border-white/5 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-300">Partite</h2>
          <span className="text-xs text-slate-600 font-mono">{cur?.partite?.length} incontri</span>
        </div>
        {cur?.partite?.map((p, i) => <MatchRow key={i} partita={p} />)}
      </div>
    </div>
  )
}
