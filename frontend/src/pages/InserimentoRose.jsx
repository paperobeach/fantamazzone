import { useState, useRef } from 'react'
import { useApp } from '../context/AppContext'
import { adminInserimentoRose } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import { UploadCloud, FileSpreadsheet, CheckCircle2, XCircle, AlertTriangle, X } from 'lucide-react'

export default function InserimentoRose() {
  const { stagione: stagioneCorrente } = useApp()

  const [stagione, setStagione] = useState(stagioneCorrente ?? '')
  const [file,     setFile]     = useState(null)
  const [loading,  setLoading]  = useState(false)
  const [errors,   setErrors]   = useState([])
  const [result,   setResult]   = useState(null)
  const fileInputRef = useRef(null)

  const resetFile = () => {
    setFile(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleFileChange = (e) => {
    const f = e.target.files?.[0] ?? null
    setErrors([])
    setResult(null)
    setFile(f)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setErrors([])
    setResult(null)

    if (!stagione) {
      setErrors([{ riga: 0, campo: 'stagione', messaggio: 'Indica la stagione' }])
      return
    }
    if (!file) {
      setErrors([{ riga: 0, campo: 'file', messaggio: 'Seleziona un file Excel (.xlsx)' }])
      return
    }

    setLoading(true)
    try {
      const res = await adminInserimentoRose(Number(stagione), file)
      setResult(res)
      resetFile()
    } catch (err) {
      // err.details = elenco { riga, campo, messaggio } quando il
      // backend restituisce un errore di validazione (VALIDATION_ERROR)
      setErrors(err.details ?? [{ riga: 0, campo: '', messaggio: err.message }])
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Inserimento rose"
        subtitle='Carica il file Excel con lo sheet "Giocatori" per aggiornare rose e svincolati della stagione.'
      />

      <div className="card p-6 max-w-2xl">
        <form onSubmit={handleSubmit}>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
            <div>
              <label className="text-xs text-slate-600 mb-1 block">Stagione</label>
              <input
                type="number"
                value={stagione}
                onChange={e => setStagione(e.target.value)}
                placeholder="Es. 2026"
                className="fanta-input"
              />
            </div>
          </div>

          <div className="mb-5">
            <label className="text-xs text-slate-600 mb-1 block">File Excel (sheet "Giocatori")</label>

            {!file ? (
              <label
                htmlFor="file-rose"
                className="flex flex-col items-center justify-center gap-2 px-4 py-8 rounded-lg
                           border border-dashed border-white/15 text-slate-500
                           hover:border-grass-500/40 hover:text-slate-300 cursor-pointer transition-colors"
              >
                <UploadCloud className="w-6 h-6" />
                <span className="text-sm">Trascina qui il file o clicca per selezionarlo</span>
                <span className="text-[10px] text-mono uppercase tracking-widest text-slate-700">Solo .xlsx</span>
                <input
                  id="file-rose"
                  ref={fileInputRef}
                  type="file"
                  accept=".xlsx"
                  onChange={handleFileChange}
                  className="hidden"
                />
              </label>
            ) : (
              <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-pitch-900 border border-white/10">
                <FileSpreadsheet className="w-4 h-4 text-grass-400 flex-shrink-0" />
                <span className="text-sm text-slate-300 truncate flex-1">{file.name}</span>
                <button type="button" onClick={resetFile} className="p-1 rounded text-slate-600 hover:text-red-400 transition-colors">
                  <X className="w-3.5 h-3.5" />
                </button>
              </div>
            )}
          </div>

          <button type="submit" disabled={loading} className="btn-primary disabled:opacity-40">
            {loading ? <><Spinner size="sm" /> Caricamento in corso...</> : 'Carica rose'}
          </button>
        </form>
      </div>

      {errors.length > 0 && (
        <div className="card p-6 max-w-2xl mt-6">
          <div className="info-box mb-4 rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm">
            <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />
            <span>Il file contiene {errors.length} errore/i. Nessun dato è stato salvato: correggi il file e ricarica.</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                  <th className="py-2 pr-4">Riga</th>
                  <th className="py-2 pr-4">Campo</th>
                  <th className="py-2">Messaggio</th>
                </tr>
              </thead>
              <tbody>
                {errors.map((er, idx) => (
                  <tr key={idx} className="border-t border-white/[0.03]">
                    <td className="py-2 pr-4 text-slate-500">{er.riga || '-'}</td>
                    <td className="py-2 pr-4 text-slate-400">{er.campo || '-'}</td>
                    <td className="py-2 text-slate-300">{er.messaggio}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {result && (
        <div className="card p-6 max-w-2xl mt-6">
          <div className="flex items-center gap-2 mb-4 text-green-300">
            <CheckCircle2 className="w-4 h-4" />
            <span className="font-medium">Caricamento completato</span>
          </div>
          <div className="grid grid-cols-3 gap-4 text-center">
            <div>
              <p className="text-display text-2xl font-bold text-white">{result.totale}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Processati</p>
            </div>
            <div>
              <p className="text-display text-2xl font-bold text-grass-400">{result.caricati}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Assegnati</p>
            </div>
            <div>
              <p className="text-display text-2xl font-bold text-gold-400">{result.svincolati}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Svincolati</p>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
