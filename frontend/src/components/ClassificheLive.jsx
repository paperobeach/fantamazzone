import { ArrowUp, ArrowDown, Minus, Calculator, Play, Info } from 'lucide-react'
import { Link } from 'react-router-dom'
import TeamLogo from './TeamLogo'
import { EmptyState } from './ui'

// ── Classifiche AGGIORNATE LIVE (pagina "LIVE Giornata in corso") ──
// Dati da live_giornata.php → "classifiche": classifica ufficiale delle giornate
// chiuse + risultati calcolati o simulati della giornata in corso (non definitivi).

// Variazione di posizione rispetto alla classifica ufficiale (delta > 0: sale)
function Variazione({ delta }) {
  const d = Number(delta) || 0
  if (d > 0) return (
    <span title={`Sale di ${d} ${d === 1 ? 'posizione' : 'posizioni'}`}
      className="inline-flex items-center gap-0.5 text-[11px] font-mono font-semibold text-green-400">
      <ArrowUp className="w-3 h-3" aria-hidden="true" />{d}
    </span>
  )
  if (d < 0) return (
    <span title={`Scende di ${-d} ${d === -1 ? 'posizione' : 'posizioni'}`}
      className="inline-flex items-center gap-0.5 text-[11px] font-mono font-semibold text-red-400">
      <ArrowDown className="w-3 h-3" aria-hidden="true" />{-d}
    </span>
  )
  return <Minus className="w-3 h-3 text-slate-700" aria-label="Invariata" />
}

