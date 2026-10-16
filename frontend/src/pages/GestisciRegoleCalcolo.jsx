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
  { value: 'SUPPLEMENTARI', label: 'Calcolo supplementari' },
]

// Default del calcolo supplementari (allineato a CalcolatoreVoti::ALGORITMO_SUPPLEMENTARI_DEFAULT)
const SUPPLEMENTARI_DEFAULT = {
  algoritmo: 'fasce_somma_v1',
  parametri: { fasce: [{ da: 18, a: 23.99, gol: 1 }], ogni_punti: 6, gol_ogni: 1 },
}

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
    ATTACCO: 'Bonus al singolo attaccante titolare privo di bonus da gol fatto, in funzione del solo voto puro (bonus/malus, assist compresi, esclusi).',
    SUPPLEMENTARI: 'Supplementari (turni Champions con il flag attivo, in caso di parità): si sommano voto + bonus del primo panchinaro di difesa, centrocampo e attacco (0 se è entrato in campo; se è s.v. si passa al successivo del ruolo). La somma si converte in gol con queste fasce.',
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
          {...(tipo === 'SUPPLEMENTARI' ? { campo: 'gol', etichettaCampo: 'Gol', stepCampo: 1 } : {})}
        />
      </div>

      {tipo === 'SUPPLEMENTARI' && (
        <div className="mt-6 pt-5 border-t border-white/[0.05] flex flex-wrap gap-6">
          <div>
            <label className="text-xs text-slate-600 mb-1 block">Oltre l'ultima fascia: un ulteriore gol ogni (punti)</label>
            <input
              type="number" min="0" step="0.5"
              value={parametri.ogni_punti ?? 6}
              onChange={e => setParametri({ ...parametri, ogni_punti: Number(e.target.value) })}
              className="fanta-input w-28"
            />
          </div>
          <div>
            <label className="text-xs text-slate-600 mb-1 block">Gol aggiunti ogni passo</label>
            <input
              type="number" min="0" step="1"
              value={parametri.gol_ogni ?? 1}
              onChange={e => setParametri({ ...parametri, gol_ogni: Number(e.target.value) })}
              className="fanta-input w-28"
            />
          </div>
          <p className="text-[11px] text-slate-700 max-w-md">
            Sotto la prima fascia 0 gol. Con i default: da 18 a 23,99 punti 1 gol, poi 1 gol ogni 6 punti
            (24 = 2 gol, 30 = 3 gol, ...), contati a partire dall'inizio dell'ultima fascia.
          </p>
        </div>
      )}

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
function CalendarioChampions({ fasi, numeroGiornate, getValore, setValore, getFlag, setFlag, readOnly, onPrecompila }) {
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
      <div className="flex items-center gap-2">
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
        {cella.supplementari && (
          <label
            className="inline-flex items-center gap-1 text-[11px] text-slate-500 cursor-pointer"
            title={cella.codice === 'CHAMP_SF_T1'
              ? 'Nessun effetto: nelle semifinali supplementari e rigori si giocano dopo il ritorno'
              : 'In caso di parità: supplementari e calci di rigore'}
          >
            <input
              type="checkbox"
              className="w-3.5 h-3.5"
              disabled={readOnly}
              checked={getFlag(cella)}
              onChange={e => setFlag(cella, e.target.checked)}
              aria-label={`Supplementari e rigori: ${cella.codice}`}
            />
            Suppl.+rig.
          </label>
        )}
      </div>
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
          configurazione. La casella "Suppl.+rig." di ogni giornata attiva, in caso di parità, supplementari e
          calci di rigore (di default solo semifinale di ritorno, finale e replay). Nelle semifinali
          (andata/ritorno) si giocano dopo il ritorno solo se la somma dei gol è in parità, nei turni a
          partita singola dopo la partita stessa.
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

// Valori di default dei parametri di stagione. Vengono proposti (e salvati con
// "Salva") solo quando il parametro manca o è vuoto. Allineati a
// backend/admin/regole_calcolo.php (CHAMPIONS_GIORNATE_DEFAULT e parametri_default).
// Fase 2: i gironi C e D corrispondono alle celle di configurazione A e B.
const DEFAULT_PARAMETRI = {
  NUMERO_GIORNATE: ['34', 'Numero di giornate della stagione'],
  GIORNATA_SERIE_A_INIZIO: ['4', 'Giornata di Serie A di partenza'],
  ULTIMA_GIORNATA_FATTORE_CASA: ['28', 'Ultima giornata con fattore casa (vuoto = sempre)'],
  FORMAZIONE_CHAMPIONS_DIVERSA: ['1', 'Formazione Champions diversa da quella di campionato (1 = sì, 0 = no)'],
  GOL_DISTACCO_MIN_PUNTI: ['3', 'Punti di scarto minimo in fasce diverse'],
  GOL_SOGLIA_PAREGGIO: ['66', 'Scarto minimo 1 a 0'],
  GOL_SOGLIA_OMAGGIO: ['59', 'Punteggio minimo al di sotto del quale scatta 1 gol per avversario'],
}
const DEFAULT_CHAMP_GIORNATE = {
  CHAMP_F1_A_T1: 2, CHAMP_F1_A_T2: 4, CHAMP_F1_A_T3: 6, CHAMP_F1_A_T4: 10, CHAMP_F1_A_T5: 12, CHAMP_F1_A_T6: 14,
  CHAMP_F1_B_T1: 3, CHAMP_F1_B_T2: 5, CHAMP_F1_B_T3: 7, CHAMP_F1_B_T4: 11, CHAMP_F1_B_T5: 13, CHAMP_F1_B_T6: 15,
  CHAMP_F2_A_T1: 17, CHAMP_F2_A_T2: 18, CHAMP_F2_A_T3: 19, CHAMP_F2_A_T4: 21, CHAMP_F2_A_T5: 22, CHAMP_F2_A_T6: 23,
  CHAMP_F2_B_T1: 17, CHAMP_F2_B_T2: 18, CHAMP_F2_B_T3: 19, CHAMP_F2_B_T4: 21, CHAMP_F2_B_T5: 22, CHAMP_F2_B_T6: 23,
  CHAMP_SF_T1: 26, CHAMP_SF_T2: 28, CHAMP_FIN_T1: 30, CHAMP_FIN_REPLAY: 31,
}
// Flag "supplementari e rigori" (semifinale di ritorno, finale, replay), attivi di default
const DEFAULT_SUPPL = {
  CHAMP_SF_T2_SUPPL: 'Semifinali ritorno',
  CHAMP_FIN_T1_SUPPL: 'Finale',
  CHAMP_FIN_REPLAY_SUPPL: 'Replay finale',
}
function conDefault(parametri) {
  const out = [...parametri]
  const mancante = (cod) => !out.some(p => p.codice === cod && String(p.valore ?? '').trim() !== '')
  const imposta = (cod, valore, etichetta) => {
    const i = out.findIndex(p => p.codice === cod)
    if (i >= 0) out[i] = { ...out[i], valore }
    else out.push({ codice: cod, etichetta, valore })
  }
  for (const [cod, [val, et]] of Object.entries(DEFAULT_PARAMETRI)) {
    // ULTIMA_GIORNATA_FATTORE_CASA vuoto = "sempre": si propone il default solo se il parametro non esiste
    const esiste = out.some(p => p.codice === cod)
    if (cod === 'ULTIMA_GIORNATA_FATTORE_CASA' ? !esiste : mancante(cod)) imposta(cod, val, et)
  }
  for (const [cod, g] of Object.entries(DEFAULT_CHAMP_GIORNATE)) {
    if (mancante(cod)) imposta(cod, String(g), `Champions - ${cod}: giornata di fantacampionato`)
  }
  for (const [cod, nome] of Object.entries(DEFAULT_SUPPL)) {
    if (!out.some(p => p.codice === cod)) {
      out.push({ codice: cod, etichetta: `Champions - ${nome}: supplementari e rigori in caso di parità (1 = sì, 0 = no)`, valore: '1' })
    }
  }
  return out
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
    // Solo per la stagione modificabile si completano i parametri mancanti con i default
    setParametri(info.modificabile === false ? (info.parametri ?? []) : conDefault(info.parametri ?? []))
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
  const getFlagSuppl = (cella) => {
    const v = getValoreParametro(cella.supplementari_codice, '')
    return v === '' ? !!cella.supplementari_default : String(v) === '1'
  }
  const setFlagSuppl = (cella, attivo) =>
    setValoreParametro(cella.supplementari_codice, attivo ? '1' : '0', `Champions - ${cella.codice}: supplementari e rigori (1 = sì, 0 = no)`)
  const numeroGiornate = Math.max(1, parseInt(getValoreParametro('NUMERO_GIORNATE', 34), 10) || 34)

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
                    config={algoritmi[value] ?? (value === 'SUPPLEMENTARI' ? SUPPLEMENTARI_DEFAULT : { algoritmo: '', parametri: { fasce: [] } })}
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
                            value={getValoreParametro('NUMERO_GIORNATE', 34)}
                            onChange={e => setValoreParametro('NUMERO_GIORNATE', e.target.value, 'Numero di giornate della stagione')}
                            className="fanta-input w-28"
                          />
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Giornata di Serie A di partenza</label>
                          <input
                            type="number" min="1" step="1"
                            value={getValoreParametro('GIORNATA_SERIE_A_INIZIO', 4)}
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
                        getFlag={getFlagSuppl}
                        setFlag={setFlagSuppl}
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

                    <Sottosezione
                      titolo="Casi particolari"
                      descrizione="Regole correttive applicate ai gol ottenuti dalle fasce qui sopra, sui punteggi finali delle due squadre (fattore casa incluso)."
                    >
                      <div className="flex flex-wrap gap-6">
                        <div className="max-w-[16rem]">
                          <label className="text-xs text-slate-600 mb-1 block">Punti di scarto minimo in fasce diverse</label>
                          <input
                            type="number" min="0" step="0.5"
                            value={getValoreParametro('GOL_DISTACCO_MIN_PUNTI', 3)}
                            onChange={e => setValoreParametro('GOL_DISTACCO_MIN_PUNTI', e.target.value, 'Punti di scarto minimo in fasce diverse')}
                            disabled={readOnly}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1">
                            Se le due squadre sono in fasce gol diverse ma distano meno di questi punti, la squadra in svantaggio pareggia (con fasce adiacenti ottiene 1 gol in più). Vale anche come distacco richiesto per il gol omaggio.
                          </p>
                        </div>
                        <div className="max-w-[16rem]">
                          <label className="text-xs text-slate-600 mb-1 block">Scarto minimo 1 a 0</label>
                          <input
                            type="number" min="0" step="0.5"
                            value={getValoreParametro('GOL_SOGLIA_PAREGGIO', 66)}
                            onChange={e => setValoreParametro('GOL_SOGLIA_PAREGGIO', e.target.value, 'Scarto minimo 1 a 0')}
                            disabled={readOnly}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1">
                            Se il caso precedente si verifica con una delle due squadre sotto questo punteggio, la partita finisce 0-0 anziché in parità (es. 1-1).
                          </p>
                        </div>
                        <div className="max-w-[16rem]">
                          <label className="text-xs text-slate-600 mb-1 block">Punteggio minimo al di sotto del quale scatta 1 gol per avversario</label>
                          <input
                            type="number" min="0" step="0.5"
                            value={getValoreParametro('GOL_SOGLIA_OMAGGIO', 59)}
                            onChange={e => setValoreParametro('GOL_SOGLIA_OMAGGIO', e.target.value, 'Punteggio minimo al di sotto del quale scatta 1 gol per avversario')}
                            disabled={readOnly}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1">
                            Una squadra sotto questo punteggio regala un gol all'avversaria, purché questa lo abbia raggiunto e stacchi di almeno i punti di scarto minimo.
                          </p>
                        </div>
                      </div>
                    </Sottosezione>

                    <Sottosezione
                      titolo="Riserve d'ufficio"
                      descrizione="Se un titolare non prende voto e non ci sono sostituti idonei in panchina, si inserisce un voto d'ufficio. La riserva d'ufficio concorre al totale delle sostituzioni consentite (movimento / portiere)."
                    >
                      <div className="flex flex-wrap gap-6">
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Riserve d'ufficio schierabili (max)</label>
                          <input
                            type="number" min="0" max="11" step="1"
                            value={getValoreParametro('RISERVE_UFFICIO_MAX', 1)}
                            onChange={e => setValoreParametro('RISERVE_UFFICIO_MAX', e.target.value, "Riserve d'ufficio: numero massimo schierabile")}
                            className="fanta-input w-28"
                          />
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Voto portiere</label>
                          <input
                            type="number" min="0" max="10" step="0.5"
                            value={getValoreParametro('VOTO_UFFICIO_PORTIERE', 3)}
                            onChange={e => setValoreParametro('VOTO_UFFICIO_PORTIERE', e.target.value, "Voto d'ufficio portiere")}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1 max-w-[11rem]">Né il portiere né i suoi sostituti hanno voto</p>
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Voto giocatore di movimento</label>
                          <input
                            type="number" min="0" max="10" step="0.5"
                            value={getValoreParametro('VOTO_UFFICIO_MOVIMENTO', 4)}
                            onChange={e => setValoreParametro('VOTO_UFFICIO_MOVIMENTO', e.target.value, "Voto d'ufficio giocatore di movimento")}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1 max-w-[11rem]">Né il titolare né i sostituti per ruolo hanno voto</p>
                        </div>
                        <div>
                          <label className="text-xs text-slate-600 mb-1 block">Voto ammonito senza voto</label>
                          <input
                            type="number" min="0" max="10" step="0.5"
                            value={getValoreParametro('VOTO_UFFICIO_AMMONITO', 5)}
                            onChange={e => setValoreParametro('VOTO_UFFICIO_AMMONITO', e.target.value, "Voto d'ufficio giocatore ammonito senza voto")}
                            className="fanta-input w-28"
                          />
                          <p className="text-[11px] text-slate-700 mt-1 max-w-[11rem]">Il voto è assegnato al giocatore stesso (ID reale)</p>
                        </div>
                      </div>
                    </Sottosezione>

                    <Sottosezione
                      titolo="Formazione Champions"
                      descrizione="Se attivo, nelle giornate in cui la squadra gioca la Champions l'utente può inserire una formazione diversa da quella di campionato."
                    >
                      <label className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={getValoreParametro('FORMAZIONE_CHAMPIONS_DIVERSA', '1') === '1'}
                          onChange={e => setValoreParametro(
                            'FORMAZIONE_CHAMPIONS_DIVERSA',
                            e.target.checked ? '1' : '0',
                            'Formazione Champions diversa da quella di campionato (1 = sì, 0 = no)',
                          )}
                          className="w-4 h-4"
                        />
                        Consenti una formazione Champions diversa da quella di campionato
                      </label>
                      <p className="text-[11px] text-slate-700 mt-2 max-w-md">
                        Se disattivo, la formazione inserita vale per entrambe le competizioni. Se attivo, la scelta
                        compare solo nelle giornate in cui la squadra gioca un turno di Champions (non se riposa).
                      </p>
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
