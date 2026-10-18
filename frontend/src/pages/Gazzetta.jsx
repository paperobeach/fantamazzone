import { Link } from 'react-router-dom'
import { Newspaper, ChevronRight, BookOpen } from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getArticoli, urlImmagine } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'

// ============================================================
// "La Gazzetta" — elenco pubblico degli articoli di commento alla
// giornata (scritti dall'AI e pubblicati da un amministratore).
// ============================================================

export const dataEstesa = (s) => {
  if (!s) return ''
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })
}

export default function Gazzetta() {
  const { stagione } = useApp()
  const { data, loading, error, refetch } = useFetch(() => getArticoli(stagione), [stagione])

  if (loading) return <LoadingState label="Caricamento articoli..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Stagione"
        title="La Gazzetta del Fantamazzone"
        subtitle="Il commento (semi-serio) di ogni giornata."
      />

      {!data?.length ? (
        <EmptyState label="Nessun articolo pubblicato per questa stagione" />
      ) : (
        <div className="space-y-3 max-w-3xl">
          {data.map(a => (
            <Link
              key={a.id}
              to={`/gazzetta/${a.id}`}
              className="card p-5 flex items-start gap-4 hover:border-white/20 transition-colors group"
            >
              {a.id_immagine_copertina ? (
                <img
                  src={urlImmagine(a.id_immagine_copertina, 'thumb')} alt="" loading="lazy"
                  className="w-16 h-16 rounded-lg object-cover flex-shrink-0 border border-white/10"
                />
              ) : (
                <div className="w-10 h-10 rounded-lg bg-grass-500/10 flex items-center justify-center flex-shrink-0">
                  <Newspaper className="w-5 h-5 text-grass-500" />
                </div>
              )}
              <div className="min-w-0 flex-1">
                <p className="text-[11px] text-mono tracking-widest uppercase text-slate-600 flex flex-wrap items-center gap-x-2">
                  <span>Giornata {a.giornata}{a.data_pubblicazione && ` · ${dataEstesa(a.data_pubblicazione)}`}</span>
                  {a.formato === 'edizione' && (
                    <span className="stat-pill text-gold-400 normal-case tracking-normal">
                      <BookOpen className="w-3 h-3" /> Edizione sfogliabile
                    </span>
                  )}
                </p>
                <h2 className="font-semibold text-slate-100 mt-0.5 leading-snug">{a.titolo}</h2>
                {a.sommario && <p className="text-sm text-slate-400 mt-1">{a.sommario}</p>}
              </div>
              <ChevronRight className="w-4 h-4 text-slate-600 group-hover:text-slate-300 flex-shrink-0 mt-1" />
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
