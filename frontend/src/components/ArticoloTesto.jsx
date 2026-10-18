import { urlImmagine } from '../api/client'

// ============================================================
// components/ArticoloTesto.jsx
//
// Rende il testo di un articolo AI (testo semplice): i paragrafi sono
// separati da una riga vuota; una riga breve tutta in MAIUSCOLO è un
// intertitolo (es. "PAGELLE"); una riga [IMG:12|didascalia] è un'immagine
// della libreria della Gazzetta (didascalia opzionale). Usato dalla
// Gazzetta pubblica e dall'anteprima nella gestione articoli.
//
// variante: 'scuro' (default, tema del sito) | 'giornale' (inchiostro su
// carta, per l'edizione sfogliabile: classi .gz-* di index.css)
// ============================================================

const eIntertitolo = (b) =>
  !b.includes('\n') && b.length <= 80 && /\p{L}/u.test(b) && b === b.toUpperCase()

const RE_IMG = /^\[IMG:\s*(\d+)\s*(?:\|\s*([^\]]*))?\]$/

export default function ArticoloTesto({ testo, variante = 'scuro' }) {
  const giornale = variante === 'giornale'
  const blocchi = String(testo ?? '')
    .split(/\n{2,}/)
    .map(b => b.trim())
    .filter(Boolean)

  return (
    <div className={giornale ? '' : 'space-y-4 text-sm text-slate-300 leading-relaxed'}>
      {blocchi.map((b, i) => {
        const img = RE_IMG.exec(b)
        if (img) {
          const dida = (img[2] ?? '').trim()
          return giornale ? (
            <figure key={i} className="gz-fig" style={{ breakInside: 'avoid' }}>
              <img src={urlImmagine(img[1])} alt={dida} loading="lazy" />
              {dida && <figcaption>{dida}</figcaption>}
            </figure>
          ) : (
            <figure key={i} className="my-2">
              <img src={urlImmagine(img[1])} alt={dida} loading="lazy"
                className="w-full max-h-96 object-cover rounded-lg border border-white/10" />
              {dida && <figcaption className="text-xs italic text-slate-500 mt-1.5">{dida}</figcaption>}
            </figure>
          )
        }
        if (eIntertitolo(b)) {
          return giornale
            ? <h4 key={i} className="gz-intertitolo">{b}</h4>
            : <h4 key={i} className="pt-2 text-sm font-bold tracking-wide text-grass-400">{b}</h4>
        }
        return <p key={i} className="whitespace-pre-line">{b}</p>
      })}
    </div>
  )
}
