import { useState } from 'react'
import { afTest } from '../api/client'
import { PageHeader } from '../components/ui'
import {
  useCredenzialiAdmin, CardCredenziali, Avviso, Voce, Pulsante, JsonGrezzo,
} from '../components/ApiFootballUi'
import {
  Stethoscope, Network, Gauge, ShieldCheck, FlaskConical,
  CheckCircle2, XCircle, AlertTriangle,
} from 'lucide-react'

// ============================================================
// "Test API-Football" — Fase 0 dell'integrazione delle statistiche
// dei giocatori. Verifica, prima di sviluppare il resto:
//   - la raggiungibilità di API-Football dall'hosting (Altervista);
//   - chiave, piano e quota dell'account;
//   - la copertura della Serie A per la stagione in uso;
//   - il formato dei dati su UNA partita conclusa (parametro "ids",
//     giocatori, eventi, minuti, rating).
// Non scrive nulla sul database. Accesso: solo amministratori.
// ============================================================

const MESSAGGI_RETE = {
  raggiungibile: { tono: 'green', icona: CheckCircle2, testo: 'API-Football è raggiungibile dal server.' },
  solo_diretto: {
    tono: 'gold', icona: AlertTriangle,
    testo: 'API-Football è raggiungibile solo saltando il proxy dell\'hosting. In config_api_football.php imposta "proxy" => "nessuno".',
  },
  bloccato_solo_api_football: {
    tono: 'red', icona: XCircle,
    testo: 'Il server raggiunge altri siti ma non API-Football: l\'hosting filtra i domini in uscita. Serve chiedere l\'abilitazione di v3.football.api-sports.io o un relay.',
  },
  bloccato_tutto: {
    tono: 'red', icona: XCircle,
    testo: 'Il server non raggiunge nessun sito esterno: le chiamate in uscita sono bloccate dall\'hosting.',
  },
  curl_assente: { tono: 'red', icona: XCircle, testo: 'cURL non è disponibile sul server.' },
}

function EsitoProva({ p }) {
  if (!p) return <span className="text-slate-600">non provato</span>
  return p.ok
    ? <span className="text-green-400">OK · HTTP {p.http} · {p.ms} ms</span>
    : <span className="text-red-400 break-words">{p.errore || 'errore'}</span>
}

function RisultatoDiagnostica({ d }) {
  return (
    <div className="space-y-4">
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        <Voce ok={d.file_configurazione} label="File config_api_football.php presente" />
        <Voce ok={d.chiave_impostata} label="Chiave API compilata" />
        <Voce ok={d.token_cron_valido} label="Token del cron impostato (almeno 16 caratteri)" />
        <Voce ok={d.ambiente.curl_disponibile} label="cURL disponibile" />
        <Voce ok={d.ambiente.openssl_disponibile} label="OpenSSL disponibile (HTTPS)" />
        <Voce ok={d.tabelle_mancanti.length === 0} label="Tabelle NEW_API_* create"
              dettaglio={d.tabelle_mancanti.length ? `mancano: ${d.tabelle_mancanti.join(', ')}` : null} />
        <Voce ok label="Lega / stagione API-Football" dettaglio={`${d.lega} / ${d.season_api}`} />
        <Voce ok label="Ora del server (Roma)" dettaglio={d.ora_roma} />
        <Voce ok label="Regole del cron" dettaglio={`fine stimata +${d.regole.durata_partita_min} min · limite +${d.regole.limite_attesa_min} min`} />
        <Voce ok label="Richieste registrate oggi (UTC)" dettaglio={`${d.richieste_oggi} / tetto locale ${d.quota_max_locale}`} />
        <Voce ok label="Proxy configurato" dettaglio={d.proxy} />
        <Voce ok label="Versione PHP" dettaglio={d.ambiente.php_version} />
      </ul>
      {d.tabelle_mancanti.length > 0 && (
        <Avviso tono="red" icona={XCircle}>
          Eseguire lo script <span className="font-mono">Script DB/NEW_API_FOOTBALL.sql</span> sul database prima di procedere.
        </Avviso>
      )}
      {!d.file_configurazione && (
        <Avviso tono="red" icona={XCircle}>
          Copia <span className="font-mono">config_api_football.example.php</span> in{' '}
          <span className="font-mono">config_api_football.php</span>, compilalo e caricalo via FTP accanto a connect.php.
        </Avviso>
      )}
    </div>
  )
}

