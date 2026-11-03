// ── Scheda giocatore ───────────────────────────────────────────────────────
// /giocatori/:id?stagione=2026
// Dati della stagione indicata (totali, andamento e dettaglio per giornata,
// percorso in lega) e storico di tutte le stagioni. L'id del giocatore è valido
// solo nella propria stagione: lo storico è collegato dal backend per nome.
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getGiocatore } from '../api/client'
import { PageHeader, StatCard, LoadingState, ErrorState, EmptyState, RoleBadge } from '../components/ui'
import { Bandiera } from '../components/Bandiera'
import TeamLogo from '../components/TeamLogo'

const f1 = n => (n === null || n === undefined ? '–' : Number(n).toFixed(1))
const f2 = n => (n === null || n === undefined ? '–' : Number(n).toFixed(2))
const stagioneLabel = s => `${s}/${String(Number(s) + 1).slice(-2)}`

function Sezione({ titolo, destra, children }) {
  return (
    <section className="card overflow-hidden mb-6">
      <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-white/5">
        <h2 className="text-display text-sm font-bold tracking-wide text-slate-300 uppercase">{titolo}</h2>
        {destra && <span className="text-xs text-slate-600 font-mono">{destra}</span>}
      </div>
      {children}
    </section>
  )
}

// Piccole etichette con gli eventi della partita (gol, assist, cartellini, ...)
function Eventi({ r, portiere }) {
  const e = []
  if (portiere && r.gol_subiti) e.push(`🥅 ${r.gol_subiti} subit${r.gol_subiti === 1 ? 'o' : 'i'}`)
  if (!portiere && r.gol) e.push(`⚽ ${r.gol}`)
  if (r.assist) e.push(`🎯 ${r.assist}`)
  if (r.rigori_segnati) e.push(`Rig. segnato${r.rigori_segnati > 1 ? ` ×${r.rigori_segnati}` : ''}`)
  if (r.rigori_parati) e.push(`Rig. parato${r.rigori_parati > 1 ? ` ×${r.rigori_parati}` : ''}`)
  if (r.rigori_sbagliati) e.push(`Rig. sbagliato${r.rigori_sbagliati > 1 ? ` ×${r.rigori_sbagliati}` : ''}`)
  if (r.autogol) e.push(`Autogol${r.autogol > 1 ? ` ×${r.autogol}` : ''}`)
  if (r.ammonizioni) e.push('🟨')
  if (r.espulsioni) e.push('🟥')
  return e.length ? <span className="text-xs text-slate-400">{e.join(' · ')}</span> : <span className="text-slate-700">–</span>
}

// Istogramma del fantavoto per giornata (s.v. = nessuna barra, trattino)
function Andamento({ giornate, media }) {
  const giocate = giornate.filter(g => g.giocata)
  if (giocate.length < 2) return null
  const max = Math.max(...giocate.map(g => g.totale), 1)
  const min = Math.min(...giocate.map(g => g.totale), 0)
  const span = Math.max(max - Math.min(min, 0), 1)
  return (
    <div className="px-4 pt-4 pb-3 border-b border-white/5 overflow-x-auto">
      <div className="flex items-end gap-1 h-28" style={{ minWidth: `${giornate.length * 18}px` }}>
        {giornate.map(g => {
          const h = g.giocata ? Math.max(6, ((g.totale - Math.min(min, 0)) / span) * 100) : 0
          const sopra = g.giocata && g.totale >= media
          return (
            <div key={`${g.giornata}-${g.id_giocatore}`} className="flex-1 min-w-[10px] h-full flex flex-col justify-end items-center gap-1"
                 title={g.giocata ? `G${g.giornata}: voto ${f1(g.voto)} · fantavoto ${f1(g.totale)}` : `G${g.giornata}: s.v.`}>
              {g.giocata ? (
                <>
                  <span className="text-[9px] font-mono text-slate-500 leading-none">{f1(g.totale)}</span>
                  <div className={`w-full rounded-t ${sopra ? 'bg-grass-500/70' : 'bg-slate-600'}`} style={{ height: `${h}%` }} />
                </>
              ) : (
                <span className="text-[9px] font-mono text-slate-700 mb-0.5">s.v.</span>
              )}
              <span className="text-[9px] font-mono text-slate-600 leading-none">{g.giornata}</span>
            </div>
          )
        })}
      </div>
      <p className="mt-2 text-[11px] text-slate-600">
        Fantavoto per giornata · barre verdi: pari o sopra la media ({f2(media)})
      </p>
    </div>
  )
}

