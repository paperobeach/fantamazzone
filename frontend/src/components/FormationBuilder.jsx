import { useMemo, useState } from 'react'
import { X, GripVertical, RotateCcw } from 'lucide-react'
import { ROLE_LABEL, MODULI_VALIDI, getSlotsForModulo } from '../lib/pitchLayout'

// ── Gettone giocatore (in campo o in panchina) ─────────────────
function PlayerChip({ player, onClick, selected, small, dragProps }) {
  return (
    <div
      {...dragProps}
      onClick={onClick}
      className={`flex flex-col items-center gap-1 select-none cursor-pointer ${small ? 'w-16' : 'w-16 sm:w-20'}`}
    >
      <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-[11px] font-bold border-2 shadow-lg transition-all
        ${selected ? 'ring-2 ring-grass-400 ring-offset-2 ring-offset-pitch-950' : ''}
        bg-grass-500/90 border-grass-300 text-pitch-950`}>
        {ROLE_LABEL[String(player.ruolo)] ?? '?'}
      </div>
      <span className="text-[10px] leading-tight text-center text-white font-medium truncate w-full [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
        {player.descrizione ?? player.giocatore}
      </span>
    </div>
  )
}

// ── Slot vuoto/occupato in campo ────────────────────────────────
function PitchSlot({ x, y, ruolo, player, onDrop, onClick, selected, dragOverActive, onDragOver, onDragLeave }) {
  return (
    <div
      className="absolute -translate-x-1/2 -translate-y-1/2"
      style={{ left: `${x}%`, top: `${y}%` }}
      onDragOver={e => { e.preventDefault(); onDragOver?.() }}
      onDragLeave={onDragLeave}
      onDrop={e => { e.preventDefault(); onDrop() }}
      onClick={onClick}
    >
      {player ? (
        <div className="relative group">
          <PlayerChip player={player} selected={selected} dragProps={{
            draggable: true,
            onDragStart: e => e.dataTransfer.setData('text/plain', String(player.id)),
          }} />
          <span className="absolute -top-1 -right-1 hidden group-hover:flex w-4 h-4 rounded-full bg-red-500 items-center justify-center">
            <X className="w-2.5 h-2.5 text-white" />
          </span>
        </div>
      ) : (
        <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full border-2 border-dashed flex items-center justify-center text-[10px] font-mono
          ${dragOverActive ? 'border-grass-300 bg-grass-500/20 text-grass-200' : 'border-white/25 text-white/40'}`}>
          {ROLE_LABEL[ruolo]}
        </div>
      )}
    </div>
  )
}

/**
 * Editor formazione: modulo + campo (drag&drop / click) + panchina riordinabile.
 *
 * props:
 *  - rosa: [{ id, descrizione, ruolo }]  rosa completa della squadra
 *  - modulo, onModuloChange
 *  - titolariIds, panchinaIds: array di id (stato controllato dal parent)
 *  - onChange(titolariIds, panchinaIds): chiamato ad ogni modifica
 *  - readOnly: disabilita ogni interazione (es. giornata già chiusa)
 */
