import { useState, useEffect } from 'react'
import TeamLogo from './TeamLogo'
import { Link } from 'react-router-dom'
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
// Il nome è un link alla scheda del giocatore (/giocatori/:id). Poiché l'id è
// valido solo nella propria stagione, il link porta con sé la stagione:
// `stagione` (se omessa, quella scelta nell'app). `link={false}` disattiva il
// link (es. elenchi in cui il click sul nome ha già un altro significato).
// `multiriga` mostra nome e squadra per intero, andando a capo invece di troncare
// (es. menu di scelta del giocatore su mobile).
export function NomeConBandiera({ id, nome, className = '', link = true, stagione, logoSquadra, nomeSquadra, multiriga = false }) {
  const { stagione: stagioneApp } = useApp()
  const st = stagione ?? stagioneApp
  const cliccabile = link && Number(id) > 0 && !!st
  const cNome = multiriga ? 'break-words min-w-0' : 'truncate'
  return (
    <span className={`flex ${multiriga ? 'items-start' : 'items-center'} gap-1.5 min-w-0 ${className}`}>
      {logoSquadra !== undefined
        ? <TeamLogo logo={logoSquadra} nome={nomeSquadra} size="xs" />
        : <Bandiera id={id} />}
      {cliccabile ? (
        <Link
          to={`/giocatori/${id}?stagione=${st}`}
          onClick={e => e.stopPropagation()}
          className={`${cNome} hover:text-grass-400 hover:underline underline-offset-2 transition-colors`}
        >
          {nome}
        </Link>
      ) : (
        <span className={cNome}>{nome}</span>
      )}
      {nomeSquadra && <span className={`text-[10px] text-slate-500 ${multiriga ? 'break-words min-w-0' : 'truncate flex-shrink-[2]'}`}>· {nomeSquadra}</span>}
    </span>
  )
}