// Punti ottenuti oggi (+3 / +1 / +0) con la fonte del risultato (calcolato o simulato)
function PuntiOggi({ s }) {
  if (!s.live) return <span className="text-slate-700 text-xs">—</span>
  const cls = s.live_punti >= 3 ? 'bg-green-500/15 text-green-400'
            : s.live_punti === 1 ? 'bg-yellow-500/15 text-yellow-400'
            : 'bg-pitch-800 text-slate-500'
  const reale = s.live_fonte === 'reale'
  return (
    <span title={reale ? 'Risultato calcolato (non definitivo)' : 'Risultato simulato'}
      className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-mono font-semibold ${cls}`}>
      {reale ? <Calculator className="w-3 h-3 opacity-70" aria-hidden="true" />
             : <Play className="w-3 h-3 opacity-70" aria-hidden="true" />}
      +{s.live_punti}
    </span>
  )
}

// Bordo di riga per campione / secondo / maglia nera, come la Classifica generale
function bordoRiga(i, totale) {
  if (i === 0) return 'border-l-2 border-l-gold-400'
  if (i === 1) return 'border-l-2 border-l-slate-300'
  if (totale > 2 && i === totale - 1) return 'border-l-2 border-l-white/20 bg-black/20'
  return ''
}

function Avviso({ children }) {
  return (
    <p className="flex items-start gap-2 px-4 py-2.5 border-b border-white/5 text-[11px] text-slate-500">
      <Info className="w-3.5 h-3.5 mt-px shrink-0 text-sky-400" aria-hidden="true" />
      <span>{children}</span>
    </p>
  )
}

// ── Campionato ──────────────────────────────────────────────
export function ClassificaCampionatoLive({ dati, giornata }) {
  if (!dati?.classifica?.length) return <EmptyState label="Classifica non disponibile" />
  const righe = dati.classifica
  const nessunLive = dati.squadre_live === 0

  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-3 border-b border-white/5">
        <h2 className="text-sm font-semibold text-slate-300">Classifica generale LIVE</h2>
      </div>
      <Avviso>
        {nessunLive
          ? <>Nessun risultato calcolato o simulato per la giornata {giornata}: la classifica coincide con quella ufficiale. Usa “Simula giornata” per vedere l’effetto dei risultati.</>
          : <>Classifica ufficiale + risultati della giornata {giornata} ({dati.partite_live} {dati.partite_live === 1 ? 'partita' : 'partite'} con risultato calcolato o simulato). Non definitiva. Le frecce indicano la variazione rispetto alla classifica ufficiale.</>}
      </Avviso>

      <div className="overflow-x-auto">
        <table className="fanta-table sticky-2 min-w-[760px]">
          <thead>
            <tr>
              <th className="w-10">#</th>
              <th>Squadra</th>
              <th className="text-center">PG</th>
              <th className="text-center bg-grass-500/10">Pts</th>
              <th className="text-center">V</th>
              <th className="text-center">N</th>
              <th className="text-center">P</th>
              <th className="text-center">GF</th>
              <th className="text-center">GS</th>
              <th className="text-center">Media</th>
              <th className="text-center">Oggi</th>
            </tr>
          </thead>
          <tbody>
            {righe.map((s, i) => (
              <tr key={s.id_squadra}
                className={`border-b border-white/[0.03] ${bordoRiga(i, righe.length)} ${s.live ? 'bg-white/[0.015]' : ''}`}>
                <td className="px-4 py-2.5 text-center">
                  <span className="text-display font-bold text-lg text-slate-500">{s.posizione}</span>
                </td>
                <td className="px-4 py-2.5">
                  <div className="flex items-center gap-2.5">
                    <Link to={`/squadre/${s.id_squadra}`} className="flex items-center gap-2.5 group min-w-0">
                      <TeamLogo logo={s.logo} nome={s.squadra} size="sm" />
                      <span className="font-medium text-slate-200 group-hover:text-grass-400 transition-colors text-sm truncate">{s.squadra}</span>
                    </Link>
                    <Variazione delta={s.delta} />
                  </div>
                </td>
                <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.partiteg}</td>
                <td className="px-3 py-2.5 text-center bg-grass-500/10 border-x border-grass-500/20">
                  <span className="text-display font-extrabold text-2xl text-grass-400">{s.punti}</span>
                </td>
                <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.vinte}</td>
                <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.nulle}</td>
                <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.perse}</td>
                <td className="px-3 py-2.5 text-center text-slate-300 text-sm">{s.golf}</td>
                <td className="px-3 py-2.5 text-center text-slate-500 text-sm">{s.gols}</td>
                <td className="px-3 py-2.5 text-center">
                  <span className="font-mono text-xs text-sky-300">{Number(s.media).toFixed(2)}</span>
                </td>
                <td className="px-3 py-2.5 text-center"><PuntiOggi s={s} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Champions (gironi) ──────────────────────────────────────
export function ClassificaChampionsLive({ dati, giornata }) {
  if (!dati?.gironi?.length) return <EmptyState label="Classifica Champions non disponibile" />
  const nessunLive = dati.squadre_live === 0

  return (
    <div className="space-y-4">
      <p className="flex items-start gap-2 text-[11px] text-slate-500">
        <Info className="w-3.5 h-3.5 mt-px shrink-0 text-sky-400" aria-hidden="true" />
        <span>
          {dati.fase_label}.{' '}
          {nessunLive
            ? <>Nessun risultato calcolato o simulato per la giornata {giornata}: la classifica coincide con quella ufficiale.</>
            : <>Classifica ufficiale + risultati della giornata {giornata} (calcolati o simulati, non definitivi).</>}
          {' '}Le prime {dati.qualificano} di ogni girone si qualificano. Parità: punti, differenza reti, gol fatti, fantapunti.
        </span>
      </p>

      {dati.gironi.map(g => (
        <div key={g.girone} className="card overflow-hidden">
          <div className="px-4 py-3 border-b border-white/5">
            <h2 className="text-sm font-semibold text-slate-300">{g.label || `Girone ${g.girone}`} — LIVE</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="fanta-table sticky-2 min-w-[640px]">
              <thead>
                <tr>
                  <th className="w-10">#</th>
                  <th>Squadra</th>
                  <th className="text-center">G</th>
                  <th className="text-center bg-grass-500/10">Pts</th>
                  <th className="text-center">V</th>
                  <th className="text-center">N</th>
                  <th className="text-center">P</th>
                  <th className="text-center">GF</th>
                  <th className="text-center">GS</th>
                  <th className="text-center">DR</th>
                  <th className="text-center">Media</th>
                  <th className="text-center">Oggi</th>
                </tr>
              </thead>
              <tbody>
                {g.squadre.map((s, i) => {
                  const passa = i < dati.qualificano
                  const dr = s.golf - s.gols
                  return (
                    <tr key={s.id_squadra}
                      className={`border-b border-white/[0.03] ${passa ? 'border-l-2 border-l-grass-500/70' : 'border-l-2 border-l-transparent'} ${s.live ? 'bg-white/[0.015]' : ''}`}>
                      <td className="px-4 py-2.5 text-center">
                        <span className="text-display font-bold text-lg text-slate-500">{s.posizione}</span>
                      </td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center gap-2.5">
                          <TeamLogo logo={s.logo} nome={s.nome} size="sm" />
                          <span className="font-medium text-slate-200 text-sm truncate">{s.nome}</span>
                          <Variazione delta={s.delta} />
                        </div>
                      </td>
                      <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.giocate}</td>
                      <td className="px-3 py-2.5 text-center bg-grass-500/10 border-x border-grass-500/20">
                        <span className="text-display font-extrabold text-2xl text-grass-400">{s.punti}</span>
                      </td>
                      <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.vinte}</td>
                      <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.nulle}</td>
                      <td className="px-3 py-2.5 text-center text-slate-400 text-sm">{s.perse}</td>
                      <td className="px-3 py-2.5 text-center text-slate-300 text-sm">{s.golf}</td>
                      <td className="px-3 py-2.5 text-center text-slate-500 text-sm">{s.gols}</td>
                      <td className="px-3 py-2.5 text-center text-sm">
                        <span className={dr > 0 ? 'text-green-400' : dr < 0 ? 'text-red-400' : 'text-slate-500'}>
                          {dr > 0 ? `+${dr}` : dr}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-center">
                        <span className="font-mono text-xs text-sky-300">{Number(s.media).toFixed(2)}</span>
                      </td>
                      <td className="px-3 py-2.5 text-center"><PuntiOggi s={s} /></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      ))}

      <div className="flex items-center gap-2 px-1">
        <div className="w-3 h-3 rounded-sm bg-grass-500/70" />
        <span className="text-xs text-slate-600">Zona qualificazione</span>
      </div>
    </div>
  )
}
