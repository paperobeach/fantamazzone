import { useState } from 'react'
import { afSync } from '../api/client'
import { PageHeader } from '../components/ui'
import {
  useCredenzialiAdmin, CardCredenziali, Avviso, Pulsante, JsonGrezzo, fmtDataOra,
} from '../components/ApiFootballUi'
import {
  RefreshCw, PlayCircle, FlaskConical, RotateCw, XCircle, CheckCircle2, Clock, AlertTriangle,
} from 'lucide-react'

// ============================================================
// "Sincronizzazione API-Football" — Fase 1. Monitor e comandi manuali
// della sincronizzazione delle statistiche dei giocatori.
//
// Il cron (cron_api_football.php, una chiamata all'ora) interroga
// API-Football SOLO se c'è una partita in corso o appena conclusa e
// non ancora sincronizzata; dopo il salvataggio delle statistiche
// finali la partita non viene più interrogata. Questa pagina mostra
// lo stato di ogni partita e permette di:
//   - simulare il cron (applica le regole, nessuna chiamata);
//   - eseguire subito una sincronizzazione;
//   - rileggere una singola partita.
// Accesso: solo amministratori.
// ============================================================

const STATI_FINALI = ['FT', 'AET', 'PEN']
const STATI_IN_CORSO = ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'SUSP', 'INT']

function BadgeStato({ p }) {
  const s = p.api_stato
  if (p.dettaglio_finale === 'Y') {
    return <span className="inline-flex items-center gap-1 text-green-400 text-xs"><CheckCircle2 className="w-3.5 h-3.5" /> Statistiche salvate</span>
  }
  if (STATI_FINALI.includes(s)) return <span className="text-gold-400 text-xs">Conclusa, statistiche mancanti</span>
  if (STATI_IN_CORSO.includes(s)) return <span className="text-sky-400 text-xs">In corso ({s})</span>
  if (['PST', 'CANC', 'ABD', 'AWD', 'WO'].includes(s)) return <span className="text-slate-400 text-xs">Non disputata ({s})</span>
  if (s === 'NS') return <span className="text-slate-500 text-xs">Non iniziata</span>
  return <span className="text-slate-600 text-xs">Mai controllata</span>
}

