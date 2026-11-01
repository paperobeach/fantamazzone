// ============================================================
// Utilità per le statistiche selezionabili della dashboard squadra.
// I dati arrivano da dashboard_metriche.php (vedi lib/MetricheSquadra.php):
//   data.metriche   catalogo { key, label, gruppo, agg, dir, unita, dec, descr }
//   data.valori     chiave -> { idSquadra: valore di stagione | null }
//   data.serie      chiave -> { idSquadra: [valore per giornata | null] }
//   data.benchmark  chiave -> { n, media, mediana, min, max }
// ============================================================
import { useState, useCallback } from 'react'

// ── Selezione multipla (condivisa tra Statistiche, Confronta, Andamento) ──

export function useSelezioneMetriche() {
  const [chiavi, setChiavi] = useState([])
  const toggle  = useCallback(k => setChiavi(c => (c.includes(k) ? c.filter(x => x !== k) : [...c, k])), [])
  const aggiungi = useCallback(k => setChiavi(c => (c.includes(k) ? c : [...c, k])), [])
  const svuota  = useCallback(() => setChiavi([]), [])
  const ha      = useCallback(k => chiavi.includes(k), [chiavi])
  return { chiavi, toggle, aggiungi, svuota, ha }
}

// ── Formattazione ────────────────────────────────────────────

export function fmtVal(def, v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '–'
  return `${Number(v).toFixed(def?.dec ?? 2)}${def?.unita === '%' ? '%' : ''}`
}

/** Scarto con segno: +0,4 / -1,2 (stessa precisione della metrica). */
export function fmtDelta(def, v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '–'
  const n = Number(v)
  const t = Math.abs(n).toFixed(def?.dec ?? 2)
  const sign = n > 0 && Number(t) !== 0 ? '+' : n < 0 && Number(t) !== 0 ? '−' : ''
  return `${sign}${t}${def?.unita === '%' ? ' pt' : ''}`
}

/** Colore di uno scarto dal punto di vista di "più è meglio / peggio". */
export function tonoDelta(def, delta) {
  if (delta === null || delta === undefined || !def || def.dir === 'neutral') return 'text-slate-500'
  if (Math.abs(delta) < 1e-9) return 'text-slate-500'
  const bene = def.dir === 'down' ? delta < 0 : delta > 0
  return bene ? 'text-green-400' : 'text-red-400'
}

// ── Accesso ai dati ──────────────────────────────────────────

export function mappaDef(data) {
  const m = {}
  data?.metriche?.forEach(d => { m[d.key] = d })
  return m
}

export const valoreSquadra = (data, k, id) => data?.valori?.[k]?.[id] ?? null

/** Posizione (1 = migliore, a pari merito condivisa) di una squadra in una metrica. */
export function posizione(data, def, id) {
  const vals = Object.values(data?.valori?.[def.key] ?? {}).filter(v => v !== null && v !== undefined)
  const mio = valoreSquadra(data, def.key, id)
  if (mio === null || !vals.length) return null
  const meglio = vals.filter(v => (def.dir === 'down' ? v < mio - 1e-9 : v > mio + 1e-9)).length
  return { pos: meglio + 1, n: vals.length }
}

/** Tutto ciò che serve per mostrare una metrica della squadra `id`. */
export function infoMetrica(data, k, id) {
  if (!data) return null
  const def = mappaDef(data)[k]
  if (!def) return null
  const valore = valoreSquadra(data, k, id)
  const bench = data.benchmark?.[k] ?? null
  const rank = posizione(data, def, id)
  const delta = valore !== null && bench?.media !== null && bench?.media !== undefined ? valore - bench.media : null
  return { def, valore, bench, rank, delta }
}

export function gruppiMetriche(data) {
  const out = []
  data?.metriche?.forEach(d => {
    let g = out.find(x => x.nome === d.gruppo)
    if (!g) { g = { nome: d.gruppo, metriche: [] }; out.push(g) }
    g.metriche.push(d)
  })
  return out
}

// ── Serie e andamento ────────────────────────────────────────

export function aggrega(agg, vals) {
  const v = vals.filter(x => x !== null && x !== undefined)
  if (!v.length) return null
  switch (agg) {
    case 'sum':  return v.reduce((a, b) => a + b, 0)
    case 'mean': return v.reduce((a, b) => a + b, 0) / v.length
    case 'last': return v[v.length - 1]
    case 'max':  return Math.max(...v)
    case 'min':  return Math.min(...v)
    default:     return null
  }
}

export const MODI_ANDAMENTO = [
  { value: 'partita',     label: 'Per giornata' },
  { value: 'progressivo', label: 'Progressivo' },
  { value: 'mobile',      label: 'Media mobile (5)' },
]

/**
 * Serie da mostrare partendo dai valori per giornata (null = non applicabile).
 *   partita      il valore della giornata
 *   progressivo  il valore di stagione dopo ogni giornata (aggregazione della metrica)
 *   mobile       media delle ultime `finestra` giornate con valore
 * Nei modi progressivo e mobile, nelle giornate senza valore resta quello precedente.
 */
export function serieModo(def, raw, modo, finestra = 5) {
  if (modo === 'partita') return raw.slice()
  const acc = []
  let prec = null
  return raw.map(x => {
    if (x !== null && x !== undefined) {
      acc.push(x)
      prec = modo === 'progressivo'
        ? aggrega(def.agg, acc)
        : aggrega('mean', acc.slice(-finestra))
    }
    return prec === null ? null : Math.round(prec * 100) / 100
  })
}

/** Media, giornata per giornata, di più serie (ignora i null). */
export function mediaSerie(serie) {
  if (!serie.length) return []
  return serie[0].map((_, i) => {
    const v = serie.map(s => s[i]).filter(x => x !== null && x !== undefined)
    return v.length ? Math.round((v.reduce((a, b) => a + b, 0) / v.length) * 100) / 100 : null
  })
}

export const PALETTE_SQUADRE = ['#38bdf8', '#f472b6', '#a78bfa', '#fb923c', '#2dd4bf', '#facc15', '#94a3b8']
export const TOOLTIP_STYLE = { background: '#0f1f2e', border: '1px solid #ffffff1a', borderRadius: 8, fontSize: 12 }

/** Metriche proposte quando la selezione è vuota. */
export const METRICHE_SUGGERITE = ['fanta_media', 'punti_partita', 'gol_fatti', 'gol_subiti', 'clean_sheet']
