import { useMemo, useState } from 'react'
import { X, GripVertical, RotateCcw, ChevronUp, ChevronDown, Armchair, ArrowRight, ArrowLeft } from 'lucide-react'
import { ROLE_LABEL, MODULI_VALIDI, getSlotsForModulo, conteggioAtteso } from '../lib/pitchLayout'

const ROLE_PILL_CLASS = {
  1: 'badge-role-p', 2: 'badge-role-d', 3: 'badge-role-c', 4: 'badge-role-a',
}

// ── Gettone giocatore (in campo, in panchina o in tribuna) ──────
function PlayerChip({ player, selected, highlight, dragProps }) {
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

// ── Slot vuoto/occupato in campo ────────────────────────────────
function PitchSlot({
  x, y, ruolo, player, onClick, selected,
  compatible, dragOverActive, onDragOver, onDragLeave,
  onPlayerDragStart, onPlayerDragEnd,
}) {
  return (
    <div
      className="absolute -translate-x-1/2 -translate-y-1/2 w-14 h-14 sm:w-16 sm:h-16 flex items-center justify-center cursor-pointer"
      style={{ left: `${x}%`, top: `${y}%` }}
      onDragOver={e => { e.preventDefault(); onDragOver?.() }}
      onDragLeave={onDragLeave}
      onClick={onClick}
    >
      {player ? (
        <div className="relative group">
          <PlayerChip player={player} selected={selected} highlight={compatible} dragProps={{
            draggable: true,
            onDragStart: e => {
              e.dataTransfer.setData('text/plain', String(player.id))
              onPlayerDragStart?.(player.id)
            },
            onDragEnd: () => onPlayerDragEnd?.(),
          }} />
          <span className="absolute -top-1 -right-1 hidden group-hover:flex w-4 h-4 rounded-full bg-red-500 items-center justify-center">
            <X className="w-2.5 h-2.5 text-white" />
          </span>
        </div>
      ) : (
        <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full border-2 flex items-center justify-center text-[10px] font-mono transition-colors
          ${dragOverActive
            ? 'border-solid border-grass-300 bg-grass-500/25 text-grass-100'
            : compatible
              ? 'border-dashed border-gold-400/70 bg-gold-500/10 text-gold-200/80'
              : 'border-dashed border-white/25 text-white/40'}`}>
          {ROLE_LABEL[ruolo]}
        </div>
      )}
    </div>
  )
}

// ── Riga di un elenco riordinabile (panchina o tribuna) ─────────
// Componente condiviso: entrambi gli elenchi sono liste di giocatori
// trascinabili/riordinabili con lo stesso comportamento; cambia solo la
// provenienza dei dati e l'azione rapida per spostarsi nell'altro elenco.
function RosterRow({
  player, index, total, selected, dragged, readOnly,
  onDragStart, onDragOverSwap, onDragEnd, onClick,
  onMoveUp, onMoveDown, onMoveOther, moveOtherIcon: MoveOtherIcon, moveOtherTitle,
}) {
  return (
    <div
      draggable={!readOnly}
      onDragStart={onDragStart}
      onDragOver={onDragOverSwap}
      onDrop={e => e.preventDefault()}
      onDragEnd={onDragEnd}
      onClick={onClick}
      className={`flex items-center gap-2 px-2 py-2 rounded-lg cursor-pointer transition-colors border
        ${selected ? 'border-grass-500/50 bg-grass-500/10' : 'border-transparent hover:bg-white/[0.03]'}
        ${dragged ? 'opacity-50' : ''}`}
    >
      {!readOnly && <GripVertical className="w-3.5 h-3.5 text-slate-700 flex-shrink-0" />}
      <span className="text-[9px] font-mono w-4 text-slate-600 flex-shrink-0">{index + 1}</span>
      <span className={`stat-pill ${ROLE_PILL_CLASS[player.ruolo] ?? ''}`}>{ROLE_LABEL[String(player.ruolo)]}</span>
      <span className="text-sm text-slate-300 truncate flex-1">{player.descrizione}</span>

      {!readOnly && (
        <div className="flex items-center flex-shrink-0">
          {onMoveOther && (
            <button
              type="button"
              title={moveOtherTitle}
              onClick={e => { e.stopPropagation(); onMoveOther() }}
              className="text-slate-600 hover:text-gold-300 p-0.5"
            >
              <MoveOtherIcon className="w-3.5 h-3.5" />
            </button>
          )}
          <div className="flex flex-col -my-1">
            <button
              type="button"
              title="Sposta su"
              disabled={index === 0}
              onClick={e => { e.stopPropagation(); onMoveUp() }}
              className="text-slate-600 hover:text-slate-300 disabled:opacity-20 disabled:hover:text-slate-600"
            >
              <ChevronUp className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              title="Sposta giù"
              disabled={index === total - 1}
              onClick={e => { e.stopPropagation(); onMoveDown() }}
              className="text-slate-600 hover:text-slate-300 disabled:opacity-20 disabled:hover:text-slate-600"
            >
              <ChevronDown className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Editor formazione: modulo + campo (drag&drop / click) + panchina e
 * tribuna riordinabili.
 *
 * Modello dati interno: gli slot in campo sono un array a posizione FISSA
 * (allineato 1:1 a `getSlotsForModulo(modulo)`); ogni slot contiene
 * `null` oppure l'id di un giocatore. Un giocatore esiste sempre in uno
 * ed un solo posto tra questi tre: uno slot di `slotPlayers`, l'array
 * `panchinaIds`, oppure l'array `tribunaIds` (i giocatori che l'utente
 * non vuole schierare né tenere in panchina per la giornata). Ogni
 * operazione (posiziona / rimuovi / riordina / sposta) rimuove
 * esplicitamente l'id dalla sua posizione precedente prima di inserirlo
 * nella nuova, così le tre liste non possono mai sovrapporsi.
 *
 * props:
 *  - rosa: [{ id, descrizione, ruolo }]  rosa completa della squadra
 *  - modulo, onModuloChange
 *  - titolariIds, panchinaIds, tribunaIds: array di id (stato controllato dal parent)
 *  - onChange(titolariIds, panchinaIds, tribunaIds): chiamato ad ogni modifica
 *  - readOnly: disabilita ogni interazione (es. giornata già chiusa)
 */
export function FormationBuilder({
  rosa = [],
  modulo,
  onModuloChange,
  titolariIds = [],
  panchinaIds = [],
  tribunaIds = [],
  onChange,
  readOnly = false,
}) {
  const [selectedId, setSelectedId] = useState(null)
  const [dragOverSlot, setDragOverSlot] = useState(null)
  const [draggedBenchIdx, setDraggedBenchIdx] = useState(null)
  const [draggedTribunaIdx, setDraggedTribunaIdx] = useState(null)
  const [draggingId, setDraggingId] = useState(null) // id del giocatore attualmente trascinato (campo, panchina o tribuna)
  const [dropdownSlot, setDropdownSlot] = useState(null) // indice dello slot con il menu a tendina aperto

  const byId = useMemo(() => Object.fromEntries(rosa.map(p => [p.id, p])), [rosa])
  const slots = useMemo(() => getSlotsForModulo(modulo), [modulo])

  // Giocatore "attivo" ai fini dell'evidenziazione delle posizioni compatibili:
  // quello che si sta trascinando, oppure quello selezionato con un click
  // in panchina o in tribuna.
  const activePlayer = byId[draggingId] ?? byId[selectedId] ?? null

  // Assegna ogni titolare allo slot del proprio ruolo, nell'ordine in cui
  // compare in titolariIds (che è sempre mantenuto già ordinato per ruolo)
  const slotPlayers = useMemo(() => {
    const cursor = { '1': 0, '2': 0, '3': 0, '4': 0 }
    const byRuolo = { '1': [], '2': [], '3': [], '4': [] }
    titolariIds.forEach(id => {
      const p = byId[id]
      if (p) byRuolo[String(p.ruolo)]?.push(id)
    })
    return slots.map(slot => byRuolo[slot.ruolo]?.[cursor[slot.ruolo]++] ?? null)
  }, [slots, titolariIds, byId])

  const panchinaPlayers = panchinaIds.map(id => byId[id]).filter(Boolean)
  const tribunaPlayers = tribunaIds.map(id => byId[id]).filter(Boolean)

  function commit(nextSlotPlayers, nextPanchina, nextTribuna) {
    onChange?.(nextSlotPlayers.filter(Boolean), nextPanchina, nextTribuna)
  }

  // Risale al giocatore trascinato confrontando le stringhe (e non
  // forzando l'id a Number): nella rosa l'id può essere un numero o una
  // stringa numerica a seconda di come lo restituisce l'API. Convertirlo
  // sempre a Number romperebbe i confronti === usati per toglierlo dalla
  // lista di provenienza quando i due tipi non coincidono, lasciando il
  // giocatore duplicato in più elenchi.
  function resolveDraggedPlayer(e) {
    const raw = e.dataTransfer.getData('text/plain')
    return rosa.find(p => String(p.id) === raw) ?? null
  }

  // ── Posiziona un giocatore (da panchina, tribuna o da un altro slot) ──
  function placePlayer(playerId, slotIndex) {
    if (readOnly) return
    const player = byId[playerId]
    const slot = slots[slotIndex]
    if (!player || !slot) return
    if (String(player.ruolo) !== slot.ruolo) return // ruolo incompatibile
    if (slotPlayers[slotIndex] === playerId) { setSelectedId(null); return }

    const nextSlots = [...slotPlayers]
    const outgoingId = nextSlots[slotIndex]

    // Se il giocatore proveniva da un altro slot, liberalo
    const fromSlotIdx = nextSlots.indexOf(playerId)
    if (fromSlotIdx !== -1) nextSlots[fromSlotIdx] = null

    nextSlots[slotIndex] = playerId

    // Rimuove il giocatore in arrivo da panchina e tribuna (da qualunque
    // delle due provenisse) e, se lo slot era occupato, rimanda
    // l'occupante precedente in panchina.
    let nextPanchina = panchinaIds.filter(id => id !== playerId)
    const nextTribuna = tribunaIds.filter(id => id !== playerId)
    if (outgoingId) nextPanchina = [outgoingId, ...nextPanchina]

    commit(nextSlots, nextPanchina, nextTribuna)
    setSelectedId(null)
  }

  function removeFromSlot(slotIndex) {
    if (readOnly) return
    const playerId = slotPlayers[slotIndex]
    if (!playerId) return
    const nextSlots = [...slotPlayers]
    nextSlots[slotIndex] = null
    commit(nextSlots, [playerId, ...panchinaIds], tribunaIds)
    setSelectedId(null)
  }

  function handleSlotClick(slotIndex) {
    if (readOnly) return
    if (selectedId) {
      placePlayer(selectedId, slotIndex)
      setDropdownSlot(null)
      return
    }
    if (slotPlayers[slotIndex]) {
      removeFromSlot(slotIndex)
      setDropdownSlot(null)
      return
    }
    // Slot vuoto e nessun giocatore selezionato: apre/chiude il menu a
    // tendina con i giocatori compatibili per ruolo e ancora in panchina,
    // per poterlo scegliere anche senza drag&drop.
    setDropdownSlot(cur => (cur === slotIndex ? null : slotIndex))
  }

  function handleListClick(id) {
    if (readOnly) return
    setDropdownSlot(null)
    setSelectedId(cur => (cur === id ? null : id))
  }

  // ── Sposta un giocatore (da campo, panchina o tribuna) nell'elenco
  //    "panchina" o "tribuna", in testa all'elenco di destinazione ──
  function moveToList(playerId, target) {
    if (readOnly) return
    const player = byId[playerId]
    if (!player) return
    const alreadyThere = target === 'panchina'
      ? panchinaIds.includes(playerId)
      : tribunaIds.includes(playerId)
    if (alreadyThere) { setSelectedId(null); return }

    const nextSlots = [...slotPlayers]
    const fromSlotIdx = nextSlots.indexOf(playerId)
    if (fromSlotIdx !== -1) nextSlots[fromSlotIdx] = null

    const restPanchina = panchinaIds.filter(id => id !== playerId)
    const restTribuna = tribunaIds.filter(id => id !== playerId)

    const nextPanchina = target === 'panchina' ? [playerId, ...restPanchina] : restPanchina
    const nextTribuna  = target === 'tribuna'  ? [playerId, ...restTribuna]  : restTribuna

    commit(nextSlots, nextPanchina, nextTribuna)
    setSelectedId(null)
    setDropdownSlot(null)
  }

  // ── Cambio modulo: riconcilia la formazione già impostata ──────
  // Se il nuovo modulo prevede meno slot per un ruolo rispetto a prima
  // (es. da difesa a 4 a difesa a 3), gli eventuali titolari in eccesso
  // per quel ruolo (quelli oltre il numero di slot disponibili) vengono
  // rimessi in panchina, così contatori e riepilogo restano coerenti:
  // nessun giocatore resta "orfano" (titolare ma senza slot in campo).
  function handleModuloChange(newModulo) {
    if (readOnly || !newModulo || newModulo === modulo) return
    const newAttesi = conteggioAtteso(newModulo)
    if (!newAttesi) return

    // Raggruppa i titolari attuali per ruolo, mantenendo l'ordine con cui
    // compaiono in titolariIds (che riflette l'ordine di assegnazione agli
    // slot: i primi N di ogni ruolo sono quelli effettivamente in campo).
    const byRuolo = { '1': [], '2': [], '3': [], '4': [] }
    titolariIds.forEach(id => {
      const p = byId[id]
      if (p) byRuolo[String(p.ruolo)]?.push(id)
    })

    const kept = []
    const eccedenti = []
    Object.keys(byRuolo).forEach(ruolo => {
      const limite = newAttesi[ruolo] ?? 0
      byRuolo[ruolo].forEach((id, idx) => {
        if (idx < limite) kept.push(id)
        else eccedenti.push(id)
      })
    })

    setSelectedId(null)
    setDropdownSlot(null)
    if (eccedenti.length > 0) {
      // I giocatori in eccesso tornano in cima alla panchina.
      onChange?.(kept, [...eccedenti, ...panchinaIds], tribunaIds)
    }
    onModuloChange?.(newModulo)
  }

  // ── Riordino panchina/tribuna: sposta l'elemento fromIdx a toIdx ──
  function movePanchina(fromIdx, toIdx) {
    if (readOnly || fromIdx === toIdx || fromIdx < 0 || toIdx < 0) return
    const arr = [...panchinaIds]
    const [moved] = arr.splice(fromIdx, 1)
    arr.splice(toIdx, 0, moved)
    onChange?.(titolariIds, arr, tribunaIds)
  }

  function moveTribuna(fromIdx, toIdx) {
    if (readOnly || fromIdx === toIdx || fromIdx < 0 || toIdx < 0) return
    const arr = [...tribunaIds]
    const [moved] = arr.splice(fromIdx, 1)
    arr.splice(toIdx, 0, moved)
    onChange?.(titolariIds, panchinaIds, arr)
  }

  const moduloAttesoOk = slotPlayers.every(Boolean)

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
            onClick={() => handleModuloChange(m)}
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

      <div className="grid grid-cols-1 lg:grid-cols-[380px_260px_260px] gap-6 lg:justify-center">
        {/* Campo */}
        <div className="w-full lg:w-[380px] mx-auto lg:mx-0">
          <div
            className="relative w-full aspect-[3/4] lg:max-h-[500px] rounded-xl overflow-hidden border border-white/10"
            style={{ background: 'repeating-linear-gradient(0deg, #123822 0px, #123822 36px, #0e2e1c 36px, #0e2e1c 72px)' }}
            onDragOver={e => e.preventDefault()}
            onDrop={e => {
              e.preventDefault()
              const player = resolveDraggedPlayer(e)
              if (player && dragOverSlot !== null) placePlayer(player.id, dragOverSlot)
              setDragOverSlot(null)
              setDraggingId(null)
              setDraggedBenchIdx(null)
              setDraggedTribunaIdx(null)
            }}
          >
            <div className="absolute inset-3 border border-white/25 rounded-sm" />
            <div className="absolute left-1/2 top-3 bottom-3 w-px bg-white/25 -translate-x-1/2" />
            <div className="absolute left-1/2 top-1/2 w-20 h-20 sm:w-24 sm:h-24 border border-white/25 rounded-full -translate-x-1/2 -translate-y-1/2" />
            <div className="absolute left-1/2 bottom-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-b-0 -translate-x-1/2" />
            <div className="absolute left-1/2 top-3 w-36 sm:w-40 h-14 sm:h-16 border border-white/25 border-t-0 -translate-x-1/2" />

            {slots.map((slot, i) => (
              <PitchSlot
                key={i}
                x={slot.x} y={slot.y} ruolo={slot.ruolo}
                player={slotPlayers[i] ? byId[slotPlayers[i]] : null}
                selected={selectedId === slotPlayers[i]}
                compatible={!!activePlayer && String(activePlayer.ruolo) === slot.ruolo}
                dragOverActive={dragOverSlot === i}
                onDragOver={() => setDragOverSlot(i)}
                onDragLeave={() => setDragOverSlot(cur => (cur === i ? null : cur))}
                onPlayerDragStart={id => { setDraggingId(id); setDropdownSlot(null) }}
                onPlayerDragEnd={() => { setDraggingId(null); setDragOverSlot(null) }}
                onClick={() => handleSlotClick(i)}
              />
            ))}

            {/* Menu a tendina: scelta rapida del giocatore per uno slot vuoto,
                cliccato senza aver prima selezionato nessuno. Elenca i
                giocatori compatibili per ruolo sia dalla panchina sia dalla
                tribuna (questi ultimi contrassegnati da un'icona). */}
            {dropdownSlot !== null && slots[dropdownSlot] && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setDropdownSlot(null)} />
                <div
                  className="absolute z-50 min-w-[170px] max-h-52 overflow-y-auto rounded-lg border border-white/10 bg-pitch-900 shadow-2xl py-1"
                  style={{
                    left: `${slots[dropdownSlot].x}%`,
                    top: `${slots[dropdownSlot].y}%`,
                    transform: slots[dropdownSlot].y > 55 ? 'translate(-50%, -110%)' : 'translate(-50%, 30px)',
                  }}
                >
                  {(() => {
                    const ruoloSlot = slots[dropdownSlot].ruolo
                    const disponibili = [
                      ...panchinaPlayers.filter(p => String(p.ruolo) === ruoloSlot).map(p => ({ p, fromTribuna: false })),
                      ...tribunaPlayers.filter(p => String(p.ruolo) === ruoloSlot).map(p => ({ p, fromTribuna: true })),
                    ]
                    if (disponibili.length === 0) {
                      return (
                        <p className="px-3 py-2 text-xs text-slate-500 whitespace-nowrap">
                          Nessun {ROLE_LABEL[ruoloSlot]} disponibile
                        </p>
                      )
                    }
                    return disponibili.map(({ p, fromTribuna }) => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => { placePlayer(p.id, dropdownSlot); setDropdownSlot(null) }}
                        className="w-full flex items-center gap-2 text-left px-3 py-1.5 text-xs text-slate-200 hover:bg-grass-500/20 hover:text-grass-200 transition-colors whitespace-nowrap"
                      >
                        <span className={`stat-pill ${ROLE_PILL_CLASS[p.ruolo] ?? ''}`}>{ROLE_LABEL[String(p.ruolo)]}</span>
                        <span className="flex-1 truncate">{p.descrizione}</span>
                        {fromTribuna && (
                          <Armchair className="w-3 h-3 text-slate-500 flex-shrink-0" aria-label="In tribuna" />
                        )}
                      </button>
                    ))
                  })()}
                </div>
              </>
            )}
          </div>
          {!moduloAttesoOk && (
            <p className="text-[11px] text-gold-400 mt-2">
              Completa tutti gli slot del modulo {modulo} per poter salvare.
            </p>
          )}
          {selectedId && (
            <p className="text-[11px] text-grass-400 mt-2">
              Giocatore selezionato: {byId[selectedId]?.descrizione}. Le posizioni compatibili sono evidenziate: clicca uno slot per posizionarlo.
            </p>
          )}
        </div>

        {/* Panchina */}
        <div className="card overflow-hidden flex flex-col">
          <div className="px-4 py-3 border-b border-white/5 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-300">Panchina</h3>
            <span className="text-[10px] text-slate-600 font-mono">{panchinaPlayers.length} giocatori</span>
          </div>
          <div
            className="p-2 space-y-1 overflow-y-auto max-h-[420px] lg:max-h-[500px]"
            onDragOver={e => e.preventDefault()}
            onDrop={e => {
              e.preventDefault()
              const player = resolveDraggedPlayer(e)
              if (player) moveToList(player.id, 'panchina')
              setDraggedBenchIdx(null)
              setDraggedTribunaIdx(null)
              setDraggingId(null)
            }}
          >
            {panchinaPlayers.length === 0 && (
              <p className="text-xs text-slate-600 px-2 py-4 text-center">Nessun giocatore in panchina</p>
            )}
            {panchinaPlayers.map((p, idx) => (
              <RosterRow
                key={p.id}
                player={p}
                index={idx}
                total={panchinaPlayers.length}
                selected={selectedId === p.id}
                dragged={draggedBenchIdx === idx}
                readOnly={readOnly}
                onDragStart={e => {
                  e.dataTransfer.setData('text/plain', String(p.id))
                  setDraggedBenchIdx(idx)
                  setDraggingId(p.id)
                  setDropdownSlot(null)
                }}
                onDragOverSwap={e => {
                  e.preventDefault()
                  if (draggedBenchIdx === null || draggedBenchIdx === idx) return
                  // Riordino "live": mentre trascino sopra un elemento, lo
                  // scambio subito di posizione, così l'ordine finale
                  // corrisponde esattamente a dove rilascio il mouse.
                  movePanchina(draggedBenchIdx, idx)
                  setDraggedBenchIdx(idx)
                }}
                onDragEnd={() => { setDraggedBenchIdx(null); setDraggingId(null) }}
                onClick={() => handleListClick(p.id)}
                onMoveUp={() => movePanchina(idx, idx - 1)}
                onMoveDown={() => movePanchina(idx, idx + 1)}
                onMoveOther={() => moveToList(p.id, 'tribuna')}
                moveOtherIcon={ArrowRight}
                moveOtherTitle="Sposta in tribuna"
              />
            ))}
          </div>
          <p className="text-[10px] text-slate-600 px-3 py-2 border-t border-white/5">
            Trascina un giocatore in campo, selezionalo con un click, oppure clicca direttamente
            su uno slot vuoto per scegliere dal menu chi schierare in quel ruolo (anche dalla tribuna).
            Trascina un giocatore su un altro per riordinare la panchina, o sulla tribuna per escluderlo.
          </p>
        </div>

        {/* Tribuna: giocatori esclusi sia dal campo sia dalla panchina */}
        <div className="card overflow-hidden flex flex-col">
          <div className="px-4 py-3 border-b border-white/5 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-300 flex items-center gap-1.5">
              <Armchair className="w-3.5 h-3.5 text-slate-500" /> Tribuna
            </h3>
            <span className="text-[10px] text-slate-600 font-mono">{tribunaPlayers.length} giocatori</span>
          </div>
          <div
            className="p-2 space-y-1 overflow-y-auto max-h-[420px] lg:max-h-[500px]"
            onDragOver={e => e.preventDefault()}
            onDrop={e => {
              e.preventDefault()
              const player = resolveDraggedPlayer(e)
              if (player) moveToList(player.id, 'tribuna')
              setDraggedBenchIdx(null)
              setDraggedTribunaIdx(null)
              setDraggingId(null)
            }}
          >
            {tribunaPlayers.length === 0 && (
              <p className="text-xs text-slate-600 px-2 py-4 text-center">
                Nessun giocatore in tribuna. Trascina qui chi non vuoi schierare né tenere in panchina.
              </p>
            )}
            {tribunaPlayers.map((p, idx) => (
              <RosterRow
                key={p.id}
                player={p}
                index={idx}
                total={tribunaPlayers.length}
                selected={selectedId === p.id}
                dragged={draggedTribunaIdx === idx}
                readOnly={readOnly}
                onDragStart={e => {
                  e.dataTransfer.setData('text/plain', String(p.id))
                  setDraggedTribunaIdx(idx)
                  setDraggingId(p.id)
                  setDropdownSlot(null)
                }}
                onDragOverSwap={e => {
                  e.preventDefault()
                  if (draggedTribunaIdx === null || draggedTribunaIdx === idx) return
                  moveTribuna(draggedTribunaIdx, idx)
                  setDraggedTribunaIdx(idx)
                }}
                onDragEnd={() => { setDraggedTribunaIdx(null); setDraggingId(null) }}
                onClick={() => handleListClick(p.id)}
                onMoveUp={() => moveTribuna(idx, idx - 1)}
                onMoveDown={() => moveTribuna(idx, idx + 1)}
                onMoveOther={() => moveToList(p.id, 'panchina')}
                moveOtherIcon={ArrowLeft}
                moveOtherTitle="Richiama in panchina"
              />
            ))}
          </div>
          <p className="text-[10px] text-slate-600 px-3 py-2 border-t border-white/5">
            I giocatori qui non sono considerati né titolari né disponibili in panchina.
            Trascinali (o usa la freccia) per richiamarli in panchina quando vuoi.
          </p>
        </div>
      </div>

      {!readOnly && (
        <button
          type="button"
          onClick={() => {
            // Rimanda in panchina solo chi era in campo: la tribuna non
            // viene toccata, resta esclusa come l'utente l'ha impostata.
            commit(slots.map(() => null), [...titolariIds, ...panchinaIds], tribunaIds)
            setDropdownSlot(null)
          }}
          className="btn-ghost text-xs"
        >
          <RotateCcw className="w-3.5 h-3.5" /> Svuota il campo
        </button>
      )}
    </div>
  )
}
