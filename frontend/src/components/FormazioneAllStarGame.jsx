import { useEffect, useMemo, useState } from 'react'
import { azioneAllStarGame } from '../api/client'
import { FormationBuilder } from './FormationBuilder'
import { OrdineRigoristi, ordinaSecondo as ordinaRig } from './OrdineRigoristi'
import { ROLE_LABEL, MODULI_VALIDI, conteggioAtteso, getSlotsForModulo, ordinaPerRuoloDefault } from '../lib/pitchLayout'
import { CheckCircle2, Circle, AlertTriangle, Save, Loader2, Lock, Info, Send, ThumbsUp, Undo2 } from 'lucide-react'

const MODULO_DEFAULT = '4-4-2'

const MOTIVO_EMAIL_LABEL = {
  email_non_configurata: 'email non configurata',
  email_non_valida: 'email non valida',
  invio_fallito: 'invio non riuscito',
}

// Stato locale (modulo, titolari, panchina, tribuna) dalle righe salvate (MAGLIA 1..11 titolari, 12+ panchina)
function costruisciStato(fonte, rosa, soglia) {
  const byId = Object.fromEntries(rosa.map(p => [p.id, p]))
  if (!fonte || fonte.length === 0) {
    // Proposta iniziale: chi ha meno del X% di presenze nel fantacampionato parte in tribuna
    // (solo se c'è già almeno una giornata chiusa su cui misurarle)
    const pochePresenze = p => soglia != null && p.pct_presenze != null && p.pct_presenze < soglia
    const tribuna = rosa.filter(pochePresenze).map(p => p.id)
    const inTribuna = new Set(tribuna)
    return {
      modulo: MODULO_DEFAULT, titolari: [],
      panchina: ordinaPerRuoloDefault(rosa.filter(p => !inTribuna.has(p.id)).map(p => p.id), byId),
      tribuna,
    }
  }
  const ordinati = [...fonte].sort((a, b) => a.maglia - b.maglia)
  const titolari = ordinati.filter(r => r.maglia <= 11).map(r => r.id_giocatore).filter(id => byId[id])
  const panchina = ordinati.filter(r => r.maglia >= 12).map(r => r.id_giocatore).filter(id => byId[id])
  const cont = { '1': 0, '2': 0, '3': 0, '4': 0 }
  titolari.forEach(id => cont[String(byId[id].ruolo)]++)
  const modulo = MODULI_VALIDI.find(m => JSON.stringify(conteggioAtteso(m)) === JSON.stringify(cont))
  const usati = new Set([...titolari, ...panchina])
  return {
    modulo: modulo ?? MODULO_DEFAULT, titolari, panchina,
    tribuna: rosa.filter(p => !usati.has(p.id)).map(p => p.id),
  }
}

const fmt = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : ''

/**
 * Formazione dell'ALL STAR GAME (Est/Ovest): la rosa è l'unione delle rose delle squadre
 * della compagine. Workflow condiviso: chiunque della compagine salva la bozza (gli ok
 * si azzerano), ogni fantallenatore dà il proprio ok, poi chiunque può inviare. Vedi
 * backend/lib/AllStarGame.php.
 */
