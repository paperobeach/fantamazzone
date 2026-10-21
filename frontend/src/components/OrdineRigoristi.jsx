import { ArrowUp, ArrowDown, RotateCcw, Target } from 'lucide-react'
import { ROLE_LABEL } from '../lib/pitchLayout'
import { NomeConBandiera } from './Bandiera'

// Ordina `ids` secondo `ordine` (lista salvata/scelta); chi non compare
// nell'ordine segue nella posizione originale.
export function ordinaSecondo(ids, ordine) {
  if (!ordine || ordine.length === 0) return ids
  const pos = new Map(ordine.map((id, i) => [id, i]))
  return [...ids].sort((a, b) => {
    const pa = pos.has(a) ? pos.get(a) : 1e6 + ids.indexOf(a)
    const pb = pos.has(b) ? pos.get(b) : 1e6 + ids.indexOf(b)
    return pa - pb
  })
}

/**
 * Ordine dei calci di rigore, per i turni di Champions che prevedono
 * supplementari e rigori. Titolari numerati da 1 a 11, poi i panchinari.
 * Senza una lista consegnata si tira per numero di maglia, dal portiere;
 * terminata la lista si va ad oltranza ripartendo dall'inizio.
 *
 * Props:
 *  titolari  id dei titolari nell'ordine di maglia (slot del modulo)
 *  panchina  id dei panchinari
 *  ordine    null | [id...]  ordine scelto (titolari poi panchinari)
 *  onChange  (ordine | null) => void   null = ripristina il default
 */
export function OrdineRigoristi({ rosa, titolari, panchina, ordine, onChange, readOnly = false }) {
  const byId = Object.fromEntries(rosa.map(p => [p.id, p]))
  const tit = ordinaSecondo(titolari, ordine)
  const pan = ordinaSecondo(panchina, ordine)

  const sposta = (lista, altra, titolariSono, idx, delta) => {
    const j = idx + delta
    if (j < 0 || j >= lista.length) return
    const copia = [...lista]
    ;[copia[idx], copia[j]] = [copia[j], copia[idx]]
    onChange(titolariSono ? [...copia, ...altra] : [...altra, ...copia])
  }

  const Riga = ({ id, n, lista, altra, titolariSono, idx }) => (
    <li className="flex items-center gap-2 py-1.5 border-b border-white/[0.03] last:border-0">
      <span className="w-6 text-right font-mono text-xs text-slate-500">{n}</span>
      <span className="w-5 text-center text-[10px] font-mono text-slate-500">{ROLE_LABEL[String(byId[id]?.ruolo)] ?? ''}</span>
      <NomeConBandiera id={id} nome={byId[id]?.descrizione ?? byId[id]?.nome ?? `#${id}`} className="flex-1 text-sm text-slate-300" />
      {!readOnly && (
        <span className="flex items-center gap-1">
          <button type="button" disabled={idx === 0} onClick={() => sposta(lista, altra, titolariSono, idx, -1)}
            className="p-1 rounded text-slate-500 hover:text-slate-200 disabled:opacity-20" aria-label="Sposta su">
            <ArrowUp className="w-3.5 h-3.5" />
          </button>
          <button type="button" disabled={idx === lista.length - 1} onClick={() => sposta(lista, altra, titolariSono, idx, 1)}
            className="p-1 rounded text-slate-500 hover:text-slate-200 disabled:opacity-20" aria-label="Sposta giù">
            <ArrowDown className="w-3.5 h-3.5" />
          </button>
        </span>
      )}
    </li>
  )

  return (
    <div className="card mt-6 p-4">
      <div className="flex items-center justify-between gap-3 flex-wrap mb-1">
        <h3 className="text-sm font-semibold text-slate-300 flex items-center gap-2">
          <Target className="w-4 h-4 text-gold-400" /> Ordine dei rigoristi
        </h3>
        {!readOnly && ordine && (
          <button type="button" onClick={() => onChange(null)} className="btn-ghost text-xs">
            <RotateCcw className="w-3.5 h-3.5" /> Ordine di default
          </button>
        )}
      </div>
      <p className="text-xs text-slate-500 mb-3">
        Questo turno prevede, in caso di parità, supplementari e calci di rigore. Il rigore è realizzato se il
        voto del giocatore è almeno 6. Se non consegni una lista si parte dal portiere, per numero di maglia;
        si tirano i primi 5 rigoristi di ciascuna squadra; in caso di ulteriore parità si prosegue ad oltranza con i successivi, uno per squadra, nell'ordine.
        {!ordine && ' (Ordine attuale: di default.)'}
      </p>
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <div className="text-[11px] uppercase tracking-wider text-slate-600 mb-1">Titolari (1-11)</div>
          <ul>{tit.map((id, i) => <Riga key={id} id={id} n={i + 1} lista={tit} altra={pan} titolariSono idx={i} />)}</ul>
        </div>
        <div>
          <div className="text-[11px] uppercase tracking-wider text-slate-600 mb-1">Panchina</div>
          <ul>
            {pan.length === 0 && <li className="text-xs text-slate-600 py-1.5">Nessun panchinaro</li>}
            {pan.map((id, i) => <Riga key={id} id={id} n={tit.length + i + 1} lista={pan} altra={tit} titolariSono={false} idx={i} />)}
          </ul>
        </div>
      </div>
    </div>
  )
}
