import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Save, Undo2, X, Star, Pencil, Clock, MinusCircle, Radio } from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getLiveGiocatori, salvaEditLive, eliminaEditLive } from '../api/client'
import { Spinner, RoleBadge } from './ui'
import { Bandiera } from './Bandiera'
import { EVENTI } from './MatchDetail'

// ── Editing dei voti della simulazione ──────────────────────
// Usato dalla pagina "LIVE Giornata in corso" (tabella per squadra) e dalla
// dashboard squadra (modale sul singolo giocatore). Il salvataggio passa da
// live_simulazione.php, che ricalcola e salva la simulazione della sola
// partita del giocatore (campionato e, se gioca, Champions).

// Nome dell'utente registrato come autore delle simulazioni
export function nomeUtente(utente) {
  return utente?.descrizione || utente?.utenza || 'Ospite'
}

// Icona/titolo di ogni statistica: le stesse del dettaglio partita (EVENTI)
const ev = (k, id) => EVENTI.find(e => e.k === k && (id ? e.id === id : true))
const campo = (k, evento) => ({ k, icona: evento.icona, titolo: evento.titolo })

export const CAMPI = [
  campo('gf',  ev('reti')),
  campo('ass', ev('assist')),
  campo('gs',  ev('retis')),
  campo('au',  ev('autogol')),
  campo('rp',  ev('rigores', 'rigore_parato')),
  campo('rf',  ev('rigores', 'rigore_segnato')),
  campo('rs',  ev('rigorep')),
  campo('amm', ev('ammonizioni')),
  campo('esp', ev('espulsioni')),
]

/** Intestazione di colonna con l'icona della statistica (sticky in alto). */
export function ThStat({ c, className = '' }) {
  return (
    <th scope="col" title={c.titolo}
        className={`sticky top-0 z-20 bg-pitch-900 px-1 py-2 font-normal text-sm leading-none ${className}`}>
      <span aria-hidden="true">{c.icona}</span>
      <span className="sr-only">{c.titolo}</span>
    </th>
  )
}

/** Intestazione della colonna Voto. */
export function ThVoto({ className = '' }) {
  return (
    <th scope="col" title="Voto"
        className={`sticky top-0 z-20 bg-pitch-900 px-1 py-2 font-normal ${className}`}>
      <Star className="w-3.5 h-3.5 inline text-gold-400" aria-hidden="true" />
      <span className="sr-only">Voto</span>
    </th>
  )
}

const FORM_ZERO = { gf: 0, gs: 0, rp: 0, rs: 0, rf: 0, au: 0, amm: 0, esp: 0, ass: 0 }

