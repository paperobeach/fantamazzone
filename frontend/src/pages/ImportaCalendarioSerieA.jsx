import { useState, useRef } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getCalendarioSerieAInfo, adminImportaCalendarioSerieA } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertTriangle, X } from 'lucide-react'

// "2026-08-22" -> "22/08/2026"
const fmtData = (iso) => {
  if (!iso) return '-'
  const [y, m, d] = String(iso).slice(0, 10).split('-')
  return `${d}/${m}/${y}`
}

export default function ImportaCalendarioSerieA() {
  const { stagione } = useApp()

  const { data: info, loading: loadingInfo, refetch } = useFetch(
    stagione ? () => getCalendarioSerieAInfo(stagione) : null,
    [stagione],
  )

  const [file,    setFile]    = useState(null)
  const [loading, setLoading] = useState(false)
  const [errors,  setErrors]  = useState([])
  const [result,  setResult]  = useState(null)
  const fileInputRef = useRef(null)

  const giaCaricato = (info?.totale ?? 0) > 0

  const resetFile = () => {
    setFile(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleFileChange = (e) => {
    setErrors([])
    setResult(null)
    setFile(e.target.files?.[0] ?? null)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setErrors([])
    setResult(null)

    if (!stagione) {
      setErrors([{ riga: 0, campo: 'stagione', messaggio: 'Seleziona la stagione dal menu laterale' }])
      return
    }
    if (!file) {
      setErrors([{ riga: 0, campo: 'file', messaggio: 'Seleziona un file Excel (.xlsx)' }])
      return
    }
    if (giaCaricato && !window.confirm(
      `Per la stagione ${stagione} esiste già un calendario di ${info.totale} partite. Verrà sostituito. Continuare?`
    )) return

    setLoading(true)
    try {
      const res = await adminImportaCalendarioSerieA(Number(stagione), file)
      setResult(res)
      resetFile()
      refetch()
    } catch (err) {
      // err.details = elenco { riga, campo, messaggio } in caso di VALIDATION_ERROR
      setErrors(err.details ?? [{ riga: 0, campo: '', messaggio: err.message }])
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Importa calendario Serie A"
        subtitle='Carica il file Excel con lo sheet "Tutte le partite" per importare il calendario della Serie A nella stagione selezionata.'
      />

      <div className="card p-6 max-w-2xl">
        <form onSubmit={handleSubmit}>
          <div className="mb-5">
            <label className="text-xs text-slate-600 mb-1 block">Stagione</label>
            <p className="text-sm text-slate-300">
              {stagione ? `${stagione} / ${Number(stagione) + 1}` : '-'}
            </p>
          </div>

          {giaCaricato && (
            <div className="mb-5 rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-300 text-sm">
              <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>
                Esiste già un calendario con {info.totale} partite ({info.giornate.length} giornate):
                il nuovo caricamento lo sostituirà.
              </span>
            </div>
          )}

          <div className="mb-5">
            <label className="text-xs text-slate-600 mb-1 block">File Excel (sheet "Tutte le partite")</label>

            {!file ? (
              <label
                htmlFor="file-calendario-serie-a"
                className="flex flex-col items-center justify-center gap-2 px-4 py-8 rounded-lg
                           border border-dashed border-white/15 text-slate-500
                           hover:border-grass-500/40 hover:text-slate-300 cursor-pointer transition-colors"
              >
                <UploadCloud className="w-6 h-6" />
                <span className="text-sm">Trascina qui il file o clicca per selezionarlo</span>
                <span className="text-[10px] text-mono uppercase tracking-widest text-slate-700">Solo .xlsx</span>
                <input
                  id="file-calendario-serie-a"
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

            <p className="text-[11px] text-slate-600 mt-2">
              Colonne attese: Giornata, N° partita nella giornata, Data, Ora, Partita (Casa-Ospite),
              Prima partita?, Stato orario. L'ora "TBD" indica un orario ancora da definire.
            </p>
          </div>

          <button type="submit" disabled={loading} className="btn-primary disabled:opacity-40">
            {loading ? <><Spinner size="sm" /> Caricamento in corso...</> : 'Importa calendario'}
          </button>
        </form>
      </div>

      {errors.length > 0 && (
        <div className="card p-6 max-w-2xl mt-6">
          <div className="mb-4 rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm">
            <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />
            <span>
              {errors.length === 1 && !errors[0].riga
                ? 'Impossibile completare l\'importazione. Nessun dato è stato salvato.'
                : `Il file contiene ${errors.length} errore/i. Nessun dato è stato salvato: correggi il file e ricarica.`}
            </span>
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
            <span className="font-medium">Importazione completata</span>
          </div>
          <div className="grid grid-cols-3 gap-4 text-center">
            <div>
              <p className="text-display text-2xl font-bold text-white">{result.totale}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Partite</p>
            </div>
            <div>
              <p className="text-display text-2xl font-bold text-grass-400">{result.giornate}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Giornate</p>
            </div>
            <div>
              <p className="text-display text-2xl font-bold text-gold-400">{result.senza_orario}</p>
              <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Orario da definire</p>
            </div>
          </div>
          {result.avvisi?.length > 0 && (
            <div className="mt-5 rounded-lg px-4 py-3 bg-gold-500/5 border border-gold-500/20 text-gold-300 text-sm">
              <p className="font-medium mb-1 flex items-center gap-2">
                <AlertTriangle className="w-4 h-4" /> Avvisi (non bloccanti)
              </p>
              <ul className="list-disc pl-5 space-y-0.5">
                {result.avvisi.map((a, i) => <li key={i}>{a}</li>)}
              </ul>
            </div>
          )}
        </div>
      )}

      {/* Riepilogo giornate attualmente caricate */}
      {loadingInfo && !info ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : giaCaricato && (
        <div className="card p-6 max-w-2xl mt-6">
          <p className="text-xs text-slate-600 uppercase tracking-widest mb-3">
            Calendario caricato · {info.totale} partite
          </p>
          <div className="overflow-x-auto max-h-96 overflow-y-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                  <th className="py-2 pr-4">Giornata</th>
                  <th className="py-2 pr-4">Dal</th>
                  <th className="py-2 pr-4">Al</th>
                  <th className="py-2 pr-4">Partite</th>
                  <th className="py-2">Orari da definire</th>
                </tr>
              </thead>
              <tbody>
                {info.giornate.map(g => (
                  <tr key={g.giornata} className="border-t border-white/[0.03]">
                    <td className="py-2 pr-4 text-slate-300">{g.giornata}</td>
                    <td className="py-2 pr-4 text-slate-400">{fmtData(g.data_inizio)}</td>
                    <td className="py-2 pr-4 text-slate-400">{fmtData(g.data_fine)}</td>
                    <td className="py-2 pr-4 text-slate-400">{g.n_partite}</td>
                    <td className="py-2 text-slate-500">{g.n_tbd || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
