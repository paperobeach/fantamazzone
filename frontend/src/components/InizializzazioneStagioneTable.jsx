import { useState } from 'react'
import {
  Plus, Trash2, CheckCircle2, XCircle, AlertTriangle, RotateCcw, DownloadCloud,
} from 'lucide-react'
import { getInizializzazioneStagione, adminInizializzaStagione } from '../api/client'
import { Spinner } from './ui'

// ============================================================
// "Inizializzazione stagione" — pagina unica (no wizard)
//
// 1) L'utente indica la stagione (anno a 4 cifre, libero) e preme
//    "Carica".
// 2) Viene mostrata un'unica tabella, una riga per squadra, con i
//    valori già presenti su DB per quella stagione (se esistono).
// 3) Al salvataggio, un'unica chiamata aggiorna in blocco le tre
//    tabelle coinvolte (NEW_SQUADRE, NEW_ALLENATORI, NEW_UTENZE),
//    con cancellazione preventiva per stagione così l'operazione
//    è ripetibile.
//
// Mapping colonna → campo DB (in lettura da NEW_SQUADRE quando il
// campo è duplicato su più tabelle, in scrittura su tutte):
//   Nome squadra      → NEW_SQUADRE.NOME
//   Logo squadra      → NEW_SQUADRE.LOGO
//   Albo              → NEW_SQUADRE.ALBO
//   Allenatore        → NEW_ALLENATORI.DESCRIZIONE
//   Foto allenatore   → NEW_ALLENATORI.LOGO
//   Utenza            → NEW_UTENZE.UTENZA
//   Password          → NEW_UTENZE.PASSWORD
//   Abilitazione      → NEW_UTENZE.ABILITAZIONE
//   Ordine            → NEW_SQUADRE.ID, NEW_ALLENATORI.ID,
//                        NEW_ALLENATORI.ID_SQUADRA, NEW_UTENZE.ID
//                        (stesso valore su tutte e 4 le colonne)
//
// NOTA: le regole di valorizzazione dei campi e i controlli sui
// valori sono per ora quelli generici; verranno affinati in un
// secondo momento con le regole di business indicate dall'utente.
// ============================================================

const emptyRiga = (ordine = '') => ({
  ordine: String(ordine),
  nome: '', logo: '', albo: '',
  allenatore: '', foto_allenatore: '',
  utenza: '', password: '', abilitazione: 'N',
})

