import { useRef, useState } from 'react'
import { afMappatura } from '../api/client'
import { PageHeader, TabBar } from '../components/ui'
import {
  useCredenzialiAdmin, CardCredenziali, Avviso, Pulsante,
} from '../components/ApiFootballUi'
import {
  RefreshCw, Download, Users, Wand2, Search, Link2, Unlink, XCircle, CheckCircle2, Square,
} from 'lucide-react'

// ============================================================
// "Mappatura API-Football" — Fase 1. Associa le squadre e i giocatori
// di API-Football a quelli del progetto (il codice giocatore del
// listone non coincide con l'ID di API-Football):
//
//   1. si caricano le squadre della Serie A da API-Football (1 richiesta)
//      e le si abbina ai nomi del calendario (in automatico, con
//      possibilità di correggere);
//   2. si carica la rosa di ogni squadra (1 richiesta per squadra);
//   3. "Abbinamento automatico" collega i giocatori con lo stesso nome
//      e squadra; il resto si rivede a mano.
//
// Un giocatore non associato non riceve statistiche nella simulazione.
// Accesso: solo amministratori.
// ============================================================

const RUOLI = { 1: 'P', 2: 'D', 3: 'C', 4: 'A' }
const PAUSA_TRA_ROSE_MS = 7000   // il piano gratuito consente 10 richieste al minuto

const attendi = (ms) => new Promise(r => setTimeout(r, ms))

function Barra({ valore, totale }) {
  const perc = totale > 0 ? Math.round((valore / totale) * 100) : 0
  return (
    <div className="flex items-center gap-2 min-w-0">
      <div className="h-1.5 flex-1 rounded-full bg-white/5 overflow-hidden">
        <div className="h-full bg-grass-500" style={{ width: `${perc}%` }} />
      </div>
      <span className="text-xs text-slate-400 tabular-nums flex-shrink-0">{valore}/{totale}</span>
    </div>
  )
}

