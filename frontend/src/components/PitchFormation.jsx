// ── Pitch formation (modulo 3-4-3) ─────────────────────────────
const ROLE_LABEL = { '1': 'P', '2': 'D', '3': 'C', '4': 'A' }

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
      <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-[11px] font-bold border-2 shadow-lg ${
        isTop
          ? 'bg-grass-500/90 border-grass-300 text-pitch-950'
          : 'bg-red-500/90 border-red-300 text-white'
      }`}>
        {ROLE_LABEL[String(player.ruolo)] ?? '?'}
      </div>
      <span className="text-[10px] leading-tight text-center text-white font-medium truncate w-full [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
        {player.giocatore}
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
      <div
        className="relative w-full aspect-[3/4] rounded-xl overflow-hidden border border-white/10"
        style={{
          background: 'repeating-linear-gradient(0deg, #123822 0px, #123822 36px, #0e2e1c 36px, #0e2e1c 72px)',
        }}
      >
        {/* segnaletica campo */}
        <div className="absolute inset-3 border border-white/25 rounded-sm" />
        <div className="absolute left-1/2 top-3 bottom-3 w-px bg-white/25 -translate-x-1/2" />
        <div className="absolute left-1/2 top-1/2 w-20 h-20 sm:w-24 sm:h-24 border border-white/25 rounded-full -translate-x-1/2 -translate-y-1/2" />
        <div className="absolute left-1/2 top-1/2 w-1.5 h-1.5 rounded-full bg-white/25 -translate-x-1/2 -translate-y-1/2" />
        <div className="absolute left-1/2 bottom-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-b-0 -translate-x-1/2" />
        <div className="absolute left-1/2 top-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-t-0 -translate-x-1/2" />

        {placed.map(({ x, y, player }, i) => (
          <div
            key={i}
            className="absolute -translate-x-1/2 -translate-y-1/2"
            style={{ left: `${x}%`, top: `${y}%` }}
          >
            <PlayerToken player={player} isTop={isTop} />
          </div>
        ))}
      </div>

      {leftovers.length > 0 && (
        <p className="text-[11px] text-slate-600 mt-2">
          Fuori formazione (3-4-3): {leftovers.map(p => p.giocatore).join(', ')}
        </p>
      )}
    </div>
  )
}
