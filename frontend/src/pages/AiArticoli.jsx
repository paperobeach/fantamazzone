import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { aiArticoli, aiImmagini, urlImmagine } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import ArticoloTesto from '../components/ArticoloTesto'
import EdizioneSfogliabile from '../components/EdizioneSfogliabile'
import SelettoreImmagine from '../components/SelettoreImmagine'
import {
  AiCredenziali, Avviso, Importo, BTN_PRIMARY, BTN_SECONDARY, BTN_DANGER,
} from '../components/AiUi'
import { dataEstesa } from './Gazzetta'
import {
  ArrowLeft, Save, Eye, EyeOff, Send, Undo2, Trash2, CheckCircle2, AlertTriangle, ExternalLink, ImagePlus, BookOpen, X,
} from 'lucide-react'

// ============================================================
// "Gestione articoli" — Fase 3: rilettura, modifica e pubblicazione delle
// bozze generate dall'AI.
//
// Accesso: solo utenze con NEW_UTENZE.abilita_ai = 'Y'. Utenza e password
// si inseriscono una volta, restano in memoria finché la pagina è aperta
// e sono verificate dal backend a ogni azione.
//
// Per ogni stagione+giornata può esserci un solo articolo pubblicato:
// pubblicandone uno, gli altri della stessa giornata tornano in bozza.
//
// Le edizioni sfogliabili si correggono pagina per pagina (titolo, testo,
// immagine e didascalia); articoli ed edizioni hanno un'immagine di apertura
// scelta dalla libreria. Nei testi degli articoli un'immagine si inserisce con
// una riga a sé: [IMG:numero|didascalia].
// ============================================================

