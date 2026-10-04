import { useState, useEffect } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getRegoleCalcolo, adminSalvaRegoleCalcolo } from '../api/client'
import { PageHeader, Spinner, ErrorState, TabBar } from '../components/ui'
import { Plus, Trash2, Save, CheckCircle2, AlertTriangle, Info, Lock, Copy } from 'lucide-react'

const TIPI_ALGORITMO = [
  { value: 'DIFESA',      label: 'Difesa' },
  { value: 'CENTROCAMPO', label: 'Centrocampo' },
  { value: 'ATTACCO',     label: 'Attacco' },
]

// Le fasce sono condivise da più algoritmi/parametri: [{ da, a, [campo] }, ...]
// da/a null = illimitato. campo/etichettaCampo permettono di riusare lo
// stesso editor anche per le fasce gol-da-punteggio (campo "gol").
function TabellaFasce({ fasce, onChange, campo = 'bonus', etichettaCampo = 'Bonus/malus', stepCampo = 0.5, readOnly = false }) {
  const setFascia = (idx, k, valore) => {
    const next = fasce.map((f, i) => (i === idx ? { ...f, [k]: valore } : f))
    onChange(next)
  }
  const aggiungi = () => onChange([...fasce, { da: null, a: null, [campo]: 0 }])
  const rimuovi = (idx) => onChange(fasce.filter((_, i) => i !== idx))

  // Sempre mostrate in ordine crescente di "da" (illimitato/null = il più basso),
  // pur continuando a modificare l'array originale tramite l'indice vero.
  const sogliaOrdinamento = (da) => (da === null || da === undefined ? -Infinity : Number(da))
  const ordinate = fasce
    .map((f, i) => ({ f, i }))
    .sort((a, b) => sogliaOrdinamento(a.f.da) - sogliaOrdinamento(b.f.da))

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
            <th className="py-2 pr-3">Da</th>
            <th className="py-2 pr-3">A</th>
            <th className="py-2 pr-3">{etichettaCampo}</th>
            {!readOnly && <th className="py-2 w-8" />}
          </tr>
        </thead>
        <tbody>
          {ordinate.map(({ f, i: idx }) => (
            <tr key={idx} className="border-t border-white/[0.03]">
              <td className="py-2 pr-3">
                <input
                  type="number" step="0.01" placeholder="illimitato"
                  value={f.da ?? ''}
                  onChange={e => setFascia(idx, 'da', e.target.value === '' ? null : Number(e.target.value))}
                  className="fanta-input w-28"
                />
              </td>
              <td className="py-2 pr-3">
                <input
                  type="number" step="0.01" placeholder="illimitato"
                  value={f.a ?? ''}
                  onChange={e => setFascia(idx, 'a', e.target.value === '' ? null : Number(e.target.value))}
                  className="fanta-input w-28"
                />
              </td>
              <td className="py-2 pr-3">
                <input
                  type="number" step={stepCampo}
                  value={f[campo] ?? 0}
                  onChange={e => setFascia(idx, campo, Number(e.target.value))}
                  className="fanta-input w-24"
                />
              </td>
              {!readOnly && (
                <td className="py-2">
                  <button type="button" onClick={() => rimuovi(idx)} className="p-1.5 rounded text-slate-600 hover:text-red-400 transition-colors">
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
      {!readOnly && (
        <button type="button" onClick={aggiungi} className="btn-ghost text-xs mt-3">
          <Plus className="w-3.5 h-3.5" /> Aggiungi fascia
        </button>
      )}
    </div>
  )
}

// Solo per DIFESA: aggiustamento in base al numero di difensori del
// modulo schierato dalla squadra che difende (es. 3, 4, 5).
function TabellaAggiustamentoModulo({ valori, onChange }) {
  const moduli = ['3', '4', '5']
  const setValore = (modulo, valore) => onChange({ ...valori, [modulo]: Number(valore) })
  return (
    <div className="flex flex-wrap gap-4">
      {moduli.map(m => (
        <div key={m}>
          <label className="text-xs text-slate-600 mb-1 block">{m} difensori</label>
          <input
            type="number" step="0.5"
            value={valori[m] ?? 0}
            onChange={e => setValore(m, e.target.value)}
            className="fanta-input w-24"
          />
        </div>
      ))}
    </div>
  )
}

function SezioneAlgoritmo({ tipo, config, onChange, readOnly = false }) {
  const parametri = config.parametri ?? { fasce: [] }
  const setParametri = (next) => onChange({ ...config, parametri: next })

  const descrizioni = {
    DIFESA: 'Bonus/malus alla squadra STESSA (non all\'avversaria) in base alla media voto dei propri difensori titolari, corretto in base al modulo schierato.',
    CENTROCAMPO: 'Bonus/malus incrociato in base alla differenza fra le somme dei voti dei centrocampisti delle due squadre, a parità di numero (i mancanti si equiparano con un voto fittizio).',
    ATTACCO: 'Bonus al singolo attaccante titolare privo di bonus da gol fatto, in funzione del voto netto già maturato con gli altri bonus/malus.',
  }

  return (
    <div className="card p-6">
      <div className="flex items-start gap-2 mb-4">
        <Info className="w-4 h-4 text-slate-600 flex-shrink-0 mt-0.5" />
        <p className="text-xs text-slate-500">{descrizioni[tipo]}</p>
      </div>

      <div className="mb-4">
        <label className="text-xs text-slate-600 mb-1 block">Fasce</label>
        <TabellaFasce
          fasce={parametri.fasce ?? []}
          onChange={fasce => setParametri({ ...parametri, fasce })}
          readOnly={readOnly}
        />
      </div>

      {tipo === 'DIFESA' && (
        <div className="mt-6 pt-5 border-t border-white/[0.05]">
          <label className="text-xs text-slate-600 mb-2 block">Aggiustamento in base al modulo di chi difende</label>
          <TabellaAggiustamentoModulo
            valori={parametri.aggiustamento_modulo ?? {}}
            onChange={aggiustamento_modulo => setParametri({ ...parametri, aggiustamento_modulo })}
          />
        </div>
      )}

      {tipo === 'CENTROCAMPO' && (
        <div className="mt-6 pt-5 border-t border-white/[0.05]">
          <label className="text-xs text-slate-600 mb-1 block">Voto fittizio (centrocampisti mancanti, a parità di numero)</label>
          <input
            type="number" step="0.5"
            value={parametri.voto_fittizio ?? 5}
            onChange={e => setParametri({ ...parametri, voto_fittizio: Number(e.target.value) })}
            className="fanta-input w-28"
          />
        </div>
      )}

      <div className="mt-5 pt-5 border-t border-white/[0.05]">
        <label className="text-xs text-slate-600 mb-1 block">Codice algoritmo</label>
        <input
          type="text"
          value={config.algoritmo ?? ''}
          onChange={e => onChange({ ...config, algoritmo: e.target.value })}
          className="fanta-input max-w-xs font-mono text-xs"
        />
        <p className="text-[11px] text-slate-700 mt-1.5">
          Identifica il modello di calcolo implementato lato server. Cambialo solo se è stato sviluppato
          un nuovo modello: la sola modifica delle fasce sopra non richiede di cambiarlo.
        </p>
      </div>
    </div>
  )
}

// Giornata minima ammessa per una cella: successiva all'ultima giornata già
// impostata più in alto nello stesso girone e a quelle della fase precedente.
function calcolaMinimi(fasi, valoreDi) {
  const minimi = {}
  let maxPrec = 0
  for (const fase of fasi) {
    const nCol = Math.max(1, fase.gironi.length)
    let maxFase = 0
    for (let col = 0; col < nCol; col++) {
      let ultima = 0
      for (const turno of fase.turni) {
        const cella = turno.celle[col]
        minimi[cella.codice] = Math.max(ultima, maxPrec) + 1
        const v = parseInt(valoreDi(cella.codice), 10)
        if (!Number.isNaN(v)) {
          ultima = Math.max(ultima, v)
          maxFase = Math.max(maxFase, v)
        }
      }
    }
    maxPrec = Math.max(maxPrec, maxFase)
  }
  return minimi
}

// Associazione turni Champions → giornata di fantacampionato. Nelle fasi a
// gironi ogni girone ha la propria colonna, indipendente dall'altra.
function CalendarioChampions({ fasi, numeroGiornate, getValore, setValore, readOnly, onPrecompila }) {
  const minimi = calcolaMinimi(fasi, getValore)
  const opzioni = Array.from({ length: numeroGiornate }, (_, i) => i + 1)

  const copiaGirone = (fase, da, a) => {
    for (const turno of fase.turni) {
      const src = turno.celle.find(c => c.girone === da)
      const dst = turno.celle.find(c => c.girone === a)
      if (src && dst) setValore(dst, getValore(src.codice))
    }
  }

  const Cella = ({ cella }) => {
    const valore = getValore(cella.codice)
    const fuori = valore !== '' && Number(valore) > numeroGiornate
    return (
      <select
        value={valore}
        disabled={readOnly}
        onChange={e => setValore(cella, e.target.value)}
        className={`fanta-input w-24 ${fuori ? 'border-red-500/50' : ''}`}
        aria-label={cella.codice}
      >
        <option value="">{cella.opzionale ? 'non previsto' : '—'}</option>
        {fuori && <option value={valore}>{valore}</option>}
        {opzioni.map(g => (
          <option key={g} value={g} disabled={g < (minimi[cella.codice] ?? 1)}>G. {g}</option>
        ))}
      </select>
    )
  }

  return (
    <div>
      <div className="flex items-start justify-between gap-3 mb-2 flex-wrap">
        <p className="text-xs text-slate-500 max-w-xl">
          Per ogni giornata della Champions scegli la giornata di fantacampionato in cui si gioca
          (la Champions è in contemporanea al campionato). I gironi sono indipendenti: ad esempio la
          giornata 1 del girone A può cadere in G. 2 e la giornata 1 del girone B in G. 3. Le giornate
          di uno stesso girone devono essere crescenti e ogni fase inizia dopo la fine della precedente.
          Il calendario Champions viene creato da "Inizializzazione stagione" in base a questa
          configurazione.
        </p>
        {!readOnly && (
          <button type="button" onClick={onPrecompila} className="btn-ghost text-xs">
            Precompila dal calendario esistente
          </button>
        )}
      </div>

      <div className="grid gap-4 md:grid-cols-2 mt-4">
        {fasi.map(fase => (
          <div key={fase.id} className={`rounded-lg border border-white/5 p-3 ${fase.gironi.length === 0 ? 'md:col-span-2' : ''}`}>
            <div className="flex items-center justify-between gap-2 mb-2">
              <div className="text-xs font-semibold text-slate-400">{fase.label}</div>
              {!readOnly && fase.gironi.length === 2 && (
                <button
                  type="button"
                  onClick={() => copiaGirone(fase, fase.gironi[0], fase.gironi[1])}
                  className="text-[11px] text-slate-500 hover:text-slate-300 inline-flex items-center gap-1"
                  title={`Copia le giornate del girone ${fase.gironi[0]} sul girone ${fase.gironi[1]}`}
                >
                  <Copy className="w-3 h-3" /> {fase.gironi[0]} → {fase.gironi[1]}
                </button>
              )}
            </div>
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] text-slate-600 uppercase tracking-widest">
                  <th className="py-1 pr-3 font-medium">Giornata Champions</th>
                  {fase.gironi.length > 0
                    ? fase.gironi.map(g => <th key={g} className="py-1 pr-3 font-medium">Girone {g}</th>)
                    : <th className="py-1 pr-3 font-medium">Giornata fantacampionato</th>}
                </tr>
              </thead>
              <tbody>
                {fase.turni.map(turno => (
                  <tr key={turno.n} className="border-t border-white/[0.03]">
                    <td className="py-1.5 pr-3 text-xs text-slate-500">{turno.label}</td>
                    {turno.celle.map(c => (
                      <td key={c.codice} className="py-1.5 pr-3">{Cella({ cella: c })}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </div>
  )
}

// Sezione con titolo per le sottosezioni di "Parametri stagione"
function Sottosezione({ titolo, descrizione, children }) {
  return (
    <section className="card p-6">
      <h3 className="text-sm font-semibold text-slate-300">{titolo}</h3>
      {descrizione && <p className="text-xs text-slate-500 mt-1">{descrizione}</p>}
      <div className="mt-5">{children}</div>
    </section>
  )
}

export default function GestisciRegoleCalcolo() {
  const { stagione, ultimaStagione } = useApp()
  const { data: info, loading, error, refetch } = useFetch(
    stagione ? () => getRegoleCalcolo(stagione) : null,
    [stagione],
  )

  const [tab, setTab] = useState('bonus')
  const [sezioneParametri, setSezioneParametri] = useState('struttura')
  const [bonus, setBonus] = useState([])
  const [algoritmi, setAlgoritmi] = useState({})
  const [parametri, setParametri] = useState([])
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!info) return
    setBonus(info.bonus ?? [])
    setAlgoritmi(info.algoritmi ?? {})
    setParametri(info.parametri ?? [])
    setSaved(false)
    setSaveError(null)
  }, [info])

  // Modificabile solo per l'ultima stagione (il backend rifiuta comunque il salvataggio)
  const readOnly = info ? info.modificabile === false || (ultimaStagione != null && stagione !== ultimaStagione) : true

  const setVoceBonus = (idx, campo, valore) => {
    setBonus(bonus.map((b, i) => (i === idx ? { ...b, [campo]: valore } : b)))
    setSaved(false)
  }
  const aggiungiVoceBonus = () => {
    setBonus([...bonus, { codice: '', etichetta: '', valore: 0, ordine: bonus.length + 1, attivo: 1 }])
    setSaved(false)
  }
  const rimuoviVoceBonus = (idx) => {
    setBonus(bonus.filter((_, i) => i !== idx))
    setSaved(false)
  }

  const setAlgoritmo = (tipo, config) => {
    setAlgoritmi({ ...algoritmi, [tipo]: config })
    setSaved(false)
  }

  const setValoreParametro = (codice, valore, etichetta = codice) => {
    setParametri(prev => {
      const esiste = prev.some(p => p.codice === codice)
      return esiste
        ? prev.map(p => (p.codice === codice ? { ...p, valore } : p))
        : [...prev, { codice, etichetta, valore }]
    })
    setSaved(false)
  }
  const getValoreParametro = (codice, default_ = '') => parametri.find(p => p.codice === codice)?.valore ?? default_

  // Giornate di fantacampionato delle celle Champions (parametri CHAMP_*)
  const fasiChampions = info?.champions ?? []
  const setGiornataCella = (cella, valore) =>
    setValoreParametro(cella.codice, String(valore ?? '').replace(/\D/g, '').slice(0, 2), `Champions - ${cella.codice}: giornata di fantacampionato`)
  const usaSuggerite = () => {
    for (const f of fasiChampions) {
      for (const t of f.turni) {
        for (const c of t.celle) {
          if (c.suggerita != null && getValoreParametro(c.codice, '') === '') setGiornataCella(c, c.suggerita)
        }
      }
    }
  }
  const numeroGiornate = Math.max(1, parseInt(getValoreParametro('NUMERO_GIORNATE', 38), 10) || 38)

  const salva = async () => {
    if (readOnly) return
    setSaving(true)
    setSaveError(null)
    setSaved(false)
    try {
      for (const b of bonus) {
        if (!b.codice.trim()) throw new Error('Ogni voce di bonus/malus deve avere un codice')
      }
      await adminSalvaRegoleCalcolo(stagione, { bonus, algoritmi, parametri })
      setSaved(true)
      refetch()
    } catch (err) {
      setSaveError(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Gestisci regole di calcolo"
        subtitle="Configura i bonus/malus e i modelli di calcolo dei modificatori usati per il punteggio fantacalcio."
      />

      {error ? (
        <ErrorState message={error} onRetry={refetch} />
      ) : loading || !info ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : (
        <>
          {readOnly && (
            <div className="max-w-3xl rounded-lg px-4 py-3 flex gap-3 bg-slate-500/5 border border-slate-500/20 text-slate-300 text-sm mb-6">
              <Lock className="w-4 h-4 text-slate-400 flex-shrink-0 mt-0.5" />
              <span>
                Sola lettura: le regole sono modificabili solo per l'ultima stagione
                {ultimaStagione ? ` (${ultimaStagione})` : ''}. Stai consultando la stagione {stagione}.
              </span>
            </div>
          )}

          {!readOnly && info.ereditata_da && (
            <div className="max-w-3xl rounded-lg px-4 py-3 flex gap-3 bg-grass-500/5 border border-grass-500/20 text-slate-300 text-sm mb-6">
              <Info className="w-4 h-4 text-grass-400 flex-shrink-0 mt-0.5" />
              <span>
                Nessuna configurazione trovata per la stagione {stagione}: è stata copiata da quella
                della stagione {info.ereditata_da}. Modificala e salva per renderla definitiva.
              </span>
            </div>
          )}

          <div className="flex items-center justify-between flex-wrap gap-3 mb-5">
            <TabBar
              tabs={[
                { value: 'bonus', label: 'Bonus / malus' },
                { value: 'modificatori', label: 'Modificatori di squadra' },
                { value: 'parametri', label: 'Parametri stagione' },
              ]}
              active={tab}
              onChange={setTab}
            />
            {!readOnly && (
              <button onClick={salva} disabled={saving} className="btn-primary disabled:opacity-40">
                {saving ? <><Spinner size="sm" /> Salvataggio...</> : <><Save className="w-4 h-4" /> Salva regole</>}
              </button>
            )}
          </div>

          {saveError && (
            <div className="max-w-3xl rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mb-5">
              <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" /> {saveError}
            </div>
          )}
          {saved && (
            <div className="max-w-3xl rounded-lg px-4 py-3 flex gap-3 bg-green-500/5 border border-green-500/20 text-green-200 text-sm mb-5">
              <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" /> Regole salvate per la stagione {stagione}.
            </div>
          )}

          {tab === 'bonus' && (
            <fieldset disabled={readOnly} className="contents">
            <div className="card p-6 max-w-3xl">
              <p className="text-xs text-slate-500 mb-4">
                Voci fisse applicate al voto del singolo giocatore. L'elenco può cambiare da una stagione
                all'altra (aggiungi o disattiva una voce), non solo i valori.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                      <th className="py-2 pr-3">Codice</th>
                      <th className="py-2 pr-3">Etichetta</th>
                      <th className="py-2 pr-3">Valore</th>
                      <th className="py-2 pr-3">Attiva</th>
                      {!readOnly && <th className="py-2 w-8" />}
                    </tr>
                  </thead>
                  <tbody>
                    {bonus.map((b, idx) => (
                      <tr key={idx} className="border-t border-white/[0.03]">
                        <td className="py-2 pr-3">
                          <input
                            type="text" value={b.codice}
                            onChange={e => setVoceBonus(idx, 'codice', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, ''))}
                            className="fanta-input w-40 font-mono text-xs"
                          />
                        </td>
                        <td className="py-2 pr-3">
                          <input
                            type="text" value={b.etichetta}
                            onChange={e => setVoceBonus(idx, 'etichetta', e.target.value)}
                            className="fanta-input w-48"
                          />
                        </td>
                        <td className="py-2 pr-3">
                          <input
                            type="number" step="0.25" value={b.valore}
                            onChange={e => setVoceBonus(idx, 'valore', Number(e.target.value))}
                            className="fanta-input w-24"
                          />
                        </td>
                        <td className="py-2 pr-3 text-center">
                          <input
                            type="checkbox" checked={!!b.attivo}
                            onChange={e => setVoceBonus(idx, 'attivo', e.target.checked ? 1 : 0)}
                            className="w-4 h-4"
                          />
                        </td>
                        {!readOnly && (
                          <td className="py-2">
                            <button type="button" onClick={() => rimuoviVoceBonus(idx)} className="p-1.5 rounded text-slate-600 hover:text-red-400 transition-colors">
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!readOnly && (
                <button type="button" onClick={aggiungiVoceBonus} className="btn-ghost text-xs mt-4">
                  <Plus className="w-3.5 h-3.5" /> Aggiungi voce
                </button>
              )}
            </div>
            </fieldset>
          )}

          {tab === 'modificatori' && (
            <fieldset disabled={readOnly} className="contents">
            <div className="flex flex-col gap-6 max-w-3xl">
              {TIPI_ALGORITMO.map(({ value, label }) => (
                <div key={value}>
                  <h3 className="text-sm font-semibold text-slate-300 mb-2">{label}</h3>
                  <SezioneAlgoritmo
                    tipo={value}
                    config={algoritmi[value] ?? { algoritmo: '', parametri: { fasce: [] } }}
                    onChange={config => setAlgoritmo(value, config)}
                    readOnly={readOnly}
                  />
                </div>
              ))}
            </div>
            </fieldset>
          )}

          {tab === 'parametri' && (
            <fieldset disabled={readOnly} className="contents">
              <div className="flex flex-col gap-6 max-w-3xl">
                <TabBar
                  tabs={[
                    { value: 'struttura', label: 'Struttura stagione' },
                    { value: 'regole', label: 'Regole di gioco' },
                    { value: 'statistiche', label: 'Configurazioni statistiche' },
                  ]}
                  active={sezioneParametri}
                  onChange={setSezioneParametri}
                />

                {sezioneParametri === 'struttura' && (
                  <>
                    <Sottosezione
                      titolo="Calendario del campionato"
                      descrizione="Numero di giornate e raccordo con il calendario della Serie A."
                    >
                      <div className="flex flex-wrap gap-6">
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Numero di giornate della stagione</label>
                          <input
                            type="number" min="1" max="99" step="1"
                            value={getValoreParametro('NUMERO_GIORNATE', 38)}
                            onChange={e => setValoreParametro('NUMERO_GIORNATE', e.target.value, 'Numero di giornate della stagione')}
                            className="fanta-input w-28"
                          />
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Giornata di Serie A di partenza</label>
                          <input
                            type="number" min="1" step="1"
                            value={getValoreParametro('GIORNATA_SERIE_A_INIZIO', 1)}
                            onChange={e => setValoreParametro('GIORNATA_SERIE_A_INIZIO', e.target.value, 'Giornata di Serie A di partenza')}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1 max-w-xs">
                            Giornata di Serie A a cui corrisponde la 1ª giornata di fantacampionato (che può iniziare dopo la 1ª di Serie A). In Gestione voti i voti Serie A vengono associati automaticamente alla fantagiornata corrispondente.
                          </p>
                        </div>
                      </div>
                    </Sottosezione>

                    <Sottosezione titolo="Calendario Champions">
                      <CalendarioChampions
                        fasi={fasiChampions}
                        numeroGiornate={numeroGiornate}
                        getValore={(cod) => getValoreParametro(cod, '')}
                        setValore={setGiornataCella}
                        readOnly={readOnly}
                        onPrecompila={usaSuggerite}
                      />
                    </Sottosezione>
                  </>
                )}

                {sezioneParametri === 'regole' && (
                  <>
                    <Sottosezione
                      titolo="Fasce gol fatti da punteggio totale"
                      descrizione="Determinano golf/gols in NEW_RISULTATI dal punteggio finale della squadra (fattore casa incluso)."
                    >
                      <TabellaFasce
                        fasce={(() => { try { return JSON.parse(getValoreParametro('FASCE_GOL_PUNTEGGIO', '[]')) } catch { return [] } })()}
                        onChange={fasce => setValoreParametro('FASCE_GOL_PUNTEGGIO', JSON.stringify(fasce), 'Fasce gol fatti da punteggio totale')}
                        campo="gol"
                        etichettaCampo="Gol"
                        stepCampo={1}
                        readOnly={readOnly}
                      />
                    </Sottosezione>

                    <Sottosezione titolo="Sostituzioni e fattore casa">
                      <div className="flex flex-wrap gap-6">
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Sostituzioni di movimento (portiere escluso)</label>
                          <input
                            type="number" min="0" step="1"
                            value={getValoreParametro('SOSTITUZIONI_MAX_MOVIMENTO', 5)}
                            onChange={e => setValoreParametro('SOSTITUZIONI_MAX_MOVIMENTO', e.target.value, 'Sostituzioni di movimento (portiere escluso)')}
                            className="fanta-input w-28"
                          />
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Sostituzioni portiere</label>
                          <input
                            type="number" min="0" step="1"
                            value={getValoreParametro('SOSTITUZIONI_MAX_PORTIERE', 1)}
                            onChange={e => setValoreParametro('SOSTITUZIONI_MAX_PORTIERE', e.target.value, 'Sostituzioni portiere')}
                            className="fanta-input w-28"
                          />
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Ultima giornata di fantacampionato con fattore casa valido</label>
                          <input
                            type="number" min="0" step="1" placeholder="sempre"
                            value={getValoreParametro('ULTIMA_GIORNATA_FATTORE_CASA', '')}
                            onChange={e => setValoreParametro('ULTIMA_GIORNATA_FATTORE_CASA', e.target.value, 'Ultima giornata con fattore casa (vuoto = sempre)')}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1">Vuoto = si applica sempre (nessun campo neutro)</p>
                        </div>
                      </div>
                    </Sottosezione>
                  </>
                )}

                {sezioneParametri === 'statistiche' && (
                  <Sottosezione titolo="Top / Flop 11">
                    <label className="text-xs text-slate-600 mb-1 block">Giocate minime (%)</label>
                    <input
                      type="number" min="0" max="100" step="1"
                      value={getValoreParametro('TOPFLOP_SOGLIA_PERCENTUALE', 50)}
                      onChange={e => setValoreParametro('TOPFLOP_SOGLIA_PERCENTUALE', e.target.value, 'Top/Flop 11: giocate minime (% delle partite giocate)')}
                      className="fanta-input w-28"
                    />
                    <p className="text-[11px] text-slate-700 mt-1">% delle partite giocate dalla squadra (50 = metà)</p>
                  </Sottosezione>
                )}
              </div>
            </fieldset>
          )}
        </>
      )}
    </div>
  )
}
