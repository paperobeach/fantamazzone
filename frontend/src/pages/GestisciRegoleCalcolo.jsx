import { useState, useEffect } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getRegoleCalcolo, adminSalvaRegoleCalcolo } from '../api/client'
import { PageHeader, Spinner, ErrorState, TabBar } from '../components/ui'
import { Plus, Trash2, Save, CheckCircle2, AlertTriangle, Info } from 'lucide-react'

const TIPI_ALGORITMO = [
  { value: 'DIFESA',      label: 'Difesa' },
  { value: 'CENTROCAMPO', label: 'Centrocampo' },
  { value: 'ATTACCO',     label: 'Attacco' },
]

// Le fasce sono condivise da più algoritmi/parametri: [{ da, a, [campo] }, ...]
// da/a null = illimitato. campo/etichettaCampo permettono di riusare lo
// stesso editor anche per le fasce gol-da-punteggio (campo "gol").
function TabellaFasce({ fasce, onChange, campo = 'bonus', etichettaCampo = 'Bonus/malus', stepCampo = 0.5 }) {
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
            <th className="py-2 w-8" />
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
              <td className="py-2">
                <button type="button" onClick={() => rimuovi(idx)} className="p-1.5 rounded text-slate-600 hover:text-red-400 transition-colors">
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <button type="button" onClick={aggiungi} className="btn-ghost text-xs mt-3">
        <Plus className="w-3.5 h-3.5" /> Aggiungi fascia
      </button>
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

function SezioneAlgoritmo({ tipo, config, onChange }) {
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

export default function GestisciRegoleCalcolo() {
  const { stagione } = useApp()
  const { data: info, loading, error, refetch } = useFetch(
    stagione ? () => getRegoleCalcolo(stagione) : null,
    [stagione],
  )

  const [tab, setTab] = useState('bonus')
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

  // Giornate di campionato dei turni Champions (parametri CHAMP_*)
  const fasiChampions = info?.champions ?? []
  const setGiornataTurno = (fase, turno, valore) =>
    setValoreParametro(
      turno.codice,
      valore.replace(/\D/g, '').slice(0, 2),
      `Champions - ${fase.label} - ${turno.label}: giornata di campionato`,
    )
  const usaSuggerite = () => {
    for (const f of fasiChampions) {
      for (const t of f.turni) {
        if (t.suggerita != null && getValoreParametro(t.codice, '') === '') {
          setGiornataTurno(f, t, String(t.suggerita))
        }
      }
    }
  }

  const aggiungiParametro = () => {
    setParametri([...parametri, { codice: '', etichetta: '', valore: '' }])
    setSaved(false)
  }
  const setCampoParametro = (idx, campo, valore) => {
    setParametri(parametri.map((p, i) => (i === idx ? { ...p, [campo]: valore } : p)))
    setSaved(false)
  }
  const rimuoviParametro = (idx) => {
    setParametri(parametri.filter((_, i) => i !== idx))
    setSaved(false)
  }

  const salva = async () => {
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
          {info.ereditata_da && (
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
            <button onClick={salva} disabled={saving} className="btn-primary disabled:opacity-40">
              {saving ? <><Spinner size="sm" /> Salvataggio...</> : <><Save className="w-4 h-4" /> Salva regole</>}
            </button>
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
                      <th className="py-2 w-8" />
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
                        <td className="py-2">
                          <button type="button" onClick={() => rimuoviVoceBonus(idx)} className="p-1.5 rounded text-slate-600 hover:text-red-400 transition-colors">
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <button type="button" onClick={aggiungiVoceBonus} className="btn-ghost text-xs mt-4">
                <Plus className="w-3.5 h-3.5" /> Aggiungi voce
              </button>
            </div>
          )}

          {tab === 'modificatori' && (
            <div className="flex flex-col gap-6 max-w-3xl">
              {TIPI_ALGORITMO.map(({ value, label }) => (
                <div key={value}>
                  <h3 className="text-sm font-semibold text-slate-300 mb-2">{label}</h3>
                  <SezioneAlgoritmo
                    tipo={value}
                    config={algoritmi[value] ?? { algoritmo: '', parametri: { fasce: [] } }}
                    onChange={config => setAlgoritmo(value, config)}
                  />
                </div>
              ))}
            </div>
          )}

          {tab === 'parametri' && (
            <div className="flex flex-col gap-6 max-w-3xl">
              <div className="card p-6">
                <p className="text-xs text-slate-500 mb-4">
                  Parametri di stagione non riconducibili a un bonus/malus o a un modificatore
                  (usati dal calcolo voti, fase 2 di "Gestione voti").
                </p>
                <div className="flex flex-wrap gap-6 mb-6">
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Sostituzioni di movimento (portiere escluso)</label>
                    <input
                      type="number" min="0" step="1"
                      value={getValoreParametro('SOSTITUZIONI_MAX_MOVIMENTO', 5)}
                      onChange={e => setValoreParametro('SOSTITUZIONI_MAX_MOVIMENTO', e.target.value)}
                      className="fanta-input w-28"
                    />
                  </div>
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Sostituzioni portiere</label>
                    <input
                      type="number" min="0" step="1"
                      value={getValoreParametro('SOSTITUZIONI_MAX_PORTIERE', 1)}
                      onChange={e => setValoreParametro('SOSTITUZIONI_MAX_PORTIERE', e.target.value)}
                      className="fanta-input w-28"
                    />
                  </div>
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Ultima giornata con fattore casa</label>
                    <input
                      type="number" min="0" step="1" placeholder="sempre"
                      value={getValoreParametro('ULTIMA_GIORNATA_FATTORE_CASA', '')}
                      onChange={e => setValoreParametro('ULTIMA_GIORNATA_FATTORE_CASA', e.target.value)}
                      className="fanta-input w-28"
                    />
                    <p className="text-[11px] text-slate-700 mt-1">Vuoto = si applica sempre (nessun campo neutro)</p>
                  </div>
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Numero di giornate della stagione</label>
                    <input
                      type="number" min="1" step="1"
                      value={getValoreParametro('NUMERO_GIORNATE', 38)}
                      onChange={e => setValoreParametro('NUMERO_GIORNATE', e.target.value)}
                      className="fanta-input w-28"
                    />
                  </div>
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Top/Flop 11: giocate minime (%)</label>
                    <input
                      type="number" min="0" max="100" step="1"
                      value={getValoreParametro('TOPFLOP_SOGLIA_PERCENTUALE', 50)}
                      onChange={e => setValoreParametro('TOPFLOP_SOGLIA_PERCENTUALE', e.target.value)}
                      className="fanta-input w-28"
                    />
                    <p className="text-[11px] text-slate-700 mt-1">% delle partite giocate dalla squadra (50 = metà)</p>
                  </div>
                  <div>
                    <label className="text-xs text-slate-600 mb-1 block">Marcatori: giocate minime migliori/peggiori</label>
                    <input
                      type="number" min="0" step="1"
                      value={getValoreParametro('MARCATORI_MIN_GIOCATE', 5)}
                      onChange={e => setValoreParametro('MARCATORI_MIN_GIOCATE', e.target.value)}
                      className="fanta-input w-28"
                    />
                  </div>
                </div>

                <div className="pt-5 border-t border-white/[0.05]">
                  <label className="text-xs text-slate-600 mb-1 block">Fasce gol fatti da punteggio totale</label>
                  <p className="text-[11px] text-slate-700 mb-2">
                    Determinano golf/gols in NEW_RISULTATI dal punteggio finale della squadra (fattore casa incluso).
                  </p>
                  <TabellaFasce
                    fasce={(() => { try { return JSON.parse(getValoreParametro('FASCE_GOL_PUNTEGGIO', '[]')) } catch { return [] } })()}
                    onChange={fasce => setValoreParametro('FASCE_GOL_PUNTEGGIO', JSON.stringify(fasce))}
                    campo="gol"
                    etichettaCampo="Gol"
                    stepCampo={1}
                  />
                </div>
              </div>

              <div className="card p-6">
                <div className="flex items-start justify-between gap-3 mb-2 flex-wrap">
                  <h3 className="text-sm font-semibold text-slate-300">Calendario Champions</h3>
                  <button type="button" onClick={usaSuggerite} className="btn-ghost text-xs">
                    Precompila dal calendario esistente
                  </button>
                </div>
                <p className="text-xs text-slate-500 mb-4">
                  Associa a ogni turno la giornata di campionato in cui si gioca. La Champions è in
                  contemporanea al campionato: la giornata indicata vale per entrambe le competizioni.
                  Le giornate devono essere comprese tra 1 e il numero di giornate della stagione, tutte diverse
                  e in ordine crescente. Il calendario Champions viene creato da "Inizializzazione stagione"
                  sulla base di questa configurazione.
                </p>
                <div className="grid gap-4 md:grid-cols-3">
                  {fasiChampions.map(f => (
                    <div key={f.id} className="rounded-lg border border-white/5 p-3">
                      <div className="text-xs font-semibold text-slate-400 mb-2">{f.label}</div>
                      <div className="space-y-1.5">
                        {f.turni.map(t => (
                          <div key={t.codice} className="flex items-center justify-between gap-2">
                            <label htmlFor={t.codice} className="text-xs text-slate-500">{t.label}</label>
                            <input
                              id={t.codice}
                              type="text" inputMode="numeric" maxLength={2}
                              value={getValoreParametro(t.codice, '')}
                              onChange={e => setGiornataTurno(f, t, e.target.value)}
                              placeholder={t.suggerita != null ? String(t.suggerita) : 'g.'}
                              title="Giornata di campionato"
                              className="fanta-input w-16 text-center"
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Elenco generico completo: mostra/permette di aggiungere qualunque altro
                  parametro, anche non previsto dai controlli dedicati sopra. */}
              <div className="card p-6">
                <p className="text-xs text-slate-500 mb-4">Elenco completo (avanzato)</p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                        <th className="py-2 pr-3">Codice</th>
                        <th className="py-2 pr-3">Etichetta</th>
                        <th className="py-2 pr-3">Valore</th>
                        <th className="py-2 w-8" />
                      </tr>
                    </thead>
                    <tbody>
                      {parametri.map((p, idx) => (
                        <tr key={idx} className="border-t border-white/[0.03]">
                          <td className="py-2 pr-3">
                            <input
                              type="text" value={p.codice}
                              onChange={e => setCampoParametro(idx, 'codice', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, ''))}
                              className="fanta-input w-56 font-mono text-xs"
                            />
                          </td>
                          <td className="py-2 pr-3">
                            <input
                              type="text" value={p.etichetta}
                              onChange={e => setCampoParametro(idx, 'etichetta', e.target.value)}
                              className="fanta-input w-56"
                            />
                          </td>
                          <td className="py-2 pr-3">
                            <input
                              type="text" value={p.valore}
                              onChange={e => setCampoParametro(idx, 'valore', e.target.value)}
                              className="fanta-input w-56 font-mono text-xs"
                            />
                          </td>
                          <td className="py-2">
                            <button type="button" onClick={() => rimuoviParametro(idx)} className="p-1.5 rounded text-slate-600 hover:text-red-400 transition-colors">
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <button type="button" onClick={aggiungiParametro} className="btn-ghost text-xs mt-4">
                  <Plus className="w-3.5 h-3.5" /> Aggiungi parametro
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
