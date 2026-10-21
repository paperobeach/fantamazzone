import { useFetch } from '../hooks/useFetch'
import { getAlboOro } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { Trophy, Shield, Medal, ShieldX } from 'lucide-react'
import TeamLogo from '../components/TeamLogo'

// Cella con logo + nome squadra (o trattino se il dato non c'è)
function Squadra({ team }) {
  if (!team) return <span className="text-slate-600">—</span>
  return (
    <div className="flex items-center gap-2 min-w-0">
      <TeamLogo logo={team.logo} nome={team.nome} size="sm" />
      <span className="truncate">{team.nome}</span>
    </div>
  )
}

const stagioneLabel = (s) => `${s}/${String(s + 1).slice(-2)}`

function Th({ icon: Icon, colore, children }) {
  return (
    <th className="px-4 py-3 text-left font-medium">
      <span className="inline-flex items-center gap-1.5">
        <Icon className={`w-3.5 h-3.5 ${colore}`} />
        {children}
      </span>
    </th>
  )
}

function Tabella({ righe, colonne }) {
  return (
    <div className="card overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-xs font-mono tracking-widest uppercase text-slate-500 border-b border-white/5">
              <th className="px-4 py-3 text-left font-medium">Stagione</th>
              {colonne.map(c => (
                <Th key={c.key} icon={c.icon} colore={c.colore}>{c.label}</Th>
              ))}
            </tr>
          </thead>
          <tbody>
            {righe.map(r => (
              <tr key={r.stagione} className="border-b border-white/5 last:border-0 hover:bg-white/[0.02]">
                <td className="px-4 py-2.5 font-mono text-slate-400 whitespace-nowrap">{stagioneLabel(r.stagione)}</td>
                {colonne.map(c => (
                  <td key={c.key} className="px-4 py-2.5 text-slate-200 whitespace-nowrap">
                    <Squadra team={r[c.key]} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const COL_CAMP = [
  { key: 'primo',   label: 'Primo',   icon: Trophy,  colore: 'text-gold-400' },
  { key: 'secondo', label: 'Secondo', icon: Medal,   colore: 'text-slate-300' },
  { key: 'ultimo',  label: 'Ultimo',  icon: ShieldX, colore: 'text-red-400' },
]
const COL_CHAMP = [
  { key: 'vincitore', label: 'Vincitore', icon: Trophy, colore: 'text-gold-400' },
  { key: 'finalista', label: 'Finalista', icon: Medal,  colore: 'text-slate-300' },
]

export default function AlboOro() {
  const { data, loading, error, refetch } = useFetch(() => getAlboOro(), [])

  if (loading) return <LoadingState label="Caricamento albo d'oro..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data || Array.isArray(data)) {
    return <ErrorState message="Risposta non valida dal server (albo_oro.php)" onRetry={refetch} />
  }

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Generale"
        title="Albo d'oro"
        subtitle="Storico dei piazzamenti di campionato e Champions"
      />

      <section className="mb-8">
        <h2 className="flex items-center gap-2 text-sm font-mono tracking-widest uppercase text-slate-400 mb-3">
          <Trophy className="w-4 h-4 text-gold-400" /> Campionato
        </h2>
        {data.campionato?.length
          ? <Tabella righe={data.campionato} colonne={COL_CAMP} />
          : <EmptyState label="Albo del campionato non ancora disponibile" />}
      </section>

      <section>
        <h2 className="flex items-center gap-2 text-sm font-mono tracking-widest uppercase text-slate-400 mb-3">
          <Shield className="w-4 h-4 text-sky-400" /> Champions
        </h2>
        {data.champions?.length
          ? <Tabella righe={data.champions} colonne={COL_CHAMP} />
          : <EmptyState label="Albo della Champions non ancora disponibile" />}
      </section>
    </div>
  )
}
