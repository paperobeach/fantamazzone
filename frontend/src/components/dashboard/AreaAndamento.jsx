// ============================================================
// Area "Andamento": le statistiche selezionate giornata per giornata, con
// benchmark di lega e altre squadre. In cima l'andamento in classifica.
// ============================================================
import { useState } from 'react'
import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts'
import { TrendingUp, Activity } from 'lucide-react'
import { Section, Vuoto } from './shared'
import { EmptyState, LoadingState, ErrorState, TabBar } from '../ui'
import { SelettoreMetriche } from './SelezioneMetriche'
import ConfrontoSquadre, { coloreSquadra } from './ConfrontoSquadre'
import { mappaDef, serieModo, mediaSerie, fmtVal, MODI_ANDAMENTO, TOOLTIP_STYLE } from './metriche'

const ASSE = { stroke: '#64748b', tick: { fontSize: 11 } }

// ── Andamento in classifica ──────────────────────────────────

function AndamentoPosizione({ andamento, nSquadre }) {
  if (!andamento?.length) return <Vuoto>Nessuna giornata chiusa.</Vuoto>
  const n = Math.max(nSquadre || 8, ...andamento.map(a => a.posizione))
  const ticks = Array.from({ length: n }, (_, i) => i + 1)
  return (
    <div className="p-4 h-64">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={andamento} margin={{ top: 8, right: 12, bottom: 0, left: -16 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" {...ASSE} tickFormatter={g => `G${g}`} />
          <YAxis reversed domain={[1, n]} ticks={ticks} allowDecimals={false} {...ASSE} tickFormatter={p => `${p}°`} />
          <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`} formatter={v => [`${v}°`, 'Posizione']} />
          <Line type="monotone" dataKey="posizione" stroke="#4ade80" strokeWidth={2.5} dot={{ r: 3, fill: '#4ade80' }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Grafico di una metrica ───────────────────────────────────

function GraficoMetrica({ def, data, me, conf, modo }) {
  const { giornate, squadre } = data
  if (!giornate.length) return <Vuoto>Nessuna giornata chiusa.</Vuoto>

  const serieDi = id => serieModo(def, data.serie[def.key]?.[id] ?? giornate.map(() => null), modo)
  const mia = serieDi(me)
  const confrontate = conf.ids.filter(id => id !== me).map(id => ({ id, nome: squadre.find(s => s.id === id)?.nome ?? `#${id}`, serie: serieDi(id) }))
  const bench = conf.bench ? mediaSerie(squadre.map(s => serieDi(s.id))) : null

  const righe = giornate.map((g, i) => {
    const r = { giornata: g, me: mia[i] }
    if (bench) r.bench = bench[i]
    confrontate.forEach(c => { r[`s${c.id}`] = c.serie[i] })
    return r
  })
  const vuoto = righe.every(r => Object.entries(r).every(([k, v]) => k === 'giornata' || v === null))
  if (vuoto) return <Vuoto>Nessun dato per questa statistica.</Vuoto>

  return (
    <div className="p-4 h-64">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={righe} margin={{ top: 8, right: 12, bottom: 0, left: -12 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" {...ASSE} tickFormatter={g => `G${g}`} />
          <YAxis domain={['auto', 'auto']} {...ASSE} tickFormatter={v => fmtVal({ ...def, unita: '' }, v)} />
          <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`}
                   formatter={(v, n) => [fmtVal(def, v), n]} />
          <Legend wrapperStyle={{ fontSize: 11 }} />
          {bench && <Line type="monotone" name="Media lega" dataKey="bench" stroke="#fbbf24" strokeWidth={2}
                          strokeDasharray="5 4" dot={false} connectNulls />}
          {confrontate.map(c => (
            <Line key={c.id} type="monotone" name={c.nome} dataKey={`s${c.id}`} stroke={coloreSquadra(squadre, me, c.id)}
                  strokeWidth={1.75} dot={false} connectNulls />
          ))}
          <Line type="monotone" name="Tu" dataKey="me" stroke="#4ade80" strokeWidth={3}
                dot={{ r: 3, fill: '#4ade80' }} activeDot={{ r: 5 }} connectNulls />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Area ─────────────────────────────────────────────────────

export default function AreaAndamento({ d, competizione, ctx }) {
  const champ = competizione === 'CHAMP'
  const nSquadre = d.classifica?.squadre
  const [modo, setModo] = useState('partita')
  const [conf, setConf] = useState({ bench: true, ids: [] })

  const posizione = !champ && (
    <Section title="Andamento in classifica" icon={TrendingUp} right="posizione per giornata">
      <AndamentoPosizione andamento={d.campionato.andamento} nSquadre={nSquadre} />
    </Section>
  )

  if (ctx.errore) return <div className="space-y-6">{posizione}<ErrorState message={ctx.errore} onRetry={ctx.riprova} /></div>
  if (!ctx.data) return <div className="space-y-6">{posizione}<LoadingState label="Calcolo degli andamenti..." /></div>

  const defs = mappaDef(ctx.data)
  const selezionate = ctx.sel.chiavi.map(k => defs[k]).filter(Boolean)

  return (
    <div className="space-y-6">
      {posizione}

      <SelettoreMetriche ctx={ctx} />

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_auto] gap-3 items-start">
        <ConfrontoSquadre squadre={ctx.data.squadre} me={ctx.me} conf={conf} setConf={setConf} mostraTutte={false} />
        <div className="rounded-xl border border-white/5 bg-pitch-900 p-3 space-y-2 min-w-0">
          <p className="text-[10px] uppercase tracking-widest text-slate-500">Valore mostrato</p>
          <div className="overflow-x-auto"><TabBar tabs={MODI_ANDAMENTO} active={modo} onChange={setModo} /></div>
        </div>
      </div>

      {selezionate.length === 0 ? (
        <EmptyState label="Seleziona una o più statistiche per vederne l'andamento." />
      ) : (
        <>
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
            {selezionate.map(def => (
              <Section key={def.key} title={def.label} icon={Activity}
                       right={MODI_ANDAMENTO.find(m => m.value === modo)?.label}>
                <GraficoMetrica def={def} data={ctx.data} me={ctx.me} conf={conf} modo={modo} />
              </Section>
            ))}
          </div>
          <p className="text-[11px] text-slate-600 leading-relaxed">
            Per giornata: il valore della singola partita. Progressivo: il valore di stagione dopo ogni giornata
            (somma, media o record a seconda della statistica). Media mobile: media delle ultime 5 partite con dato.
            La media lega è calcolata sulle serie di tutte le squadre, la tua compresa.
          </p>
        </>
      )}
    </div>
  )
}