export default function Giocatore() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [sp] = useSearchParams()
  const { stagione: stagioneApp, ultimaStagione } = useApp()
  const stagione = Number(sp.get('stagione')) || stagioneApp

  const { data, loading, error, refetch } = useFetch(
    stagione ? () => getGiocatore(stagione, id) : null,
    [stagione, id]
  )

  if (loading && !data) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data) return <EmptyState label="Giocatore non trovato" />

  const { anagrafica: a, stagione_corrente: sc, storico } = data
  const portiere = a.ruolo === 1
  const t = sc.totali
  // La mappa delle bandiere vale per la stagione scelta e per l'ultima
  const bandieraOk = stagione === stagioneApp || stagione === ultimaStagione

  return (
    <div className="animate-fade-up">
      <button type="button" onClick={() => navigate(-1)}
              className="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-300 mb-4">
        <ArrowLeft className="w-3.5 h-3.5" /> Indietro
      </button>

      <PageHeader label="Scheda giocatore" title={a.nome}
                  subtitle={`Stagione ${stagioneLabel(stagione)}`} />

      {/* Anagrafica */}
      <div className="card p-4 mb-6 flex flex-wrap items-center gap-x-6 gap-y-3">
        <div className="flex items-center gap-2">
          <RoleBadge ruolo={a.ruolo} />
          {bandieraOk && <Bandiera id={a.id} size="md" />}
          {a.nazione && <span className="text-sm text-slate-400">{a.nazione}</span>}
        </div>
        {a.squadra_serie_a && (
          <div>
            <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">Serie A</p>
            <p className="text-sm text-slate-300">{a.squadra_serie_a}</p>
          </div>
        )}
        <div>
          <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">In lega</p>
          {a.in_rosa && a.squadra ? (
            <Link to={`/squadre/${a.id_squadra}`} className="flex items-center gap-2 text-sm text-slate-300 hover:text-grass-400">
              <TeamLogo logo={a.logo} nome={a.squadra} size="sm" /> {a.squadra}
            </Link>
          ) : (
            <p className="text-sm text-slate-500">Svincolato</p>
          )}
        </div>
        {a.crediti !== null && a.in_rosa && (
          <div>
            <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">Crediti</p>
            <p className="text-sm font-mono text-slate-300">{a.crediti}</p>
          </div>
        )}
      </div>

      {/* Stagione in corso */}
      <Sezione titolo={`Stagione ${stagioneLabel(stagione)}`}>
        {!t ? (
          <div className="p-6"><EmptyState label="Nessuna presenza in questa stagione" /></div>
        ) : (
          <div className="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <StatCard label="Presenze" value={t.giocate} />
            <StatCard label="Media" value={f2(t.media)} accent />
            <StatCard label={portiere ? 'Gol subiti' : 'Gol'} value={portiere ? t.gol_subiti : t.gol} />
            <StatCard label="Assist" value={t.assist} />
            <StatCard label="Ammonizioni" value={t.ammonizioni} />
            <StatCard label="Espulsioni" value={t.espulsioni} />
            <StatCard label="Autogol" value={t.autogol} />
            <StatCard
              label={portiere ? 'Rigori parati' : 'Rigori segnati'}
              value={portiere ? t.rigori_parati : t.rigori_segnati}
              sub={t.rigori_sbagliati ? `${t.rigori_sbagliati} sbagliat${t.rigori_sbagliati === 1 ? 'o' : 'i'}` : undefined}
            />
          </div>
        )}
      </Sezione>

      {a.ids_stagione?.length > 1 && (
        <p className="text-[11px] text-slate-600 -mt-3 mb-6">
          In questa stagione il giocatore compare con {a.ids_stagione.length} id (cessione e acquisto tra squadre):
          i dati della stagione sono la somma di tutti; nello storico trovi una riga per ciascun id, in ordine cronologico (1 = il primo).
        </p>
      )}

      {sc.giornate.length > 0 && (
        <Sezione titolo="Giornata per giornata" destra={`${sc.giornate.filter(g => g.giocata).length} presenze`}>
          <Andamento giornate={sc.giornate} media={t?.media ?? 0} />
          <div className="overflow-x-auto">
            <table className="fanta-table min-w-[420px]">
              <thead>
                <tr>
                  <th className="w-14">G</th>
                  <th className="text-center">Voto</th>
                  <th className="text-center">Fantavoto</th>
                  <th>Eventi</th>
                </tr>
              </thead>
              <tbody>
                {[...sc.giornate].reverse().map(g => (
                  <tr key={`${g.giornata}-${g.id_giocatore}`}>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{g.giornata}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-sm text-slate-400">
                      {g.giocata ? f1(g.voto) : <span className="text-xs text-slate-600">s.v.</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center font-mono text-sm font-semibold text-grass-400">
                      {g.giocata ? f1(g.totale) : '–'}
                    </td>
                    <td className="px-4 py-2.5"><Eventi r={g} portiere={portiere} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Sezione>
      )}

      {sc.percorso.length > 0 && (
        <Sezione titolo="Percorso in lega" destra={`Stagione ${stagioneLabel(stagione)}`}>
          <ul>
            {sc.percorso.map((p, i) => (
              <li key={i} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
                <TeamLogo logo={p.logo} nome={p.squadra} size="sm" />
                <span className="flex-1 min-w-0 truncate text-sm text-slate-300">{p.squadra ?? `Squadra ${p.id_squadra}`}</span>
                <span className="text-xs font-mono text-slate-500 flex-shrink-0">
                  {p.a_giornata === null ? `dalla G${p.da_giornata}` : `G${p.da_giornata} – G${p.a_giornata}`}
                </span>
              </li>
            ))}
          </ul>
        </Sezione>
      )}

      {/* Storico */}
      <Sezione titolo="Storico" destra={storico.collegamento === 'raccordo' ? 'raccordo per id' : 'collegato per nome'}>
        {!storico.stagioni.length ? (
          <div className="p-6"><EmptyState label="Nessun dato storico" /></div>
        ) : (
          <div className="overflow-x-auto">
            <table className="fanta-table min-w-[640px]">
              <thead>
                <tr>
                  <th>Stagione</th>
                  <th>Squadra</th>
                  <th className="text-center">Pres.</th>
                  <th className="text-center">Media</th>
                  <th className="text-center">Gol</th>
                  <th className="text-center">Ass.</th>
                  <th className="text-center">Amm.</th>
                  <th className="text-center">Esp.</th>
                </tr>
              </thead>
              <tbody>
                {storico.stagioni.map(r => (
                  <tr key={`${r.stagione}-${r.id_giocatore}`} className={r.stagione === stagione ? 'bg-grass-500/5' : ''}>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-400 whitespace-nowrap">
                      {stagioneLabel(r.stagione)}
                      {r.totale_stagione > 1 && (
                        <span className="block text-[10px] text-gold-400/80" title="Più id nella stessa stagione (cessione/acquisto): 1 = il primo in ordine cronologico">
                          {r.ordine_stagione}/{r.totale_stagione}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-sm text-slate-300">
                      <span className="block truncate max-w-[12rem]">{r.squadra ?? 'Svincolato'}</span>
                      {r.squadra_serie_a && <span className="block text-[11px] text-slate-600">{r.squadra_serie_a}</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-400">{r.giocate}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-sm font-semibold text-grass-400">{r.giocate > 0 ? f2(r.media) : '–'}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-400">
                      {r.ruolo === 1 ? `${r.gol_subiti} sub.` : r.gol}
                    </td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-400">{r.assist}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-400">{r.ammonizioni}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-400">{r.espulsioni}</td>
                  </tr>
                ))}
                <tr className="border-t border-white/10 bg-white/[0.02]">
                  <td className="px-4 py-3 text-xs font-bold text-slate-300 uppercase tracking-wide" colSpan={2}>
                    Carriera ({storico.carriera.stagioni} stagion{storico.carriera.stagioni === 1 ? 'e' : 'i'})
                  </td>
                  <td className="px-4 py-3 text-center font-mono text-xs font-bold text-slate-300">{storico.carriera.giocate}</td>
                  <td className="px-4 py-3 text-center font-mono text-sm font-bold text-grass-400">{f2(storico.carriera.media)}</td>
                  <td className="px-4 py-3 text-center font-mono text-xs font-bold text-slate-300">
                    {portiere ? `${storico.carriera.gol_subiti} sub.` : storico.carriera.gol}
                  </td>
                  <td className="px-4 py-3 text-center font-mono text-xs font-bold text-slate-300">{storico.carriera.assist}</td>
                  <td className="px-4 py-3 text-center font-mono text-xs font-bold text-slate-300">{storico.carriera.ammonizioni}</td>
                  <td className="px-4 py-3 text-center font-mono text-xs font-bold text-slate-300">{storico.carriera.espulsioni}</td>
                </tr>
              </tbody>
            </table>
          </div>
        )}
      </Sezione>
      {storico.collegamento !== 'raccordo' ? (
        <p className="text-[11px] text-slate-600 -mt-3 mb-6">
          Giocatore non ancora raccordato tra le stagioni: lo storico riunisce le stagioni in cui compare con lo stesso nome.
          Eventuali omonimi con lo stesso ruolo possono essere confusi.
        </p>
      ) : storico.da_verificare ? (
        <p className="text-[11px] text-gold-400/80 -mt-3 mb-6">
          Il collegamento tra alcune stagioni è in attesa di verifica da parte dell'amministratore.
        </p>
      ) : null}
    </div>
  )
}
