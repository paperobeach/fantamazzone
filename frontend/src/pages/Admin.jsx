import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadre, adminInsertRisultato, adminInsertVoti, adminChiudiEAggiorna } from '../api/client'
import { PageHeader, LoadingState, Spinner } from '../components/ui'
import { CheckCircle2, XCircle, AlertTriangle } from 'lucide-react'

// ── Wizard chiusura giornata ────────────────────────────────────────────────
function ChiudiGiornataWizard({ stagione }) {
  const [giornata, setGiornata] = useState('')
  const [running,  setRunning]  = useState(false)
  const [steps,    setSteps]    = useState(null)

  const run = async () => {
    if (!giornata) return
    setRunning(true)
    setSteps(null)
    const res = await adminChiudiEAggiorna(stagione, Number(giornata))
    setSteps(res)
    setRunning(false)
  }

  return (
    <div className="card p-6">
      <h3 className="font-semibold text-slate-200 mb-1">Chiudi giornata e aggiorna</h3>
      <p className="text-xs text-slate-600 mb-5">
        Chiude la giornata e ricalcola in sequenza classifica, statistiche, Top/Flop e Kulovic.
      </p>

      <div className="info-box warning mb-5 rounded-lg px-4 py-3 flex gap-3 bg-yellow-500/5 border border-yellow-500/20 text-yellow-200 text-sm">
        <AlertTriangle className="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" />
        <span>Operazione irreversibile. Assicurati di aver inserito tutti i voti e i risultati prima di procedere.</span>
      </div>

      <div className="flex gap-3 mb-5">
        <input
          type="number"
          min="1"
          max="38"
          value={giornata}
          onChange={e => setGiornata(e.target.value)}
          placeholder="N° giornata"
          className="fanta-input w-36"
        />
        <button onClick={run} disabled={running || !giornata} className="btn-primary disabled:opacity-40">
          {running ? <><Spinner size="sm" /> Elaborazione...</> : 'Chiudi e aggiorna'}
        </button>
      </div>

      {steps && (
        <div className="space-y-2">
          {steps.map((s, i) => (
            <div key={i} className={`flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm ${
              s.ok ? 'bg-green-500/5 border border-green-500/10' : 'bg-red-500/5 border border-red-500/10'
            }`}>
              {s.ok
                ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0" />
                : <XCircle     className="w-4 h-4 text-red-400 flex-shrink-0" />}
              <span className={s.ok ? 'text-green-300' : 'text-red-300'}>{s.step}</span>
              {s.error && <span className="text-xs text-red-500 ml-auto">{s.error}</span>}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Inserimento risultato ───────────────────────────────────────────────────
function InserisciRisultato({ stagione, squadre }) {
  const [form, setForm] = useState({
    giornata: '', id_squadra: '', id_squadra_a: '',
    ftotale: '', ftotale_a: '', golf: '', gols: '',
    modificatore: '0', modificatore_a: '0',
  })
  const [saving, setSaving] = useState(false)
  const [msg,    setMsg]    = useState(null)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const handleSave = async () => {
    setSaving(true)
    setMsg(null)
    try {
      const res = await adminInsertRisultato(stagione, {
        giornata:      Number(form.giornata),
        id_squadra:    Number(form.id_squadra),
        id_squadra_a:  Number(form.id_squadra_a),
        ftotale:       Number(form.ftotale),
        ftotale_a:     Number(form.ftotale_a),
        golf:          Number(form.golf),
        gols:          Number(form.gols),
        modificatore:  Number(form.modificatore),
        modificatore_a:Number(form.modificatore_a),
      })
      setMsg({ ok: true, text: `Salvato — Risultato: ${res.golf ?? '?'} : ${res.gols ?? '?'}` })
    } catch (e) {
      setMsg({ ok: false, text: e.message })
    } finally {
      setSaving(false)
    }
  }

  const sqOpts = (squadre ?? []).map(s => ({ value: s.id, label: s.nome }))

  return (
    <div className="card p-6">
      <h3 className="font-semibold text-slate-200 mb-5">Inserisci risultato partita</h3>

      <div className="grid grid-cols-2 gap-4 mb-4">
        <div>
          <label className="text-xs text-slate-600 mb-1 block">Giornata</label>
          <input type="number" value={form.giornata} onChange={e => set('giornata', e.target.value)} className="fanta-input" placeholder="15" />
        </div>
        <div />

        <div>
          <label className="text-xs text-slate-600 mb-1 block">Squadra casa</label>
          <select value={form.id_squadra} onChange={e => set('id_squadra', e.target.value)} className="fanta-input cursor-pointer">
            <option value="">Scegli...</option>
            {sqOpts.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>
        <div>
          <label className="text-xs text-slate-600 mb-1 block">Squadra ospite</label>
          <select value={form.id_squadra_a} onChange={e => set('id_squadra_a', e.target.value)} className="fanta-input cursor-pointer">
            <option value="">Scegli...</option>
            {sqOpts.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>

        <div>
          <label className="text-xs text-slate-600 mb-1 block">Punteggio casa</label>
          <input type="number" step="0.5" value={form.ftotale} onChange={e => set('ftotale', e.target.value)} className="fanta-input" placeholder="89.5" />
        </div>
        <div>
          <label className="text-xs text-slate-600 mb-1 block">Punteggio ospite</label>
          <input type="number" step="0.5" value={form.ftotale_a} onChange={e => set('ftotale_a', e.target.value)} className="fanta-input" placeholder="76.0" />
        </div>

        <div>
          <label className="text-xs text-slate-600 mb-1 block">Gol casa</label>
          <input type="number" value={form.golf} onChange={e => set('golf', e.target.value)} className="fanta-input" placeholder="2" />
        </div>
        <div>
          <label className="text-xs text-slate-600 mb-1 block">Gol ospite</label>
          <input type="number" value={form.gols} onChange={e => set('gols', e.target.value)} className="fanta-input" placeholder="1" />
        </div>

        <div>
          <label className="text-xs text-slate-600 mb-1 block">Modificatore casa</label>
          <input type="number" value={form.modificatore} onChange={e => set('modificatore', e.target.value)} className="fanta-input" />
        </div>
        <div>
          <label className="text-xs text-slate-600 mb-1 block">Modificatore ospite</label>
          <input type="number" value={form.modificatore_a} onChange={e => set('modificatore_a', e.target.value)} className="fanta-input" />
        </div>
      </div>

      <button onClick={handleSave} disabled={saving} className="btn-primary disabled:opacity-40">
        {saving ? <Spinner size="sm" /> : 'Salva risultato'}
      </button>

      {msg && (
        <div className={`mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2 ${
          msg.ok
            ? 'bg-green-500/10 border border-green-500/20 text-green-300'
            : 'bg-red-500/10 border border-red-500/20 text-red-300'
        }`}>
          {msg.ok ? <CheckCircle2 className="w-4 h-4" /> : <XCircle className="w-4 h-4" />}
          {msg.text}
        </div>
      )}
    </div>
  )
}

// ── Main Admin page ─────────────────────────────────────────────────────────
export default function Admin() {
  const { stagione } = useApp()
  const [tab, setTab] = useState('risultati')

  const { data: squadre, loading } = useFetch(() => getSquadre(stagione), [stagione])

  if (loading) return <LoadingState />

  const TABS = [
    { value: 'risultati', label: 'Risultati' },
    { value: 'chiudi',    label: 'Chiudi giornata' },
  ]

  return (
    <div className="animate-fade-up">
      <PageHeader label="Gestione" title="Pannello Admin" />

      <div className="flex gap-2 mb-6">
        {TABS.map(t => (
          <button
            key={t.value}
            onClick={() => setTab(t.value)}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all ${
              tab === t.value
                ? 'bg-gold-500 text-pitch-950'
                : 'bg-pitch-900 text-slate-400 hover:text-slate-200 border border-white/5'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'risultati' && <InserisciRisultato stagione={stagione} squadre={squadre} />}
      {tab === 'chiudi'    && <ChiudiGiornataWizard stagione={stagione} />}
    </div>
  )
}
