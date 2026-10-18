import { useRef, useState } from 'react'
import { useApp } from '../context/AppContext'
import { aiImmagini, aiImmaginiCarica } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import {
  AiCredenziali, Avviso, Importo, BTN_PRIMARY, BTN_SECONDARY, BTN_DANGER,
} from '../components/AiUi'
import { dataEstesa } from './Gazzetta'
import {
  Upload, Save, Trash2, ArrowLeft, Sparkles, CheckCircle2, AlertTriangle, ImageOff, Search, PenLine,
} from 'lucide-react'

// ============================================================
// "Immagini" — libreria delle immagini della Gazzetta.
//
// Le immagini caricate qui vengono proposte all'AI quando genera un
// articolo o un'edizione: l'AI NON vede le foto, legge la descrizione, i tag,
// il tono e le persone indicate. Due modi per descriverle:
//   B · a mano  (gratuito): descrizione, tag e persone si scrivono tu;
//   C · con AI  (a pagamento, circa un centesimo ogni 3-5 foto): un modello con
//        visione guarda la foto e propone descrizione, tag e tono; le persone
//        (l'AI non le riconosce) le indichi tu.
// Il caricamento è multiplo; ogni file viene ridimensionato nel browser prima
// dell'invio (meno traffico, meno costo) e di nuovo sul server.
//
// Accesso: solo utenze con NEW_UTENZE.abilita_ai = 'Y'.
// ============================================================

const TONI = ['', 'comico', 'epico', 'drammatico', 'malinconico', 'festoso', 'neutro']
const csvToList = (s) => String(s ?? '').split(',').map(x => x.trim()).filter(Boolean)

/** Ridimensiona nel browser (lato lungo max 1600 px, JPEG). In caso di problemi restituisce il file originale. */
async function ridimensiona(file, lato = 1600, qualita = 0.86) {
  if (!file.type.startsWith('image/') || file.type === 'image/gif') return file
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const max = Math.max(bmp.width, bmp.height)
    if (max <= lato && file.size <= 900000) { bmp.close?.(); return file }
    const f = Math.min(1, lato / max)
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(bmp.width * f))
    canvas.height = Math.max(1, Math.round(bmp.height * f))
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height)
    bmp.close?.()
    const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', qualita))
    if (!blob || (f === 1 && blob.size >= file.size)) return file
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' })
  } catch {
    return file
  }
}

const STATI = {
  attesa:    { t: 'In attesa',               c: 'text-slate-500' },
  invio:     { t: 'Caricamento…',            c: 'text-slate-300' },
  ok:        { t: 'Caricata',                c: 'text-green-400' },
  ok_senza_ai: { t: 'Caricata, ma l\'AI non ha risposto', c: 'text-gold-400' },
  duplicata: { t: 'Già presente (saltata)',  c: 'text-gold-400' },
  errore:    { t: 'Errore',                  c: 'text-red-400' },
  saltata:   { t: 'Non inviata',             c: 'text-slate-500' },
}

function ChipUtenze({ utenze, selezionate, onToggle }) {
  if (!utenze?.length) return null
  return (
    <div className="flex flex-wrap gap-2">
      {utenze.map(u => {
        const on = selezionate.some(x => x.toLowerCase() === u.utenza.toLowerCase())
        return (
          <button
            key={u.utenza} type="button" onClick={() => onToggle(u.utenza)} aria-pressed={on}
            className={`px-3 py-1 rounded-full text-xs border transition-colors ${
              on ? 'bg-grass-500/15 border-grass-500/40 text-grass-300'
                 : 'border-white/10 text-slate-400 hover:text-slate-200'}`}
          >
            {u.allenatore || u.utenza}
          </button>
        )
      })}
    </div>
  )
}

