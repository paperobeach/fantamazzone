import { useState, useEffect, useRef } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getIncontri, getCalendario } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { MatchDetailPanel } from '../components/MatchDetail'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import TeamLogo from '../components/TeamLogo'

function MatchCard({ match }) {
  const [open, setOpen] = useState(false)
  const { casa, ospite, golf, gols, segno } = match

  return (
    <div className="card overflow-hidden mb-4">
      {/* Header partita */}
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-2 sm:gap-4 px-3 sm:px-5 py-3 sm:py-4 hover:bg-white/[0.02] transition-colors"
      >
        {/* Casa */}
        <div className="flex-1 min-w-0 flex items-center justify-end gap-2 sm:gap-3">
          <span className="font-semibold text-slate-200 text-xs sm:text-sm truncate text-right">{casa.nome}</span>
          <TeamLogo logo={casa.logo} nome={casa.nome} size="md" />
        </div>

        {/* Score */}
        <div className="flex-shrink-0 flex flex-col items-center gap-1">
          <div className="flex items-center gap-1.5 sm:gap-2">
            <span className={`w-7 h-7 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center text-sm sm:text-lg font-bold text-display ${
              segno === 'W' ? 'bg-green-500/15 text-green-400' :
              segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                              'bg-pitch-800 text-slate-400'
            }`}>{golf}</span>
            <span className="text-slate-600 font-mono text-sm">:</span>
            <span className={`w-7 h-7 sm:w-9 sm:h-9 rounded-xl flex items-center justify-center text-sm sm:text-lg font-bold text-display ${
              segno === 'L' ? 'bg-green-500/15 text-green-400' :
              segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                              'bg-pitch-800 text-slate-400'
            }`}>{gols}</span>
          </div>
          <div className="hidden sm:flex items-center gap-2 text-[10px] font-mono text-slate-700">
            <span>{Number(casa.ftotale).toFixed(1)}</span>
            <span>vs</span>
            <span>{Number(ospite.ftotale).toFixed(1)}</span>
          </div>
        </div>

        {/* Ospite */}
        <div className="flex-1 min-w-0 flex items-center gap-2 sm:gap-3">
          <TeamLogo logo={ospite.logo} nome={ospite.nome} size="md" />
          <span className="font-semibold text-slate-200 text-xs sm:text-sm truncate">{ospite.nome}</span>
        </div>

        <ChevronRight className={`w-4 h-4 text-slate-600 transition-transform flex-shrink-0 ${open ? 'rotate-90' : ''}`} />
      </button>

      {/* Dettaglio voti */}
      {open && <MatchDetailPanel casa={casa} ospite={ospite} />}
    </div>
  )
}

export default function Incontri() {
  const { stagione } = useApp()

  // Numero totale di giornate e ultima giornata giocata: li ricaviamo dal
  // calendario reale (stesso approccio della pagina Calendario), invece che
  // dal solo parametro di sistema "giornata_corrente". Quel parametro non
  // viene aggiornato da nessuna azione admin (nemmeno "Chiudi giornata"), per
  // cui può restare bloccato a un valore fisso e impedire la navigazione tra
  // le giornate.
  const { data: calendario } = useFetch(
    () => getCalendario(stagione),
    [stagione]
  )

  const totalGiornate = calendario?.length ?? 1
  const lastPlayed = calendario?.reduce((acc, g, i) =>
    g.partite.some(p => p.risultato !== null) ? i + 1 : acc, 0) || 1

  const [giornata, setGiornata] = useState(null)
  const initRef = useRef(false)

  // Imposta la giornata di default una sola volta, appena il calendario è
  // disponibile (evita di "congelare" lo state su un valore calcolato prima
  // che i dati fossero pronti).
  useEffect(() => {
    if (!initRef.current && calendario) {
      setGiornata(lastPlayed)
      initRef.current = true
    }
  }, [calendario, lastPlayed])

  const { data, loading, error, refetch } = useFetch(
    () => giornata ? getIncontri(stagione, giornata) : Promise.resolve(null),
    [stagione, giornata]
  )

  if (giornata === null || loading) return <LoadingState label="Caricamento incontri..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />

  return (
    <div className="animate-fade-up">
      <PageHeader label="Risultati" title="Incontri" subtitle={`Giornata ${giornata}`}>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setGiornata(g => Math.max(1, g - 1))}
            disabled={giornata <= 1}
            className="btn-ghost disabled:opacity-30"
          >
            <ChevronLeft className="w-4 h-4" />
          </button>
          <select
            value={giornata}
            onChange={(e) => setGiornata(Number(e.target.value))}
            className="bg-pitch-800 border border-white/10 rounded-lg text-display font-bold text-sm sm:text-base text-white text-center px-2 py-1.5 cursor-pointer focus:outline-none focus:ring-1 focus:ring-white/20"
          >
            {Array.from({ length: totalGiornate }, (_, i) => i + 1).map(g => (
              <option key={g} value={g} className="bg-pitch-800 text-white">
                Giornata {g}
              </option>
            ))}
          </select>
          <button
            onClick={() => setGiornata(g => Math.min(totalGiornate, g + 1))}
            disabled={giornata >= totalGiornate}
            className="btn-ghost disabled:opacity-30"
          >
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
