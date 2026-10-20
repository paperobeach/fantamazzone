import { useState, useEffect } from 'react'
import { useApp } from '../context/AppContext'

// Bandiera della nazionalità di un giocatore.
// La mappa id_giocatore → file bandiera arriva da AppContext (api/bandiere.php);
// i file si trovano in /public/flags/ e mantengono i nomi del pacchetto
// delle bandiere (es. "Brazilian.jpg", "Bosnian and Herzegovinian.jpg").
// Se il giocatore non ha una nazione nota, o il file non si carica, non
// viene mostrato nulla (nessun buco nel layout).

const SIZE = {
  xs: 'h-2.5 w-[0.875rem]',
  sm: 'h-3 w-4',
  md: 'h-4 w-[1.375rem]',
}

export function useBandieraFile(id) {
  const { bandiere } = useApp()
  if (id === null || id === undefined) return null
  return bandiere?.[id] ?? null
}

function urlBandiera(file) {
  return `${import.meta.env.BASE_URL}flags/${encodeURIComponent(file)}`
}

// Bandierina rettangolare, da affiancare al nome del giocatore.
export function Bandiera({ id, size = 'sm', className = '' }) {
  const file = useBandieraFile(id)
  const [failed, setFailed] = useState(false)
  useEffect(() => setFailed(false), [file])
  if (!file || failed) return null
  return (
    <img
      src={urlBandiera(file)}
      alt=""
      loading="lazy"
      draggable={false}
      onError={() => setFailed(true)}
      className={`${SIZE[size] ?? SIZE.sm} inline-block flex-shrink-0 rounded-[2px] object-cover ring-1 ring-white/20 ${className}`}
    />
  )
}

// Bandiera circolare che riempie il contenitore (gettone in campo, al posto
// del segnaposto del ruolo). Mostra `children` (il segnaposto) se manca.
export function BandieraTonda({ id, children }) {
  const file = useBandieraFile(id)
  const [failed, setFailed] = useState(false)
  useEffect(() => setFailed(false), [file])
  if (!file || failed) return children ?? null
  return (
    <img
      src={urlBandiera(file)}
      alt=""
      draggable={false}
      onError={() => setFailed(true)}
      className="w-full h-full rounded-full object-cover"
    />
  )
}

// Nome del giocatore preceduto dalla bandiera; il nome si tronca con i puntini.
// `className` riguarda il contenitore (colore, dimensione, flex-1, ...).
export function NomeConBandiera({ id, nome, className = '' }) {
  return (
    <span className={`flex items-center gap-1.5 min-w-0 ${className}`}>
      <Bandiera id={id} />
      <span className="truncate">{nome}</span>
    </span>
  )
}
