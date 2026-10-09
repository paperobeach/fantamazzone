import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getCalendario, getDettaglioPartita } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { MatchDetailPanel } from '../components/MatchDetail'
import { MatchResultRow } from '../components/MatchResult'
import { ChevronLeft, ChevronRight } from 'lucide-react'

function MatchRow({ partita, stagione, giornata, maxScore, minScore }) {
  const { casa, ospite, risultato } = partita
  const [open, setOpen] = useState(false)

  // Dettaglio caricato on-demand all'apertura della riga: voti se la partita
  // ha risultati, altrimenti la sola formazione inserita.
  const { data: dettaglio, loading, error } = useFetch(
    () => open ? getDettaglioPartita(stagione, giornata, casa?.id, 'CAMP', ospite?.id) : Promise.resolve(null),
    [open, stagione, giornata, casa?.id, ospite?.id]
  )
  const match = dettaglio?.[0] ?? null

  return (
    <div className="border-b border-white/[0.03] last:border-0">
      <MatchResultRow
        casa={casa}
        ospite={ospite}
        risultato={risultato}
        maxScore={maxScore}
        minScore={minScore}
        expandable
        open={open}
        onToggle={() => setOpen(o => !o)}
      />

      {/* Dettaglio voti */}
      {open && (
        <MatchDetailPanel
          casa={match?.casa}
          ospite={match?.ospite}
          loading={loading}
          error={error}
        />
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
  // Difesa: se l'API risponde con qualcosa che non è un array di giornate
  // (es. stringa/oggetto per un errore lato PHP) mostriamo un errore
  // leggibile invece di mandare in crash il rendering.
  if (data && !Array.isArray(data)) {
    console.error('calendario.php: risposta inattesa', data)
    return <ErrorState message="Risposta non valida dal server (calendario.php)" onRetry={refetch} />
  }
  if (!data?.length) return <EmptyState label="Nessun dato per questa stagione" />

  // Inizializza sull'ultima giornata giocata
  const lastPlayed = data.reduce((acc, g, i) =>
    (g.partite ?? []).some(p => p.risultato !== null) ? i : acc, 0)

  const idx     = giornataIdx === 0 ? lastPlayed : giornataIdx - 1
  const cur     = data[idx]
  const total   = data.length
  const played  = data.filter(g => (g.partite ?? []).every(p => p.risultato !== null)).length

  const prev = () => setGiornataIdx(i => Math.max(1, (i === 0 ? lastPlayed + 1 : i) - 1))
  const next = () => setGiornataIdx(i => Math.min(total, (i === 0 ? lastPlayed + 1 : i) + 1))

  // Punteggio più alto e più basso tra le partite giocate della giornata corrente
  const punteggiGiocati = (cur?.partite ?? [])
    .filter(p => p.risultato !== null)
    .flatMap(p => [Number(p.risultato.ftotale_casa), Number(p.risultato.ftotale_ospite)])
  const puntiGiornata = {
    max: punteggiGiocati.length ? Math.max(...punteggiGiocati) : null,
    min: punteggiGiocati.length ? Math.min(...punteggiGiocati) : null,
  }

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
          const done = (g.partite ?? []).every(p => p.risultato !== null)
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
        </div>
        {cur?.partite?.map((p, i) => (
          <MatchRow
            key={i}
            partita={p}
            stagione={stagione}
            giornata={cur.giornata}
            maxScore={puntiGiornata.max}
            minScore={puntiGiornata.min}
          />
        ))}
      </div>
    </div>
  )
}
