import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { aiContesto } from '../api/client'
import { PageHeader, TabBar, Spinner } from '../components/ui'
import {
  AiCredenziali, Avviso, BTN_PRIMARY, BTN_SECONDARY, BTN_DANGER,
} from '../components/AiUi'
import { dataEstesa } from './Gazzetta'
import { Save, Trash2, Plus, ArrowLeft, CheckCircle2, AlertTriangle } from 'lucide-react'

// ============================================================
// "Profili ed episodi" — il contesto che rende gli articoli AI
// personali: carattere, manie e rivalità di ogni fantallenatore (agganciati
// al NOME UTENZA, che resta uguale tra le stagioni) ed episodi storici
// della lega da richiamare quando c'entrano con la giornata.
//
// Accesso: solo utenze con NEW_UTENZE.abilita_ai = 'Y'; utenza e password
// si inseriscono una volta e restano in memoria finché la pagina è aperta.
// ============================================================

const PROFILO_VUOTO = {
  utenza_profilo: '', soprannome: '', carattere: '', squadra_cuore: '',
  tormentoni: '', rivalita: '', note: '',
}
const EPISODIO_VUOTO = {
  id: 0, data_episodio: '', titolo: '', descrizione: '', tag: '', utenze: '', attivo: 'Y',
}

const CAMPI_PROFILO = [
  { k: 'soprannome',    label: 'Soprannome',       riga: true,  max: 100 },
  { k: 'squadra_cuore', label: 'Squadra del cuore', riga: true,  max: 100 },
  { k: 'carattere',     label: 'Carattere e manie (come gioca, cosa fa di solito)', max: 2000 },
  { k: 'tormentoni',    label: 'Tormentoni (frasi o abitudini ricorrenti)', max: 2000 },
  { k: 'rivalita',      label: 'Rivalità con altri fantallenatori', max: 2000 },
  { k: 'note',          label: 'Altre note utili', max: 2000 },
]

const csvToList = (s) => String(s ?? '').split(',').map(x => x.trim()).filter(Boolean)

