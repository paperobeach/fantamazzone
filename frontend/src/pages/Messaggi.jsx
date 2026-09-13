import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getMessaggi, sendMessaggio, getSquadre } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, TabBar } from '../components/ui'
import { Send } from 'lucide-react'

export default function Messaggi() {
  const { stagione, utente } = useApp()
  const [tab, setTab] = useState('ricevuti')
  const [dest, setDest] = useState('')
  const [testo, setTesto] = useState('')
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(false)

  const { data, loading, error, refetch } = useFetch(
    () => getMessaggi(stagione, utente?.id, tab),
    [stagione, utente?.id, tab]
  )

  const { data: squadre } = useFetch(() => getSquadre(stagione), [stagione])

  const handleSend = async () => {
    if (!dest || !testo.trim()) return
    setSending(true)
    try {
      await sendMessaggio(stagione, utente.id, Number(dest), testo.trim())
      setTesto('')
      setSent(true)
      setTimeout(() => setSent(false), 3000)
      if (tab === 'inviati') refetch()
    } catch (e) {
      alert(e.message)
    } finally {
      setSending(false)
    }
  }

  const TABS = [{ value: 'ricevuti', label: 'Ricevuti' }, { value: 'inviati', label: 'Inviati' }]

  return (
    <div className="animate-fade-up">
      <PageHeader label="Comunicazioni" title="Messaggi" />

      {/* Form invio */}
      <div className="card p-5 mb-6">
        <p className="text-xs font-mono tracking-widest uppercase text-slate-600 mb-4">Nuovo messaggio</p>
        <div className="flex gap-3">
          <select
            value={dest}
            onChange={e => setDest(e.target.value)}
            className="fanta-input w-48 cursor-pointer"
          >
            <option value="">Destinatario...</option>
            {(squadre ?? []).map(s => (
              <option key={s.id} value={s.id}>{s.allenatore ?? s.nome}</option>
            ))}
          </select>
          <input
            type="text"
            maxLength={100}
            value={testo}
            onChange={e => setTesto(e.target.value)}
            placeholder="Scrivi un messaggio... (max 100 caratteri)"
            className="fanta-input flex-1"
            onKeyDown={e => e.key === 'Enter' && handleSend()}
          />
          <button
            onClick={handleSend}
            disabled={sending || !dest || !testo.trim()}
            className="btn-primary disabled:opacity-40"
          >
            {sent ? '✓ Inviato' : <><Send className="w-4 h-4" /> Invia</>}
          </button>
        </div>
        <p className="text-xs text-slate-700 mt-2 text-right">{testo.length}/100</p>
      </div>

      {/* Tab messaggi */}
      <div className="mb-4">
        <TabBar tabs={TABS} active={tab} onChange={setTab} />
      </div>

      {loading ? <LoadingState /> : error ? <ErrorState message={error} onRetry={refetch} /> :
        !data?.length ? <EmptyState label="Nessun messaggio" /> : (
          <div className="space-y-2">
            {data.map((m, i) => (
              <div key={i} className="card p-4 flex items-start gap-4">
                <div className="w-8 h-8 rounded-full bg-pitch-800 border border-white/10 flex items-center justify-center text-xs font-bold text-grass-400 flex-shrink-0">
                  {(tab === 'ricevuti' ? m.nome_mittente : m.nome_destinatario)?.[0] ?? '?'}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="text-sm font-semibold text-slate-300">
                      {tab === 'ricevuti' ? m.nome_mittente : m.nome_destinatario}
                    </span>
                    <span className="text-xs text-slate-700">
                      {tab === 'ricevuti' ? '→ te' : '→ ' + m.nome_destinatario}
                    </span>
                  </div>
                  <p className="text-sm text-slate-400">{m.messaggio}</p>
                </div>
              </div>
            ))}
          </div>
        )
      }
    </div>
  )
}