export default function AiImmagini() {
  const { utente, ultimaStagione, stagione } = useApp()
  const stagioneRif = Number(ultimaStagione ?? stagione)

  const [utenza,   setUtenza]   = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  const [sbloccato, setSbloccato] = useState(false)
  const [busy,     setBusy]     = useState(false)
  const [errore,   setErrore]   = useState(null)
  const [msg,      setMsg]      = useState(null)

  const [info,     setInfo]     = useState(null)
  const [elenco,   setElenco]   = useState(null)       // { immagini, totale, pagina, per_pagina }
  const [filtro,   setFiltro]   = useState('tutte')
  const [cerca,    setCerca]    = useState('')
  const [pagina,   setPagina]   = useState(1)

  // Caricamento
  const inputFile = useRef(null)
  const [files,    setFiles]    = useState([])
  const [modalita, setModalita] = useState('manuale')  // 'manuale' (B) | 'ai' (C)
  const [modello,  setModello]  = useState('')
  const [comuni,   setComuni]   = useState({ descrizione: '', tag: '', tono: '', utenze: [] })
  const [coda,     setCoda]     = useState([])
  const [inCorso,  setInCorso]  = useState(false)

  // Modifica
  const [sel,      setSel]      = useState(null)       // dettaglio con "immagine"
  const [form,     setForm]     = useState(null)
  const [modelloDescr, setModelloDescr] = useState('')
  const [conferma, setConferma] = useState(false)

  const chiama = (azione, extra = {}) =>
    aiImmagini(stagioneRif, { utenza: utenza.trim(), password, azione, ...extra })

  const caricaElenco = async (p = pagina, f = filtro, c = cerca) => {
    const r = await chiama('elenco', { pagina: p, filtro: f, cerca: c })
    setElenco(r)
    return r
  }
  const caricaInfo = async () => {
    const r = await chiama('info')
    setInfo(r)
    const primo = r.modelli?.[0]?.modello ?? ''
    setModello(m => m || primo)
    setModelloDescr(m => m || primo)
    return r
  }

  const entra = async () => {
    setErrore(null); setBusy(true)
    try { await Promise.all([caricaInfo(), caricaElenco(1, 'tutte', '')]); setSbloccato(true) }
    catch (e) { setErrore(e.message) }
    finally { setBusy(false) }
  }

  const cambiaFiltro = async (f) => {
    setFiltro(f); setPagina(1)
    setErrore(null); setBusy(true)
    try { await caricaElenco(1, f, cerca) } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }
  const vaiPagina = async (p) => {
    setPagina(p); setBusy(true); setErrore(null)
    try { await caricaElenco(p) } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }
  const eseguiRicerca = async (e) => {
    e.preventDefault(); setPagina(1); setBusy(true); setErrore(null)
    try { await caricaElenco(1, filtro, cerca) } catch (er) { setErrore(er.message) } finally { setBusy(false) }
  }

  // ── Caricamento multiplo ───────────────────────────────────
  const scegliFile = (e) => {
    const lista = Array.from(e.target.files ?? []).filter(f => f.type.startsWith('image/'))
    setFiles(lista); setCoda([]); setMsg(null); setErrore(null)
  }
  const aggiornaCoda = (i, patch) => setCoda(c => c.map((x, k) => (k === i ? { ...x, ...patch } : x)))

  const costoUnitario = info?.modelli?.find(m => m.modello === modello)?.costo_stimato_usd ?? null
  const costoMaxUnitario = info?.modelli?.find(m => m.modello === modello)?.costo_massimo_usd ?? null

  const avviaCaricamento = async () => {
    if (!files.length) return
    setErrore(null); setMsg(null); setInCorso(true)
    setCoda(files.map(f => ({ nome: f.name, stato: 'attesa', msg: '' })))
    let fermo = false
    let ok = 0, dup = 0, ko = 0, costo = 0
    for (let i = 0; i < files.length; i++) {
      if (fermo) { aggiornaCoda(i, { stato: 'saltata' }); continue }
      aggiornaCoda(i, { stato: 'invio' })
      try {
        const file = await ridimensiona(files[i])
        const res = await aiImmaginiCarica(stagioneRif, {
          utenza: utenza.trim(), password, file, modalita,
          modello: modalita === 'ai' ? modello : undefined,
          descrizione: modalita === 'manuale' ? comuni.descrizione : '',
          tag: comuni.tag, tono: comuni.tono, utenze: comuni.utenze.join(','),
        })
        if (res.duplicata) { dup++; aggiornaCoda(i, { stato: 'duplicata', msg: `uguale all'immagine n. ${res.immagine.id}` }) }
        else if (res.ai?.richiesta && !res.ai.ok) { ok++; aggiornaCoda(i, { stato: 'ok_senza_ai', msg: res.ai.errore }) }
        else { ok++; aggiornaCoda(i, { stato: 'ok' }) }
        costo += Number(res.ai?.costo_usd ?? 0)
      } catch (e) {
        ko++
        aggiornaCoda(i, { stato: 'errore', msg: e.message })
        // credenziali, tetto di spesa, libreria piena, configurazione: inutile continuare
        if ([402, 403, 409, 500].includes(e.status)) fermo = true
      }
    }
    setInCorso(false)
    try { await Promise.all([caricaElenco(1, filtro, cerca), caricaInfo()]); setPagina(1) } catch (e) { setErrore(e.message) }
    setMsg(`${ok} caricate, ${dup} già presenti, ${ko} con errore.` + (costo > 0 ? ` Costo AI: $${costo.toFixed(4)}.` : ''))
    setFiles([]); if (inputFile.current) inputFile.current.value = ''
  }

  // ── Modifica di una immagine ───────────────────────────────
  const apri = async (id) => {
    setErrore(null); setMsg(null); setConferma(false); setBusy(true)
    try {
      const d = await chiama('dettaglio', { id })
      setSel(d)
      setForm({ descrizione: d.descrizione ?? '', tag: d.tag ?? '', tono: d.tono ?? '', utenze: csvToList(d.utenze), attivo: d.attivo })
    } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }
  const chiudi = () => { setSel(null); setForm(null); setConferma(false) }

  const salva = async () => {
    setErrore(null); setMsg(null); setBusy(true)
    try {
      const r = await chiama('salva', {
        id: sel.id, descrizione: form.descrizione, tag: form.tag, tono: form.tono,
        utenze: form.utenze.join(','), attivo: form.attivo ? 'Y' : 'N',
      })
      setSel(s => ({ ...s, ...r }))
      await Promise.all([caricaElenco(), caricaInfo()])
      setMsg('Immagine salvata.')
    } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }
  const descriviAi = async () => {
    setErrore(null); setMsg(null); setBusy(true)
    try {
      const r = await chiama('descrivi', { id: sel.id, modello: modelloDescr })
      setSel(s => ({ ...s, ...r.immagine }))
      setForm(f => ({ ...f, descrizione: r.immagine.descrizione ?? '', tag: r.immagine.tag ?? '', tono: r.immagine.tono ?? '' }))
      await Promise.all([caricaElenco(), caricaInfo()])
      setMsg(`Descrizione proposta dall'AI (costo $${Number(r.costo_usd).toFixed(4)}). Controllala e salva se serve ritoccarla.`)
    } catch (e) { setErrore(e.message) } finally { setBusy(false) }
  }
  const elimina = async () => {
    setErrore(null); setMsg(null); setBusy(true)
    try {
      await chiama('elimina', { id: sel.id })
      chiudi()
      await Promise.all([caricaElenco(), caricaInfo()])
      setMsg('Immagine eliminata.')
    } catch (e) { setErrore(e.message); setConferma(false) } finally { setBusy(false) }
  }

  const cambio = info?.cambio_eur ?? null
  const pagine = elenco ? Math.max(1, Math.ceil(elenco.totale / elenco.per_pagina)) : 1
  const modelloEtichetta = (id) => info?.modelli?.find(m => m.modello === id)?.etichetta ?? id
  const costoDescr = info?.modelli?.find(m => m.modello === modelloDescr)?.costo_stimato_usd ?? null
  const toggleUtenza = (setter) => (u) => setter(f => {
    const ha = f.utenze.some(x => x.toLowerCase() === u.toLowerCase())
    return { ...f, utenze: ha ? f.utenze.filter(x => x.toLowerCase() !== u.toLowerCase()) : [...f.utenze, u] }
  })

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="AI"
        title="Immagini della Gazzetta"
        subtitle="La libreria di foto da cui l'AI sceglie quelle da inserire negli articoli e nelle edizioni sfogliabili."
      />

      {!sbloccato && (
        <AiCredenziali
          utenza={utenza} password={password}
          setUtenza={setUtenza} setPassword={setPassword}
          onSubmit={entra} busy={busy} errore={errore}
          testoBottone="Apri la libreria immagini"
        />
      )}

      {sbloccato && !sel && (
        <div className="max-w-3xl space-y-6">
          {msg && <Avviso tono="green" icona={CheckCircle2}>{msg}</Avviso>}
          {errore && <Avviso tono="red">{errore}</Avviso>}

          {/* ───────── CARICAMENTO ───────── */}
          <div className="card p-5 space-y-4">
            <h3 className="font-semibold text-slate-200 flex items-center gap-2">
              <Upload className="w-4 h-4 text-slate-400" /> Carica immagini
            </h3>
            <p className="text-xs text-slate-500">
              Puoi selezionare più file insieme (JPG, PNG, GIF, WebP). In libreria: {info.n_immagini} immagini,
              {' '}{info.n_attive} attive{info.n_senza_descrizione > 0 && <>, <strong className="text-gold-400">{info.n_senza_descrizione} senza descrizione</strong> (l'AI non le vede)</>}.
            </p>

            <div>
              <label htmlFor="img-file" className="text-xs text-slate-600 mb-1 block">File</label>
              <input
                id="img-file" ref={inputFile} type="file" accept="image/*" multiple onChange={scegliFile}
                disabled={inCorso}
                className="block w-full text-sm text-slate-400 file:mr-3 file:px-4 file:py-2 file:rounded-lg file:border-0
                           file:bg-pitch-800 file:text-slate-200 file:cursor-pointer hover:file:bg-pitch-700"
              />
              {files.length > 0 && <p className="text-xs text-slate-500 mt-1">{files.length} file selezionati.</p>}
            </div>

            <fieldset>
              <legend className="text-xs text-slate-600 mb-2">Come descriverle</legend>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label className={`rounded-lg border px-4 py-3 cursor-pointer transition-colors ${modalita === 'manuale' ? 'border-grass-500/50 bg-grass-500/5' : 'border-white/10 hover:border-white/20'}`}>
                  <div className="flex items-start gap-3">
                    <input type="radio" name="modalita" className="mt-1" checked={modalita === 'manuale'} onChange={() => setModalita('manuale')} />
                    <div>
                      <p className="text-sm font-medium text-slate-200 flex items-center gap-1.5"><PenLine className="w-3.5 h-3.5" /> B · A mano (gratuito)</p>
                      <p className="text-xs text-slate-500 mt-0.5">Le descrizioni le scrivi tu, subito o dopo il caricamento.</p>
                    </div>
                  </div>
                </label>
                <label className={`rounded-lg border px-4 py-3 transition-colors ${!info.chiave_configurata ? 'opacity-50 cursor-not-allowed border-white/5' : 'cursor-pointer'} ${modalita === 'ai' ? 'border-grass-500/50 bg-grass-500/5' : 'border-white/10 hover:border-white/20'}`}>
                  <div className="flex items-start gap-3">
                    <input type="radio" name="modalita" className="mt-1" checked={modalita === 'ai'}
                      disabled={!info.chiave_configurata} onChange={() => setModalita('ai')} />
                    <div>
                      <p className="text-sm font-medium text-slate-200 flex items-center gap-1.5"><Sparkles className="w-3.5 h-3.5" /> C · Con l'AI (a pagamento)</p>
                      <p className="text-xs text-slate-500 mt-0.5">
                        L'AI guarda ogni foto e propone descrizione, tag e tono.
                        {!info.chiave_configurata && ' Chiave API non configurata.'}
                      </p>
                    </div>
                  </div>
                </label>
              </div>
            </fieldset>

            {modalita === 'ai' && (
              <div className="space-y-3">
                <div>
                  <label htmlFor="img-modello" className="text-xs text-slate-600 mb-1 block">Modello</label>
                  <select id="img-modello" value={modello} onChange={e => setModello(e.target.value)} className="fanta-input cursor-pointer">
                    {info.modelli.map(m => (
                      <option key={m.modello} value={m.modello}>{m.etichetta}</option>
                    ))}
                  </select>
                </div>
                <Avviso>
                  Costo previsto: circa <Importo usd={costoUnitario} cambio={cambio} /> a immagine
                  {files.length > 0 && <> → circa <strong><Importo usd={costoUnitario != null ? costoUnitario * files.length : null} cambio={cambio} /></strong> per {files.length} file
                    {' '}(al massimo <Importo usd={costoMaxUnitario != null ? costoMaxUnitario * files.length : null} cambio={cambio} />)</>}.
                  Le immagini vengono inviate ad Anthropic per l'analisi: carica solo foto che i partecipanti sono d'accordo a condividere.
                  Spesa AI del mese: <Importo usd={info.spesa_mese_usd} cambio={cambio} />
                  {info.tetto_mensile_usd != null && <> su un tetto di <Importo usd={info.tetto_mensile_usd} cambio={cambio} /></>}.
                </Avviso>
              </div>
            )}

            <div className="space-y-3 rounded-lg border border-white/5 p-4">
              <p className="text-xs text-slate-500">
                Valori applicati a TUTTE le immagini di questo caricamento (facoltativi; puoi sempre ritoccarli dopo
                {modalita === 'ai' ? '; l\'AI aggiunge i suoi tag a quelli indicati qui' : ''}).
              </p>
              {modalita === 'manuale' && (
                <div>
                  <label htmlFor="img-desc" className="text-xs text-slate-600 mb-1 block">Descrizione (lascia vuoto per scriverla dopo, foto per foto)</label>
                  <textarea id="img-desc" rows={2} maxLength={600} value={comuni.descrizione}
                    onChange={e => setComuni(c => ({ ...c, descrizione: e.target.value }))} className="fanta-input" />
                </div>
              )}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="sm:col-span-2">
                  <label htmlFor="img-tag" className="text-xs text-slate-600 mb-1 block">Tag (separati da virgola)</label>
                  <input id="img-tag" type="text" maxLength={300} value={comuni.tag}
                    onChange={e => setComuni(c => ({ ...c, tag: e.target.value }))} placeholder="es. cena, trofeo, estate" className="fanta-input" />
                </div>
                <div>
                  <label htmlFor="img-tono" className="text-xs text-slate-600 mb-1 block">Tono</label>
                  <select id="img-tono" value={comuni.tono} onChange={e => setComuni(c => ({ ...c, tono: e.target.value }))} className="fanta-input cursor-pointer">
                    {TONI.map(t => <option key={t} value={t}>{t || '—'}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <p className="text-xs text-slate-600 mb-1">Chi compare (l'AI non riconosce le persone: lo indichi tu)</p>
                <ChipUtenze utenze={info.utenze} selezionate={comuni.utenze} onToggle={toggleUtenza(setComuni)} />
              </div>
            </div>

            <button type="button" onClick={avviaCaricamento} disabled={!files.length || inCorso || (modalita === 'ai' && !modello)} className={BTN_PRIMARY}>
              {inCorso ? <><Spinner size="sm" /> Caricamento in corso…</> : <><Upload className="w-4 h-4" /> Carica {files.length > 0 ? `${files.length} immagini` : 'immagini'}</>}
            </button>

            {coda.length > 0 && (
              <ul className="space-y-1 text-sm" aria-live="polite">
                {coda.map((c, i) => (
                  <li key={i} className="flex flex-wrap items-baseline justify-between gap-x-3 border-b border-white/5 py-1">
                    <span className="text-slate-300 truncate max-w-[60%]">{c.nome}</span>
                    <span className={`text-xs ${STATI[c.stato].c}`}>
                      {STATI[c.stato].t}{c.msg ? ` — ${c.msg}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* ───────── LIBRERIA ───────── */}
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
              <select value={filtro} onChange={e => cambiaFiltro(e.target.value)} aria-label="Filtro" className="fanta-input !w-auto cursor-pointer">
                <option value="tutte">Tutte</option>
                <option value="attive">Solo attive</option>
                <option value="inattive">Disattivate</option>
                <option value="senza_descrizione">Senza descrizione</option>
              </select>
              <form onSubmit={eseguiRicerca} className="flex gap-2 flex-1 min-w-[12rem]">
                <input type="search" value={cerca} onChange={e => setCerca(e.target.value)} placeholder="Cerca per descrizione, tag o persona"
                  aria-label="Cerca" className="fanta-input" />
                <button type="submit" aria-label="Cerca" className={BTN_SECONDARY}><Search className="w-4 h-4" /></button>
              </form>
            </div>

            {elenco && elenco.immagini.length === 0 && (
              <div className="card p-6 text-sm text-slate-400">Nessuna immagine in questa vista.</div>
            )}
            {elenco && elenco.immagini.length > 0 && (
              <ul className={`grid grid-cols-2 sm:grid-cols-3 gap-3 ${busy ? 'opacity-60' : ''}`}>
                {elenco.immagini.map(im => (
                  <li key={im.id}>
                    <button type="button" onClick={() => apri(im.id)} disabled={busy}
                      className={`card w-full text-left overflow-hidden hover:border-white/20 transition-colors ${!im.attivo ? 'opacity-60' : ''}`}>
                      {im.miniatura
                        ? <img src={im.miniatura} alt={im.descrizione || `Immagine ${im.id}`} className="w-full aspect-[4/3] object-cover" />
                        : <div className="w-full aspect-[4/3] flex items-center justify-center text-slate-600"><ImageOff className="w-6 h-6" /></div>}
                      <div className="p-3 space-y-1.5">
                        <p className="text-xs text-slate-300 leading-snug line-clamp-3 min-h-[2.4em]">
                          {im.descrizione || <em className="text-gold-400">senza descrizione</em>}
                        </p>
                        <div className="flex flex-wrap gap-1">
                          {im.origine_descrizione === 'ai' && im.descrizione && <span className="stat-pill text-grass-400">AI</span>}
                          {!im.attivo && <span className="stat-pill">Disattivata</span>}
                          {im.ultimo_utilizzo && <span className="stat-pill">usata il {dataEstesa(im.ultimo_utilizzo)}</span>}
                        </div>
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}

            {pagine > 1 && (
              <div className="flex items-center justify-center gap-3 text-xs text-slate-500">
                <button type="button" disabled={pagina <= 1 || busy} onClick={() => vaiPagina(pagina - 1)} className={BTN_SECONDARY}>Precedente</button>
                <span>Pagina {pagina} di {pagine} · {elenco.totale} immagini</span>
                <button type="button" disabled={pagina >= pagine || busy} onClick={() => vaiPagina(pagina + 1)} className={BTN_SECONDARY}>Successiva</button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ───────── MODIFICA ───────── */}
      {sbloccato && sel && form && (
        <div className="max-w-3xl space-y-4">
          <button type="button" onClick={chiudi} className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300">
            <ArrowLeft className="w-4 h-4" /> Torna alla libreria
          </button>

          {msg && <Avviso tono="green" icona={CheckCircle2}>{msg}</Avviso>}
          {errore && <Avviso tono="red">{errore}</Avviso>}

          <div className="card p-5 space-y-4">
            <img src={sel.immagine} alt={form.descrizione || `Immagine ${sel.id}`} className="w-full max-h-96 object-contain rounded-lg bg-black/30" />
            <p className="text-xs text-slate-500">
              n. {sel.id} · {sel.larghezza}×{sel.altezza} · caricata {dataEstesa(sel.data_caricamento)}{sel.caricata_da && ` da ${sel.caricata_da}`}
              {sel.usi > 0 && <> · <strong className="text-slate-300">usata in {sel.usi} articol{sel.usi === 1 ? 'o' : 'i'}</strong></>}
              {sel.origine_descrizione === 'ai' && sel.modello && <> · descrizione AI ({modelloEtichetta(sel.modello)})</>}
            </p>

            <div>
              <label htmlFor="ed-desc" className="text-xs text-slate-600 mb-1 block">Descrizione (cosa si vede: è ciò che l'AI legge)</label>
              <textarea id="ed-desc" rows={3} maxLength={600} value={form.descrizione}
                onChange={e => setForm(f => ({ ...f, descrizione: e.target.value }))} className="fanta-input" />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="sm:col-span-2">
                <label htmlFor="ed-tag" className="text-xs text-slate-600 mb-1 block">Tag (separati da virgola)</label>
                <input id="ed-tag" type="text" maxLength={300} value={form.tag}
                  onChange={e => setForm(f => ({ ...f, tag: e.target.value }))} className="fanta-input" />
              </div>
              <div>
                <label htmlFor="ed-tono" className="text-xs text-slate-600 mb-1 block">Tono</label>
                <select id="ed-tono" value={form.tono} onChange={e => setForm(f => ({ ...f, tono: e.target.value }))} className="fanta-input cursor-pointer">
                  {TONI.map(t => <option key={t} value={t}>{t || '—'}</option>)}
                </select>
              </div>
            </div>
            <div>
              <p className="text-xs text-slate-600 mb-1">Chi compare</p>
              <ChipUtenze utenze={info.utenze} selezionate={form.utenze} onToggle={toggleUtenza(setForm)} />
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" checked={form.attivo} onChange={e => setForm(f => ({ ...f, attivo: e.target.checked }))} />
              Attiva (può essere scelta dall'AI per gli articoli)
            </label>
          </div>

          <div className="card p-5 space-y-3">
            <h3 className="text-sm font-semibold text-slate-200 flex items-center gap-2"><Sparkles className="w-4 h-4 text-slate-400" /> Descrivi con l'AI (opzione C)</h3>
            <p className="text-xs text-slate-500">
              Sostituisce descrizione e tono con quelli proposti dall'AI (i tag si aggiungono). Puoi poi ritoccarli.
            </p>
            <div className="flex flex-wrap items-end gap-3">
              <div className="min-w-[12rem]">
                <label htmlFor="ed-modello" className="text-xs text-slate-600 mb-1 block">Modello</label>
                <select id="ed-modello" value={modelloDescr} onChange={e => setModelloDescr(e.target.value)} className="fanta-input cursor-pointer">
                  {info.modelli.map(m => <option key={m.modello} value={m.modello}>{m.etichetta}</option>)}
                </select>
              </div>
              <button type="button" disabled={busy || !info.chiave_configurata || !modelloDescr} onClick={descriviAi} className={BTN_SECONDARY}>
                {busy ? <Spinner size="sm" /> : <Sparkles className="w-4 h-4" />} Descrivi — circa <Importo usd={costoDescr} cambio={cambio} />
              </button>
            </div>
          </div>

          <div className="flex flex-wrap gap-3">
            <button type="button" disabled={busy} onClick={salva} className={BTN_PRIMARY}>
              {busy ? <Spinner size="sm" /> : <Save className="w-4 h-4" />} Salva
            </button>
            {!conferma ? (
              <button type="button" disabled={busy} onClick={() => setConferma(true)} className={BTN_DANGER}>
                <Trash2 className="w-4 h-4" /> Elimina
              </button>
            ) : (
              <button type="button" disabled={busy} onClick={elimina} className={BTN_DANGER}>
                <AlertTriangle className="w-4 h-4" /> Conferma eliminazione definitiva
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
