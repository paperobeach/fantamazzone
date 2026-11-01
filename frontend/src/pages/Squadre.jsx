import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadre } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { Link } from 'react-router-dom'
import { Users } from 'lucide-react'
import TeamLogo from '../components/TeamLogo'
import AlboTrofei from '../components/AlboTrofei'
import { CoachPhoto } from '../components/dashboard/shared'

export default function Squadre() {
  const { stagione } = useApp()

  const { data, loading, error, refetch } = useFetch(
    () => getSquadre(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Caricamento squadre..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  // Difesa: risposta non-array (errore lato PHP) -> messaggio leggibile
  if (data && !Array.isArray(data)) {
    console.error('squadre.php: risposta inattesa', data)
    return <ErrorState message="Risposta non valida dal server (squadre.php)" onRetry={refetch} />
  }
  if (!data?.length) return <EmptyState label="Nessuna squadra trovata" />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Partecipanti"
        title="Squadre"
        subtitle={`${data.length} squadre iscritte`}
      />

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {data.map((sq, i) => {
          const allenatore = typeof sq.allenatore === 'string' ? sq.allenatore : ''
          return (
            <Link
              key={sq.id}
              to={`/squadre/${sq.id}`}
              className="card-hover p-5 group animate-fade-up"
              style={{ animationDelay: `${i * 50}ms` }}
            >
              {/* Logo squadra + nome + allenatore con foto */}
              <div className="flex items-center gap-4 mb-4">
                <TeamLogo logo={sq.logo} nome={sq.nome} size="lg" />
                <div className="min-w-0 flex-1">
                  <h3 className="font-semibold text-slate-200 group-hover:text-grass-400 transition-colors truncate">
                    {sq.nome}
                  </h3>
                  <div className="flex items-center gap-2 mt-1.5 min-w-0">
                    <CoachPhoto logo={sq.logo_allenatore} nome={allenatore} className="w-9 h-9" />
                    <p className="text-xs text-slate-500 truncate">{allenatore || 'Allenatore n.d.'}</p>
                  </div>
                </div>
              </div>

              {/* Albo: campionati, secondi posti, Champions, maglie nere */}
              <AlboTrofei trofei={sq.trofei} />

              {/* Stats row */}
              <div className="flex items-center gap-4 pt-4 mt-4 border-t border-white/5">
                <div className="flex items-center gap-1.5 text-slate-600">
                  <Users className="w-3.5 h-3.5" />
                  <span className="text-xs font-mono">Vedi rosa →</span>
                </div>
              </div>
            </Link>
          )
        })}
      </div>
    </div>
  )
}