export default function ApiFootballMappatura() {
  const cred = useCredenzialiAdmin()
  const [tab, setTab] = useState('squadre')
  const [errore, setErrore] = useState(null)
  const [occupato, setOccupato] = useState(null)
  const [stato, setStato] = useState(null)
  const [sq, setSq] = useState(null)             // { squadre_api, squadre_progetto }
  const [avanz, setAvanz] = useState(null)       // { fatte, totale, corrente }
  const interrompi = useRef(false)

  const [filtro, setFiltro] = useState('da_associare')
  const [fSquadra, setFSquadra] = useState('')
  const [q, setQ] = useState('')
  const [elenco, setElenco] = useState(null)     // { totale, righe }
  const [riepilogo, setRiepilogo] = useState(null)
  const [ricerca, setRicerca] = useState({})     // id_giocatore -> { q, risultati }

  const off = !cred.pronto || !!occupato || !!avanz

  // Chiamata "semplice": gestisce errore e stato di occupazione
  const raw = (azione, extra = {}) => afMappatura(cred.stagioneRif, { ...cred.auth, azione, ...extra })
  const chiama = async (azione, extra = {}, etichetta = azione) => {
    setErrore(null)
    setOccupato(etichetta)
    try {
      return await raw(azione, extra)
    } catch (e) {
      setErrore(e.message)
      return null
    } finally {
      setOccupato(null)
    }
  }

  const aggiornaStato = async () => {
    const [s, e] = await Promise.all([
      raw('stato').catch(err => { setErrore(err.message); return null }),
      raw('squadre_elenco').catch(err => { setErrore(err.message); return null }),
    ])
    if (s) setStato(s)
    if (e) setSq(e)
  }
  const iniziale = async () => {
    setErrore(null); setOccupato('stato')
    try { await aggiornaStato() } finally { setOccupato(null) }
  }

  // ── Squadre ──────────────────────────────────────────────
  const caricaSquadre = async () => {
    const r = await chiama('squadre_carica')
    if (r) { setSq({ squadre_api: r.squadre_api, squadre_progetto: r.squadre_progetto }); setStato(await raw('stato').catch(() => stato)) }
  }
  const associaSquadra = async (apiTeamId, nome) => {
    const r = await chiama('squadra_associa', { api_team_id: apiTeamId, squadra_serie_a: nome }, 'squadra')
    if (r) { setSq(r); setStato(await raw('stato').catch(() => stato)) }
  }
  const caricaRosa = async (apiTeamId) => {
    const r = await chiama('giocatori_carica', { api_team_id: apiTeamId }, `rosa-${apiTeamId}`)
    if (r) await aggiornaStato()
  }
  const caricaTutteLeRose = async () => {
    const squadre = (sq?.squadre_api ?? []).filter(s => s.squadra_serie_a)
    if (!squadre.length) return
    interrompi.current = false
    setErrore(null)
    try {
      for (let i = 0; i < squadre.length; i++) {
        if (interrompi.current) break
        setAvanz({ fatte: i, totale: squadre.length, corrente: squadre[i].api_nome })
        await raw('giocatori_carica', { api_team_id: squadre[i].api_team_id })
        if (i < squadre.length - 1) await attendi(PAUSA_TRA_ROSE_MS)
      }
    } catch (e) {
      setErrore(e.message)
    } finally {
      setAvanz(null)
      await aggiornaStato()
    }
  }

  // ── Giocatori ────────────────────────────────────────────
  const caricaElenco = async (override = {}) => {
    const r = await chiama('giocatori_elenco', {
      filtro: override.filtro ?? filtro, squadra: override.squadra ?? fSquadra, q: override.q ?? q, limite: 150,
    }, 'elenco')
    if (r) setElenco(r)
  }
  const automatch = async () => {
    const r = await chiama('giocatori_automatch', {}, 'automatch')
    if (r) { setRiepilogo(r); await aggiornaStato(); await caricaElenco() }
  }
  const associa = async (idGiocatore, apiPlayerId) => {
    const r = await chiama('giocatore_associa', { id_giocatore: idGiocatore, api_player_id: apiPlayerId }, 'associa')
    if (r) { await aggiornaStato(); await caricaElenco(); setRicerca(x => ({ ...x, [idGiocatore]: undefined })) }
  }
  const cercaApi = async (idGiocatore) => {
    const testo = ricerca[idGiocatore]?.q ?? ''
    if (testo.trim().length < 2) return
    const r = await chiama('giocatori_cerca_api', { q: testo.trim() }, 'cerca')
    if (r) setRicerca(x => ({ ...x, [idGiocatore]: { ...x[idGiocatore], risultati: r.righe } }))
  }

  const nomiSquadre = sq?.squadre_progetto ?? []
  const squadreAssociate = (sq?.squadre_api ?? []).filter(s => s.squadra_serie_a).length

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Avvio stagione"
        title="Mappatura API-Football"
        subtitle="Collega squadre e giocatori di API-Football a quelli del progetto, così le statistiche delle partite finiscono sul giocatore giusto."
      />

      <CardCredenziali cred={cred} />

      <div className="max-w-5xl mb-6 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <Pulsante onClick={iniziale} disabled={off} caricando={occupato === 'stato'} icona={RefreshCw} primario>
            {stato ? 'Aggiorna' : 'Carica stato'}
          </Pulsante>
          <TabBar
            tabs={[{ value: 'squadre', label: 'Squadre' }, { value: 'giocatori', label: 'Giocatori' }]}
            active={tab} onChange={setTab}
          />
        </div>

        {stato && (
          <div className="card p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <div className="text-xs text-slate-500 mb-1">Squadre associate</div>
              <Barra valore={stato.squadre_associate} totale={stato.squadre_progetto} />
            </div>
            <div>
              <div className="text-xs text-slate-500 mb-1">Giocatori del progetto associati</div>
              <Barra valore={stato.giocatori_progetto_associati} totale={stato.giocatori_progetto} />
            </div>
            <div>
              <div className="text-xs text-slate-500 mb-1">Richieste oggi</div>
              <Barra valore={stato.richieste_oggi} totale={stato.quota_max_locale} />
            </div>
          </div>
        )}

        {errore && <Avviso tono="red" icona={XCircle}>{errore}</Avviso>}

        {!stato && !errore && (
          <Avviso>
            Ordine consigliato: <b>1)</b> carica le squadre, <b>2)</b> controlla gli abbinamenti, <b>3)</b> carica le
            rose (1 richiesta per squadra, circa 20 in tutto), <b>4)</b> esegui l'abbinamento automatico dei giocatori,
            <b>5)</b> rivedi quelli rimasti.
          </Avviso>
        )}
      </div>

      {/* ── SQUADRE ─────────────────────────────────────────── */}
      {tab === 'squadre' && (
        <div className="card p-6 max-w-5xl">
          <div className="flex flex-wrap items-center gap-3 mb-5">
            <Pulsante onClick={caricaSquadre} disabled={off} caricando={occupato === 'squadre_carica'} icona={Download}
                      titolo="Usa 1 richiesta">
              Carica squadre da API-Football
            </Pulsante>
            <Pulsante onClick={caricaTutteLeRose} disabled={off || squadreAssociate === 0} icona={Users}
                      titolo="1 richiesta per squadra, con pausa tra una e l'altra">
              Carica tutte le rose
            </Pulsante>
            {avanz && (
              <>
                <span className="text-sm text-slate-400">
                  {avanz.corrente}… ({avanz.fatte + 1}/{avanz.totale})
                </span>
                <Pulsante onClick={() => { interrompi.current = true }} icona={Square} piccolo>Interrompi</Pulsante>
              </>
            )}
          </div>

          {!sq || sq.squadre_api.length === 0 ? (
            <p className="text-sm text-slate-500">Nessuna squadra caricata: usa "Carica squadre da API-Football".</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-slate-500 border-b border-white/5">
                    <th className="py-2 pr-3 font-medium">Squadra API-Football</th>
                    <th className="py-2 pr-3 font-medium">Squadra del progetto</th>
                    <th className="py-2 pr-3 font-medium">Giocatori associati</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-white/5">
                  {sq.squadre_api.map(s => (
                    <tr key={s.api_team_id}>
                      <td className="py-2 pr-3 text-slate-200">{s.api_nome}</td>
                      <td className="py-2 pr-3">
                        <div className="flex items-center gap-2">
                          <select
                            value={s.squadra_serie_a ?? ''} disabled={off}
                            onChange={e => associaSquadra(s.api_team_id, e.target.value)}
                            className="fanta-input cursor-pointer max-w-[14rem]"
                          >
                            <option value="">— non associata —</option>
                            {nomiSquadre.map(n => <option key={n} value={n}>{n}</option>)}
                          </select>
                          {s.metodo && <span className="text-[10px] uppercase tracking-wide text-slate-500">{s.metodo}</span>}
                        </div>
                      </td>
                      <td className="py-2 pr-3 text-slate-400 tabular-nums">
                        {s.giocatori_api > 0 ? `${s.giocatori_associati}/${s.giocatori_api}` : <span className="text-slate-600">rosa non caricata</span>}
                      </td>
                      <td className="py-2 text-right">
                        <Pulsante onClick={() => caricaRosa(s.api_team_id)} disabled={off || !s.squadra_serie_a}
                                  caricando={occupato === `rosa-${s.api_team_id}`} icona={Download} piccolo
                                  titolo={s.squadra_serie_a ? 'Usa 1 richiesta' : 'Associa prima la squadra'}>
                          Carica rosa
                        </Pulsante>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ── GIOCATORI ───────────────────────────────────────── */}
      {tab === 'giocatori' && (
        <div className="card p-6 max-w-5xl">
          <div className="flex flex-wrap items-center gap-3 mb-4">
            <Pulsante onClick={automatch} disabled={off} caricando={occupato === 'automatch'} icona={Wand2} primario
                      titolo="Operazione locale: nessuna richiesta all'API">
              Abbinamento automatico
            </Pulsante>
          </div>

          {riepilogo && (
            <div className="mb-4">
              <Avviso tono="green" icona={CheckCircle2}>
                Associati ora <b>{riepilogo.associati_ora}</b> giocatori su {riepilogo.giocatori_progetto - riepilogo.gia_associati} da abbinare:
                {' '}<b>{riepilogo.da_rivedere}</b> da rivedere (più candidati o affinità incerta),{' '}
                <b>{riepilogo.senza_candidati}</b> senza candidati (rosa non caricata o nome troppo diverso).
              </Avviso>
            </div>
          )}

          <div className="flex flex-wrap items-end gap-3 mb-5">
            <div>
              <label className="text-xs text-slate-600 mb-1 block">Mostra</label>
              <select value={filtro} onChange={e => { setFiltro(e.target.value); setElenco(null) }}
                      className="fanta-input cursor-pointer">
                <option value="da_associare">Da associare</option>
                <option value="associati">Associati</option>
              </select>
            </div>
            <div>
              <label className="text-xs text-slate-600 mb-1 block">Squadra</label>
              <select value={fSquadra} onChange={e => setFSquadra(e.target.value)} className="fanta-input cursor-pointer">
                <option value="">Tutte</option>
                {nomiSquadre.map(n => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs text-slate-600 mb-1 block">Nome</label>
              <input value={q} onChange={e => setQ(e.target.value)} onKeyDown={e => e.key === 'Enter' && cred.pronto && caricaElenco()}
                     className="fanta-input" placeholder="cerca…" />
            </div>
            <Pulsante onClick={() => caricaElenco()} disabled={off} caricando={occupato === 'elenco'} icona={Search}>
              Mostra elenco
            </Pulsante>
          </div>

          {elenco && (
            <>
              <p className="text-xs text-slate-500 mb-2">
                {elenco.totale} giocatori{elenco.totale > elenco.righe.length ? ` (primi ${elenco.righe.length}: restringi con i filtri)` : ''}
              </p>
              <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
                {elenco.righe.length === 0 && <li className="px-3 py-3 text-sm text-slate-500">Nessun giocatore.</li>}
                {elenco.righe.map(r => (
                  <li key={r.id_giocatore} className="px-3 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                      <span className="w-5 text-center text-xs font-semibold text-slate-400">{RUOLI[r.ruolo] ?? '?'}</span>
                      <span className="text-slate-200 font-medium">{r.nome}</span>
                      <span className="text-xs text-slate-500">{r.squadra}</span>

                      {filtro === 'associati' ? (
                        <>
                          <span className="text-slate-400 flex items-center gap-1.5">
                            <Link2 className="w-3.5 h-3.5" /> {r.api_nome}
                            <span className="text-[10px] uppercase tracking-wide text-slate-500">{r.metodo}{r.punteggio != null ? ` ${r.punteggio}` : ''}</span>
                          </span>
                          <span className="ml-auto">
                            <Pulsante onClick={() => associa(r.id_giocatore, null)} disabled={off} icona={Unlink} piccolo>Sgancia</Pulsante>
                          </span>
                        </>
                      ) : (
                        <span className="ml-auto flex flex-wrap items-center gap-2">
                          {r.candidati.map(c => (
                            <Pulsante key={c.api_player_id} onClick={() => associa(r.id_giocatore, c.api_player_id)} disabled={off} piccolo
                                      titolo={`Affinità ${c.punteggio}`}>
                              {c.api_nome} <span className="text-slate-500">({c.punteggio})</span>
                            </Pulsante>
                          ))}
                          <Pulsante onClick={() => setRicerca(x => ({ ...x, [r.id_giocatore]: x[r.id_giocatore] ? undefined : { q: '', risultati: null } }))}
                                    disabled={off} icona={Search} piccolo>
                            Cerca
                          </Pulsante>
                        </span>
                      )}
                    </div>

                    {ricerca[r.id_giocatore] && (
                      <div className="mt-2 ml-8 space-y-2">
                        <div className="flex gap-2">
                          <input
                            value={ricerca[r.id_giocatore].q}
                            onChange={e => setRicerca(x => ({ ...x, [r.id_giocatore]: { ...x[r.id_giocatore], q: e.target.value } }))}
                            onKeyDown={e => e.key === 'Enter' && cercaApi(r.id_giocatore)}
                            className="fanta-input max-w-xs" placeholder="nome in API-Football" autoFocus
                          />
                          <Pulsante onClick={() => cercaApi(r.id_giocatore)} disabled={off} icona={Search} piccolo>Cerca</Pulsante>
                        </div>
                        {ricerca[r.id_giocatore].risultati && (
                          ricerca[r.id_giocatore].risultati.length === 0
                            ? <p className="text-xs text-slate-500">Nessun giocatore API libero con questo nome (carica prima le rose).</p>
                            : (
                              <div className="flex flex-wrap gap-2">
                                {ricerca[r.id_giocatore].risultati.map(c => (
                                  <Pulsante key={c.api_player_id} onClick={() => associa(r.id_giocatore, c.api_player_id)} disabled={off} piccolo>
                                    {c.api_nome} <span className="text-slate-500">{c.squadra_api ?? ''}</span>
                                  </Pulsante>
                                ))}
                              </div>
                            )
                        )}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
    </div>
  )
}
