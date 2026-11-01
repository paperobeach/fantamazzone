// ============================================================
// Elementi per scegliere le statistiche della dashboard e portarle in
// "Confronta" e "Andamento":
//   CardMetrica      indicatore selezionabile con posizione e scarto dalla lega
//   RigaMetrica      riga con barra (percentuali) selezionabile
//   ChipMetrica      versione compatta (etichetta · valore · posizione)
//   BarraSelezione   barra in fondo alla pagina con le metriche scelte
//   SelettoreMetriche pannello per aggiungere/togliere metriche per gruppo
// `ctx` = { data, me, sel } (data = risposta di dashboard_metriche.php)
// ============================================================
import { useState } from 'react'
import { CheckCircle2, Circle, X, Swords, TrendingUp, Plus, ChevronUp, Sparkles } from 'lucide-react'
import { Section } from './shared'
import {
  infoMetrica, fmtVal, fmtDelta, tonoDelta, gruppiMetriche, mappaDef, METRICHE_SUGGERITE,
} from './metriche'

function Spunta({ on, className = 'w-4 h-4' }) {
  return on
    ? <CheckCircle2 className={`${className} text-grass-400`} aria-hidden />
    : <Circle className={`${className} text-slate-700`} aria-hidden />
}

/** Posizione e scarto dalla lega, in una riga. */
function PosizioneLega({ info, className = '' }) {
  if (!info || info.valore === null) return null
  const { def, rank, delta, bench } = info
  return (
    <p className={`text-[10px] font-mono mt-1 leading-tight ${className}`}>
      {rank && <span className="text-slate-400">{rank.pos}° su {rank.n}</span>}
      {delta !== null && bench?.n > 1 && (
        <span className={`${tonoDelta(def, delta)} ${rank ? 'ml-1.5' : ''}`}>{fmtDelta(def, delta)} vs lega</span>
      )}
    </p>
  )
}

// ── Card ─────────────────────────────────────────────────────

export function CardMetrica({ k, ctx, label, sub, tono = 'text-white' }) {
  const info = infoMetrica(ctx.data, k, ctx.me)
  const on = ctx.sel.ha(k)
  const def = info?.def
  return (
    <button
      type="button"
      onClick={() => info && ctx.sel.toggle(k)}
      disabled={!info}
      aria-pressed={on}
      title={def ? `${def.label}${def.descr ? ` — ${def.descr}` : ''}. Tocca per ${on ? 'togliere dalla' : 'aggiungere alla'} selezione.` : undefined}
      className={`relative text-center rounded-xl border px-3 py-3 transition-all duration-150 min-w-0 ${
        on ? 'bg-grass-500/10 border-grass-500/50' : 'bg-pitch-900 border-white/5 hover:border-white/20'
      } ${info ? '' : 'opacity-60 cursor-default'}`}
    >
      <span className="absolute top-1.5 right-1.5"><Spunta on={on} /></span>
      <p className={`text-display text-2xl font-bold leading-none ${tono}`}>{info ? fmtVal(def, info.valore) : '–'}</p>
      <p className="text-[10px] uppercase tracking-wider text-slate-500 mt-1.5">{label ?? def?.label ?? k}</p>
      {sub && <p className="text-[10px] text-slate-600 font-mono mt-0.5">{sub}</p>}
      <PosizioneLega info={info} />
    </button>
  )
}

// ── Riga con barra ───────────────────────────────────────────

export function RigaMetrica({ k, ctx, label, colore = 'bg-grass-500' }) {
  const info = infoMetrica(ctx.data, k, ctx.me)
  const on = ctx.sel.ha(k)
  const v = info?.valore ?? null
  const perc = v === null ? 0 : Math.max(0, Math.min(100, v))
  return (
    <button
      type="button"
      onClick={() => info && ctx.sel.toggle(k)}
      disabled={!info}
      aria-pressed={on}
      className={`w-full flex items-center gap-2 sm:gap-3 text-xs rounded-lg px-2 py-1.5 border transition-colors ${
        on ? 'bg-grass-500/10 border-grass-500/40' : 'border-transparent hover:bg-white/[0.03]'
      }`}
    >
      <Spunta on={on} className="w-3.5 h-3.5 flex-shrink-0" />
      <span className="w-24 sm:w-32 flex-shrink-0 text-left text-slate-400 font-mono truncate">{label ?? info?.def?.label ?? k}</span>
      <div className="flex-1 h-2.5 rounded-full bg-pitch-800 overflow-hidden min-w-0">
        <div className={`h-full rounded-full ${colore}`} style={{ width: `${perc}%` }} />
      </div>
      <span className="w-10 text-right text-slate-300 font-mono flex-shrink-0">{info ? fmtVal(info.def, v) : '–'}</span>
      <span className="w-9 text-right text-[10px] text-slate-500 font-mono flex-shrink-0">
        {info?.rank ? `${info.rank.pos}°` : ''}
      </span>
    </button>
  )
}

// ── Chip compatto ────────────────────────────────────────────

export function ChipMetrica({ k, ctx, label }) {
  const info = infoMetrica(ctx.data, k, ctx.me)
  const on = ctx.sel.ha(k)
  return (
    <button
      type="button"
      onClick={() => info && ctx.sel.toggle(k)}
      disabled={!info}
      aria-pressed={on}
      className={`inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border text-xs transition-colors ${
        on ? 'bg-grass-500/10 border-grass-500/50 text-grass-300' : 'bg-pitch-950/40 border-white/10 text-slate-300 hover:border-white/25'
      }`}
    >
      <Spunta on={on} className="w-3.5 h-3.5" />
      <span className="text-slate-400">{label ?? info?.def?.label ?? k}</span>
      <span className="font-mono font-bold">{info ? fmtVal(info.def, info.valore) : '–'}</span>
      {info?.rank && info.valore !== null && <span className="font-mono text-[10px] text-slate-500">{info.rank.pos}°</span>}
    </button>
  )
}

