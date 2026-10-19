import { useState } from 'react'
import { CheckCircle2, MinusCircle, XCircle, User, Home, Plane } from 'lucide-react'
import TeamLogo from '../TeamLogo'

// ── Utilità ──────────────────────────────────────────────────

export const fmt = (n, d = 1) =>
  (n === null || n === undefined || Number.isNaN(Number(n)) ? '–' : Number(n).toFixed(d))

/** "2026-10-18 20:45:00" (ora locale del server) → Date */
export function parseDataOra(s) {
  if (!s) return null
  const d = new Date(String(s).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? null : d
}

export function fmtDataOra(s) {
  const d = parseDataOra(s)
  if (!d) return null
  return d.toLocaleString('it-IT', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

export const SEGNO = {
  V: { Icon: CheckCircle2, cls: 'text-green-400',  bg: 'bg-green-500/10 border-green-500/20',   solid: 'bg-green-500 text-pitch-950',  label: 'Vittoria' },
  N: { Icon: MinusCircle,  cls: 'text-yellow-400', bg: 'bg-yellow-500/10 border-yellow-500/20', solid: 'bg-yellow-400 text-pitch-950', label: 'Pareggio' },
  P: { Icon: XCircle,      cls: 'text-red-400',    bg: 'bg-red-500/10 border-red-500/20',       solid: 'bg-red-500 text-white',        label: 'Sconfitta' },
}

export function EsitoIcon({ segno, className = 'w-5 h-5' }) {
  const s = SEGNO[segno]
  if (!s) return null
  return <s.Icon className={`${className} ${s.cls} flex-shrink-0`} aria-label={s.label} />
}

/** Pallino V/N/P pieno, usato per la "forma". */
export function PallinoForma({ segno, title, size = 'w-7 h-7 text-xs' }) {
  const s = SEGNO[segno]
  return (
    <span
      title={title}
      className={`${size} rounded-full inline-flex items-center justify-center font-bold font-mono ${s?.solid ?? 'bg-pitch-800 text-slate-400'}`}
    >
      {segno}
    </span>
  )
}

// ── Contenitori ──────────────────────────────────────────────

export function Section({ title, icon: Icon, right, children, className = '' }) {
  return (
    <div className={`card overflow-hidden min-w-0 ${className}`}>
      <div className="px-4 py-3 border-b border-white/5 flex flex-wrap items-center gap-x-2 gap-y-1">
        {Icon && <Icon className="w-4 h-4 text-grass-400 flex-shrink-0" />}
        <h2 className="font-semibold text-slate-200 text-sm min-w-0">{title}</h2>
        {right && <div className="ml-auto min-w-0 max-w-full text-xs text-slate-500 font-mono text-right">{right}</div>}
      </div>
      {children}
    </div>
  )
}

export function Vuoto({ children }) {
  return <p className="px-4 py-6 text-sm text-slate-600 text-center">{children}</p>
}

/** Numero grande con etichetta (griglie di indicatori). */
export function Indicatore({ label, value, sub, tono = 'text-white' }) {
  return (
    <div className="rounded-xl bg-pitch-900 border border-white/5 px-3 py-3 text-center">
      <p className={`text-display text-2xl font-bold leading-none ${tono}`}>{value}</p>
      <p className="text-[10px] uppercase tracking-wider text-slate-500 mt-1.5">{label}</p>
      {sub && <p className="text-[10px] text-slate-600 font-mono mt-0.5">{sub}</p>}
    </div>
  )
}

/** Barra orizzontale con percentuale (distribuzioni). */
export function BarraPerc({ label, valore, totale, colore = 'bg-grass-500', dx }) {
  const perc = totale > 0 ? Math.round((valore / totale) * 100) : 0
  return (
    <div className="flex items-center gap-3 text-xs">
      <span className="w-20 flex-shrink-0 text-slate-400 font-mono">{label}</span>
      <div className="flex-1 h-2.5 rounded-full bg-pitch-800 overflow-hidden">
        <div className={`h-full rounded-full ${colore}`} style={{ width: `${perc}%` }} />
      </div>
      <span className="w-16 text-right text-slate-300 font-mono flex-shrink-0">{dx ?? `${valore} · ${perc}%`}</span>
    </div>
  )
}

// ── Foto allenatore ──────────────────────────────────────────
// I file stanno in /public/allenatori/ e il nome file è il campo
// NEW_ALLENATORI.logo (stessa convenzione dei loghi squadra).

export function CoachPhoto({ logo, nome, className = 'w-16 h-16' }) {
  const [failed, setFailed] = useState(false)
  if (!logo || failed) {
    return (
      <div className={`${className} rounded-full bg-pitch-800 border border-white/10 flex items-center justify-center flex-shrink-0`}>
        <User className="w-1/2 h-1/2 text-slate-500" />
      </div>
    )
  }
  return (
    <img
      src={`${import.meta.env.BASE_URL}allenatori/${logo}`}
      alt={nome ?? 'Allenatore'}
      loading="lazy"
      onError={() => setFailed(true)}
      className={`${className} rounded-full object-cover border border-white/10 flex-shrink-0`}
    />
  )
}

// ── Riga risultato ───────────────────────────────────────────

export function RigaRisultato({ p, mostraTurno = false }) {
  const s = SEGNO[p.segno]
  return (
    <div className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
      <EsitoIcon segno={p.segno} />
      <span className="text-xs font-mono text-slate-600 w-8 flex-shrink-0">G{p.giornata}</span>
      {p.casa !== null && p.casa !== undefined && (
        p.casa
          ? <Home  className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" aria-label="In casa" />
          : <Plane className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" aria-label="In trasferta" />
      )}
      <TeamLogo logo={p.logo_avv} nome={p.avversario} size="sm" />
      <span className="flex-1 min-w-0 text-sm text-slate-300 truncate">
        {p.avversario}
        {mostraTurno && p.turno && <span className="text-xs text-slate-600"> · {p.turno}</span>}
      </span>
      <div className="text-right flex-shrink-0">
        <p className={`font-mono font-bold text-sm ${s?.cls ?? 'text-slate-300'}`}>{p.golf} - {p.gols}</p>
        <p className="text-[10px] font-mono text-slate-600">{fmt(p.ftotale)} / {fmt(p.ftotale_a)}</p>
      </div>
    </div>
  )
}

// ── Matrice vs le altre squadre ──────────────────────────────

export function MatriceScontri({ matrice, vuota }) {
  if (!matrice?.length) return <Vuoto>Nessuna squadra avversaria.</Vuoto>
  return (
    <div className="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
      {matrice.map(m => {
        const v = m.incontri.filter(i => i.segno === 'V').length
        const n = m.incontri.filter(i => i.segno === 'N').length
        const p = m.incontri.filter(i => i.segno === 'P').length
        const saldo = v - p
        const bordo = m.incontri.length === 0 ? 'border-white/5'
          : saldo > 0 ? 'border-green-500/30' : saldo < 0 ? 'border-red-500/30' : 'border-yellow-500/30'
        return (
          <div key={m.id} className={`rounded-xl border ${bordo} bg-pitch-900 p-3 flex flex-col gap-2`}>
            <div className="flex items-center gap-2 min-w-0">
              <TeamLogo logo={m.logo} nome={m.nome} size="sm" />
              <span className="text-xs text-slate-300 font-medium truncate">{m.nome}</span>
            </div>
            {m.incontri.length === 0 ? (
              <p className="text-xs text-slate-600">{vuota}</p>
            ) : (
              <>
                <div className="flex items-center gap-2 text-xs font-mono">
                  <span className="text-green-400">{v}V</span>
                  <span className="text-yellow-400">{n}N</span>
                  <span className="text-red-400">{p}P</span>
                </div>
                <div className="flex flex-wrap gap-1">
                  {m.incontri.map((i, k) => (
                    <span
                      key={k}
                      title={`Giornata ${i.giornata}${i.turno ? ` · ${i.turno}` : ''}`}
                      className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[11px] font-mono ${SEGNO[i.segno]?.bg ?? ''} ${SEGNO[i.segno]?.cls ?? ''}`}
                    >
                      {i.segno} {i.golf}-{i.gols}
                    </span>
                  ))}
                </div>
              </>
            )}
          </div>
        )
      })}
    </div>
  )
}
