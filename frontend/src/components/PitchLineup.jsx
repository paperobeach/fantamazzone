// ── Formazione in sola lettura (campo + panchina) ───────────────
// Visualizza una formazione già inserita riusando gli stessi pezzi della
// pagina di inserimento: campo e gettoni (PitchField / PlayerChip),
// posizioni degli slot (lib/pitchLayout) e riga panchina (RosterRow).
// Il modulo è ricavato dai titolari (maglie 1-11), quindi funziona anche
// con formazioni incomplete.
import { PitchField, PlayerChip } from './PitchField'
import { RosterRow } from './FormationBuilder'
import { getSlotsForConteggio } from '../lib/pitchLayout'

// players: [{ id_giocatore, giocatore, ruolo, maglia, titolare }]
export function PitchLineup({ players }) {
  const ordinati = [...(players ?? [])]
    .sort((a, b) => a.maglia - b.maglia)
    .map(p => ({ id: p.id_giocatore, descrizione: p.giocatore, ruolo: p.ruolo, titolare: p.titolare }))

  const titolari = ordinati.filter(p => p.titolare)
  const panchina = ordinati.filter(p => !p.titolare)

  const perRuolo = { '1': [], '2': [], '3': [], '4': [] }
  titolari.forEach(p => perRuolo[String(p.ruolo)]?.push(p))
  const conteggio = { d: perRuolo['2'].length, c: perRuolo['3'].length, a: perRuolo['4'].length }
  const slots = getSlotsForConteggio(conteggio)

  const cursor = { '1': 0, '2': 0, '3': 0, '4': 0 }
  const placed = slots
    .map(slot => ({ slot, player: perRuolo[slot.ruolo][cursor[slot.ruolo]++] }))
    .filter(x => x.player)

  return (
    <div>
      <div className="relative">
        <PitchField centerDot>
          {placed.map(({ slot, player }) => (
            <div
              key={player.id}
              className="absolute -translate-x-1/2 -translate-y-1/2"
              style={{ left: `${slot.x}%`, top: `${slot.y}%` }}
            >
              <PlayerChip player={player} />
            </div>
          ))}
        </PitchField>
        {titolari.length > 0 && (
          <span className="absolute top-4 left-4 text-[10px] font-mono font-bold text-white/70 bg-black/30 rounded px-1.5 py-0.5">
            {conteggio.d}-{conteggio.c}-{conteggio.a}
          </span>
        )}
      </div>

      {titolari.length < 11 && (
        <p className="text-[11px] text-gold-400 mt-2">Formazione incompleta: {titolari.length}/11 titolari.</p>
      )}

      {panchina.length > 0 && (
        <div className="mt-3">
          <p className="text-[10px] uppercase tracking-widest text-slate-600 mb-1">Panchina</p>
          {panchina.map((p, i) => (
            <RosterRow
              key={p.id}
              player={p}
              index={i}
              total={panchina.length}
              displayNumber={12 + i}
              readOnly
            />
          ))}
        </div>
      )}
    </div>
  )
}