export default function AiContesto() {
  const { utente, ultimaStagione, stagione } = useApp()
  const stagioneRif = Number(ultimaStagione ?? stagione)

  const [utenza,   setUtenza]   = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  const [sbloccato, setSbloccato] = useState(false)
  const [tab,      setTab]      = useState('profili')
  const [profili,  setProfili]  = useState([])
  const [utenze,   setUtenze]   = useState([])
  const [episodi,  setEpisodi]  = useState([])
  const [formP,    setFormP]    = useState(null)   // profilo in modifica
  const [formE,    setFormE]    = useState(null)   // episodio in modifica
  const [conferma, setConferma] = useState(false)
  const [busy,     setBusy]     = useState(false)
  const [errore,   setErrore]   = useState(null)
  const [msg,      setMsg]      = useState(null)

  const chiama = (tipo, azione, extra = {}) =>
    aiContesto(stagioneRif, { utenza: utenza.trim(), password, tipo, azione, ...extra })

  const caricaTutto = async () => {
    const [p, e] = await Promise.all([chiama('profili', 'elenco'), chiama('episodi', 'elenco')])
    setProfili(p.profili); setUtenze(p.utenze); setEpisodi(e.episodi)
  }

  const entra = async () => {
    setErrore(null); setBusy(true)
    try { await caricaTutto(); setSbloccato(true) }
    catch (e) { setErrore(e.message) }
    finally { setBusy(false) }
  }

  const esegui = async (fn, messaggio) => {
    setErrore(null); setMsg(null); setBusy(true)
    try { await fn(); await caricaTutto(); setMsg(messaggio) }
    catch (e) { setErrore(e.message) }
    finally { setBusy(false); setConferma(false) }
  }

  const cambiaTab = (t) => { setTab(t); setFormP(null); setFormE(null); setMsg(null); setErrore(null); setConferma(false) }

  // ── Profili ────────────────────────────────────────────────
  const profiloDi = (u) => profili.find(p => p.utenza.toLowerCase() === u.toLowerCase())
  const nomiStagione = new Set(utenze.map(u => u.utenza.toLowerCase()))
  const righeProfili = [
    ...utenze.map(u => ({ utenza: u.utenza, etichetta: u.allenatore || u.utenza, squadra: u.squadra, profilo: profiloDi(u.utenza) })),
    ...profili.filter(p => !nomiStagione.has(p.utenza.toLowerCase()))
      .map(p => ({ utenza: p.utenza, etichetta: p.utenza, squadra: 'non in questa stagione', profilo: p })),
  ]

  const apriProfilo = (r) => {
    setMsg(null); setErrore(null); setConferma(false)
    const p = r.profilo ?? {}
    setFormP({
      ...PROFILO_VUOTO, utenza_profilo: r.utenza,
      soprannome: p.soprannome ?? '', carattere: p.carattere ?? '', squadra_cuore: p.squadra_cuore ?? '',
      tormentoni: p.tormentoni ?? '', rivalita: p.rivalita ?? '', note: p.note ?? '',
      _esiste: !!r.profilo, _etichetta: r.etichetta,
    })
  }
  const salvaProfilo = () => esegui(async () => {
    const { _esiste, _etichetta, ...dati } = formP
    await chiama('profili', 'salva', dati)
    setFormP(null)
  }, 'Profilo salvato.')
  const eliminaProfilo = () => esegui(async () => {
    await chiama('profili', 'elimina', { utenza_profilo: formP.utenza_profilo })
    setFormP(null)
  }, 'Profilo eliminato.')

  // ── Episodi ────────────────────────────────────────────────
  const apriEpisodio = (e) => {
    setMsg(null); setErrore(null); setConferma(false)
    setFormE(e ? {
      id: e.id, data_episodio: e.data_episodio ?? '', titolo: e.titolo, descrizione: e.descrizione,
      tag: e.tag ?? '', utenze: e.utenze ?? '', attivo: e.attivo,
    } : { ...EPISODIO_VUOTO })
  }
  const salvaEpisodio = () => esegui(async () => {
    await chiama('episodi', 'salva', formE)
    setFormE(null)
  }, 'Episodio salvato.')
  const eliminaEpisodio = () => esegui(async () => {
    await chiama('episodi', 'elimina', { id: formE.id })
    setFormE(null)
  }, 'Episodio eliminato.')
  const toggleUtenza = (u) => setFormE(f => {
    const lista = csvToList(f.utenze)
    const ha = lista.some(x => x.toLowerCase() === u.toLowerCase())
    const nuova = ha ? lista.filter(x => x.toLowerCase() !== u.toLowerCase()) : [...lista, u]
    return { ...f, utenze: nuova.join(',') }
  })

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="AI"
        title="Profili ed episodi"
        subtitle="Il contesto che rende gli articoli personali: chi sono i fantallenatori e cosa è successo nella lega."
      />

      {!sbloccato && (
        <AiCredenziali
          utenza={utenza} password={password}
          setUtenza={setUtenza} setPassword={setPassword}
          onSubmit={entra} busy={busy} errore={errore}
          testoBottone="Apri profili ed episodi"
        />
      )}

      {sbloccato && (
        <div className="max-w-3xl space-y-4">
          <TabBar
            tabs={[{ value: 'profili', label: 'Profili' }, { value: 'episodi', label: 'Episodi' }]}
            active={tab} onChange={cambiaTab}
          />

          {msg && <Avviso tono="green" icona={CheckCircle2}>{msg}</Avviso>}
          {errore && <Avviso tono="red">{errore}</Avviso>}

          {/* ───────── PROFILI ───────── */}
          {tab === 'profili' && !formP && (
            <div className="space-y-2">
              <p className="text-xs text-slate-500">
                Un profilo per ogni fantallenatore, agganciato al nome utenza. Più è concreto
                (manie, tormentoni, rivalità), più l'articolo risulta personale. Senza profilo l'AI usa solo i dati.
              </p>
              {righeProfili.map(r => (
                <button
                  key={r.utenza} type="button" onClick={() => apriProfilo(r)}
                  className="card w-full text-left p-4 hover:border-white/20 transition-colors flex items-center gap-3"
                >
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-slate-200">
                      {r.etichetta}
                      {r.profilo?.soprannome && <span className="text-slate-500 font-normal"> · «{r.profilo.soprannome}»</span>}
                    </p>
                    <p className="text-xs text-slate-500">{r.squadra} · utenza {r.utenza}</p>
                  </div>
                  {r.profilo
                    ? <span className="stat-pill text-green-400">Profilo</span>
                    : <span className="stat-pill">Da compilare</span>}
                </button>
              ))}
            </div>
          )}

          {tab === 'profili' && formP && (
            <div className="space-y-4">
              <button type="button" onClick={() => { setFormP(null); setConferma(false) }} className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300">
                <ArrowLeft className="w-4 h-4" /> Torna ai profili
              </button>
              <div className="card p-5 space-y-4">
                <h3 className="font-semibold text-slate-200">
                  {formP._etichetta} <span className="text-slate-500 font-normal">· utenza {formP.utenza_profilo}</span>
                </h3>
                {CAMPI_PROFILO.map(c => (
                  <div key={c.k}>
                    <label htmlFor={`p-${c.k}`} className="text-xs text-slate-600 mb-1 block">{c.label}</label>
                    {c.riga ? (
                      <input id={`p-${c.k}`} type="text" maxLength={c.max} value={formP[c.k]}
                        onChange={e => setFormP(f => ({ ...f, [c.k]: e.target.value }))} className="fanta-input" />
                    ) : (
                      <textarea id={`p-${c.k}`} rows={3} maxLength={c.max} value={formP[c.k]}
                        onChange={e => setFormP(f => ({ ...f, [c.k]: e.target.value }))} className="fanta-input" />
                    )}
                  </div>
                ))}
                <p className="text-[11px] text-slate-600">
                  Evita informazioni private o delicate (salute, famiglia, lavoro): finiscono in un articolo letto da tutta la lega.
                </p>
              </div>
              <div className="flex flex-wrap gap-3">
                <button type="button" disabled={busy} onClick={salvaProfilo} className={BTN_PRIMARY}>
                  {busy ? <Spinner size="sm" /> : <Save className="w-4 h-4" />} Salva profilo
                </button>
                {formP._esiste && (!conferma ? (
                  <button type="button" disabled={busy} onClick={() => setConferma(true)} className={BTN_DANGER}>
                    <Trash2 className="w-4 h-4" /> Elimina
                  </button>
                ) : (
                  <button type="button" disabled={busy} onClick={eliminaProfilo} className={BTN_DANGER}>
                    <AlertTriangle className="w-4 h-4" /> Conferma eliminazione
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* ───────── EPISODI ───────── */}
          {tab === 'episodi' && !formE && (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-4">
                <p className="text-xs text-slate-500">
                  Aneddoti da richiamare quando c'entrano con la giornata. A parità di pertinenza
                  vengono scelti quelli usati meno di recente. Senza utenze indicate l'episodio vale per tutti.
                </p>
                <button type="button" onClick={() => apriEpisodio(null)} className={`${BTN_PRIMARY} flex-shrink-0`}>
                  <Plus className="w-4 h-4" /> Nuovo
                </button>
              </div>
              {episodi.length === 0 && (
                <div className="card p-6 text-sm text-slate-400">Nessun episodio inserito.</div>
              )}
              {episodi.map(e => (
                <button
                  key={e.id} type="button" onClick={() => apriEpisodio(e)}
                  className={`card w-full text-left p-4 hover:border-white/20 transition-colors ${e.attivo === 'N' ? 'opacity-60' : ''}`}
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium text-slate-200">{e.titolo}</p>
                    {e.attivo === 'N' && <span className="stat-pill">Disattivato</span>}
                  </div>
                  <p className="text-xs text-slate-500 mt-1">
                    {e.data_episodio ? dataEstesa(e.data_episodio) : 'senza data'}
                    {' · '}{e.utenze ? csvToList(e.utenze).join(', ') : 'tutti'}
                    {e.ultimo_utilizzo && ` · usato il ${dataEstesa(e.ultimo_utilizzo)}`}
                  </p>
                </button>
              ))}
            </div>
          )}

          {tab === 'episodi' && formE && (
            <div className="space-y-4">
              <button type="button" onClick={() => { setFormE(null); setConferma(false) }} className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300">
                <ArrowLeft className="w-4 h-4" /> Torna agli episodi
              </button>
              <div className="card p-5 space-y-4">
                <h3 className="font-semibold text-slate-200">{formE.id ? 'Modifica episodio' : 'Nuovo episodio'}</h3>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div className="sm:col-span-2">
                    <label htmlFor="e-titolo" className="text-xs text-slate-600 mb-1 block">Titolo</label>
                    <input id="e-titolo" type="text" maxLength={150} value={formE.titolo}
                      onChange={e => setFormE(f => ({ ...f, titolo: e.target.value }))} className="fanta-input" />
                  </div>
                  <div>
                    <label htmlFor="e-data" className="text-xs text-slate-600 mb-1 block">Data (opzionale)</label>
                    <input id="e-data" type="date" value={formE.data_episodio}
                      onChange={e => setFormE(f => ({ ...f, data_episodio: e.target.value }))} className="fanta-input" />
                  </div>
                </div>
                <div>
                  <label htmlFor="e-desc" className="text-xs text-slate-600 mb-1 block">Cosa è successo</label>
                  <textarea id="e-desc" rows={5} maxLength={3000} value={formE.descrizione}
                    onChange={e => setFormE(f => ({ ...f, descrizione: e.target.value }))} className="fanta-input" />
                </div>
                <div>
                  <label className="text-xs text-slate-600 mb-1 block">Chi è coinvolto (nessuno = episodio generico)</label>
                  <div className="flex flex-wrap gap-2 mb-2">
                    {utenze.map(u => {
                      const on = csvToList(formE.utenze).some(x => x.toLowerCase() === u.utenza.toLowerCase())
                      return (
                        <button
                          key={u.utenza} type="button" onClick={() => toggleUtenza(u.utenza)}
                          className={`px-3 py-1 rounded-full text-xs border transition-colors ${
                            on ? 'bg-grass-500/15 border-grass-500/40 text-grass-300'
                               : 'border-white/10 text-slate-400 hover:text-slate-200'}`}
                        >
                          {u.allenatore || u.utenza}
                        </button>
                      )
                    })}
                  </div>
                  <input type="text" aria-label="Utenze coinvolte" value={formE.utenze}
                    onChange={e => setFormE(f => ({ ...f, utenze: e.target.value }))}
                    placeholder="nomi utenza separati da virgola (anche di chi non c'è più)" className="fanta-input" />
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                  <div>
                    <label htmlFor="e-tag" className="text-xs text-slate-600 mb-1 block">Tag (opzionale)</label>
                    <input id="e-tag" type="text" maxLength={200} value={formE.tag}
                      onChange={e => setFormE(f => ({ ...f, tag: e.target.value }))} className="fanta-input" />
                  </div>
                  <label className="flex items-center gap-2 text-sm text-slate-300 pb-2 cursor-pointer">
                    <input type="checkbox" checked={formE.attivo === 'Y'}
                      onChange={e => setFormE(f => ({ ...f, attivo: e.target.checked ? 'Y' : 'N' }))} />
                    Attivo (può essere richiamato negli articoli)
                  </label>
                </div>
              </div>
              <div className="flex flex-wrap gap-3">
                <button type="button" disabled={busy || !formE.titolo.trim() || !formE.descrizione.trim()}
                  onClick={salvaEpisodio} className={BTN_PRIMARY}>
                  {busy ? <Spinner size="sm" /> : <Save className="w-4 h-4" />} Salva episodio
                </button>
                {formE.id > 0 && (!conferma ? (
                  <button type="button" disabled={busy} onClick={() => setConferma(true)} className={BTN_DANGER}>
                    <Trash2 className="w-4 h-4" /> Elimina
                  </button>
                ) : (
                  <button type="button" disabled={busy} onClick={eliminaEpisodio} className={BTN_DANGER}>
                    <AlertTriangle className="w-4 h-4" /> Conferma eliminazione
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
