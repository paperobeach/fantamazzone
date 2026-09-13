import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadre } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { Link } from 'react-router-dom'
import { Users, Trophy } from 'lucide-react'

export default function Squadre() {
  const { stagione } = useApp()

  const { data, loading, error, refetch } = useFetch(
    () => getSquadre(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Caricamento squadre..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.length) return <EmptyState label="Nessuna squadra trovata" />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Partecipanti"
        title="Squadre"
        subtitle={`${data.length} squadre iscritte`}
      />

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {data.map((sq, i) => (
          <Link
            key={sq.id}
            to={`/squadre/${sq.id}`}
            className="card-hover p-5 group animate-fade-up"
            style={{ animationDelay: `${i * 50}ms` }}
          >
            {/* Avatar + name */}
            <div className="flex items-center gap-4 mb-4">
              <div className="w-12 h-12 rounded-xl bg-pitch-800 border border-white/10 flex items-center justify-center text-xl font-bold text-grass-400 group-hover:border-grass-500/30 transition-colors">
                {sq.nome?.[0]}
              </div>
              <div>
                <h3 className="font-semibold text-slate-200 group-hover:text-grass-400 transition-colors">
                  {sq.nome}
                </h3>
                <p className="text-xs text-slate-600">{sq.allenatore}</p>
              </div>
            </div>

            {/* Stats row */}
            <div className="flex items-center gap-4 pt-4 border-t border-white/5">
              {sq.albo && Number(sq.albo) > 0 && (
                <div className="flex items-center gap-1.5 text-gold-400">
                  <Trophy className="w-3.5 h-3.5" />
                  <span className="text-xs font-mono">{sq.albo} titoli</span>
                </div>
              )}
              <div className="flex items-center gap-1.5 text-slate-600">
                <Users className="w-3.5 h-3.5" />
                <span className="text-xs font-mono">Vedi rosa →</span>
              </div>
            </div>
          </Link>
        ))}
      </div>
    </div>
  )
}
