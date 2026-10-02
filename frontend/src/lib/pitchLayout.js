// ============================================================
// src/lib/pitchLayout.js
// Domino dei moduli validi e calcolo degli slot (x, y) sul campo
// per ciascun modulo. Usato dalla pagina di inserimento formazione.
// ============================================================

export const ROLE_LABEL = { '1': 'P', '2': 'D', '3': 'C', '4': 'A' }
export const ROLE_NAME  = { '1': 'Portiere', '2': 'Difensore', '3': 'Centrocampista', '4': 'Attaccante' }

// modulo -> { d, c, a } (il portiere è sempre 1 ed è implicito)
export const MODULI = {
  '3-4-3': { d: 3, c: 4, a: 3 },
  '4-4-2': { d: 4, c: 4, a: 2 },
  '5-4-1': { d: 5, c: 4, a: 1 },
  '4-3-3': { d: 4, c: 3, a: 3 },
  '5-3-2': { d: 5, c: 3, a: 2 },
  '4-5-1': { d: 4, c: 5, a: 1 },
  '3-5-2': { d: 3, c: 5, a: 2 },
}

export const MODULI_VALIDI = Object.keys(MODULI)

// Distribuisce n punti in orizzontale a una data quota y, con margine
// percentuale dai bordi campo.
function rowSlots(n, y, margin = 14) {
  if (n <= 1) return [{ x: 50, y }]
  const step = (100 - margin * 2) / (n - 1)
  return Array.from({ length: n }, (_, i) => ({ x: margin + i * step, y }))
}

// Restituisce l'elenco ordinato degli slot per il modulo scelto:
// [{ ruolo, x, y }] — sempre 1 portiere + d difensori + c centrocampisti + a attaccanti
export function getSlotsForModulo(modulo) {
  const cfg = MODULI[modulo]
  if (!cfg) return []
  const slots = []
  slots.push({ ruolo: '1', ...rowSlots(1, 91)[0] })
  rowSlots(cfg.d, 72).forEach(s => slots.push({ ruolo: '2', ...s }))
  rowSlots(cfg.c, 46).forEach(s => slots.push({ ruolo: '3', ...s }))
  rowSlots(cfg.a, 18).forEach(s => slots.push({ ruolo: '4', ...s }))
  return slots
}

// Conteggio atteso dei ruoli per un modulo, comprensivo del portiere
// (usato per validare lato client prima di salvare)
export function conteggioAtteso(modulo) {
  const cfg = MODULI[modulo]
  if (!cfg) return null
  return { '1': 1, '2': cfg.d, '3': cfg.c, '4': cfg.a }
}

// Ordine di ruolo usato per la panchina quando non c'è nessun altro
// criterio (ordine già salvato, o appena stabilito dall'utente):
// Portieri, Attaccanti, Centrocampisti, Difensori. Si applica sia alla
// "situazione di default" (nessuna formazione da caricare, né per la
// giornata corrente né per la precedente) sia a "Svuota il campo".
export const ORDINE_RUOLO_PANCHINA_DEFAULT = { '1': 0, '4': 1, '3': 2, '2': 3 }

// Ordina un elenco di id giocatore secondo ORDINE_RUOLO_PANCHINA_DEFAULT.
// L'ordinamento è stabile (Array.prototype.sort lo è negli ambienti
// moderni): a parità di ruolo, i giocatori mantengono l'ordine relativo
// che avevano in ingresso.
export function ordinaPerRuoloDefault(ids, byId) {
  return [...ids].sort((a, b) => {
    const ra = ORDINE_RUOLO_PANCHINA_DEFAULT[String(byId[a]?.ruolo)] ?? 99
    const rb = ORDINE_RUOLO_PANCHINA_DEFAULT[String(byId[b]?.ruolo)] ?? 99
    return ra - rb
  })
}
