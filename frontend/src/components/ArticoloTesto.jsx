// ============================================================
// components/ArticoloTesto.jsx
//
// Rende il testo di un articolo AI (testo semplice): i paragrafi sono
// separati da una riga vuota; una riga breve tutta in MAIUSCOLO è un
// intertitolo (es. "PAGELLE"). Usato dalla Gazzetta pubblica e
// dall'anteprima nella gestione articoli.
// ============================================================

const eIntertitolo = (b) =>
  !b.includes('\n') && b.length <= 80 && /\p{L}/u.test(b) && b === b.toUpperCase()

export default function ArticoloTesto({ testo }) {
  const blocchi = String(testo ?? '')
    .split(/\n{2,}/)
    .map(b => b.trim())
    .filter(Boolean)

  return (
    <div className="space-y-4 text-sm text-slate-300 leading-relaxed">
      {blocchi.map((b, i) =>
        eIntertitolo(b) ? (
          <h4 key={i} className="pt-2 text-sm font-bold tracking-wide text-grass-400">{b}</h4>
        ) : (
          <p key={i} className="whitespace-pre-line">{b}</p>
        )
      )}
    </div>
  )
}