function Report({ r }) {
  return (
    <div className="space-y-4">
      <Avviso tono={r.ok ? 'green' : 'red'} icona={r.ok ? CheckCircle2 : XCircle}>
        {r.dry ? 'Simulazione — ' : ''}{r.messaggio} <span className="text-xs opacity-70">(ore {r.ora.slice(11, 16)})</span>
      </Avviso>

      {r.avvisi?.map((a, i) => <Avviso key={i} tono="gold">{a}</Avviso>)}

      {r.partite.length > 0 && (
        <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
          {r.partite.map(p => (
            <li key={`${p.giornata}-${p.n_partita}`} className="px-3 py-2 text-sm">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-slate-200">{p.partita}</span>
                <span className="text-xs text-slate-500">{fmtDataOra(p.inizio)}</span>
                <span className={`ml-auto text-xs ${p.decisione === 'controlla' ? 'text-grass-400' : 'text-slate-500'}`}>
                  {p.decisione === 'controlla' ? 'interrogata' : 'saltata'}: {p.motivo}
                </span>
              </div>
              {p.esito && <div className="text-xs text-slate-400 mt-0.5">{p.esito}</div>}
            </li>
          ))}
        </ul>
      )}

      {r.chiamate?.length > 0 && (
        <div>
          <h4 className="text-xs font-semibold text-slate-400 mb-1">Chiamate effettuate ({r.chiamate.length})</h4>
          <ul className="divide-y divide-white/5 rounded-lg border border-white/5 text-xs">
            {r.chiamate.map((c, i) => (
              <li key={i} className="px-3 py-1.5 flex gap-2">
                {c.ok ? <CheckCircle2 className="w-3.5 h-3.5 text-green-400 flex-shrink-0 mt-px" /> : <XCircle className="w-3.5 h-3.5 text-red-400 flex-shrink-0 mt-px" />}
                <span className="text-slate-300 font-mono break-all">{c.endpoint}?{c.parametri}</span>
                {c.errore && <span className="text-red-400 ml-auto text-right">{c.errore}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
      <JsonGrezzo dati={r} />
    </div>
  )
}

export default function ApiFootballSync() {
  const cred = useCredenzialiAdmin()
  const [errore, setErrore] = useState(null)
  const [occupato, setOccupato] = useState(null)
  const [dati, setDati] = useState(null)      // risposta di 'stato'
  const [report, setReport] = useState(null)  // risposta di simula/esegui/forza
  const [giornata, setGiornata] = useState(null)

  const off = !cred.pronto || !!occupato

  const chiama = async (azione, extra = {}, etichetta = azione) => {
    setErrore(null)
    setOccupato(etichetta)
    try {
      return await afSync(cred.stagioneRif, { ...cred.auth, azione, ...extra })
    } catch (e) {
      setErrore(e.message)
      return null
    } finally {
      setOccupato(null)
    }
  }

  const carica = async (g = giornata) => {
    const r = await chiama('stato', g ? { giornata: g } : {}, 'stato')
    if (r) { setDati(r); setGiornata(r.giornata) }
  }
  const lancia = async (azione, extra = {}, etichetta = azione) => {
    const r = await chiama(azione, extra, etichetta)
    if (r) { setReport(r); await carica() }
  }

  const quotaPerc = dati ? Math.min(100, Math.round((dati.richieste_oggi / Math.max(1, dati.quota_max_locale)) * 100)) : 0

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Avvio stagione"
        title="Sincronizzazione API-Football"
        subtitle="Stato delle partite di Serie A e delle statistiche scaricate. Il cron interroga l'API solo con partite in corso o appena concluse."
      />

      <CardCredenziali cred={cred} />

      <div className="max-w-5xl space-y-4 mb-6">
        <div className="flex flex-wrap gap-3">
          <Pulsante onClick={() => carica()} disabled={off} caricando={occupato === 'stato'} icona={RefreshCw} primario>
            {dati ? 'Aggiorna stato' : 'Carica stato'}
          </Pulsante>
          <Pulsante onClick={() => lancia('simula', {}, 'simula')} disabled={off} caricando={occupato === 'simula'} icona={FlaskConical}
                    titolo="Applica le regole del cron senza chiamare l'API">
            Simula cron (nessuna chiamata)
          </Pulsante>
          <Pulsante onClick={() => lancia('esegui', {}, 'esegui')} disabled={off} caricando={occupato === 'esegui'} icona={PlayCircle}
                    titolo="Equivale a un'esecuzione del cron: usa richieste solo se serve">
            Esegui sincronizzazione ora
          </Pulsante>
        </div>
        {errore && <Avviso tono="red" icona={XCircle}>{errore}</Avviso>}
      </div>

      {dati && (
        <div className="max-w-5xl space-y-6">
          {!dati.chiave_impostata && (
            <Avviso tono="red" icona={XCircle}>
              Chiave API non configurata: crea <span className="font-mono">config_api_football.php</span> (vedi{' '}
              <span className="font-mono">config_api_football.example.php</span>) e verifica da "Test API-Football".
            </Avviso>
          )}
          {dati.mappatura.squadre_associate < dati.mappatura.squadre_api || dati.mappatura.squadre_api === 0 ? (
            <Avviso>
              Squadre associate a API-Football: {dati.mappatura.squadre_associate}/{dati.mappatura.squadre_api}.
              Completa la <b>Mappatura API-Football</b> prima di attivare il cron.
            </Avviso>
          ) : null}

          <div className="card p-4 grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm">
            <div>
              <div className="text-xs text-slate-500 mb-1">Ora del server (Roma)</div>
              <div className="text-slate-200 flex items-center gap-1.5"><Clock className="w-3.5 h-3.5 text-slate-500" /> {fmtDataOra(dati.ora_roma)}</div>
            </div>
            <div>
              <div className="text-xs text-slate-500 mb-1">Richieste oggi (UTC)</div>
              <div className="flex items-center gap-2">
                <div className="h-1.5 flex-1 rounded-full bg-white/5 overflow-hidden"><div className={`h-full ${quotaPerc > 80 ? 'bg-red-500' : 'bg-grass-500'}`} style={{ width: `${quotaPerc}%` }} /></div>
                <span className="text-xs text-slate-400 tabular-nums">{dati.richieste_oggi}/{dati.quota_max_locale}</span>
              </div>
            </div>
            <div>
              <div className="text-xs text-slate-500 mb-1">Giocatori associati</div>
              <div className="text-slate-200 tabular-nums">{dati.mappatura.giocatori_associati}/{dati.mappatura.giocatori_api}</div>
            </div>
            <div>
              <div className="text-xs text-slate-500 mb-1">Regole</div>
              <div className="text-slate-400 text-xs">fine stimata +{dati.regole.durata_partita_min}′ · limite +{dati.regole.limite_attesa_min}′</div>
            </div>
          </div>

          <div className="card p-6">
            <div className="flex flex-wrap items-center gap-3 mb-4">
              <h3 className="font-semibold text-slate-200">Giornata di Serie A</h3>
              <select
                value={giornata ?? ''} disabled={off}
                onChange={e => { setGiornata(Number(e.target.value)); carica(Number(e.target.value)) }}
                className="fanta-input cursor-pointer max-w-[8rem]"
              >
                {dati.giornate.map(g => <option key={g} value={g}>{g}ª</option>)}
              </select>
            </div>

            {dati.partite.length === 0 ? (
              <p className="text-sm text-slate-500">Nessuna partita nel calendario per questa giornata.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-slate-500 border-b border-white/5">
                      <th className="py-2 pr-3 font-medium">Inizio</th>
                      <th className="py-2 pr-3 font-medium">Partita</th>
                      <th className="py-2 pr-3 font-medium">Stato</th>
                      <th className="py-2 pr-3 font-medium">Statistiche</th>
                      <th className="py-2 pr-3 font-medium">Ultimo controllo</th>
                      <th className="py-2 pr-3 font-medium">Prossima azione</th>
                      <th className="py-2 font-medium" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5 align-top">
                    {dati.partite.map(p => (
                      <tr key={p.n_partita}>
                        <td className="py-2 pr-3 text-slate-400 whitespace-nowrap">{fmtDataOra(p.inizio)}</td>
                        <td className="py-2 pr-3 text-slate-200">
                          {p.casa} – {p.ospite}
                          {p.gol_casa != null && <span className="ml-2 text-slate-400 tabular-nums">{p.gol_casa}-{p.gol_ospite}</span>}
                        </td>
                        <td className="py-2 pr-3"><BadgeStato p={p} />{p.errore && <div className="text-xs text-red-400 mt-0.5">{p.errore}</div>}</td>
                        <td className="py-2 pr-3 text-slate-400 text-xs tabular-nums">
                          {p.n_giocatori > 0 ? <>{p.n_giocatori} giocatori{p.n_non_mappati > 0 && <span className="text-gold-400"> · {p.n_non_mappati} non associati</span>}</> : '—'}
                        </td>
                        <td className="py-2 pr-3 text-slate-500 text-xs whitespace-nowrap">{fmtDataOra(p.ultimo_controllo)}{p.n_controlli > 0 && ` (${p.n_controlli})`}</td>
                        <td className="py-2 pr-3 text-slate-500 text-xs">{p.prossima_azione}</td>
                        <td className="py-2 text-right">
                          <Pulsante onClick={() => lancia('forza', { giornata: p.giornata, n_partita: p.n_partita }, `forza-${p.n_partita}`)}
                                    disabled={off || !p.inizio} caricando={occupato === `forza-${p.n_partita}`} icona={RotateCw} piccolo
                                    titolo="Rilegge questa partita da API-Football (1-2 richieste)">
                            Rileggi
                          </Pulsante>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {dati.ultime_chiamate.length > 0 && (
            <div className="card p-6">
              <h3 className="font-semibold text-slate-200 mb-3">Ultime chiamate ad API-Football</h3>
              <ul className="divide-y divide-white/5 text-xs">
                {dati.ultime_chiamate.map((c, i) => (
                  <li key={i} className="py-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
                    <span className="text-slate-500 tabular-nums">{c.data_ora.slice(5, 16)} UTC</span>
                    <span className={c.ok === 'Y' ? 'text-green-400' : 'text-red-400'}>{c.ok === 'Y' ? 'OK' : 'ERR'} {c.http}</span>
                    <span className="text-slate-300 font-mono break-all">{c.endpoint}{c.parametri ? `?${c.parametri}` : ''}</span>
                    <span className="text-slate-600 ml-auto">{c.ms} ms{c.richieste_rimaste != null ? ` · rimaste ${c.richieste_rimaste}` : ''}</span>
                    {c.errore && <span className="w-full text-red-400">{c.errore}</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="card p-6">
            <h3 className="font-semibold text-slate-200 mb-3 flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-slate-400" /> Attivazione del cron
            </h3>
            <ol className="text-sm text-slate-400 space-y-1.5 list-decimal pl-5">
              <li>Imposta in <span className="font-mono">config_api_football.php</span> un <span className="font-mono">cron_token</span> lungo e casuale.</li>
              <li>
                Configura un cron <b>orario</b> (es. cron-job.org, ogni ora al minuto 0) sull'indirizzo{' '}
                <span className="font-mono break-all">…/cron_api_football.php?token=IL_TUO_TOKEN</span>
              </li>
              <li>Con nessuna partita in corso la chiamata non contatta API-Football e risponde subito: puoi lasciarlo attivo sempre.</li>
              <li>Dopo il salvataggio delle statistiche finali di una partita, questa non viene più interrogata (usa "Rileggi" per forzarla).</li>
            </ol>
          </div>
        </div>
      )}

      {report && (
        <div className="card p-6 max-w-5xl mt-6">
          <h3 className="font-semibold text-slate-200 mb-4">Esito dell'ultima operazione</h3>
          <Report r={report} />
        </div>
      )}
    </div>
  )
}