export function InizializzazioneStagioneTable({ stagione: stagioneIniziale }) {
  const [stagione, setStagione] = useState(stagioneIniziale ? String(stagioneIniziale) : '')
  const [caricata, setCaricata] = useState(false)
  const [righe, setRighe]       = useState([])

  const [loading, setLoading]   = useState(false)
  const [saving,  setSaving]    = useState(false)
  const [result,  setResult]    = useState(null)
  const [errors,  setErrors]    = useState(null)     // dettagli 422: [{ indice, campo, messaggio }]
  const [errorMsg, setErrorMsg] = useState(null)

  const stagioneValida = /^\d{4}$/.test(stagione)

  // Mappa errori per input puntuale: "indice:campo" → messaggio
  const errorMap = {}
  for (const e of errors ?? []) {
    if (e.indice !== null && e.indice !== undefined) errorMap[`${e.indice}:${e.campo}`] = e.messaggio
  }
  const erroriGenerali = (errors ?? []).filter(e => e.indice === null || e.indice === undefined)

  // ── Fase 1 → 2: carica (o inizializza vuota) la tabella ──────
  const carica = async () => {
    if (!stagioneValida) return
    setLoading(true)
    setErrorMsg(null)
    setErrors(null)
    setResult(null)
    try {
      const dati = await getInizializzazioneStagione(Number(stagione))
      setRighe(dati.righe?.length
        ? dati.righe.map(r => ({
            ordine: String(r.ordine ?? ''),
            nome: r.nome ?? '', logo: r.logo ?? '', albo: r.albo ?? '',
            allenatore: r.allenatore ?? '', foto_allenatore: r.foto_allenatore ?? '',
            utenza: r.utenza ?? '', password: '', abilitazione: r.abilitazione === 'Y' ? 'Y' : 'N',
          }))
        : [emptyRiga(1)])
      setCaricata(true)
    } catch (e) {
      setErrorMsg(e.message)
    } finally {
      setLoading(false)
    }
  }

  const cambiaStagione = () => {
    setCaricata(false)
    setRighe([])
    setResult(null)
    setErrors(null)
    setErrorMsg(null)
  }

  // ── Editing righe ─────────────────────────────────────────────
  const update = (i, k, v) => setRighe(rows => rows.map((r, idx) => idx === i ? { ...r, [k]: v } : r))
  const addRiga = () => setRighe(rows => {
    const maxOrdine = rows.reduce((m, r) => Math.max(m, Number(r.ordine) || 0), 0)
    return [...rows, emptyRiga(maxOrdine + 1)]
  })
  const removeRiga = (i) => setRighe(rows => rows.length > 1 ? rows.filter((_, idx) => idx !== i) : rows)

  // ── Salvataggio consolidato ───────────────────────────────────
  const salva = async () => {
    setSaving(true)
    setResult(null)
    setErrors(null)
    setErrorMsg(null)
    try {
      const payload = {
        righe: righe.map(r => ({
          ordine: Number(r.ordine),
          nome: r.nome, logo: r.logo, albo: r.albo,
          allenatore: r.allenatore, foto_allenatore: r.foto_allenatore,
          utenza: r.utenza, password: r.password, abilitazione: r.abilitazione,
        })),
      }
      const res = await adminInizializzaStagione(Number(stagione), payload)
      setResult(res)
    } catch (e) {
      if (e.code === 'VALIDATION_ERROR' && e.details) {
        setErrors(e.details)
      } else {
        setErrorMsg(e.message)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card p-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h3 className="font-semibold text-slate-200 mb-1">Inizializzazione stagione</h3>
          <p className="text-xs text-slate-600">
            Una riga per squadra: dati squadra, allenatore e utenza di accesso associata.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <input
            type="text"
            inputMode="numeric"
            maxLength={4}
            value={stagione}
            onChange={e => setStagione(e.target.value.replace(/\D/g, ''))}
            placeholder="Stagione (es. 2027)"
            className="fanta-input w-40"
          />
          {!caricata ? (
            <button onClick={carica} disabled={!stagioneValida || loading} className="btn-primary text-sm disabled:opacity-40">
              {loading ? <Spinner size="sm" /> : <DownloadCloud className="w-3.5 h-3.5" />} Carica
            </button>
          ) : (
            <button onClick={cambiaStagione} className="btn-ghost text-xs">
              <RotateCcw className="w-3.5 h-3.5" /> Cambia stagione
            </button>
          )}
        </div>
      </div>

      {!caricata && (
        <div className="info-box mb-2 rounded-lg px-4 py-3 flex gap-3 bg-yellow-500/5 border border-yellow-500/20 text-yellow-200 text-sm">
          <AlertTriangle className="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" />
          <span>Indica una stagione (anno a 4 cifre) e premi "Carica" per visualizzare o creare i dati.</span>
        </div>
      )}

      {errorMsg && (
        <div className="mb-5 px-4 py-3 rounded-lg text-sm flex items-center gap-2 bg-red-500/10 border border-red-500/20 text-red-300">
          <XCircle className="w-4 h-4 flex-shrink-0" /> {errorMsg}
        </div>
      )}

      {caricata && (
        <>
          {erroriGenerali.length > 0 && (
            <div className="mb-4 px-4 py-3 rounded-lg text-xs bg-red-500/10 border border-red-500/20 text-red-300 space-y-1">
              {erroriGenerali.map((e, i) => <div key={i}><span className="font-medium">{e.campo}</span>: {e.messaggio}</div>)}
            </div>
          )}

          {/* overflow-x-auto + min-width sulla tabella: sotto quella soglia
              scorre in orizzontale invece di schiacciare le colonne, così
              ogni campo resta leggibile per intero */}
          <div className="overflow-x-auto -mx-2 px-2">
            <table className="text-sm border-separate" style={{ borderSpacing: 0, minWidth: '1360px', width: '100%', tableLayout: 'fixed' }}>
              <colgroup>
                <col style={{ width: '90px' }} />   {/* Ordine */}
                <col style={{ width: '190px' }} />  {/* Nome squadra */}
                <col style={{ width: '170px' }} />  {/* Logo squadra */}
                <col style={{ width: '90px' }} />   {/* Albo */}
                <col style={{ width: '190px' }} />  {/* Allenatore */}
                <col style={{ width: '170px' }} />  {/* Foto allenatore */}
                <col style={{ width: '160px' }} />  {/* Utenza */}
                <col style={{ width: '150px' }} />  {/* Password */}
                <col style={{ width: '110px' }} />  {/* Abilitata */}
                <col style={{ width: '44px' }} />   {/* Elimina */}
              </colgroup>
              <thead>
                <tr className="text-xs text-slate-600 text-left">
                  <th className="pb-2 pr-3">Ordine</th>
                  <th className="pb-2 pr-3">Nome squadra</th>
                  <th className="pb-2 pr-3">Logo squadra</th>
                  <th className="pb-2 pr-3">Albo</th>
                  <th className="pb-2 pr-3">Allenatore</th>
                  <th className="pb-2 pr-3">Foto allenatore</th>
                  <th className="pb-2 pr-3">Utenza</th>
                  <th className="pb-2 pr-3">Password</th>
                  <th className="pb-2 pr-3">Abilitata</th>
                  <th className="pb-2" />
                </tr>
              </thead>
              <tbody>
                {righe.map((r, i) => {
                  const err = (k) => errorMap[`${i}:${k}`]
                  const cls = (k) => `fanta-input w-full ${err(k) ? 'border-red-500/60' : ''}`
                  return (
                    <tr key={i} className="border-t border-white/5 align-top">
                      <td className="py-2 pr-3">
                        <input type="number" value={r.ordine} onChange={e => update(i, 'ordine', e.target.value)} className={cls('ordine')} />
                        {err('ordine') && <p className="text-[10px] text-red-400 mt-1">{err('ordine')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.nome} onChange={e => update(i, 'nome', e.target.value)} className={cls('nome')} placeholder="Nome squadra" />
                        {err('nome') && <p className="text-[10px] text-red-400 mt-1">{err('nome')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.logo} onChange={e => update(i, 'logo', e.target.value)} className={cls('logo')} placeholder="logo.png" />
                        {err('logo') && <p className="text-[10px] text-red-400 mt-1">{err('logo')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.albo} onChange={e => update(i, 'albo', e.target.value)} className={`${cls('albo')} text-center`} maxLength={2} placeholder="—" />
                        {err('albo') && <p className="text-[10px] text-red-400 mt-1">{err('albo')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.allenatore} onChange={e => update(i, 'allenatore', e.target.value)} className={cls('allenatore')} placeholder="Nome allenatore" />
                        {err('allenatore') && <p className="text-[10px] text-red-400 mt-1">{err('allenatore')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.foto_allenatore} onChange={e => update(i, 'foto_allenatore', e.target.value)} className={cls('foto_allenatore')} placeholder="foto.png" />
                        {err('foto_allenatore') && <p className="text-[10px] text-red-400 mt-1">{err('foto_allenatore')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.utenza} onChange={e => update(i, 'utenza', e.target.value)} className={cls('utenza')} placeholder="username" />
                        {err('utenza') && <p className="text-[10px] text-red-400 mt-1">{err('utenza')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <input value={r.password} onChange={e => update(i, 'password', e.target.value)} className={cls('password')} maxLength={8} placeholder="invariata se vuota" />
                        {err('password') && <p className="text-[10px] text-red-400 mt-1">{err('password')}</p>}
                      </td>
                      <td className="py-2 pr-3">
                        <select value={r.abilitazione} onChange={e => update(i, 'abilitazione', e.target.value)} className={`${cls('abilitazione')} cursor-pointer`}>
                          <option value="Y">Sì</option>
                          <option value="N">No</option>
                        </select>
                      </td>
                      <td className="py-2">
                        <button onClick={() => removeRiga(i)} className="text-slate-600 hover:text-red-400"><Trash2 className="w-4 h-4" /></button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <button onClick={addRiga} className="btn-ghost text-xs mt-3"><Plus className="w-3.5 h-3.5" /> Aggiungi riga</button>

          <p className="text-[11px] text-slate-600 mt-4">
            Lascia vuota la password di un'utenza già esistente per non modificarla. Confermando, i dati esistenti per
            la stagione {stagione} in NEW_SQUADRE, NEW_ALLENATORI e NEW_UTENZE verranno sostituiti con quelli qui sopra.
          </p>

          <div className="flex items-center justify-end mt-4 pt-4 border-t border-white/5">
            <button onClick={salva} disabled={saving} className="btn-primary text-sm disabled:opacity-40">
              {saving ? <><Spinner size="sm" /> Salvataggio in corso...</> : 'Salva'}
            </button>
          </div>

          {result?.ok && (
            <div className="mt-5 px-4 py-3 rounded-lg text-sm bg-green-500/10 border border-green-500/20 text-green-300">
              <div className="flex items-center gap-2 font-medium">
                <CheckCircle2 className="w-4 h-4" /> Stagione {result.stagione} salvata correttamente ({result.righe} righe)
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