// Immagine scelta (miniatura) con pulsanti per cambiarla o toglierla
function SlotImmagine({ id, cache, onScegli, onRimuovi, etichetta }) {
  return (
    <div className="flex items-center gap-3">
      {id ? (
        <img
          src={cache[id] ?? urlImmagine(id, 'thumb')} alt={`Immagine ${id}`}
          className="w-20 h-20 rounded-lg object-cover border border-white/10 bg-black/30"
        />
      ) : (
        <div className="w-20 h-20 rounded-lg border border-dashed border-white/10 flex items-center justify-center text-slate-600">
          <ImagePlus className="w-5 h-5" />
        </div>
      )}
      <div className="flex flex-col items-start gap-1.5">
        <span className="text-xs text-slate-500">{etichetta}{id ? ` · n. ${id}` : ' · nessuna'}</span>
        <div className="flex gap-2">
          <button type="button" onClick={onScegli} className="text-xs px-3 py-1.5 rounded-lg border border-white/10 text-slate-300 hover:text-white hover:border-white/20">
            {id ? 'Cambia' : 'Scegli'}
          </button>
          {id && (
            <button type="button" onClick={onRimuovi} className="text-xs px-3 py-1.5 rounded-lg border border-white/10 text-slate-400 hover:text-white inline-flex items-center gap-1">
              <X className="w-3 h-3" /> Togli
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

function StatoPill({ stato }) {
  return stato === 'pubblicato'
    ? <span className="stat-pill text-green-400">Pubblicato</span>
    : <span className="stat-pill">Bozza</span>
}

export default function AiArticoli() {
  const { utente, ultimaStagione, stagione } = useApp()
  const stagioneRif = Number(ultimaStagione ?? stagione)
  const [params, setParams] = useSearchParams()

  const [utenza,   setUtenza]   = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  const [sbloccato, setSbloccato] = useState(false)
  const [elenco,   setElenco]   = useState(null)    // { articoli, spesa_*, cambio_eur }
  const [sel,      setSel]      = useState(null)    // articolo aperto (completo)
  const [form,     setForm]     = useState({ titolo: '', sommario: '', testo: '' })
  const [pagine,   setPagine]   = useState([])      // pagine di un'edizione (in modifica)
  const [cop,      setCop]       = useState(null)    // id immagine di apertura (in modifica)
  const [picker,   setPicker]    = useState(null)    // null | 'cop' | indice di pagina
  const [cacheImg, setCacheImg]  = useState({})      // id → miniatura (data URI) delle immagini appena scelte
  const [anteprima, setAnteprima] = useState(false)
  const [conferma, setConferma] = useState(false)   // 1° click su "Elimina"
  const [busy,     setBusy]     = useState(false)
  const [errore,   setErrore]   = useState(null)
  const [msg,      setMsg]      = useState(null)

  const chiama = (azione, extra = {}) =>
    aiArticoli(stagioneRif, { utenza: utenza.trim(), password, azione, ...extra })

  const ricaricaElenco = async () => {
    const res = await chiama('elenco')
    setElenco(res)
    return res
  }

  const apri = async (id) => {
    setErrore(null); setMsg(null); setConferma(false); setAnteprima(false)
    setBusy(true)
    try {
      const a = await chiama('dettaglio', { id })
      setSel(a)
      setForm({ titolo: a.titolo ?? '', sommario: a.sommario ?? '', testo: a.testo ?? '' })
      setPagine(a.pagine ?? []); setCop(a.id_immagine_copertina ?? null); setPicker(null)
    } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }

  const chiudi = () => {
    setSel(null); setConferma(false); setMsg(null); setErrore(null)
    if (params.get('id')) setParams({}, { replace: true })
  }

  const entra = async () => {
    setErrore(null); setBusy(true)
    try {
      await ricaricaElenco()
      setSbloccato(true)
      const idUrl = Number(params.get('id'))
      if (idUrl > 0) await apri(idUrl)
    } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }

  // Esegue un'azione sull'articolo aperto e aggiorna elenco e form
  const azione = async (nome, extra = {}, messaggio) => {
    setErrore(null); setMsg(null); setBusy(true)
    try {
      const a = await chiama(nome, { id: sel.id, ...extra })
      if (nome === 'elimina') {
        await ricaricaElenco()
        chiudi()
        setMsg('Articolo eliminato.')
        return
      }
      setSel(a)
      setForm({ titolo: a.titolo ?? '', sommario: a.sommario ?? '', testo: a.testo ?? '' })
      setPagine(a.pagine ?? []); setCop(a.id_immagine_copertina ?? null)
      await ricaricaElenco()
      setMsg(nome === 'pubblica' && a.sostituiti > 0
        ? `${messaggio} Un altro articolo della stessa giornata è tornato in bozza.`
        : messaggio)
    } catch (e) { setErrore(e.message) } finally { setBusy(false); setConferma(false) }
  }

  const eEdizione = sel?.formato === 'edizione'
  const campiPagina = (p) => [p.titolo ?? '', p.testo ?? '', p.id_immagine ?? null, p.didascalia ?? '']
  const pagineModificate = eEdizione && JSON.stringify(pagine.map(campiPagina)) !== JSON.stringify((sel.pagine ?? []).map(campiPagina))
  const dirty = sel && (
    form.titolo !== (sel.titolo ?? '') ||
    form.sommario !== (sel.sommario ?? '') ||
    (!eEdizione && form.testo !== (sel.testo ?? '')) ||
    pagineModificate ||
    (cop ?? null) !== (sel.id_immagine_copertina ?? null)
  )

  const cambiaPagina = (i, patch) => setPagine(ps => ps.map((p, k) => (k === i ? { ...p, ...patch } : p)))
  const caricaImmagini = ({ pagina, cerca }) =>
    aiImmagini(stagioneRif, { utenza: utenza.trim(), password, azione: 'elenco', filtro: 'attive', pagina, cerca, per_pagina: 24 })
  const sceltaImmagine = (im) => {
    if (im?.miniatura) setCacheImg(c => ({ ...c, [im.id]: im.miniatura }))
    const id = im ? im.id : null
    if (picker === 'cop') setCop(id)
    else if (typeof picker === 'number') cambiaPagina(picker, { id_immagine: id })
    setPicker(null)
  }
  const datiSalvataggio = () => eEdizione
    ? { titolo: form.titolo, sommario: form.sommario, id_immagine_copertina: cop,
        pagine: pagine.map(p => ({ id: p.id, titolo: p.titolo, testo: p.testo, id_immagine: p.id_immagine, didascalia: p.didascalia })) }
    : { ...form, id_immagine_copertina: cop }
  const edizioneAnteprima = sel && eEdizione ? {
    titolo: form.titolo, sommario: form.sommario, stagione: sel.stagione, giornata: sel.giornata,
    data_pubblicazione: sel.data_pubblicazione, id_immagine_copertina: cop, pagine,
  } : null

  const cambio = elenco?.cambio_eur ?? null

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="AI"
        title="Gestione articoli"
        subtitle="Rileggi, correggi e pubblica gli articoli generati. Solo quelli pubblicati compaiono nella Gazzetta."
      />

      {!sbloccato && (
        <AiCredenziali
          utenza={utenza} password={password}
          setUtenza={setUtenza} setPassword={setPassword}
          onSubmit={entra} busy={busy} errore={errore}
          testoBottone="Apri gestione articoli"
        />
      )}

      {sbloccato && !sel && elenco && (
        <div className="max-w-3xl">
          <p className="text-xs text-slate-500 mb-4">
            Spesa AI del mese: <Importo usd={elenco.spesa_mese_usd} cambio={cambio} />
            {' · '}totale: <Importo usd={elenco.spesa_totale_usd} cambio={cambio} />
          </p>

          {msg && <div className="mb-4"><Avviso tono="green" icona={CheckCircle2}>{msg}</Avviso></div>}
          {errore && <div className="mb-4"><Avviso tono="red">{errore}</Avviso></div>}

          {elenco.articoli.length === 0 ? (
            <div className="card p-6 text-sm text-slate-400">
              Nessun articolo generato. Creane uno da{' '}
              <Link to="/ai/articolo" className="text-grass-400 hover:underline">Genera articolo</Link>.
            </div>
          ) : (
            <div className="space-y-2">
              {elenco.articoli.map(a => (
                <button
                  key={a.id} type="button" onClick={() => apri(a.id)} disabled={busy}
                  className="card w-full text-left p-4 hover:border-white/20 transition-colors disabled:opacity-60"
                >
                  <div className="flex flex-wrap items-center gap-2 mb-1">
                    <StatoPill stato={a.stato} />
                    <span className="text-[11px] text-mono tracking-widest uppercase text-slate-600">
                      Stagione {a.stagione} · Giornata {a.giornata}
                    </span>
                    {a.formato === 'edizione' && <span className="stat-pill text-gold-400"><BookOpen className="w-3 h-3" /> Edizione</span>}
                    {a.troncato && <span className="stat-pill text-gold-400">Troncato</span>}
                  </div>
                  <p className="font-medium text-slate-200 leading-snug">{a.titolo || '(senza titolo)'}</p>
                  <p className="text-xs text-slate-500 mt-1">
                    {dataEstesa(a.data_generazione)} · {a.modello}
                    {a.costo_usd != null && <> · <Importo usd={a.costo_usd} cambio={cambio} /></>}
                  </p>
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      {sbloccato && sel && (
        <div className="max-w-3xl space-y-4">
          <button type="button" onClick={chiudi} className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300">
            <ArrowLeft className="w-4 h-4" /> Torna all'elenco
          </button>

          <div className="flex flex-wrap items-center gap-2">
            <StatoPill stato={sel.stato} />
            <span className="text-xs text-slate-500">
              Stagione {sel.stagione} · Giornata {sel.giornata} · {sel.modello} · creato da {sel.creato_da || 'n/d'}
            </span>
          </div>

          {sel.troncato && (
            <Avviso>
              Il testo era stato interrotto per il limite di token: controlla la parte finale
              (di solito le pagelle) e completala a mano, oppure rigenera con un limite più alto
              (<code>max_token</code> in config_ai.php).
            </Avviso>
          )}
          {msg && <Avviso tono="green" icona={CheckCircle2}>{msg}</Avviso>}
          {errore && <Avviso tono="red">{errore}</Avviso>}

          <div className="card p-5 space-y-4">
            <div>
              <label htmlFor="art-titolo" className="text-xs text-slate-600 mb-1 block">Titolo</label>
              <input
                id="art-titolo" type="text" maxLength={250} value={form.titolo}
                onChange={e => setForm(f => ({ ...f, titolo: e.target.value }))} className="fanta-input"
              />
            </div>
            <div>
              <label htmlFor="art-sommario" className="text-xs text-slate-600 mb-1 block">Sommario</label>
              <textarea
                id="art-sommario" rows={2} maxLength={1000} value={form.sommario}
                onChange={e => setForm(f => ({ ...f, sommario: e.target.value }))} className="fanta-input"
              />
            </div>
            <div>
              <p className="text-xs text-slate-600 mb-1.5">Immagine di apertura</p>
              <SlotImmagine
                id={cop} cache={cacheImg} etichetta="Copertina"
                onScegli={() => setPicker('cop')} onRimuovi={() => setCop(null)}
              />
            </div>

            {eEdizione ? (
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <p className="text-xs text-slate-600">
                    Pagine dell'edizione ({pagine.length}). Il tabellino con punteggi e classifica viene dai dati e non si modifica.
                  </p>
                  <button
                    type="button" onClick={() => setAnteprima(v => !v)}
                    className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-300 flex-shrink-0"
                  >
                    {anteprima ? <><EyeOff className="w-3.5 h-3.5" /> Modifica</> : <><Eye className="w-3.5 h-3.5" /> Anteprima</>}
                  </button>
                </div>
                {anteprima ? (
                  <>
                    <EdizioneSfogliabile edizione={edizioneAnteprima} />
                    <p className="text-[11px] text-slate-600">Le immagini appena scelte compaiono in anteprima dopo il salvataggio.</p>
                  </>
                ) : pagine.map((p, i) => (
                  <fieldset key={p.id} className="rounded-lg border border-white/10 p-4 space-y-3">
                    <legend className="px-2 text-xs text-slate-500">
                      Pagina {i + 2} · {p.tipo === 'commento' ? 'commento generale' : p.dati?.casa ? `${p.dati.casa} – ${p.dati.ospite}` : 'partita'}
                    </legend>
                    <div>
                      <label htmlFor={`pg-tit-${p.id}`} className="text-xs text-slate-600 mb-1 block">Titolo</label>
                      <input id={`pg-tit-${p.id}`} type="text" maxLength={250} value={p.titolo ?? ''}
                        onChange={e => cambiaPagina(i, { titolo: e.target.value })} className="fanta-input" />
                    </div>
                    <div>
                      <label htmlFor={`pg-txt-${p.id}`} className="text-xs text-slate-600 mb-1 block">
                        Testo <span className="text-slate-700">(paragrafi separati da una riga vuota; intertitoli in MAIUSCOLO)</span>
                      </label>
                      <textarea id={`pg-txt-${p.id}`} rows={p.tipo === 'commento' ? 14 : 8} value={p.testo ?? ''}
                        onChange={e => cambiaPagina(i, { testo: e.target.value })} className="fanta-input leading-relaxed" />
                      {!(p.testo ?? '').trim() && <p className="text-[11px] text-gold-400 mt-1">Pagina senza testo: scrivilo a mano.</p>}
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                      <SlotImmagine
                        id={p.id_immagine} cache={cacheImg} etichetta="Immagine della pagina"
                        onScegli={() => setPicker(i)} onRimuovi={() => cambiaPagina(i, { id_immagine: null, didascalia: '' })}
                      />
                      {p.id_immagine && (
                        <div>
                          <label htmlFor={`pg-did-${p.id}`} className="text-xs text-slate-600 mb-1 block">Didascalia</label>
                          <input id={`pg-did-${p.id}`} type="text" maxLength={300} value={p.didascalia ?? ''}
                            onChange={e => cambiaPagina(i, { didascalia: e.target.value })} className="fanta-input" />
                        </div>
                      )}
                    </div>
                  </fieldset>
                ))}
              </div>
            ) : (
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label htmlFor="art-testo" className="text-xs text-slate-600">
                    Testo <span className="text-slate-700">(paragrafi separati da una riga vuota; intertitoli in MAIUSCOLO; immagine: riga a sé [IMG:numero|didascalia])</span>
                  </label>
                  <button
                    type="button" onClick={() => setAnteprima(v => !v)}
                    className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-300"
                  >
                    {anteprima ? <><EyeOff className="w-3.5 h-3.5" /> Modifica</> : <><Eye className="w-3.5 h-3.5" /> Anteprima</>}
                  </button>
                </div>
                {anteprima ? (
                  <div className="rounded-lg border border-white/10 bg-black/20 p-4">
                    <h3 className="text-lg font-bold text-slate-100">{form.titolo}</h3>
                    {form.sommario && <p className="text-sm italic text-slate-400 mt-1 mb-4">{form.sommario}</p>}
                    <ArticoloTesto testo={form.testo} />
                  </div>
                ) : (
                  <textarea
                    id="art-testo" rows={20} value={form.testo}
                    onChange={e => setForm(f => ({ ...f, testo: e.target.value }))}
                    className="fanta-input leading-relaxed"
                  />
                )}
                <p className="text-[11px] text-slate-600 mt-1">{form.testo.length} caratteri</p>
              </div>
            )}

            {sel.contesto_giornata && (
              <details className="text-xs text-slate-500">
                <summary className="cursor-pointer hover:text-slate-300 select-none">Note di giornata usate per generarlo</summary>
                <p className="mt-2 whitespace-pre-line text-slate-400">{sel.contesto_giornata}</p>
              </details>
            )}

            <p className="text-xs text-slate-500">
              {sel.token_input != null && <>Token {sel.token_input} in / {sel.token_output} out · </>}
              costo <Importo usd={sel.costo_usd} cambio={cambio} />
              {sel.costo_stimato_usd != null && <> (previsto <Importo usd={sel.costo_stimato_usd} cambio={cambio} />)</>}
              {sel.data_pubblicazione && <> · pubblicato il {dataEstesa(sel.data_pubblicazione)}</>}
            </p>
          </div>

          {dirty && sel.stato !== 'pubblicato' && (
            <p className="text-xs text-gold-400">Hai modifiche non salvate: salva prima di pubblicare.</p>
          )}

          <div className="flex flex-wrap gap-3">
            <button
              type="button" disabled={busy || !dirty || !form.titolo.trim() || (!eEdizione && !form.testo.trim())}
              onClick={() => azione('salva', datiSalvataggio(), 'Modifiche salvate.')} className={BTN_PRIMARY}
            >
              {busy ? <Spinner size="sm" /> : <Save className="w-4 h-4" />} Salva modifiche
            </button>

            {sel.stato === 'pubblicato' ? (
              <>
                <button type="button" disabled={busy} onClick={() => azione('bozza', {}, 'Articolo tolto dalla Gazzetta (tornato in bozza).')} className={BTN_SECONDARY}>
                  <Undo2 className="w-4 h-4" /> Torna in bozza
                </button>
                <Link to={`/gazzetta/${sel.id}`} className={BTN_SECONDARY}>
                  <ExternalLink className="w-4 h-4" /> Vedi nella Gazzetta
                </Link>
              </>
            ) : (
              <button
                type="button" disabled={busy || dirty}
                onClick={() => azione('pubblica', {}, 'Articolo pubblicato nella Gazzetta.')} className={BTN_SECONDARY}
              >
                <Send className="w-4 h-4" /> Pubblica
              </button>
            )}

            {!conferma ? (
              <button type="button" disabled={busy} onClick={() => setConferma(true)} className={BTN_DANGER}>
                <Trash2 className="w-4 h-4" /> Elimina
              </button>
            ) : (
              <button type="button" disabled={busy} onClick={() => azione('elimina')} className={BTN_DANGER}>
                <AlertTriangle className="w-4 h-4" /> Conferma eliminazione definitiva
              </button>
            )}
          </div>

          {picker !== null && (
            <SelettoreImmagine
              carica={caricaImmagini} onScegli={sceltaImmagine} onChiudi={() => setPicker(null)}
              titolo={picker === 'cop' ? 'Immagine di apertura' : `Immagine della pagina ${picker + 2}`}
            />
          )}
        </div>
      )}
    </div>
  )
}