function RisultatoRete({ r }) {
  const m = MESSAGGI_RETE[r.esito] ?? MESSAGGI_RETE.bloccato_tutto
  return (
    <div className="space-y-4">
      <Avviso tono={m.tono} icona={m.icona}>{m.testo}</Avviso>
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        {r.prove.map(p => (
          <li key={p.host} className="px-3 py-2 text-sm">
            <div className="flex items-center gap-2">
              {(p.predefinito.ok || p.diretto?.ok)
                ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0" />
                : <XCircle className="w-4 h-4 text-red-400 flex-shrink-0" />}
              <span className="text-slate-300">{p.host}</span>
            </div>
            <div className="mt-1 ml-6 text-xs text-slate-500 space-y-0.5">
              <div>Percorso predefinito: <EsitoProva p={p.predefinito} /></div>
              {p.diretto && <div>Connessione diretta: <EsitoProva p={p.diretto} /></div>}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}

function RisultatoStato({ r }) {
  if (!r.ok) return <Avviso tono="red" icona={XCircle}>{r.errore || 'Errore sconosciuto'}</Avviso>
  const p = r.piano ?? {}
  const q = r.richieste ?? {}
  return (
    <div className="space-y-3">
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        <Voce ok={p.active !== false} label="Piano" dettaglio={`${p.plan ?? '—'}${p.end ? ` · scade ${String(p.end).slice(0, 10)}` : ''}`} />
        <Voce ok label="Richieste di oggi secondo API-Football" dettaglio={`${q.current ?? '—'} / ${q.limit_day ?? '—'}`} />
        <Voce ok label="Richieste registrate dall'app oggi" dettaglio={r.richieste_oggi_registro} />
      </ul>
      {Number(q.limit_day) > 0 && Number(q.limit_day) <= 100 && (
        <Avviso tono="gold">
          Piano gratuito: 100 richieste al giorno e 10 al minuto. Le regole del cron (una chiamata all'ora, solo con
          partite in corso) restano ampiamente sotto questo limite.
        </Avviso>
      )}
    </div>
  )
}

const FLAG = (v) => (v === true ? 'sì' : v === false ? 'no' : '—')

function RisultatoCopertura({ r }) {
  if (!r.ok) return <Avviso tono="red" icona={XCircle}>{r.errore || 'Errore sconosciuto'}</Avviso>
  const c = r.dettaglio_season?.copertura
  return (
    <div className="space-y-3">
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        <Voce ok label="Lega" dettaglio={`${r.lega.nome} (${r.lega.paese}) · id ${r.lega.id}`} />
        <Voce ok={r.season_trovata} label={`Stagione ${r.season_richiesta} presente`}
              dettaglio={r.season_trovata ? `${r.dettaglio_season.inizio} → ${r.dettaglio_season.fine}` : 'non trovata'} />
        {c && <>
          <Voce ok={!!c.fixtures?.statistics_players} label="Statistiche per giocatore" dettaglio={FLAG(c.fixtures?.statistics_players)} />
          <Voce ok={!!c.fixtures?.events} label="Eventi di gioco (gol, autogol, cartellini)" dettaglio={FLAG(c.fixtures?.events)} />
          <Voce ok={!!c.fixtures?.lineups} label="Formazioni" dettaglio={FLAG(c.fixtures?.lineups)} />
          <Voce ok={!!c.players} label="Rose dei giocatori" dettaglio={FLAG(c.players)} />
        </>}
      </ul>
      {!r.season_trovata && (
        <Avviso tono="red" icona={XCircle}>
          La stagione non risulta in API-Football: verifica <span className="font-mono">season_api</span> in config_api_football.php.
        </Avviso>
      )}
    </div>
  )
}

function RisultatoProva({ r }) {
  const g = r.giocatori
  return (
    <div className="space-y-4">
      {r.avvisi?.map((a, i) => <Avviso key={i} tono={r.ok ? 'gold' : 'red'}>{a}</Avviso>)}
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        {r.passi.map((p, i) => (
          <Voce key={i} ok={p.ok} label={p.passo} dettaglio={p.errore ?? (p.trovate != null ? `${p.trovate} trovate` : (p.ms != null ? `${p.ms} ms` : null))} />
        ))}
        {r.ids_supportato != null && (
          <Voce ok={r.ids_supportato} label='Il parametro "ids" restituisce il dettaglio della partita' />
        )}
      </ul>

      {r.partita && (
        <div className="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-300">
          <span className="font-semibold">{r.partita.casa} {r.partita.gol} {r.partita.ospite}</span>
          <span className="text-xs text-slate-500"> · {r.partita.stato} · id {r.partita.fixture_id} · {String(r.partita.data).slice(0, 10)}</span>
        </div>
      )}

      {r.sezioni_nella_risposta && (
        <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
          <Voce ok={r.sezioni_nella_risposta.players} label="Statistiche giocatore nella stessa risposta" />
          <Voce ok={r.sezioni_nella_risposta.events} label="Eventi nella stessa risposta (autogol)" />
        </ul>
      )}

      {g && (
        <>
          <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
            <Voce ok={g.totale > 0} label="Giocatori con statistiche" dettaglio={`${g.totale} (${g.con_minuti} con minuti)`} />
            <Voce ok={g.con_rating > 0} label="Giocatori con rating API" dettaglio={g.con_rating} />
            <Voce ok label="Gol · assist · ammonizioni · espulsioni · autogol"
                  dettaglio={`${g.gol} · ${g.assist} · ${g.ammonizioni} · ${g.espulsioni} · ${g.autogol}`} />
          </ul>
          <p className="text-xs text-slate-500">
            Il confronto con il risultato reale (gol, autogol, cartellini) è il controllo di qualità dei dati.
            Il rating API non è il fantavoto: il voto base resta 6 salvo modifica manuale.
          </p>
        </>
      )}

      {r.esempio?.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-white/5">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-500 border-b border-white/5">
                {['Giocatore', 'Min', 'Rating', 'Gf', 'Rf', 'Gs', 'Ass', 'Amm', 'Esp', 'Au'].map(h => (
                  <th key={h} className="px-3 py-2 font-medium">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5 text-slate-300">
              {r.esempio.map((e, i) => (
                <tr key={i}>
                  <td className="px-3 py-1.5">{e.nome}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.minuti}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.rating ?? '—'}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.gf}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.rf}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.gs}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.ass}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.amm}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.esp}</td>
                  <td className="px-3 py-1.5 tabular-nums">{e.au}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function Sezione({ titolo, icona: Icona, children, grezzo }) {
  return (
    <div className="card p-6 max-w-2xl mb-6">
      <h3 className="font-semibold text-slate-200 mb-4 flex items-center gap-2">
        <Icona className="w-4 h-4 text-slate-400" /> {titolo}
      </h3>
      {children}
      {grezzo && <JsonGrezzo dati={grezzo} />}
    </div>
  )
}

export default function ApiFootballTest() {
  const cred = useCredenzialiAdmin()
  const [azione, setAzione] = useState(null)
  const [errore, setErrore] = useState(null)
  const [fixtureId, setFixtureId] = useState('')
  const [ris, setRis] = useState({})

  const esegui = async (tipo) => {
    setErrore(null)
    setAzione(tipo)
    try {
      const extra = tipo === 'prova_fixture' && fixtureId.trim() !== '' ? { fixture_id: Number(fixtureId) } : {}
      const res = await afTest(cred.stagioneRif, { ...cred.auth, azione: tipo, ...extra })
      setRis(r => ({ ...r, [tipo]: res }))
    } catch (err) {
      setErrore(err.message)
    } finally {
      setAzione(null)
    }
  }
  const off = !cred.pronto || !!azione

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Avvio stagione"
        title="Test API-Football"
        subtitle="Verifica che il server raggiunga API-Football, che chiave e piano siano validi e che i dati delle partite siano nel formato atteso. Non scrive nulla sul database."
      />

      <CardCredenziali cred={cred} />

      <div className="card p-6 max-w-2xl mb-6">
        <h3 className="font-semibold text-slate-200 mb-4">Verifiche</h3>
        <div className="flex flex-wrap gap-3">
          <Pulsante onClick={() => esegui('diagnostica')} disabled={off} caricando={azione === 'diagnostica'} icona={Stethoscope}>
            Diagnostica (gratuita)
          </Pulsante>
          <Pulsante onClick={() => esegui('rete')} disabled={off} caricando={azione === 'rete'} icona={Network}>
            Verifica rete (gratuita)
          </Pulsante>
          <Pulsante onClick={() => esegui('stato')} disabled={off} caricando={azione === 'stato'} icona={Gauge}
                    titolo="Usa 1 richiesta">
            Piano e quota
          </Pulsante>
          <Pulsante onClick={() => esegui('copertura')} disabled={off} caricando={azione === 'copertura'} icona={ShieldCheck}
                    titolo="Usa 1 richiesta">
            Copertura Serie A
          </Pulsante>
        </div>

        <div className="mt-5 pt-5 border-t border-white/5">
          <label htmlFor="af-fixture" className="text-xs text-slate-600 mb-1 block">
            ID partita (facoltativo: se vuoto si usa l'ultima partita conclusa)
          </label>
          <div className="flex flex-wrap gap-3 items-center">
            <input
              id="af-fixture" type="text" inputMode="numeric" value={fixtureId}
              onChange={e => setFixtureId(e.target.value.replace(/\D/g, ''))}
              className="fanta-input max-w-[10rem]" placeholder="es. 1234567"
            />
            <Pulsante onClick={() => esegui('prova_fixture')} disabled={off} caricando={azione === 'prova_fixture'}
                      icona={FlaskConical} primario titolo="Usa 2-4 richieste">
              Prova su una partita (2-4 richieste)
            </Pulsante>
          </div>
        </div>

        {errore && <div className="mt-4"><Avviso tono="red" icona={XCircle}>{errore}</Avviso></div>}
      </div>

      {ris.diagnostica && <Sezione titolo="Diagnostica" icona={Stethoscope} grezzo={ris.diagnostica}><RisultatoDiagnostica d={ris.diagnostica} /></Sezione>}
      {ris.rete && <Sezione titolo="Raggiungibilità" icona={Network} grezzo={ris.rete}><RisultatoRete r={ris.rete} /></Sezione>}
      {ris.stato && <Sezione titolo="Piano e quota" icona={Gauge} grezzo={ris.stato}><RisultatoStato r={ris.stato} /></Sezione>}
      {ris.copertura && <Sezione titolo="Copertura Serie A" icona={ShieldCheck} grezzo={ris.copertura}><RisultatoCopertura r={ris.copertura} /></Sezione>}
      {ris.prova_fixture && <Sezione titolo="Prova su una partita conclusa" icona={FlaskConical} grezzo={ris.prova_fixture}><RisultatoProva r={ris.prova_fixture} /></Sezione>}
    </div>
  )
}
