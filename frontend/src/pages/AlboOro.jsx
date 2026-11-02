import { useFetch } from '../hooks/useFetch'
import { getAlboOro } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { Trophy, Shield, Medal, ShieldX, Shirt, Crown } from 'lucide-react'
import TeamLogo from '../components/TeamLogo'
import { stagioneBreve } from '../components/AlboTrofei'

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

// ── Medagliere: totali per squadra (chiave = utenza, stabile tra le stagioni) ──
// Nome e logo sono quelli della stagione più recente in cui la squadra compare
// (i dati arrivano ordinati per stagione decrescente).
function calcolaMedagliere(data) {
  const m = new Map()
  const get = (t) => {
    if (!t) return null
    if (!m.has(t.utenza)) m.set(t.utenza, { utenza: t.utenza, nome: t.nome, logo: t.logo, campionati: 0, secondi: 0, champions: 0, finali: 0, nere: 0,
      st: { campionati: [], secondi: [], champions: [], finali: [], nere: [] } })
    return m.get(t.utenza)
  }
  // oltre al conteggio si tiene la stagione (anno di inizio) di ciascun piazzamento
  const segna = (t, tipo, stagione) => { const x = get(t); if (x) { x[tipo]++; x.st[tipo].push(stagione) } }
  for (const r of data.campionato ?? []) {
    segna(r.primo, 'campionati', r.stagione)
    segna(r.secondo, 'secondi', r.stagione)
    segna(r.ultimo, 'nere', r.stagione)
  }
  for (const r of data.champions ?? []) {
    segna(r.vincitore, 'champions', r.stagione)
    segna(r.finalista, 'finali', r.stagione)
  }
  for (const x of m.values()) for (const k of Object.keys(x.st)) x.st[k].sort((a, b) => a - b)
  return [...m.values()].sort((a, b) =>
    (b.campionati + b.champions) - (a.campionati + a.champions)
    || b.campionati - a.campionati || b.champions - a.champions
    || b.secondi - a.secondi || a.nere - b.nere)
}

const TIPI = [
  { key: 'campionati', label: 'Campionati', Icon: Trophy, cls: 'text-gold-400' },
  { key: 'champions',  label: 'Champions',  Icon: Shield, cls: 'text-sky-400' },
  { key: 'secondi',    label: '2° posti',   Icon: Medal,  cls: 'text-slate-300' },
  { key: 'finali',     label: 'Finali perse', Icon: ShieldX, cls: 'text-sky-700' },
  { key: 'nere',       label: 'Maglie nere', Icon: Shirt,  cls: 'text-slate-500' },
]

// Un'icona per ogni trofeo, accompagnata dalla stagione in cui e' stato conquistato (es. "19/20")
function Titoli({ stagioni, Icon, cls }) {
  if (!stagioni?.length) return <span className="text-slate-700 text-xs">—</span>
  return (
    <span className="flex flex-wrap gap-x-2 gap-y-0.5">
      {stagioni.map(st => (
        <span key={st} title={`Stagione ${st}/${st + 1}`}
              className="inline-flex items-center gap-0.5 text-[11px] font-mono text-slate-300">
          <Icon className={`w-3.5 h-3.5 ${cls}`} aria-hidden="true" />{stagioneBreve(st)}
        </span>
      ))}
    </span>
  )
}

function Podio({ squadre }) {
  const top = squadre.slice(0, 3)
  const stile = [
    'border-gold-400/50 bg-gradient-to-b from-gold-500/15 to-transparent',
    'border-slate-300/40 bg-gradient-to-b from-slate-300/10 to-transparent',
    'border-amber-700/50 bg-gradient-to-b from-amber-700/15 to-transparent',
  ]
  return (
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
      {top.map((t, i) => (
        <div key={t.utenza} className={`card border p-4 flex flex-col items-center text-center ${stile[i]}`}>
          <div className="flex items-center gap-1.5 text-xs font-mono uppercase tracking-widest text-slate-400 mb-2">
            {i === 0 && <Crown className="w-4 h-4 text-gold-400" aria-hidden="true" />}
            {i + 1}° posto
          </div>
          <TeamLogo logo={t.logo} nome={t.nome} size="lg" />
          <p className="mt-2 font-semibold text-slate-100 truncate max-w-full">{t.nome}</p>
          <div className="mt-3 flex items-end gap-5">
            <div className="flex flex-col items-center">
              <Trophy className="w-5 h-5 text-gold-400" aria-hidden="true" />
              <span className="text-display text-3xl font-bold text-gold-400 leading-none">{t.campionati}</span>
              <span className="text-[9px] font-mono uppercase text-slate-500">Campionati</span>
            </div>
            <div className="flex flex-col items-center">
              <Shield className="w-5 h-5 text-sky-400" aria-hidden="true" />
              <span className="text-display text-3xl font-bold text-sky-400 leading-none">{t.champions}</span>
              <span className="text-[9px] font-mono uppercase text-slate-500">Champions</span>
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}

function Medagliere({ squadre }) {
  return (
    <>
      <Podio squadre={squadre} />
      <div className="card overflow-hidden">
        <ul className="divide-y divide-white/5">
          {squadre.map((t, i) => (
            <li key={t.utenza} className="px-4 py-3 flex flex-col lg:flex-row lg:items-center gap-3">
              <div className="flex items-center gap-3 lg:w-64 min-w-0">
                <span className="text-display font-bold text-lg text-slate-600 w-6 text-center">{i + 1}</span>
                <TeamLogo logo={t.logo} nome={t.nome} size="sm" />
                <span className="font-medium text-slate-200 text-sm truncate">{t.nome}</span>
              </div>
              <div className="flex-1 grid grid-cols-2 sm:grid-cols-5 gap-x-4 gap-y-2">
                {TIPI.map(({ key, label, Icon, cls }) => (
                  <div key={key} className="min-w-0">
                    <p className="flex items-center gap-1 text-[10px] font-mono uppercase tracking-wider text-slate-500 mb-0.5">
                      {label}
                      <span className={`font-bold ${t[key] ? cls : 'text-slate-700'}`}>{t[key]}</span>
                    </p>
                    <Titoli stagioni={t.st[key]} Icon={Icon} cls={cls} />
                  </div>
                ))}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </>
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

  const medagliere = calcolaMedagliere(data)

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Generale"
        title="Albo d'oro"
        subtitle="Medagliere e storico dei piazzamenti di campionato e Champions"
      />

      {medagliere.length > 0 && (
        <section className="mb-8">
          <h2 className="flex items-center gap-2 text-sm font-mono tracking-widest uppercase text-slate-400 mb-3">
            <Crown className="w-4 h-4 text-gold-400" /> Medagliere
          </h2>
          <Medagliere squadre={medagliere} />
        </section>
      )}

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