// ── Chip rimovibile ──────────────────────────────────────────

function ChipSelezionata({ def, onRemove }) {
  return (
    <span className="inline-flex items-center gap-1 pl-2.5 pr-1 py-1 rounded-full bg-grass-500/15 border border-grass-500/40 text-xs text-grass-300 whitespace-nowrap">
      {def.label}
      <button type="button" onClick={onRemove} aria-label={`Togli ${def.label}`}
              className="p-0.5 rounded-full hover:bg-white/10"><X className="w-3 h-3" /></button>
    </span>
  )
}

// ── Barra di selezione (in fondo alla pagina Statistiche) ────

export function BarraSelezione({ ctx, onConfronta, onAndamento }) {
  const { chiavi, toggle, svuota } = ctx.sel
  if (!chiavi.length) return null
  const defs = mappaDef(ctx.data)
  return (
    <div className="sticky bottom-3 z-20 mt-6">
      <div className="rounded-2xl border border-grass-500/30 bg-pitch-900/95 backdrop-blur shadow-xl shadow-black/40 p-3 space-y-2.5">
        <div className="flex items-center gap-2">
          <p className="text-xs text-slate-400 flex-shrink-0"><b className="text-white">{chiavi.length}</b> selezionat{chiavi.length === 1 ? 'a' : 'e'}</p>
          <div className="flex-1 min-w-0 overflow-x-auto">
            <div className="flex gap-1.5 w-max">
              {chiavi.map(k => defs[k] && <ChipSelezionata key={k} def={defs[k]} onRemove={() => toggle(k)} />)}
            </div>
          </div>
          <button type="button" onClick={svuota} className="text-[11px] text-slate-500 hover:text-slate-300 flex-shrink-0">Svuota</button>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <button type="button" onClick={onConfronta}
                  className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-grass-500 text-pitch-950 text-sm font-semibold hover:bg-grass-400 transition-colors">
            <Swords className="w-4 h-4" /> Confronta
          </button>
          <button type="button" onClick={onAndamento}
                  className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-sky-500 text-pitch-950 text-sm font-semibold hover:bg-sky-400 transition-colors">
            <TrendingUp className="w-4 h-4" /> Andamento
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Selettore per Confronta e Andamento ──────────────────────

export function SelettoreMetriche({ ctx, titolo = 'Statistiche da analizzare' }) {
  const { chiavi, toggle, aggiungi, svuota } = ctx.sel
  const [aperto, setAperto] = useState(chiavi.length === 0)
  const defs = mappaDef(ctx.data)
  const gruppi = gruppiMetriche(ctx.data)

  return (
    <Section title={titolo} icon={Sparkles}
             right={
               <span className="inline-flex items-center gap-3">
                 {chiavi.length > 0 && (
                   <button type="button" onClick={svuota} className="text-slate-500 hover:text-slate-300">Svuota</button>
                 )}
                 <button type="button" onClick={() => setAperto(a => !a)}
                         className="inline-flex items-center gap-1 text-grass-400 hover:text-grass-300">
                   {aperto ? <><ChevronUp className="w-3.5 h-3.5" /> Chiudi</> : <><Plus className="w-3.5 h-3.5" /> Aggiungi</>}
                 </button>
               </span>
             }>
      <div className="p-4 space-y-4">
        {chiavi.length > 0 ? (
          <div className="flex flex-wrap gap-1.5">
            {chiavi.map(k => defs[k] && <ChipSelezionata key={k} def={defs[k]} onRemove={() => toggle(k)} />)}
          </div>
        ) : (
          <div className="space-y-2">
            <p className="text-sm text-slate-500">
              Nessuna statistica selezionata. Sceglile qui sotto oppure dalla sezione Statistiche toccando le card.
            </p>
            <div className="flex flex-wrap gap-1.5">
              {METRICHE_SUGGERITE.filter(k => defs[k]).map(k => (
                <button key={k} type="button" onClick={() => aggiungi(k)}
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border border-white/10 text-xs text-slate-300 hover:border-grass-500/50 hover:text-grass-300">
                  <Plus className="w-3 h-3" /> {defs[k].label}
                </button>
              ))}
            </div>
          </div>
        )}

        {aperto && (
          <div className="space-y-4 pt-3 border-t border-white/5">
            {gruppi.map(g => (
              <div key={g.nome}>
                <p className="text-[10px] uppercase tracking-widest text-slate-500 mb-2">{g.nome}</p>
                <div className="flex flex-wrap gap-1.5">
                  {g.metriche.map(d => {
                    const on = chiavi.includes(d.key)
                    return (
                      <button key={d.key} type="button" onClick={() => toggle(d.key)} aria-pressed={on}
                              title={d.descr || undefined}
                              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs transition-colors ${
                                on ? 'bg-grass-500/15 border-grass-500/50 text-grass-300'
                                   : 'border-white/10 text-slate-400 hover:text-slate-200 hover:border-white/25'}`}>
                        <Spunta on={on} className="w-3 h-3" /> {d.label}
                      </button>
                    )
                  })}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </Section>
  )
}