export function FormationBuilder({
  rosa = [],
  modulo,
  onModuloChange,
  titolariIds = [],
  panchinaIds = [],
  onChange,
  readOnly = false,
}) {
  const [selectedId, setSelectedId] = useState(null)
  const [dragOverSlot, setDragOverSlot] = useState(null)
  const [dragOverBenchIdx, setDragOverBenchIdx] = useState(null)

  const byId = useMemo(() => Object.fromEntries(rosa.map(p => [p.id, p])), [rosa])
  const slots = useMemo(() => getSlotsForModulo(modulo), [modulo])

  // Assegna ogni titolare allo slot del proprio ruolo, nell'ordine indicato
  const slotAssignment = useMemo(() => {
    const cursor = { '1': 0, '2': 0, '3': 0, '4': 0 }
    const titolariByRuolo = { '1': [], '2': [], '3': [], '4': [] }
    titolariIds.forEach(id => {
      const p = byId[id]
      if (p) titolariByRuolo[String(p.ruolo)]?.push(id)
    })
    return slots.map(slot => {
      const idx = cursor[slot.ruolo]++
      const id = titolariByRuolo[slot.ruolo]?.[idx] ?? null
      return { ...slot, playerId: id }
    })
  }, [slots, titolariIds, byId])

  const panchinaPlayers = panchinaIds.map(id => byId[id]).filter(Boolean)

  function commit(nextTitolari, nextPanchina) {
    onChange?.(nextTitolari, nextPanchina)
  }

  function placePlayer(playerId, slotIndex) {
    if (readOnly) return
    const player = byId[playerId]
    const slot = slotAssignment[slotIndex]
    if (!player || !slot) return
    if (String(player.ruolo) !== slot.ruolo) return // ruolo incompatibile

    const outgoingId = slot.playerId
    const nextTitolari = [...titolariIds]

    if (outgoingId === playerId) return // già lì

    // Rimuovi il giocatore in arrivo da dove si trovava
    let nextPanchina = panchinaIds.filter(id => id !== playerId)
    const wasIdx = nextTitolari.indexOf(playerId)
    if (wasIdx !== -1) nextTitolari.splice(wasIdx, 1)

    if (outgoingId) {
      // Sostituisce chi occupava lo slot: lo rimuove dai titolari e lo
      // rimette in panchina in cima
      const outIdx = nextTitolari.indexOf(outgoingId)
      if (outIdx !== -1) nextTitolari.splice(outIdx, 1)
      nextPanchina = [outgoingId, ...nextPanchina]
    }

    // Inserisce il nuovo titolare mantenendo il raggruppamento per ruolo,
    // nella posizione corrispondente allo slot scelto
    const ruoloPlayers = nextTitolari.filter(id => String(byId[id]?.ruolo) === slot.ruolo)
    const others = nextTitolari.filter(id => String(byId[id]?.ruolo) !== slot.ruolo)
    const posInRole = slotIndex - slots.findIndex(s => s.ruolo === slot.ruolo)
    ruoloPlayers.splice(Math.max(0, Math.min(posInRole, ruoloPlayers.length)), 0, playerId)

    // Ricompone rispettando l'ordine dei ruoli: P, D, C, A
    const merged = [
      ...ruoloPlayers.filter(id => String(byId[id]?.ruolo) === '1'),
      ...(slot.ruolo === '1' ? [] : others.filter(id => String(byId[id]?.ruolo) === '1')),
      ...(slot.ruolo === '2' ? ruoloPlayers : others.filter(id => String(byId[id]?.ruolo) === '2')),
      ...(slot.ruolo === '3' ? ruoloPlayers : others.filter(id => String(byId[id]?.ruolo) === '3')),
      ...(slot.ruolo === '4' ? ruoloPlayers : others.filter(id => String(byId[id]?.ruolo) === '4')),
    ]

    commit(merged, nextPanchina)
    setSelectedId(null)
  }

  function removeFromSlot(slotIndex) {
    if (readOnly) return
    const slot = slotAssignment[slotIndex]
    if (!slot?.playerId) return
    const nextTitolari = titolariIds.filter(id => id !== slot.playerId)
    commit(nextTitolari, [slot.playerId, ...panchinaIds])
    setSelectedId(null)
  }

  function handleSlotClick(slotIndex) {
    if (readOnly) return
    const slot = slotAssignment[slotIndex]
    if (selectedId) {
      placePlayer(selectedId, slotIndex)
    } else if (slot.playerId) {
      removeFromSlot(slotIndex)
    }
  }

  function handleBenchClick(id) {
    if (readOnly) return
    setSelectedId(cur => (cur === id ? null : id))
  }

  // ── Riordino panchina (drag & drop nativo) ──
  function reorderPanchina(fromId, toIndex) {
    if (readOnly) return
    const cur = panchinaIds.filter(id => id !== fromId)
    cur.splice(toIndex, 0, fromId)
    commit(titolariIds, cur)
  }

  const moduloAttesoOk = slotAssignment.every(s => !!s.playerId)

  return (
    <div className="space-y-5">
      {/* Selettore modulo */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs text-slate-600 font-mono uppercase tracking-widest mr-1">Modulo</span>
        {MODULI_VALIDI.map(m => (
          <button
            key={m}
            type="button"
            disabled={readOnly}
            onClick={() => onModuloChange?.(m)}
            className={`px-3 py-1.5 rounded-lg text-sm font-mono font-semibold transition-all ${
              modulo === m
                ? 'bg-grass-500 text-pitch-950'
                : 'bg-pitch-800 text-slate-400 hover:text-slate-200 border border-white/10'
            } disabled:opacity-40 disabled:cursor-not-allowed`}
          >
            {m}
          </button>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-6">
        {/* Campo */}
        <div>
          <div
            className="relative w-full aspect-[3/4] rounded-xl overflow-hidden border border-white/10"
            style={{ background: 'repeating-linear-gradient(0deg, #123822 0px, #123822 36px, #0e2e1c 36px, #0e2e1c 72px)' }}
            onDragOver={e => e.preventDefault()}
            onDrop={e => {
              e.preventDefault()
              const id = Number(e.dataTransfer.getData('text/plain'))
              if (id && dragOverSlot !== null) placePlayer(id, dragOverSlot)
              setDragOverSlot(null)
            }}
          >
            <div className="absolute inset-3 border border-white/25 rounded-sm" />
            <div className="absolute left-1/2 top-3 bottom-3 w-px bg-white/25 -translate-x-1/2" />
            <div className="absolute left-1/2 top-1/2 w-20 h-20 sm:w-24 sm:h-24 border border-white/25 rounded-full -translate-x-1/2 -translate-y-1/2" />
            <div className="absolute left-1/2 bottom-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-b-0 -translate-x-1/2" />
            <div className="absolute left-1/2 top-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-t-0 -translate-x-1/2" />

            {slotAssignment.map((slot, i) => (
              <PitchSlot
                key={i}
                x={slot.x} y={slot.y} ruolo={slot.ruolo}
                player={slot.playerId ? byId[slot.playerId] : null}
                selected={selectedId === slot.playerId}
                dragOverActive={dragOverSlot === i}
                onDragOver={() => setDragOverSlot(i)}
                onDragLeave={() => setDragOverSlot(cur => (cur === i ? null : cur))}
                onDrop={() => { if (selectedId) placePlayer(selectedId, i) }}
                onClick={() => handleSlotClick(i)}
              />
            ))}
          </div>
          {!moduloAttesoOk && (
            <p className="text-[11px] text-gold-400 mt-2">
              Completa tutti gli slot del modulo {modulo} per poter salvare.
            </p>
          )}
          {selectedId && (
            <p className="text-[11px] text-grass-400 mt-2">
              Giocatore selezionato: {byId[selectedId]?.descrizione}. Clicca uno slot compatibile per posizionarlo.
            </p>
          )}
        </div>

        {/* Panchina */}
        <div className="card overflow-hidden flex flex-col">
          <div className="px-4 py-3 border-b border-white/5 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-300">Panchina</h3>
            <span className="text-[10px] text-slate-600 font-mono">{panchinaPlayers.length} giocatori</span>
          </div>
          <div className="p-2 space-y-1 overflow-y-auto max-h-[520px]">
            {panchinaPlayers.length === 0 && (
              <p className="text-xs text-slate-600 px-2 py-4 text-center">Nessun giocatore in panchina</p>
            )}
            {panchinaPlayers.map((p, idx) => (
              <div
                key={p.id}
                draggable={!readOnly}
                onDragStart={e => e.dataTransfer.setData('text/plain', String(p.id))}
                onDragOver={e => { e.preventDefault(); setDragOverBenchIdx(idx) }}
                onDragLeave={() => setDragOverBenchIdx(cur => (cur === idx ? null : cur))}
                onDrop={e => {
                  e.preventDefault()
                  const draggedId = Number(e.dataTransfer.getData('text/plain'))
                  if (draggedId && panchinaIds.includes(draggedId)) reorderPanchina(draggedId, idx)
                  setDragOverBenchIdx(null)
                }}
                onClick={() => handleBenchClick(p.id)}
                className={`flex items-center gap-2 px-2 py-2 rounded-lg cursor-pointer transition-colors border
                  ${selectedId === p.id ? 'border-grass-500/50 bg-grass-500/10' : 'border-transparent hover:bg-white/[0.03]'}
                  ${dragOverBenchIdx === idx ? 'border-t-2 border-t-grass-400' : ''}`}
              >
                {!readOnly && <GripVertical className="w-3.5 h-3.5 text-slate-700 flex-shrink-0" />}
                <span className="text-[9px] font-mono w-4 text-slate-600 flex-shrink-0">{idx + 1}</span>
                <span className={`stat-pill ${
                  p.ruolo == 1 ? 'badge-role-p' : p.ruolo == 2 ? 'badge-role-d' : p.ruolo == 3 ? 'badge-role-c' : 'badge-role-a'
                }`}>{ROLE_LABEL[String(p.ruolo)]}</span>
                <span className="text-sm text-slate-300 truncate flex-1">{p.descrizione}</span>
              </div>
            ))}
          </div>
          <p className="text-[10px] text-slate-600 px-3 py-2 border-t border-white/5">
            Trascina i giocatori per riordinare la panchina (ordine di subentro).
          </p>
        </div>
      </div>

      {!readOnly && (
        <button
          type="button"
          onClick={() => commit([], rosa.map(p => p.id))}
          className="btn-ghost text-xs"
        >
          <RotateCcw className="w-3.5 h-3.5" /> Svuota il campo
        </button>
      )}
    </div>
  )
}
