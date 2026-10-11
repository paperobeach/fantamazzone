import { Lock, AlertTriangle } from 'lucide-react'
import { Spinner } from './ui'

// ============================================================
// components/AiUi.jsx
//
// Elementi condivisi dalle pagine della sezione "AI": formattazione
// degli importi (USD ed eventuale euro), avvisi, stili dei pulsanti e la
// card di conferma identità (utenza + password, verificate lato server).
// ============================================================

export const BTN_PRIMARY =
  'inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-grass-500 hover:bg-grass-400 ' +
  'text-pitch-950 font-semibold text-sm transition-all duration-150 active:scale-95 ' +
  'disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100'

export const BTN_SECONDARY =
  'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/10 ' +
  'text-slate-300 hover:text-white hover:border-white/20 font-medium text-sm ' +
  'transition-all duration-150 active:scale-95 ' +
  'disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100'

export const BTN_DANGER =
  'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-red-500/30 ' +
  'text-red-300 hover:text-red-200 hover:border-red-500/50 font-medium text-sm ' +
  'transition-all duration-150 active:scale-95 ' +
  'disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100'

// Importi in USD (e in euro, se config_ai.php ha "cambio_eur_per_usd")
export function fmtUsd(n) {
  if (n == null) return '—'
  if (n > 0 && n < 0.0001) return '<$0.0001'
  return `$${Number(n).toFixed(n < 1 ? 4 : 2)}`
}
export function fmtEur(n, cambio) {
  if (n == null || cambio == null) return null
  const e = Number(n) * cambio
  if (e > 0 && e < 0.0001) return '<€0.0001'
  return `€${e.toFixed(e < 1 ? 4 : 2)}`
}
export function Importo({ usd, cambio }) {
  const eur = fmtEur(usd, cambio)
  return (
    <span className="tabular-nums">
      {fmtUsd(usd)}{eur && <span className="text-slate-500"> (≈ {eur})</span>}
    </span>
  )
}

export function Avviso({ children, tono = 'gold', icona: Icona = AlertTriangle }) {
  const stili = tono === 'red'
    ? 'bg-red-500/5 border-red-500/20 text-red-200'
    : tono === 'green'
      ? 'bg-green-500/5 border-green-500/20 text-green-200'
      : 'bg-gold-500/5 border-gold-500/20 text-gold-100'
  const col = tono === 'red' ? 'text-red-400' : tono === 'green' ? 'text-green-400' : 'text-gold-400'
  return (
    <div className={`rounded-lg px-4 py-3 flex gap-3 border text-sm ${stili}`}>
      <Icona className={`w-4 h-4 flex-shrink-0 mt-0.5 ${col}`} />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

/**
 * Card di accesso: utenza + password, verificate dal backend a ogni chiamata
 * (serve NEW_UTENZE.abilita_ai = 'Y'). Il chiamante tiene le credenziali in
 * memoria e le riusa per le azioni successive: non vengono mai salvate.
 */
export function AiCredenziali({
  utenza, password, setUtenza, setPassword, onSubmit, busy, errore,
  testoBottone = 'Accedi', descrizione,
}) {
  const pronto = utenza.trim() !== '' && password !== '' && !busy
  return (
    <div className="card p-6 max-w-2xl mb-6">
      <h3 className="font-semibold text-slate-200 mb-1 flex items-center gap-2">
        <Lock className="w-4 h-4 text-slate-400" /> Conferma la tua identità
      </h3>
      <p className="text-xs text-slate-500 mb-5">
        {descrizione ?? 'Riscrivi utenza e password: vengono verificate lato server e serve l\'abilitazione AI.'}
      </p>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
          <label htmlFor="aicred-utenza" className="text-xs text-slate-600 mb-1 block">Utenza</label>
          <input
            id="aicred-utenza" type="text" autoComplete="username"
            value={utenza} onChange={e => setUtenza(e.target.value)} className="fanta-input"
          />
        </div>
        <div>
          <label htmlFor="aicred-password" className="text-xs text-slate-600 mb-1 block">Password</label>
          <input
            id="aicred-password" type="password" autoComplete="current-password"
            value={password} onChange={e => setPassword(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter' && pronto) onSubmit() }}
            className="fanta-input"
          />
        </div>
      </div>
      <button type="button" onClick={onSubmit} disabled={!pronto} className={BTN_PRIMARY}>
        {busy ? <><Spinner size="sm" /> Verifica...</> : testoBottone}
      </button>
      {errore && (
        <div className="mt-4">
          <Avviso tono="red">{errore}</Avviso>
        </div>
      )}
    </div>
  )
}
