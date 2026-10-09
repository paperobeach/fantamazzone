import { Link } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import TeamLogo from './TeamLogo'

// ── Componenti condivisi per mostrare il risultato di una partita ──
// Layout di riferimento: pagina Calendario. Usati da Calendario,
// LIVE Giornata e Champions così che le righe risultato siano uniformi.

// Calcola il segno (V/N/P) dai gol se non è fornito
function segnoDa(risultato) {
  if (risultato.segno) return risultato.segno
  const f = Number(risultato.golf), s = Number(risultato.gols)
  return f > s ? 'V' : f === s ? 'N' : 'P'
}

// ── Punteggio in gol (es. 2 : 1); risultato null = non disputata ──
export function ScoreBox({ risultato }) {
  if (!risultato) return (
    <div className="flex items-center gap-2">
      <span className="w-9 h-9 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-slate-600 text-base">—</span>
      <span className="text-slate-700 text-xs">:</span>
      <span className="w-9 h-9 rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center text-slate-600 text-base">—</span>
    </div>
  )
  const { golf, gols } = risultato
  const segno = segnoDa(risultato)
  return (
    <div className="flex items-center gap-2">
      <span className={`w-9 h-9 rounded-lg flex items-center justify-center text-base font-bold ${
        ['V', 'W'].includes(segno) ? 'bg-green-500/15 text-green-400' :
        segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                        'bg-pitch-800 text-slate-400'
      }`}>{golf}</span>
      <span className="text-slate-600 text-xs font-mono">:</span>
      <span className={`w-9 h-9 rounded-lg flex items-center justify-center text-base font-bold ${
        ['P', 'L'].includes(segno) ? 'bg-green-500/15 text-green-400' :
        segno === 'N' ? 'bg-yellow-500/15 text-yellow-400' :
                        'bg-pitch-800 text-slate-400'
      }`}>{gols}</span>
    </div>
  )
}

// ── Fantapunti di una squadra (evidenzia max/min della giornata) ──
export function PunteggioBadge({ valore, isMax, isMin }) {
  if (valore === null || valore === undefined) {
    return <span className="px-2.5 py-1 rounded-md font-mono text-sm bg-pitch-800 text-slate-600">–</span>
  }
  return (
    <span className={`px-2.5 py-1 rounded-md font-mono text-sm font-bold ${
      isMax ? 'bg-green-500/15 text-green-400 ring-1 ring-green-500/30' :
      isMin ? 'bg-red-500/15 text-red-400 ring-1 ring-red-500/30' :
              'bg-pitch-800 text-slate-300'
    }`}>
      {Number(valore).toFixed(1)}
    </span>
  )
}

function Squadra({ squadra, lato, stopClick }) {
  const inner = lato === 'casa' ? (
    <>
      <span className="text-sm text-slate-300 group-hover:text-grass-400 transition-colors font-medium text-right truncate">{squadra?.nome}</span>
      <TeamLogo logo={squadra?.logo} nome={squadra?.nome} size="sm" />
    </>
  ) : (
    <>
      <TeamLogo logo={squadra?.logo} nome={squadra?.nome} size="sm" />
      <span className="text-sm text-slate-300 group-hover:text-grass-400 transition-colors font-medium truncate">{squadra?.nome}</span>
    </>
  )
  const cls = `flex items-center gap-2 flex-1 group min-w-0 ${lato === 'casa' ? 'justify-end' : ''}`
  if (!squadra?.id) return <div className={cls}>{inner}</div>
  return <Link onClick={stopClick} to={`/squadre/${squadra.id}`} className={cls}>{inner}</Link>
}

// ── Riga partita uniforme ──
// Props:
//  casa, ospite        { id, nome, logo }
//  risultato           { golf, gols, segno?, ftotale_casa, ftotale_ospite } | null
//  maxScore/minScore   evidenziano i fantapunti più alto/basso della giornata
//  expandable, open, onToggle   riga espandibile (chevron)
//  centroExtra         contenuto aggiuntivo accanto al punteggio (es. etichetta Calc./Sim.)
//  disabled            riga attenuata e non cliccabile
export function MatchResultRow({
  casa, ospite, risultato, maxScore = null, minScore = null,
  expandable = false, open = false, onToggle, centroExtra = null, disabled = false,
}) {
  const stop = (e) => e.stopPropagation()
  const attiva = expandable && !disabled
  const haPunti = risultato && risultato.ftotale_casa !== undefined && risultato.ftotale_ospite !== undefined

  return (
    <div
      role={attiva ? 'button' : undefined}
      tabIndex={attiva ? 0 : undefined}
      onClick={attiva ? onToggle : undefined}
      onKeyDown={attiva ? (e) => (e.key === 'Enter' || e.key === ' ') && onToggle?.() : undefined}
      className={`flex items-center gap-2 sm:gap-4 py-3 px-4 transition-colors ${
        attiva ? 'cursor-pointer hover:bg-white/[0.02]' : ''
      } ${disabled ? 'opacity-60 cursor-default' : ''}`}
    >
      <Squadra squadra={casa} lato="casa" stopClick={stop} />

      <div className="flex-shrink-0 flex items-center gap-2">
        {centroExtra}
        <ScoreBox risultato={risultato} />
      </div>

      <Squadra squadra={ospite} lato="ospite" stopClick={stop} />

      {haPunti && (
        <div className="flex items-center gap-2 flex-shrink-0">
          <PunteggioBadge
            valore={risultato.ftotale_casa}
            isMax={maxScore !== null && Number(risultato.ftotale_casa) === maxScore}
            isMin={minScore !== null && Number(risultato.ftotale_casa) === minScore}
          />
          <span className="text-slate-700 text-xs hidden sm:inline">vs</span>
          <PunteggioBadge
            valore={risultato.ftotale_ospite}
            isMax={maxScore !== null && Number(risultato.ftotale_ospite) === maxScore}
            isMin={minScore !== null && Number(risultato.ftotale_ospite) === minScore}
          />
        </div>
      )}

      {expandable && (
        <ChevronDown className={`w-4 h-4 flex-shrink-0 transition-transform ${
          disabled ? 'text-slate-800' : 'text-slate-600'
        } ${open ? 'rotate-180' : ''}`} />
      )}
    </div>
  )
}
