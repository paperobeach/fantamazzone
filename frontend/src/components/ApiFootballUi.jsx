import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { Spinner } from './ui'
import { AlertTriangle, CheckCircle2, XCircle, Lock } from 'lucide-react'

// ============================================================
// Componenti condivisi dalle pagine di integrazione con API-Football
// (Test, Mappatura, Sincronizzazione): credenziali amministratore,
// avvisi, voci di checklist, pulsanti e visualizzazione JSON grezzo.
// ============================================================

/** Credenziali amministratore: verificate lato server ad ogni chiamata. */
export function useCredenzialiAdmin() {
  const { utente, ultimaStagione, stagione } = useApp()
  // Come per il login, le credenziali si verificano sulla stagione più recente
  const stagioneRif = Number(ultimaStagione ?? stagione)
  const [utenza, setUtenza] = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  return {
    stagioneRif, utenza, setUtenza, password, setPassword,
    pronto: utenza.trim() !== '' && password !== '' && !!stagioneRif,
    // Parametri da passare alle funzioni di api/client.js
    auth: { utenza: utenza.trim(), password },
  }
}

export function CardCredenziali({ cred, descrizione }) {
  return (
    <div className="card p-6 max-w-2xl mb-6">
      <h3 className="font-semibold text-slate-200 mb-1 flex items-center gap-2">
        <Lock className="w-4 h-4 text-slate-400" /> Conferma la tua identità
      </h3>
      <p className="text-xs text-slate-500 mb-5">
        {descrizione ?? 'Riscrivi utenza e password di un amministratore (verificate lato server).'}
      </p>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label htmlFor="af-utenza" className="text-xs text-slate-600 mb-1 block">Utenza</label>
          <input
            id="af-utenza" type="text" autoComplete="username"
            value={cred.utenza} onChange={e => cred.setUtenza(e.target.value)}
            className="fanta-input"
          />
        </div>
        <div>
          <label htmlFor="af-password" className="text-xs text-slate-600 mb-1 block">Password</label>
          <input
            id="af-password" type="password" autoComplete="current-password"
            value={cred.password} onChange={e => cred.setPassword(e.target.value)}
            className="fanta-input"
          />
        </div>
      </div>
    </div>
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

export function Voce({ ok, label, dettaglio }) {
  return (
    <li className="flex items-start gap-2 px-3 py-2 text-sm">
      {ok
        ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" />
        : <XCircle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />}
      <span className="text-slate-300 min-w-0">{label}</span>
      {dettaglio != null && (
        <span className="ml-auto text-xs text-slate-500 text-right break-words max-w-[55%]">{dettaglio}</span>
      )}
    </li>
  )
}

/** Pulsante: variante "primario" (verde) o secondario (bordo). */
export function Pulsante({ onClick, disabled, caricando, icona: Icona, primario = false, piccolo = false, children, titolo }) {
  const base = `inline-flex items-center gap-2 rounded-lg font-medium text-sm transition-all duration-150 active:scale-95
                disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100 ${piccolo ? 'px-3 py-1.5' : 'px-4 py-2.5'}`
  const stile = primario
    ? 'bg-grass-500 hover:bg-grass-400 text-pitch-950 font-semibold'
    : 'border border-white/10 text-slate-300 hover:text-white hover:border-white/20'
  return (
    <button type="button" onClick={onClick} disabled={disabled || caricando} title={titolo} className={`${base} ${stile}`}>
      {caricando ? <Spinner size="sm" /> : (Icona ? <Icona className="w-4 h-4" /> : null)}
      {children}
    </button>
  )
}

/** Risposta grezza del server, ripiegata: utile per la diagnosi. */
export function JsonGrezzo({ dati }) {
  return (
    <details className="mt-3 text-xs">
      <summary className="cursor-pointer text-slate-500 hover:text-slate-300">Risposta grezza</summary>
      <pre className="mt-2 p-3 rounded-lg bg-pitch-900 border border-white/5 text-slate-400 overflow-x-auto max-h-80">
        {JSON.stringify(dati, null, 2)}
      </pre>
    </details>
  )
}

/** "2026-10-25 20:45:00" → "25/10 20:45" */
export function fmtDataOra(s) {
  if (!s) return '—'
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]} ${m[4]}:${m[5]}` : s
}
