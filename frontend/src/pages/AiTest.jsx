import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { aiConnessione } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import {
  PlugZap, Stethoscope, CheckCircle2, XCircle, AlertTriangle, Lock, Network,
} from 'lucide-react'

// ============================================================
// "Test connessione AI" — Fase 1 della funzione di generazione articoli.
// Verifica dal server (Altervista) che le chiamate verso le API
// Anthropic funzionino e che la chiave configurata in
// backend/config_ai.php sia valida.
//
// Accesso: solo utenze con NEW_UTENZE.abilita_ai = 'Y' (voce di menu
// "AI" e rotta protetta in App.jsx). Ogni chiamata richiede di nuovo
// utenza e password, verificate lato server (il test ha un costo).
//
// Tre azioni:
//   - Diagnostica: controlli locali sull'hosting, nessuna chiamata
//     verso Anthropic, nessun costo;
//   - Verifica rete: prova la raggiungibilità di api.anthropic.com e di
//     host di controllo (con proxy dell'hosting e in diretta), gratuita;
//   - Test: conteggio token (gratuito) + risposta di pochi token
//     (costo dell'ordine di frazioni di centesimo).
// ============================================================

const usd = (n) => (n == null ? '—' : `$${Number(n).toFixed(6)}`)

function Avviso({ children, tono = 'gold', icona: Icona = AlertTriangle }) {
  const stili = tono === 'red'
    ? 'bg-red-500/5 border-red-500/20 text-red-200'
    : tono === 'green'
      ? 'bg-green-500/5 border-green-500/20 text-green-200'
      : 'bg-gold-500/5 border-gold-500/20 text-gold-100'
  const col = tono === 'red' ? 'text-red-400' : tono === 'green' ? 'text-green-400' : 'text-gold-400'
  return (
    <div className={`rounded-lg px-4 py-3 flex gap-3 border text-sm ${stili}`}>
      <Icona className={`w-4 h-4 flex-shrink-0 mt-0.5 ${col}`} />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

function Voce({ ok, label, dettaglio }) {
  return (
    <li className="flex items-start gap-2 px-3 py-2 text-sm">
      {ok
        ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" />
        : <XCircle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />}
      <span className="text-slate-300 min-w-0">{label}</span>
      {dettaglio != null && (
        <span className="ml-auto text-xs text-slate-500 text-right break-words max-w-[55%]">{dettaglio}</span>
      )}
    </li>
  )
}

function RisultatoDiagnostica({ d }) {
  const a = d.ambiente
  return (
    <div className="space-y-4">
      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        <Voce ok={d.config_presente} label="File config_ai.php presente" />
        <Voce ok={d.chiave_configurata} label="Chiave API compilata" />
        <Voce ok={a.curl_disponibile} label="cURL disponibile" />
        <Voce ok={a.openssl_disponibile} label="OpenSSL disponibile (HTTPS)" />
        <Voce ok={a.allow_url_fopen} label="allow_url_fopen attivo (alternativa a cURL)" />
        <Voce ok={d.chiamate_in_uscita_possibili} label="Chiamate in uscita possibili" />
        <Voce ok label="Tempo massimo di esecuzione" dettaglio={`${a.max_execution_time}s`} />
        <Voce ok label="Versione PHP" dettaglio={a.php_version} />
      </ul>

      {d.modelli?.length > 0 && (
        <div>
          <h4 className="text-sm font-semibold text-slate-300 mb-2">Modelli e listino (USD per milione di token)</h4>
          <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
            {d.modelli.map(m => (
              <li key={m.modello} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <span className="text-slate-300 min-w-0 truncate">
                  {m.etichetta}
                  {m.modello === d.modello_test && <span className="text-xs text-slate-500"> · usato dal test</span>}
                </span>
                <span className="text-slate-400 tabular-nums flex-shrink-0 text-xs">
                  in ${m.usd_per_mln_in} · out ${m.usd_per_mln_out}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {!d.chiamate_in_uscita_possibili && (
        <Avviso tono="red" icona={XCircle}>
          Il server non consente chiamate verso l'esterno (né cURL né allow_url_fopen): la
          generazione degli articoli non è realizzabile su questo hosting senza un'alternativa.
        </Avviso>
      )}
    </div>
  )
}

const MESSAGGI_RETE = {
  anthropic_raggiungibile: {
    tono: 'green', icona: CheckCircle2,
    testo: "api.anthropic.com è raggiungibile dal server: puoi eseguire il test.",
  },
  anthropic_solo_diretto: {
    tono: 'gold', icona: AlertTriangle,
    testo: "api.anthropic.com è raggiungibile solo saltando il proxy dell'hosting. In config_ai.php imposta \"proxy\" => \"nessuno\" e riprova il test.",
  },
  bloccato_solo_anthropic: {
    tono: 'red', icona: XCircle,
    testo: "Il server raggiunge altri siti ma non api.anthropic.com: l'hosting filtra i domini in uscita. Serve un'alternativa (chiedi ad Altervista di abilitare il dominio, oppure un relay).",
  },
  bloccato_tutto: {
    tono: 'red', icona: XCircle,
    testo: "Il server non riesce a raggiungere nessun sito esterno: le chiamate in uscita sono bloccate dall'hosting. Serve un'alternativa (relay esterno o generazione fuori dall'hosting).",
  },
  curl_assente: {
    tono: 'red', icona: XCircle,
    testo: "cURL non è disponibile sul server.",
  },
}

function EsitoProva({ p }) {
  if (!p) return <span className="text-slate-600">non provato</span>
  return p.ok
    ? <span className="text-green-400">OK · HTTP {p.http} · {p.ms} ms</span>
    : <span className="text-red-400 break-words">{p.errore || 'errore'}</span>
}

function RisultatoRete({ r }) {
  const m = MESSAGGI_RETE[r.esito] ?? MESSAGGI_RETE.bloccato_tutto
  const envKeys = Object.keys(r.proxy_ambiente ?? {})
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

      <div className="text-xs text-slate-500 space-y-1">
        <p>Proxy configurato in config_ai.php: <span className="text-slate-300 font-mono">{r.proxy_configurato}</span></p>
        <p>
          Proxy nell'ambiente del server:{' '}
          {envKeys.length === 0
            ? <span className="text-slate-300">nessuno visibile</span>
            : envKeys.map(k => (
                <span key={k} className="text-slate-300 font-mono mr-2">{k}={r.proxy_ambiente[k]}</span>
              ))}
        </p>
        {r.curl_versione && <p>Versione cURL: <span className="text-slate-300 font-mono">{r.curl_versione}</span></p>}
      </div>
    </div>
  )
}

function RisultatoTest({ t }) {
  const c = t.conteggio_token
  const g = t.generazione
  return (
    <div className="space-y-4">
      {t.tutto_ok ? (
        <Avviso tono="green" icona={CheckCircle2}>
          Connessione riuscita: il server raggiunge le API Anthropic e la chiave funziona.
        </Avviso>
      ) : (
        <Avviso tono="red" icona={XCircle}>
          Test non riuscito: controlla i dettagli qui sotto. Se l'errore riguarda la connessione
          (es. "CONNECT tunnel failed"), esegui "Verifica rete" per capire dove si blocca.
        </Avviso>
      )}

      <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
        <Voce ok label="Modello usato" dettaglio={t.modello} />
        <Voce
          ok={c.ok}
          label="Conteggio token (gratuito)"
          dettaglio={c.ok ? `${c.token_input} token · ${c.ms} ms` : `HTTP ${c.http || '—'} · ${c.errore}`}
        />
        <Voce
          ok={g.ok}
          label="Generazione di prova"
          dettaglio={g.ok
            ? `“${(g.risposta ?? '').trim()}” · ${g.ms} ms`
            : `HTTP ${g.http || '—'} · ${g.errore}`}
        />
        {g.ok && (
          <>
            <Voce ok label="Token usati (input / output)" dettaglio={`${g.token_input} / ${g.token_output}`} />
            <Voce ok label="Costo reale del test" dettaglio={usd(g.costo_usd)} />
          </>
        )}
      </ul>
    </div>
  )
}

export default function AiTest() {
  const { utente, ultimaStagione, stagione } = useApp()
  // Come per il login, le credenziali si verificano sulla stagione più recente
  const stagioneRif = Number(ultimaStagione ?? stagione)

  const [utenza,   setUtenza]   = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  const [azione,   setAzione]   = useState(null)   // 'diagnostica' | 'rete' | 'test' (in corso)
  const [errore,   setErrore]   = useState(null)
  const [diagn,    setDiagn]    = useState(null)
  const [rete,     setRete]     = useState(null)
  const [test,     setTest]     = useState(null)

  const pronto = utenza.trim() !== '' && password !== '' && !azione && !!stagioneRif

  const esegui = async (tipo) => {
    setErrore(null)
    setAzione(tipo)
    try {
      const res = await aiConnessione(stagioneRif, { utenza: utenza.trim(), password, azione: tipo })
      if (tipo === 'diagnostica') setDiagn(res)
      else if (tipo === 'rete') setRete(res)
      else setTest(res)
    } catch (err) {
      setErrore(err.message)
    } finally {
      setAzione(null)
    }
  }

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="AI"
        title="Test connessione AI"
        subtitle="Verifica che il server riesca a contattare le API Anthropic e che la chiave sia valida, prima di generare gli articoli."
      />

      <div className="card p-6 max-w-2xl mb-6">
        <h3 className="font-semibold text-slate-200 mb-1 flex items-center gap-2">
          <Lock className="w-4 h-4 text-slate-400" /> Conferma la tua identità
        </h3>
        <p className="text-xs text-slate-500 mb-5">
          Il test usa la chiave API del server e ha un costo trascurabile: riscrivi utenza e
          password (verificate lato server, serve l'abilitazione AI).
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label htmlFor="ai-utenza" className="text-xs text-slate-600 mb-1 block">Utenza</label>
            <input
              id="ai-utenza" type="text" autoComplete="username"
              value={utenza} onChange={e => setUtenza(e.target.value)}
              className="fanta-input"
            />
          </div>
          <div>
            <label htmlFor="ai-password" className="text-xs text-slate-600 mb-1 block">Password</label>
            <input
              id="ai-password" type="password" autoComplete="current-password"
              value={password} onChange={e => setPassword(e.target.value)}
              className="fanta-input"
            />
          </div>
        </div>

        <div className="flex flex-wrap gap-3">
          <button
            type="button"
            onClick={() => esegui('diagnostica')}
            disabled={!pronto}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/10
                       text-slate-300 hover:text-white hover:border-white/20 font-medium text-sm
                       transition-all duration-150 active:scale-95
                       disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
          >
            {azione === 'diagnostica'
              ? <><Spinner size="sm" /> Controllo...</>
              : <><Stethoscope className="w-4 h-4" /> Diagnostica (gratuita)</>}
          </button>

          <button
            type="button"
            onClick={() => esegui('rete')}
            disabled={!pronto}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/10
                       text-slate-300 hover:text-white hover:border-white/20 font-medium text-sm
                       transition-all duration-150 active:scale-95
                       disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
          >
            {azione === 'rete'
              ? <><Spinner size="sm" /> Verifica in corso...</>
              : <><Network className="w-4 h-4" /> Verifica rete (gratuita)</>}
          </button>

          <button
            type="button"
            onClick={() => esegui('test')}
            disabled={!pronto}
            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-grass-500 hover:bg-grass-400
                       text-pitch-950 font-semibold text-sm transition-all duration-150 active:scale-95
                       disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
          >
            {azione === 'test'
              ? <><Spinner size="sm" /> Test in corso...</>
              : <><PlugZap className="w-4 h-4" /> Esegui test (costo trascurabile)</>}
          </button>
        </div>

        {errore && (
          <div className="mt-4">
            <Avviso tono="red" icona={XCircle}>{errore}</Avviso>
          </div>
        )}
      </div>

      {diagn && (
        <div className="card p-6 max-w-2xl mb-6">
          <h3 className="font-semibold text-slate-200 mb-4 flex items-center gap-2">
            <Stethoscope className="w-4 h-4 text-slate-400" /> Diagnostica
          </h3>
          <RisultatoDiagnostica d={diagn} />
        </div>
      )}

      {rete && (
        <div className="card p-6 max-w-2xl mb-6">
          <h3 className="font-semibold text-slate-200 mb-4 flex items-center gap-2">
            <Network className="w-4 h-4 text-slate-400" /> Verifica rete
          </h3>
          <RisultatoRete r={rete} />
        </div>
      )}

      {test && (
        <div className="card p-6 max-w-2xl">
          <h3 className="font-semibold text-slate-200 mb-4 flex items-center gap-2">
            <PlugZap className="w-4 h-4 text-slate-400" /> Esito del test
          </h3>
          <RisultatoTest t={test} />
        </div>
      )}
    </div>
  )
}
