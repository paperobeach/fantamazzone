// ── Campo da calcio + gettone giocatore (condivisi) ─────────────
// Usati da: inserimento formazione (FormationBuilder), Top/Flop 11
// (PitchFormation) e Dashboard squadra (PitchLineup).
import { ROLE_LABEL } from '../lib/pitchLayout'

/**
 * Cornice del campo con la segnaletica. I figli (gettoni, menu, ecc.) sono
 * posizionati in assoluto al suo interno. `className` e le altre props
 * (onDragOver, onDrop, ...) sono inoltrate al contenitore.
 */
export function PitchField({ children, className = '', centerDot = false, ...rest }) {
  return (
    <div
      {...rest}
      className={`relative w-full aspect-[3/4] rounded-xl overflow-hidden border border-white/10 ${className}`}
      style={{ background: 'repeating-linear-gradient(0deg, #123822 0px, #123822 36px, #0e2e1c 36px, #0e2e1c 72px)' }}
    >
      <div className="absolute inset-3 border border-white/25 rounded-sm" />
      <div className="absolute left-1/2 top-3 bottom-3 w-px bg-white/25 -translate-x-1/2" />
      <div className="absolute left-1/2 top-1/2 w-20 h-20 sm:w-24 sm:h-24 border border-white/25 rounded-full -translate-x-1/2 -translate-y-1/2" />
      {centerDot && (
        <div className="absolute left-1/2 top-1/2 w-1.5 h-1.5 rounded-full bg-white/25 -translate-x-1/2 -translate-y-1/2" />
      )}
      <div className="absolute left-1/2 bottom-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-b-0 -translate-x-1/2" />
      <div className="absolute left-1/2 top-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-t-0 -translate-x-1/2" />
      {children}
    </div>
  )
}

// Gettone giocatore (in campo): cerchio col ruolo + nome.
export function PlayerChip({ player, selected, highlight, dragProps }) {
  return (
    <div {...dragProps} className="flex flex-col items-center gap-1 select-none cursor-pointer w-16 sm:w-20">
      <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-[11px] font-bold border-2 shadow-lg transition-all
        ${selected ? 'ring-2 ring-grass-400 ring-offset-2 ring-offset-pitch-950' : ''}
        ${highlight && !selected ? 'ring-2 ring-gold-400/70 ring-offset-2 ring-offset-pitch-950' : ''}
        bg-grass-500/90 border-grass-300 text-pitch-950`}>
        {ROLE_LABEL[String(player.ruolo)] ?? '?'}
      </div>
      <span className="text-[10px] leading-tight text-center text-white font-medium truncate w-full [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
        {player.descrizione ?? player.giocatore}
      </span>
    </div>
  )
}
