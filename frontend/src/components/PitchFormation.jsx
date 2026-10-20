// ── Pitch formation (modulo 3-4-3) ─────────────────────────────
import TeamLogo from './TeamLogo'
import { PitchField } from './PitchField'
import { Bandiera } from './Bandiera'

// Posizioni percentuali (x, y) sul campo per il modulo 3-4-3.
// y = 0 rete avversaria (attacco), y = 100 propria porta (difesa).
const FORMATION_SLOTS = {
  '1': [{ x: 50, y: 91 }],                                                    // portiere
  '2': [{ x: 18, y: 70 }, { x: 50, y: 74 }, { x: 82, y: 70 }],                 // difensori
  '3': [{ x: 12, y: 45 }, { x: 38, y: 49 }, { x: 62, y: 49 }, { x: 88, y: 45 }], // centrocampisti
  '4': [{ x: 18, y: 16 }, { x: 50, y: 11 }, { x: 82, y: 16 }],                 // attaccanti
}

function buildSlots(players) {
  const byRole = { '1': [], '2': [], '3': [], '4': [] }
  players?.forEach(p => byRole[String(p.ruolo)]?.push(p))

  const placed = []
  const leftovers = []
  Object.entries(FORMATION_SLOTS).forEach(([ruolo, slots]) => {
    const list = byRole[ruolo] || []
    slots.forEach((slot, i) => {
      if (list[i]) placed.push({ ...slot, player: list[i] })
    })
    if (list.length > slots.length) leftovers.push(...list.slice(slots.length))
  })
  return { placed, leftovers }
}

function PlayerToken({ player, isTop }) {
  return (
    <div className="flex flex-col items-center gap-1 w-16 sm:w-20">
      <div className={`rounded-lg ring-2 shadow-lg ${isTop ? 'ring-grass-300' : 'ring-red-400'}`}>
        <TeamLogo logo={player.logo} nome={player.squadra} size="sm" className="!max-w-[3.5rem] sm:!max-w-[4.5rem]" />
      </div>
      <span className="flex items-center justify-center gap-1 w-full min-w-0 text-[10px] leading-tight text-white font-medium [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
        <Bandiera id={player.id_giocatore} size="xs" />
        <span className="truncate">{player.giocatore}</span>
      </span>
      <span className={`text-[10px] font-mono font-bold ${isTop ? 'text-grass-300' : 'text-red-300'}`}>
        {Number(player.media).toFixed(2)}
      </span>
    </div>
  )
}

export function PitchFormation({ players, isTop }) {
  const { placed, leftovers } = buildSlots(players)

  return (
    <div>
      <PitchField centerDot>
        {placed.map(({ x, y, player }, i) => (
          <div
            key={i}
            className="absolute -translate-x-1/2 -translate-y-1/2"
            style={{ left: `${x}%`, top: `${y}%` }}
          >
            <PlayerToken player={player} isTop={isTop} />
          </div>
        ))}
      </PitchField>

      {leftovers.length > 0 && (
        <p className="text-[11px] text-slate-600 mt-2">
          Fuori formazione (3-4-3): {leftovers.map(p => p.giocatore).join(', ')}
        </p>
      )}
    </div>
  )
}