export function FormazioneAllStarGame({ stagione, giornata, idSquadra, ctx, onReload }) {
  const rosa = useMemo(() => {
    const perId = new Map((ctx.avversarie ?? []).map(a => [Number(a.id_giocatore), a]))
    return (ctx.rosa ?? []).map(p => {
      const a = perId.get(Number(p.id))
      const base = { ...p, squadra_asg: p.squadra }
      return a ? { ...base, serie_a: a.serie_a, avversaria: a.avversaria, casa: a.casa, data: a.data, ora: a.ora } : base
    })
  }, [ctx.rosa, ctx.avversarie])

  const [forma, setForma] = useState({ modulo: MODULO_DEFAULT, titolari: [], panchina: [], tribuna: [] })
  const [rigoristi, setRigoristi] = useState(null)
  const [busy, setBusy] = useState(null)       // 'salva' | 'ok' | 'revoca_ok' | 'invia'
  const [msg, setMsg] = useState(null)         // { tipo, testo }
  const [modificata, setModificata] = useState(false)

  // Ricarica lo stato locale quando cambia la formazione sul server
  useEffect(() => {
    if (rosa.length === 0) return
    setForma(costruisciStato(ctx.formazione, rosa, ctx.giornate_chiuse > 0 ? ctx.soglia_presenze_tribuna : null))
    setRigoristi(ctx.rigoristi?.length ? ctx.rigoristi : null)
    setModificata(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rosa.length, ctx.formazione, ctx.rigoristi])

  const { modulo, titolari, panchina, tribuna } = forma
  const attesi = conteggioAtteso(modulo)
  const conteggio = { '1': 0, '2': 0, '3': 0, '4': 0 }
  titolari.forEach(id => { const p = rosa.find(r => r.id === id); if (p) conteggio[String(p.ruolo)]++ })
  const completa = titolari.length === 11 && JSON.stringify(conteggio) === JSON.stringify(attesi)
  const readOnly = !ctx.modificabile

  function aggiorna(patch) { setForma(f => ({ ...f, ...patch })); setModificata(true); setMsg(null) }

  function buildPayload() {
    const slots = getSlotsForModulo(modulo)
    const cursor = { '1': 0, '2': 0, '3': 0, '4': 0 }
    const byRuolo = { '1': [], '2': [], '3': [], '4': [] }
    titolari.forEach(id => { const p = rosa.find(r => r.id === id); if (p) byRuolo[String(p.ruolo)].push(id) })
    const tit = slots.map((slot, i) => ({ id: byRuolo[slot.ruolo][cursor[slot.ruolo]++], maglia: i + 1 }))
    return [...tit, ...panchina.map((id, i) => ({ id, maglia: 12 + i }))]
  }
  const titolariPerMaglia = completa ? buildPayload().slice(0, 11).map(g => g.id) : titolari

  async function esegui(azione, extra, okTesto) {
    setBusy(azione); setMsg(null)
    try {
      const res = await azioneAllStarGame(stagione, giornata, idSquadra, azione, extra)
      let testo = okTesto
      if (azione === 'invia') {
        const m = res?.email ?? []
        const ko = m.filter(x => !x.ok)
        testo = `Formazione inviata. Email: ${res.email_inviate}/${res.email_totali} inviate`
          + (ko.length ? ` (non inviate a: ${ko.map(x => `${x.squadra} - ${MOTIVO_EMAIL_LABEL[x.motivo] ?? x.motivo}`).join('; ')})` : '') + '.'
      }
      setMsg({ tipo: 'ok', testo })
      await onReload()
    } catch (e) {
      setMsg({ tipo: 'err', testo: e.message ?? 'Errore' })
    } finally {
      setBusy(null)
    }
  }

  const salva = () => esegui('salva', {
    giocatori: buildPayload(),
    rigoristi: rigoristi ? [...ordinaRig(titolariPerMaglia, rigoristi), ...ordinaRig(panchina, rigoristi)] : [],
  }, 'Bozza salvata.')

  const compagni = ctx.compagni ?? []
  const bozzaSalvata = ctx.completa && !modificata
  const puoOk = !readOnly
  const puoInviare = !readOnly && bozzaSalvata && ctx.tutti_ok

  return (
    <div>
      <div className="mb-4 flex items-start gap-2 rounded-lg border border-gold-400/20 bg-gold-500/5 px-3 py-2.5">
        <Info className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
        <p className="text-xs text-slate-300">
          All Star Game · <b>{ctx.nome_lato}</b> contro <b>{ctx.avversaria?.nome}</b>. La rosa è l'unione delle rose delle squadre
          dell'{ctx.nome_lato}. La formazione è unica per la compagine: l'ok di ogni fantallenatore è una delega a decidere
          per lui. Quando tutti hanno dato l'ok, uno qualsiasi può salvare e inviare la formazione, che viene notificata
          via mail a tutti i fantallenatori. In caso di parità: supplementari e rigori.
          {ctx.giornate_chiuse > 0 && !ctx.formazione?.length && (
            <> Come proposta iniziale, chi ha meno del {ctx.soglia_presenze_tribuna}% di presenze nel fantacampionato è in tribuna.</>
          )}
        </p>
      </div>

      {ctx.inviata && (
        <div className="mb-4 flex items-start gap-2 rounded-lg border border-grass-500/30 bg-grass-500/5 px-3 py-2.5">
          <Send className="w-4 h-4 text-grass-400 flex-shrink-0 mt-0.5" />
          <p className="text-xs text-slate-300">
            Formazione inviata{ctx.inviata_da ? ` da ${ctx.inviata_da}` : ''}{ctx.inviata_il ? ` il ${fmt(ctx.inviata_il)}` : ''}: è definitiva.
          </p>
        </div>
      )}
      {!ctx.inviata && ctx.motivo_blocco && (
        <div className="mb-4 flex items-start gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2.5">
          <Lock className="w-4 h-4 text-slate-500 flex-shrink-0 mt-0.5" />
          <p className="text-xs text-slate-400">{ctx.motivo_blocco}</p>
        </div>
      )}
      {!ctx.inviata && !ctx.motivo_blocco && ctx.limite_inserimento && (
        <p className="mb-4 text-xs text-slate-500">Puoi modificare e inviare la formazione fino al {fmt(ctx.limite_inserimento)}.</p>
      )}

      <FormationBuilder
        rosa={rosa}
        modulo={modulo}
        onModuloChange={m => aggiorna({ modulo: m })}
        titolariIds={titolari}
        panchinaIds={panchina}
        tribunaIds={tribuna}
        onChange={(t, p, tr) => aggiorna({ titolari: t, panchina: p, tribuna: tr })}
        readOnly={readOnly}
      />

      <OrdineRigoristi
        rosa={rosa}
        titolari={titolariPerMaglia}
        panchina={panchina}
        ordine={rigoristi}
        onChange={o => { setRigoristi(o); setModificata(true); setMsg(null) }}
        readOnly={readOnly}
      />

      <div className="card mt-6 p-4">
        <div className="flex items-center justify-between mb-3 gap-3 flex-wrap">
          <h3 className="text-sm font-semibold text-slate-300">Bozza e deleghe · {ctx.nome_lato}</h3>
          <div className="flex items-center gap-3">
            {completa ? (
              <span className="flex items-center gap-1.5 text-xs text-grass-400"><CheckCircle2 className="w-3.5 h-3.5" /> Modulo {modulo} completo</span>
            ) : (
              <span className="flex items-center gap-1.5 text-xs text-gold-400"><AlertTriangle className="w-3.5 h-3.5" /> {titolari.length}/11 titolari inseriti</span>
            )}
            {!readOnly && (
              <button type="button" onClick={salva} disabled={!completa || !!busy || !modificata && ctx.completa}
                className="btn-primary text-xs px-4 py-2 disabled:opacity-40 disabled:cursor-not-allowed">
                {busy === 'salva' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                Salva bozza
              </button>
            )}
          </div>
        </div>

        <p className="text-[11px] text-slate-500 mb-2">
          Ok (deleghe) dei fantallenatori{ctx.aggiornata_da ? ` (ultima modifica: ${ctx.aggiornata_da}${ctx.aggiornata_il ? ', ' + fmt(ctx.aggiornata_il) : ''})` : ''}:
        </p>
        <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
          {compagni.map(c => (
            <li key={c.id} className="flex items-center gap-2 rounded-lg bg-white/[0.03] px-3 py-2 text-sm">
              {c.ok ? <CheckCircle2 className="w-4 h-4 text-grass-400" /> : <Circle className="w-4 h-4 text-slate-600" />}
              <span className="text-slate-300 truncate flex-1">
                {c.nome}{c.allenatore ? <span className="text-slate-600"> · {c.allenatore}</span> : null}
                {c.id === idSquadra && <span className="text-slate-500"> (tu)</span>}
              </span>
            </li>
          ))}
        </ul>

        {!ctx.inviata && (
          <div className="flex items-center gap-2 flex-wrap">
            {ctx.ok_mio ? (
              <button type="button" disabled={!!busy || readOnly}
                onClick={() => esegui('revoca_ok', {}, 'Ok ritirato.')}
                className="btn-ghost text-xs px-4 py-2 disabled:opacity-40">
                {busy === 'revoca_ok' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Undo2 className="w-3.5 h-3.5" />} Ritira la delega
              </button>
            ) : (
              <button type="button" disabled={!puoOk || !!busy}
                onClick={() => esegui('ok', {}, 'Ok registrato.')}
                className="btn-primary text-xs px-4 py-2 disabled:opacity-40 disabled:cursor-not-allowed">
                {busy === 'ok' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <ThumbsUp className="w-3.5 h-3.5" />} Delego (dai il mio ok)
              </button>
            )}
            <button type="button" disabled={!puoInviare || !!busy}
              onClick={() => window.confirm('Inviare la formazione? Diventa definitiva e sarà notificata via mail a tutti i fantallenatori.') && esegui('invia', {}, '')}
              className="btn-primary text-xs px-4 py-2 disabled:opacity-40 disabled:cursor-not-allowed">
              {busy === 'invia' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Send className="w-3.5 h-3.5" />} Invia formazione
            </button>
            {!ctx.tutti_ok && <span className="text-[11px] text-slate-600">L'invio è possibile solo dopo l'ok di tutti i fantallenatori.</span>}
            {modificata && <span className="text-[11px] text-gold-400">Modifiche non salvate: salva la bozza prima di inviare.</span>}
          </div>
        )}

        {msg && (
          <p className={`flex items-center gap-1.5 text-xs mt-3 ${msg.tipo === 'ok' ? 'text-grass-400' : 'text-red-400'}`}>
            {msg.tipo === 'ok' ? <CheckCircle2 className="w-3.5 h-3.5" /> : <AlertTriangle className="w-3.5 h-3.5" />} {msg.testo}
          </p>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs mt-4">
          {['1', '2', '3', '4'].map(r => (
            <div key={r} className="rounded-lg bg-white/[0.03] px-3 py-2 flex items-center justify-between">
              <span className="text-slate-500">{ROLE_LABEL[r]}</span>
              <span className="font-mono text-slate-300">{conteggio[r]} / {attesi[r]}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
