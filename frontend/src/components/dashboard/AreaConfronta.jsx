// ============================================================
// Area "Confronta": le statistiche selezionate, messe a confronto con altre
// squadre e/o con il benchmark di lega. Il valore della squadra loggata resta sempre
// in primo piano (riga "Tu" mai attenuata e linea di riferimento su ogni barra).
// Gli scontri con le altre squadre sono nella sezione Statistiche.
// ============================================================
import { useState } from 'react'
import { BarChart3 } from 'lucide-react'
import { Section, LogoCompatto, BadgeVista } from './shared'
import { GraficoZoom } from './GraficiUtil'
import { EmptyState, LoadingState, ErrorState } from '../ui'
import { SelettoreMetriche } from './SelezioneMetriche'
import ConfrontoSquadre, { coloreSquadra } from './ConfrontoSquadre'
import { mappaDef, infoMetrica, fmtVal, fmtDelta, tonoDelta } from './metriche'

/** Righe del confronto per una metrica, ordinate dalla migliore alla peggiore. */
function righeMetrica(data, def, me, conf) {
  const valori = data.valori[def.key] ?? {}
  const ids = [me, ...conf.ids.filter(id => id !== me)]
  const righe = ids
    .map(id => {
      const s = data.squadre.find(x => x.id === id)
      return s ? { tipo: id === me ? 'me' : 'squadra', id, nome: s.nome, logo: s.logo, valore: valori[id] ?? null } : null
    })
    .filter(Boolean)
  const b = data.benchmark[def.key]
  if (conf.bench && b?.media !== null && b?.media !== undefined) {
    righe.push({ tipo: 'bench', id: 'bench', nome: 'Media lega', valore: b.media })
  }
  const ord = def.dir === 'down' ? 1 : -1
  return righe.sort((a, b2) => {
    if (a.valore === null) return 1
    if (b2.valore === null) return -1
    return ord * (a.valore - b2.valore)
  })
}

function CardConfronto({ def, data, me, conf, vista }) {
  const righe = righeMetrica(data, def, me, conf)
  const info = infoMetrica(data, def.key, me)
  const vals = righe.map(r => r.valore).filter(v => v !== null)
  const lo = Math.min(0, ...vals)
  const hi = Math.max(0, ...vals)
  const span = hi - lo || 1
  const mio = righe.find(r => r.tipo === 'me')?.valore ?? null
  const b = data.benchmark[def.key]

  // Clic su una riga: la evidenzia e attenua le altre (nuovo clic = ripristina)
  const [scelta, setScelta] = useState(null)
  const evid = righe.some(r => r.id === scelta) ? scelta : null
  const toggle = id => setScelta(c => (c === id ? null : id))

  const corpo = zoom => (
    <div className={zoom ? 'space-y-3' : 'space-y-2'}>
      {def.descr && <p className={`${zoom ? 'text-xs' : 'text-[11px]'} text-slate-600 -mt-1 mb-1`}>{def.descr}</p>}
      {righe.map(r => {
        const scarto = r.tipo !== 'me' && mio !== null && r.valore !== null ? mio - r.valore : null
        const colore = r.tipo === 'me' ? 'bg-grass-500'
          : r.tipo === 'bench' ? 'bg-amber-400/80'
          : ''
        const stile = r.tipo === 'squadra' ? { background: coloreSquadra(data.squadre, me, r.id), opacity: 0.75 } : {}
        const sinistra = r.valore === null ? 0 : ((Math.min(r.valore, 0) - lo) / span) * 100
        const larghezza = r.valore === null ? 0 : (Math.abs(r.valore) / span) * 100
        const sel = evid === r.id
        // La riga della squadra loggata non viene mai attenuata: è il riferimento del confronto
        const spenta = evid !== null && !sel && r.tipo !== 'me'
        return (
          <div key={r.id} role="button" tabIndex={0} aria-pressed={sel}
               title={sel ? 'Tocca per mostrare di nuovo tutte le righe' : 'Tocca per evidenziare questa riga'}
               onClick={() => toggle(r.id)}
               onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(r.id) } }}
               className={`flex items-center gap-2 sm:gap-3 cursor-pointer rounded-lg px-1 py-0.5 -mx-1 transition-all ${
                 sel ? 'bg-white/[0.06] ring-1 ring-white/20' : 'hover:bg-white/[0.03]'} ${spenta ? 'opacity-25' : ''}`}>
            <div className={`${zoom ? 'w-28 sm:w-44' : 'w-24 sm:w-32'} flex items-center gap-1.5 flex-shrink-0 min-w-0`}>
              {r.tipo === 'bench' ? <span className="w-7 h-7 rounded-lg bg-amber-400/15 border border-amber-400/30 text-amber-300 text-[10px] font-bold flex items-center justify-center flex-shrink-0">∅</span>
                : <LogoCompatto logo={r.logo} nome={r.nome} />}
              <span className={`${zoom ? 'text-sm' : 'text-xs'} truncate ${r.tipo === 'me' ? 'text-grass-300 font-semibold' : 'text-slate-400'}`}>
                {r.tipo === 'me' ? 'Tu' : r.nome}
              </span>
            </div>
            <div className={`relative flex-1 ${zoom ? 'h-5' : 'h-3'} rounded-full bg-pitch-800 overflow-hidden min-w-0`}>
              <div className={`absolute top-0 h-full rounded-full ${colore}`}
                   style={{ left: `${sinistra}%`, width: `${Math.max(larghezza, r.valore === null ? 0 : 1)}%`, ...stile }} />
              {mio !== null && r.tipo !== 'me' && (
                <div className="absolute top-0 h-full w-0.5 bg-white/80 pointer-events-none" title={`Tuo valore: ${fmtVal(def, mio)}`}
                     style={{ left: `${((mio - lo) / span) * 100}%` }} />
              )}
            </div>
            <span className={`${zoom ? 'w-20 text-base' : 'w-14 text-sm'} text-right font-mono flex-shrink-0 ${r.tipo === 'me' ? 'text-white font-bold' : 'text-slate-300'}`}>
              {fmtVal(def, r.valore)}
            </span>
            <span className={`${zoom ? 'w-20 text-xs' : 'w-14 text-[11px]'} text-right font-mono flex-shrink-0 ${scarto === null ? 'text-slate-700' : tonoDelta(def, scarto)}`}
                  title="Tuo valore meno quello della riga">
              {scarto === null ? '' : fmtDelta(def, scarto)}
            </span>
          </div>
        )
      })}
      {b?.n > 0 && (
        <p className={`${zoom ? 'text-xs' : 'text-[11px]'} text-slate-600 font-mono pt-2 border-t border-white/5`}>
          Lega ({b.n} squadre): media {fmtVal(def, b.media)} · mediana {fmtVal(def, b.mediana)} · min {fmtVal(def, b.min)} · max {fmtVal(def, b.max)}
        </p>
      )}
    </div>
  )

  return (
    <Section title={def.label} icon={BarChart3}
             right={(
               <span className="inline-flex items-center gap-2">
                 {def.giocatori && <BadgeVista vista={vista} />}
                 {info?.rank && info.valore !== null ? `${info.rank.pos}° su ${info.rank.n}` : null}
               </span>
             )}>
      <GraficoZoom titolo={def.label} fluido>{corpo}</GraficoZoom>
    </Section>
  )
}

