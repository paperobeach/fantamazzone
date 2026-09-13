import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getIncontri, getSistema } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge } from '../components/ui'
import { ChevronLeft, ChevronRight } from 'lucide-react'

function VotoBox({ voto, totale, giocata }) {
  if (!giocata || !voto) return <span className="text-slate-700 font-mono text-xs">sv</span>
  const v = Number(totale)
  return (
    <span className={`font-mono text-xs font-semibold ${
      v >= 7.5 ? 'text-green-400' :
      v >= 6   ? 'text-slate-300' :
      v >= 5   ? 'text-yellow-400' :
                 'text-red-400'
    }`}>{v.toFixed(2)}</span>
  )
}

function GiocatoriTable({ giocatori }) {
  return (
    <table className="w-full text-xs">
      <thead>
        <tr className="border-b border-white/5">
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal">R</th>
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal">Giocatore</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Voto</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Tot</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">⚽</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">🟨</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">🟥</th>
        </tr>
      </thead>
      <tbody>
        {(giocatori ?? []).map((g, i) => (
          <tr key={i} className="border-b border-white/[0.03] hover:bg-white/[0.02]">
            <td className="px-3 py-2"><RoleBadge ruolo={g.ruolo} /></td>
            <td className="px-3 py-2 text-slate-300 font-medium">{g.giocatore}</td>
            <td className="px-3 py-2 text-center">
              <VotoBox voto={g.voto} totale={g.voto} giocata={g.giocata} />
            </td>
            <td className="px-3 py-2 text-center">
              <VotoBox voto={g.totale} totale={g.totale} giocata={g.giocata} />
            </td>
            <td className="px-3 py-2 text-center text-slate-500">{g.reti > 0 ? g.reti : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.ammonizioni > 0 ? g.ammonizioni : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.espulsioni > 0 ? g.espulsioni : '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function MatchCard({ match }) {
  const [open, setOpen] = useState(false)
  const { casa, ospite, golf, gols, segno } = match

  return (
    <div className="card overflow-hidden mb-4">
      {/* Header partita */}
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-4 px-5 py-4 hover:bg-white/[0.02] transition-colors"
      >
        {/* Casa */}
        <div className="flex-1 flex items-center justify-end gap-3">
          <span className="font-semibold text-slate-200 text-sm">{casa.nome}</span>
          <div className="w-8 h-8 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-xs font-bold text-slate-500">
            {casa.nome?.[0]}
          </div>
        </div>

        {/* Score */}
        <div className="flex-shrink-0 flex flex-col items-center gap-1">
          <div className="flex items-center gap-2">
            <span className={`w-9 h-9 rounded-xl flex items-center justify-center text-lg font-bold text-display ${
              segno === 'W' ? 'bg-green-500/15 text-green-400' :
              segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                              'bg-pitch-800 text-slate-400'
            }`}>{golf}</span>
            <span className="text-slate-600 font-mono text-sm">:</span>
            <span className={`w-9 h-9 rounded-xl flex items-center justify-center text-lg font-bold text-display ${
              segno === 'L' ? 'bg-green-500/15 text-green-400' :
              segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                              'bg-pitch-800 text-slate-400'
            }`}>{gols}</span>
          </div>
          <div className="flex items-center gap-2 text-[10px] font-mono text-slate-700">
            <span>{Number(casa.ftotale).toFixed(1)}</span>
            <span>vs</span>
            <span>{Number(ospite.ftotale).toFixed(1)}</span>
          </div>
        </div>

        {/* Ospite */}
        <div className="flex-1 flex items-center gap-3">
          <div className="w-8 h-8 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-xs font-bold text-slate-500">
            {ospite.nome?.[0]}
          </div>
          <span className="font-semibold text-slate-200 text-sm">{ospite.nome}</span>
        </div>

        <ChevronRight className={`w-4 h-4 text-slate-600 transition-transform flex-shrink-0 ${open ? 'rotate-90' : ''}`} />
      </button>

      {/* Dettaglio voti */}
      {open && (
        <div className="border-t border-white/5 grid grid-cols-2 divide-x divide-white/5">
          <div>
            <div className="px-3 py-2 border-b border-white/5">
              <p className="text-xs font-semibold text-slate-400">{casa.nome}</p>
            </div>
            <GiocatoriTable giocatori={casa.giocatori} />
          </div>
          <div>
            <div className="px-3 py-2 border-b border-white/5">
              <p className="text-xs font-semibold text-slate-400">{ospite.nome}</p>
            </div>
            <GiocatoriTable giocatori={ospite.giocatori} />
          </div>
        </div>
      )}
    </div>
  )
}

export default function Incontri() {
  const { stagione, sistemaParams } = useApp()
  const maxGiornata = Number(sistemaParams?.giornata_corrente ?? 1)
  const [giornata, setGiornata] = useState(maxGiornata)

  const { data, loading, error, refetch } = useFetch(
    () => getIncontri(stagione, giornata),
    [stagione, giornata]
  )

  if (loading) return <LoadingState label="Caricamento incontri..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />

  return (
    <div className="animate-fade-up">
      <PageHeader label="Risultati" title="Incontri" subtitle={`Giornata ${giornata}`}>
        <div className="flex items-center gap-2">
          <button onClick={() => setGiornata(g => Math.max(1, g - 1))} className="btn-ghost">
            <ChevronLeft className="w-4 h-4" />
          </button>
          <span className="text-display font-bold text-xl text-white w-10 text-center">{giornata}</span>
          <button onClick={() => setGiornata(g => Math.min(maxGiornata, g + 1))} className="btn-ghost">
            <ChevronRight className="w-4 h-4" />
          </button>
        </div>
      </PageHeader>

      {!data?.length
        ? <EmptyState label="Nessun incontro trovato per questa giornata" />
        : data.map((match, i) => <MatchCard key={i} match={match} />)
      }
    </div>
  )
}
