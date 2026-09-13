import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getKulovic } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { RadarChart, Radar, PolarGrid, PolarAngleAxis, ResponsiveContainer, Tooltip } from 'recharts'

function RangeBar({ value, max, color }) {
  const pct = max > 0 ? Math.round((value / max) * 100) : 0
  return (
    <div className="flex items-center gap-2">
      <div className="flex-1 h-1.5 bg-pitch-800 rounded-full overflow-hidden">
        <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pct}%`, background: color }} />
      </div>
      <span className="font-mono text-xs text-slate-500 w-4 text-right">{value}</span>
    </div>
  )
}

function KulovicCard({ sq, maxRange }) {
  const radarData = [
    { subject: '<70',    A: Number(sq.PRIMO_RANGE),   B: Number(sq.PRIMO_RANGE_A) },
    { subject: '70-79',  A: Number(sq.SECONDO_RANGE), B: Number(sq.SECONDO_RANGE_A) },
    { subject: '80-89',  A: Number(sq.TERZO_RANGE),   B: Number(sq.TERZO_RANGE_A) },
    { subject: '90-99',  A: Number(sq.QUARTO_RANGE),  B: Number(sq.QUARTO_RANGE_A) },
    { subject: '≥100',   A: Number(sq.QUINTO_RANGE),  B: Number(sq.QUINTO_RANGE_A) },
  ]

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="font-semibold text-slate-200">{sq.SQUADRA ?? sq.squadra}</h3>
          <div className="flex items-center gap-3 mt-1">
            <span className="text-xs text-yellow-400 font-mono">🍀 Culo: {sq.CULO}</span>
            <span className="text-xs text-red-400 font-mono">😤 Sfiga: {sq.SFIGA}</span>
          </div>
        </div>
      </div>

      <div className="h-36">
        <ResponsiveContainer width="100%" height="100%">
          <RadarChart data={radarData}>
            <PolarGrid stroke="#1e2a38" />
            <PolarAngleAxis dataKey="subject" tick={{ fill: '#475569', fontSize: 10 }} />
            <Tooltip
              contentStyle={{ background: '#091219', border: '1px solid rgba(255,255,255,0.07)', borderRadius: 8, fontSize: 12 }}
              labelStyle={{ color: '#94a3b8' }}
            />
            <Radar name="Fatti" dataKey="A" stroke="#22c55e" fill="#22c55e" fillOpacity={0.15} strokeWidth={1.5} />
            <Radar name="Subiti" dataKey="B" stroke="#f87171" fill="#f87171" fillOpacity={0.1} strokeWidth={1.5} />
          </RadarChart>
        </ResponsiveContainer>
      </div>

      <div className="space-y-1.5 mt-3 pt-3 border-t border-white/5">
        <div className="flex items-center gap-2 mb-2">
          <div className="w-2 h-2 rounded-full bg-green-500" /><span className="text-[10px] text-slate-600">Punteggi fatti</span>
          <div className="w-2 h-2 rounded-full bg-red-400 ml-2" /><span className="text-[10px] text-slate-600">Punteggi subiti</span>
        </div>
        {[
          { label: '< 70',   val: sq.PRIMO_RANGE },
          { label: '70-79',  val: sq.SECONDO_RANGE },
          { label: '80-89',  val: sq.TERZO_RANGE },
          { label: '90-99',  val: sq.QUARTO_RANGE },
          { label: '≥ 100',  val: sq.QUINTO_RANGE },
        ].map(r => (
          <div key={r.label} className="flex items-center gap-2">
            <span className="text-[10px] font-mono text-slate-700 w-12">{r.label}</span>
            <RangeBar value={Number(r.val)} max={maxRange} color="#22c55e" />
          </div>
        ))}
      </div>
    </div>
  )
}

export default function Kulovic() {
  const { stagione } = useApp()
  const { data, loading, error, refetch } = useFetch(
    () => getKulovic(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Caricamento Kulovic..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.length) return <EmptyState />

  const maxRange = Math.max(...data.map(s =>
    Math.max(s.PRIMO_RANGE, s.SECONDO_RANGE, s.TERZO_RANGE, s.QUARTO_RANGE, s.QUINTO_RANGE)
  ))

  // Podio culo/sfiga
  const topCulo  = [...data].sort((a, b) => b.CULO - a.CULO)[0]
  const topSfiga = [...data].sort((a, b) => b.SFIGA - a.SFIGA)[0]

  return (
    <div className="animate-fade-up">
      <PageHeader label="Statistiche avanzate" title="Kulovic" subtitle="Distribuzione punteggi e indice fortuna/sfortuna" />

      <div className="grid grid-cols-2 gap-4 mb-8">
        <div className="card p-4 border-yellow-500/20">
          <p className="text-xs text-slate-600 mb-1">🍀 Re del Culo</p>
          <p className="font-semibold text-yellow-400">{topCulo?.SQUADRA ?? topCulo?.squadra}</p>
          <p className="text-xs font-mono text-slate-600 mt-0.5">{topCulo?.CULO} vittorie fortunate</p>
        </div>
        <div className="card p-4 border-red-500/20">
          <p className="text-xs text-slate-600 mb-1">😤 Re della Sfiga</p>
          <p className="font-semibold text-red-400">{topSfiga?.SQUADRA ?? topSfiga?.squadra}</p>
          <p className="text-xs font-mono text-slate-600 mt-0.5">{topSfiga?.SFIGA} sconfitte sfortùnate</p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {data.map((sq, i) => (
          <KulovicCard key={i} sq={sq} maxRange={maxRange} />
        ))}
      </div>
    </div>
  )
}
