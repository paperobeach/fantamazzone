// ============================================================
// Area "Andamento": le statistiche selezionate giornata per giornata, con
// benchmark di lega e altre squadre. All'ingresso propone la posizione in classifica
// (metrica "posizione", solo campionato).
// ============================================================
import { useState } from 'react'
import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts'
import { Activity } from 'lucide-react'
import { Section, Vuoto } from './shared'
import { GraficoZoom, LegendaInterattiva, useEvidenzia, asse } from './GraficiUtil'
import { EmptyState, LoadingState, ErrorState, TabBar } from '../ui'
import { SelettoreMetriche } from './SelezioneMetriche'
import ConfrontoSquadre, { coloreSquadra } from './ConfrontoSquadre'
import { mappaDef, serieModo, mediaSerie, fmtVal, MODI_ANDAMENTO, TOOLTIP_STYLE } from './metriche'

// ── Grafico di una metrica ───────────────────────────────────

function GraficoMetrica({ def, data, me, conf, modo }) {
  const { giornate, squadre } = data

  // Serie presenti nel grafico: servono prima di ogni return anticipato (regole degli hook)
  const chiavi = ['me', ...(conf.bench && def.key !== 'posizione' ? ['bench'] : []), ...conf.ids.filter(id => id !== me).map(id => `s${id}`)]
  const { evid, toggle, reset } = useEvidenzia(chiavi)

  if (!giornate.length) return <Vuoto>Nessuna giornata chiusa.</Vuoto>

  const serieDi = id => serieModo(def, data.serie[def.key]?.[id] ?? giornate.map(() => null), modo)
  const mia = serieDi(me)
  const confrontate = conf.ids.filter(id => id !== me).map(id => ({ id, nome: squadre.find(s => s.id === id)?.nome ?? `#${id}`, serie: serieDi(id) }))
  // La media di lega della posizione in classifica è sempre la stessa: non ha senso tracciarla
  const classifica = def.key === 'posizione'
  const bench = conf.bench && !classifica ? mediaSerie(squadre.map(s => serieDi(s.id))) : null

  const righe = giornate.map((g, i) => {
    const r = { giornata: g, me: mia[i] }
    if (bench) r.bench = bench[i]
    confrontate.forEach(c => { r[`s${c.id}`] = c.serie[i] })
    return r
  })
  const vuoto = righe.every(r => Object.entries(r).every(([k, v]) => k === 'giornata' || v === null))
  if (vuoto) return <Vuoto>Nessun dato per questa statistica.</Vuoto>

  // Definizione di ogni linea (la chiave coincide con il dataKey delle righe)
  const tu = { key: 'me', nome: 'Tu', colore: '#4ade80', spessore: 3, punti: true }
  const altre = [
    ...(bench ? [{ key: 'bench', nome: 'Media lega', colore: '#fbbf24', spessore: 2, tratteggio: true }] : []),
    ...confrontate.map(c => ({ key: `s${c.id}`, nome: c.nome, colore: coloreSquadra(squadre, me, c.id), spessore: 1.75 })),
  ]
  const voci = [tu, ...altre]
  // La serie evidenziata viene disegnata per ultima, quindi sopra le altre
  const ordinate = [...altre, tu].sort((x, y) => (x.key === evid ? 1 : 0) - (y.key === evid ? 1 : 0))

  return (
    <GraficoZoom titolo={def.label}
                 legenda={zoom => <LegendaInterattiva voci={voci} evid={evid} onToggle={toggle} onReset={reset} zoom={zoom} />}>
      {zoom => (
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={righe} margin={{ top: 8, right: 12, bottom: 0, left: -12 }}>
            <CartesianGrid stroke="#ffffff10" vertical={false} />
            <XAxis dataKey="giornata" {...asse(zoom)} tickFormatter={g => `G${g}`} />
            {classifica
              ? <YAxis reversed domain={[1, Math.max(squadre.length, 2)]} allowDecimals={false}
                       ticks={Array.from({ length: Math.max(squadre.length, 2) }, (_, i) => i + 1)}
                       {...asse(zoom)} tickFormatter={p => `${p}°`} />
              : <YAxis domain={['auto', 'auto']} {...asse(zoom)} tickFormatter={v => fmtVal({ ...def, unita: '' }, v)} />}
            <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`}
                     formatter={(v, n) => [classifica ? `${v}°` : fmtVal(def, v), n]} />
            {ordinate.map(l => {
              const scelta = evid === l.key
              const spenta = evid !== null && !scelta
              return (
                <Line key={l.key} type="monotone" name={l.nome} dataKey={l.key} stroke={l.colore}
                      strokeWidth={scelta ? l.spessore + 1.5 : l.spessore} strokeOpacity={spenta ? 0.12 : 1}
                      strokeDasharray={l.tratteggio ? '5 4' : undefined}
                      dot={!spenta && (l.punti || scelta) ? { r: zoom ? 4 : 3, fill: l.colore } : false}
                      activeDot={spenta ? false : { r: 5 }}
                      connectNulls isAnimationActive={false} />
              )
            })}
          </LineChart>
        </ResponsiveContainer>
      )}
    </GraficoZoom>
  )
}

// ── Area ─────────────────────────────────────────────────────

export default function AreaAndamento({ d, competizione, ctx }) {  // eslint-disable-line no-unused-vars
  const [modo, setModo] = useState('partita')
  const [conf, setConf] = useState({ bench: true, ids: [] })

  if (ctx.errore) return <ErrorState message={ctx.errore} onRetry={ctx.riprova} />
  if (!ctx.data) return <LoadingState label="Calcolo degli andamenti..." />

  const defs = mappaDef(ctx.data)
  const selezionate = ctx.sel.chiavi.map(k => defs[k]).filter(Boolean)

  return (
    <div className="space-y-6">
      {/* Selezione e confronto comprimibili, per lasciare più spazio ai grafici */}
      <div id="and-selezione" className="sezione-scroll space-y-6">
        <SelettoreMetriche ctx={ctx} comprimibile apertoIniziale={false} />

        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_auto] gap-3 items-start">
          <ConfrontoSquadre squadre={ctx.data.squadre} me={ctx.me} conf={conf} setConf={setConf} comprimibile apertoIniziale={false} />
        <div className="rounded-xl border border-white/5 bg-pitch-900 p-3 space-y-2 min-w-0">
          <p className="text-[10px] uppercase tracking-widest text-slate-500">Valore mostrato</p>
          <div className="overflow-x-auto"><TabBar tabs={MODI_ANDAMENTO} active={modo} onChange={setModo} /></div>
        </div>
        </div>
      </div>

      <div id="and-grafici" className="sezione-scroll">
      {selezionate.length === 0 ? (
        <EmptyState label="Seleziona una o più statistiche per vederne l'andamento." />
      ) : (
        <div className="space-y-6">
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
            Tocca una voce della legenda per evidenziare la serie (le altre si attenuano); tocca di nuovo per ripristinare.
          </p>
        </div>
      )}
      </div>
    </div>
  )
}