/** Stato del form di editing di un giocatore (dati di live_simulazione.php GET) + azioni salva/ripristina. */
export function useEditGiocatore(g, stagione, onChanged) {
  const { utente } = useApp()
  const [form, setForm] = useState(() => ({
    voto: g.voto ?? 6,
    ...Object.fromEntries(CAMPI.map(c => [c.k, g[c.k] ?? 0])),
  }))
  const [busy, setBusy] = useState(false)
  const [err, setErr]   = useState(null)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const run = async (fn) => {
    setBusy(true); setErr(null)
    try {
      await fn()   // il backend ricalcola e salva la sola partita del giocatore
      onChanged()
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const salva = () => run(() => salvaEditLive(Number(stagione), {
    id_giocatore: g.id_giocatore,
    utente: nomeUtente(utente),
    voto: Number(form.voto),
    ...Object.fromEntries(CAMPI.map(c => [c.k, Number(form[c.k]) || 0])),
  }))
  const reset = () => run(async () => {
    await eliminaEditLive(Number(stagione), g.id_giocatore, nomeUtente(utente))
    setForm(f => ({ ...f, voto: 6, ...FORM_ZERO }))
  })

  return { form, set, busy, err, salva, reset }
}

/** Etichetta dello stato del dato usato dalla simulazione. */
export function BadgeStatoDato({ stato }) {
  const m = {
    reale:       ['voto reale', 'text-slate-500', Radio],
    senza_voto:  ['senza voto', 'text-red-400', MinusCircle],
    provvisorio: ['6 provvisorio', 'text-sky-400', Clock],
    manuale:     ['manuale', 'text-violet-400', Pencil],
  }[stato]
  if (!m) return null
  const [label, cls, Icon] = m
  return (
    <span className={`inline-flex items-center gap-1 text-[10px] font-mono uppercase ${cls}`}>
      <Icon className="w-3 h-3" aria-hidden="true" />{label}
    </span>
  )
}

// ── Modale: editing del singolo giocatore ───────────────────

function FormModale({ g, stagione, onClose, onSaved }) {
  const { form, set, busy, err, salva, reset } = useEditGiocatore(g, stagione, () => { onSaved(); onClose() })
  const bloccato = !g.modificabile

  return (
    <>
      <div className="px-4 py-3 border-b border-white/5 flex items-start gap-3">
        <div className="min-w-0">
          <p className="text-sm text-white font-medium flex items-center gap-1.5">
            <RoleBadge ruolo={g.ruolo} /><Bandiera id={g.id_giocatore} />
            <span className="truncate">{g.giocatore}</span>
            {!g.titolare && <span className="text-slate-600 text-xs">(panchina)</span>}
          </p>
          <p className="mt-0.5 flex items-center gap-2">
            <BadgeStatoDato stato={g.stato_dato} />
            {g.squadra_serie_a && <span className="text-[10px] text-slate-600">{g.squadra_serie_a}</span>}
          </p>
        </div>
        <button onClick={onClose} aria-label="Chiudi" className="ml-auto btn-ghost !px-2 !py-1"><X className="w-4 h-4" /></button>
      </div>

      {bloccato ? (
        <p className="px-4 py-5 text-xs text-slate-500 italic">{g.motivo_blocco}</p>
      ) : (
        <div className="px-4 py-4 space-y-3">
          <label className="flex items-center gap-3">
            <span className="flex items-center gap-1.5 text-xs text-slate-400 w-24"><Star className="w-3.5 h-3.5 text-gold-400" aria-hidden="true" /> Voto</span>
            <input type="number" step="0.5" min="0" max="10" value={form.voto}
              onChange={e => set('voto', e.target.value)}
              className="fanta-input !px-2 !py-1 !w-20 text-center" aria-label="Voto" />
            <span className="text-[10px] text-slate-600">0 = senza voto (sostituito)</span>
          </label>
          <div className="grid grid-cols-3 gap-x-3 gap-y-2">
            {CAMPI.map(c => (
              <label key={c.k} className="flex items-center gap-2" title={c.titolo}>
                <span className="text-base leading-none w-6 text-center" aria-hidden="true">{c.icona}</span>
                <input type="number" min="0" max="20" value={form[c.k]}
                  onChange={e => set(c.k, e.target.value)} aria-label={c.titolo}
                  className="fanta-input !px-1 !py-1 !w-full text-center" />
              </label>
            ))}
          </div>
          {err && <p className="text-xs text-red-400">{err}</p>}
          <div className="flex items-center gap-2 pt-1">
            <button onClick={salva} disabled={busy} className="btn-primary text-xs !py-1.5">
              {busy ? <Spinner size="sm" /> : <Save className="w-3.5 h-3.5" />} Salva e ricalcola
            </button>
            {g.stato_dato === 'manuale' && (
              <button onClick={reset} disabled={busy} className="btn-ghost text-xs !py-1.5" title="Ripristina il 6 provvisorio">
                <Undo2 className="w-3.5 h-3.5" /> Ripristina 6
              </button>
            )}
            <button onClick={onClose} className="btn-ghost text-xs !py-1.5 ml-auto">Annulla</button>
          </div>
          <p className="text-[10px] text-slate-600">Al salvataggio la simulazione della partita viene ricalcolata.</p>
        </div>
      )}
    </>
  )
}

/**
 * Modale di editing di un giocatore, come nella simulazione.
 * @param idSquadra    squadra fanta che schiera il giocatore
 * @param competizione 'CAMP' | 'CHAMP': formazione in cui il giocatore è schierato
 * @param giocatore    { id_giocatore, nome }
 * @param onSaved      richiamata dopo il salvataggio (la simulazione è già stata ricalcolata)
 */
export function EditorGiocatoreModal({ stagione, idSquadra, competizione = 'CAMP', giocatore, onClose, onSaved }) {
  const { data, loading, error } = useFetch(
    () => getLiveGiocatori(Number(stagione), idSquadra, competizione), [stagione, idSquadra, competizione])
  const g = data?.giocatori?.find(x => x.id_giocatore === giocatore.id_giocatore) ?? null

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return createPortal(
    <div className="fixed inset-0 z-[60] flex items-end sm:items-center justify-center bg-black/60 p-0 sm:p-4"
         onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}
         role="dialog" aria-modal="true" aria-label={`Editing ${giocatore.nome}`}>
      <div className="w-full sm:max-w-md bg-pitch-900 border border-violet-500/30 rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[90vh] overflow-auto">
        {loading && !data ? (
          <div className="py-10 text-center"><Spinner /></div>
        ) : error ? (
          <div className="p-4">
            <p className="text-xs text-red-400">{error}</p>
            <button onClick={onClose} className="btn-ghost text-xs mt-3">Chiudi</button>
          </div>
        ) : !g ? (
          <div className="p-4">
            <p className="text-xs text-slate-500">{giocatore.nome} non risulta nella formazione di questa giornata.</p>
            <button onClick={onClose} className="btn-ghost text-xs mt-3">Chiudi</button>
          </div>
        ) : (
          <FormModale g={g} stagione={stagione} onClose={onClose} onSaved={onSaved} />
        )}
      </div>
    </div>,
    document.body
  )
}