export default function AreaConfronta({ ctx }) {
  const [conf, setConf] = useState({ bench: true, ids: [] })

  if (ctx.errore) return <ErrorState message={ctx.errore} onRetry={ctx.riprova} />
  if (!ctx.data) return <LoadingState label="Calcolo dei confronti..." />

  const defs = mappaDef(ctx.data)
  const selezionate = ctx.sel.chiavi.map(k => defs[k]).filter(Boolean)
  const nulla = !conf.bench && conf.ids.length === 0
  const vista = ctx.vistaApplicata ?? ctx.vista
  const conGiocatori = selezionate.some(d => d.giocatori)

  return (
    <div className="space-y-6">
      <div id="conf-selezione" className="sezione-scroll space-y-6">
        <SelettoreMetriche ctx={ctx} />
        <ConfrontoSquadre squadre={ctx.data.squadre} me={ctx.me} conf={conf} setConf={setConf} />
      </div>

      <div id="conf-grafici" className="sezione-scroll">
      {selezionate.length === 0 ? (
        <EmptyState label="Seleziona una o più statistiche per confrontarle con la lega." />
      ) : (
        <>
          {nulla && <p className="text-xs text-slate-500">Scegli il benchmark di lega o almeno una squadra per il confronto.</p>}
          {conGiocatori && (
            <p className="text-xs text-slate-500 mb-3 flex items-center gap-2 flex-wrap">
              <BadgeVista vista={vista} />
              Il filtro giocatori vale per le statistiche dei giocatori (voto e fantavoto di reparto), per tutte le squadre a confronto.
              {ctx.vistaNota && <span className="text-gold-400">{ctx.vistaNota}</span>}
            </p>
          )}
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
            {selezionate.map(def => (
              <CardConfronto key={def.key} def={def} data={ctx.data} me={ctx.me} conf={conf} vista={vista} />
            ))}
          </div>
          <p className="text-[11px] text-slate-600">
            Le righe sono ordinate dalla migliore alla peggiore. Lo scarto a destra è il tuo valore meno quello della riga:
            verde se a tuo favore, rosso se a favore dell’altra. Benchmark = media delle squadre della lega, la tua inclusa.
            La linea bianca su ogni barra indica il tuo valore; la riga «Tu» resta sempre visibile.
            Tocca una riga per evidenziarla (le altre si attenuano); tocca di nuovo per ripristinare.
          </p>
        </>
      )}
      </div>
    </div>
  )
}
