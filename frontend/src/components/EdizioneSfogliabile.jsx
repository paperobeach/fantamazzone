import { useCallback, useEffect, useRef, useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { urlImmagine } from '../api/client'
import ArticoloTesto from './ArticoloTesto'

// ============================================================
// components/EdizioneSfogliabile.jsx
//
// "La Gazzetta del Fantamazzone" in edizione sfogliabile: una copertina,
// una pagina per ogni partita (con tabellino grafico preso dai dati, non
// dal testo dell'AI) e una pagina finale di commento con classifica.
//
// Si sfoglia con lo swipe (scroll orizzontale con aggancio alle pagine),
// con i pulsanti, con le frecce della tastiera o dall'indice in copertina.
// Nessuna libreria esterna.
//
// Props:
//   edizione = { titolo, sommario, stagione, giornata, data_pubblicazione,
//                id_immagine_copertina, pagine[{ tipo, competizione, titolo,
//                testo, id_immagine, didascalia, dati }] }
// ============================================================

const num = (n) =>
  n == null || n === '' ? '–' : Number(n).toLocaleString('it-IT', { maximumFractionDigits: 2 })

const dataLunga = (s) => {
  if (!s) return ''
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

function Foto({ id, didascalia }) {
  if (!id) return null
  return (
    <figure className="gz-fig">
      <img src={urlImmagine(id)} alt={didascalia || ''} loading="lazy" />
      {didascalia && <figcaption>{didascalia}</figcaption>}
    </figure>
  )
}

function elencoMarcatori(m) {
  return (m ?? []).map(x => `${x.giocatore}${x.gol > 1 ? ` (${x.gol})` : ''}`).join(', ')
}

function Copertina({ ed, pagine, vai }) {
  return (
    <>
      <div className="gz-testata">La Gazzetta del Fantamazzone</div>
      <div className="gz-striscia">
        <span>Stagione {ed.stagione}</span>
        <span>Giornata {ed.giornata}</span>
        {ed.data_pubblicazione && <span>{dataLunga(ed.data_pubblicazione)}</span>}
      </div>

      <h1 className="gz-titolone">{ed.titolo}</h1>
      {ed.sommario && <p className="gz-occhiello">{ed.sommario}</p>}
      <Foto id={ed.id_immagine_copertina} />

      <div className="gz-box">
        <h3>In questo numero</h3>
        <ol className="gz-indice">
          {pagine.map((p, i) => (
            <li key={i}>
              <button type="button" onClick={() => vai(i + 1)}>
                <span>{p.tipo === 'commento' ? 'Il commento e le pagelle' : (p.dati?.casa ? `${p.dati.casa} – ${p.dati.ospite}` : p.titolo)}</span>
                <span className="gz-pag">pag. {i + 2}</span>
              </button>
            </li>
          ))}
        </ol>
      </div>
    </>
  )
}

function PaginaPartita({ p, giornata }) {
  const d = p.dati ?? {}
  const champions = d.competizione === 'champions'
  const marcCasa = elencoMarcatori(d.marcatori_casa)
  const marcOsp = elencoMarcatori(d.marcatori_ospite)
  return (
    <>
      <div className="gz-rubrica">
        {champions ? `Champions${d.girone ? ` · Girone ${d.girone}` : ''}` : 'Campionato'} · Giornata {giornata}
      </div>

      {d.casa && (
        <div
          className="gz-tabellino"
          role="img"
          aria-label={`Risultato: ${d.casa} ${d.gol_casa}, ${d.ospite} ${d.gol_ospite}`}
        >
          <div className="gz-sq">{d.casa}<small>{num(d.punteggio_casa)} pt</small></div>
          <div className="gz-risultato">{d.gol_casa}<span>–</span>{d.gol_ospite}</div>
          <div className="gz-sq">{d.ospite}<small>{num(d.punteggio_ospite)} pt</small></div>
        </div>
      )}
      {(marcCasa || marcOsp) && (
        <p className="gz-marcatori">
          <strong>Marcatori</strong>
          {marcCasa && <> · {d.casa}: {marcCasa}</>}
          {marcOsp && <> · {d.ospite}: {marcOsp}</>}
        </p>
      )}

      <h2 className="gz-titolo">{p.titolo}</h2>
      <Foto id={p.id_immagine} didascalia={p.didascalia} />
      <div className="gz-colonne gz-capolettera">
        <ArticoloTesto testo={p.testo} variante="giornale" />
      </div>
    </>
  )
}

function PaginaCommento({ p, giornata }) {
  const d = p.dati ?? {}
  const classifica = Array.isArray(d.classifica) ? d.classifica : []
  const r = d.riepilogo ?? {}
  return (
    <>
      <div className="gz-rubrica">Il commento · Giornata {giornata}</div>
      <h2 className="gz-titolo">{p.titolo}</h2>
      <Foto id={p.id_immagine} didascalia={p.didascalia} />

      {(r.miglior_punteggio || r.peggior_punteggio) && (
        <div className="gz-box">
          <h3>Il tabellino della giornata</h3>
          <table>
            <tbody>
              {r.miglior_punteggio && (
                <tr><td>Miglior punteggio</td><td>{r.miglior_punteggio.squadra}</td><td className="n">{num(r.miglior_punteggio.punti)}</td></tr>
              )}
              {r.peggior_punteggio && (
                <tr><td>Peggior punteggio</td><td>{r.peggior_punteggio.squadra}</td><td className="n">{num(r.peggior_punteggio.punti)}</td></tr>
              )}
              {r.partita_piu_netta && (
                <tr><td>Più netta</td><td>{r.partita_piu_netta.partita}</td><td className="n">{num(r.partita_piu_netta.scarto_punti)}</td></tr>
              )}
              {r.partita_piu_tirata && (
                <tr><td>Più tirata</td><td>{r.partita_piu_tirata.partita}</td><td className="n">{num(r.partita_piu_tirata.scarto_punti)}</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <div className="gz-colonne gz-capolettera">
        <ArticoloTesto testo={p.testo} variante="giornale" />
      </div>

      {d.classifica_inclusa && classifica.length > 0 && (
        <div className="gz-box">
          <h3>La classifica</h3>
          <table>
            <thead>
              <tr><th className="n">#</th><th>Squadra</th><th className="n">G</th><th className="n">Pt</th></tr>
            </thead>
            <tbody>
              {classifica.map(c => (
                <tr key={c.pos}>
                  <td className="n">{c.pos}</td><td>{c.squadra}</td>
                  <td className="n">{c.giocate}</td><td className="n"><strong>{c.punti}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

export default function EdizioneSfogliabile({ edizione }) {
  const pagine = edizione.pagine ?? []
  const totale = pagine.length + 1           // + copertina
  const scroller = useRef(null)
  const raf = useRef(0)
  const [corrente, setCorrente] = useState(0)

  const vai = useCallback((i) => {
    const el = scroller.current
    if (!el) return
    const n = Math.max(0, Math.min(totale - 1, i))
    const ridotto = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    el.scrollTo({ left: n * el.clientWidth, behavior: ridotto ? 'auto' : 'smooth' })
  }, [totale])

  const onScroll = () => {
    cancelAnimationFrame(raf.current)
    raf.current = requestAnimationFrame(() => {
      const el = scroller.current
      if (el && el.clientWidth) setCorrente(Math.round(el.scrollLeft / el.clientWidth))
    })
  }

  // Frecce della tastiera (non quando si sta scrivendo in un campo)
  useEffect(() => {
    const onKey = (e) => {
      if (e.target?.closest?.('input, textarea, select, [contenteditable="true"]')) return
      if (e.key === 'ArrowRight') vai(corrente + 1)
      else if (e.key === 'ArrowLeft') vai(corrente - 1)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [corrente, vai])

  // Se cambia la larghezza (rotazione, resize) resto sulla stessa pagina
  useEffect(() => {
    const onResize = () => {
      const el = scroller.current
      if (el) el.scrollTo({ left: corrente * el.clientWidth, behavior: 'auto' })
    }
    window.addEventListener('resize', onResize)
    return () => { window.removeEventListener('resize', onResize); cancelAnimationFrame(raf.current) }
  }, [corrente])

  const controlli = (className = '') => (
    <div className={`flex items-center justify-between gap-3 ${className}`}>
      <button
        type="button" onClick={() => vai(corrente - 1)} disabled={corrente === 0}
        aria-label="Pagina precedente"
        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-white/10 text-slate-300
                   hover:text-white hover:border-white/20 text-sm disabled:opacity-30 disabled:cursor-not-allowed"
      >
        <ChevronLeft className="w-4 h-4" /> <span className="hidden sm:inline">Indietro</span>
      </button>
      <div className="flex items-center gap-1.5" role="group" aria-label="Pagine">
        {Array.from({ length: totale }, (_, i) => (
          <button
            key={i} type="button" onClick={() => vai(i)}
            aria-label={`Vai a pagina ${i + 1}`} aria-current={i === corrente ? 'page' : undefined}
            className={`h-2 rounded-full transition-all ${i === corrente ? 'w-6 bg-grass-400' : 'w-2 bg-white/20 hover:bg-white/40'}`}
          />
        ))}
      </div>
      <button
        type="button" onClick={() => vai(corrente + 1)} disabled={corrente >= totale - 1}
        aria-label="Pagina successiva"
        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-white/10 text-slate-300
                   hover:text-white hover:border-white/20 text-sm disabled:opacity-30 disabled:cursor-not-allowed"
      >
        <span className="hidden sm:inline">Avanti</span> <ChevronRight className="w-4 h-4" />
      </button>
    </div>
  )

  return (
    <div className="gz">
      {controlli('mb-3')}
      <p className="text-center text-xs text-slate-500 mb-3" aria-live="polite">
        Pagina {corrente + 1} di {totale} · scorri di lato o usa le frecce
      </p>

      <div
        ref={scroller} onScroll={onScroll} className="gz-scroller"
        role="region" aria-roledescription="giornale sfogliabile" aria-label={edizione.titolo}
      >
        <section className="gz-page" aria-roledescription="pagina" aria-label={`Pagina 1 di ${totale}: copertina`}>
          <Copertina ed={edizione} pagine={pagine} vai={vai} />
          <div className="gz-piede">— 1 —</div>
        </section>

        {pagine.map((p, i) => (
          <section
            key={i} className="gz-page" aria-roledescription="pagina"
            aria-label={`Pagina ${i + 2} di ${totale}: ${p.titolo}`}
          >
            {p.tipo === 'commento'
              ? <PaginaCommento p={p} giornata={edizione.giornata} />
              : <PaginaPartita p={p} giornata={edizione.giornata} />}
            <div className="gz-piede">— {i + 2} —</div>
          </section>
        ))}
      </div>

      {controlli('mt-3')}
    </div>
  )
}
