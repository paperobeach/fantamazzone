import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { login } from '../api/client'
import { Spinner } from '../components/ui'
import { Trophy } from 'lucide-react'

export default function Login() {
  const { ultimaStagione, doLogin } = useApp()
  const navigate = useNavigate()

  const [utenza,   setUtenza]   = useState('')
  const [password, setPassword] = useState('')
  const [error,    setError]    = useState(null)
  const [loading,  setLoading]  = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const utente = await login(ultimaStagione, utenza, password)
      doLogin(utente)
      navigate('/')
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-pitch-950 flex items-center justify-center p-4">
      {/* Background glow */}
      <div className="fixed inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-grass-500/5 rounded-full blur-3xl" />
      </div>

      <div className="w-full max-w-sm animate-fade-up">
        {/* Logo */}
        <div className="text-center mb-8">
          <div className="w-14 h-14 rounded-2xl bg-grass-500 flex items-center justify-center mx-auto mb-4">
            <Trophy className="w-7 h-7 text-pitch-950" />
          </div>
          <h1 className="text-display text-3xl font-bold text-white">LegaFantaMazzone</h1>
          <p className="text-slate-600 text-sm mt-1">Accedi al tuo account</p>
        </div>

        <form onSubmit={handleSubmit} className="card p-6 space-y-4">
          <div>
            <label className="text-xs font-mono tracking-widest uppercase text-slate-600 block mb-1.5">Username</label>
            <input
              type="text"
              value={utenza}
              onChange={e => setUtenza(e.target.value)}
              placeholder="Il tuo username"
              className="fanta-input"
              required
              autoFocus
            />
          </div>

          <div>
            <label className="text-xs font-mono tracking-widest uppercase text-slate-600 block mb-1.5">Password</label>
            <input
              type="password"
              value={password}
              onChange={e => setPassword(e.target.value)}
              placeholder="••••••••"
              className="fanta-input"
              required
            />
          </div>

          {error && (
            <div className="bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-3 text-sm text-red-400">
              {error}
            </div>
          )}

          <button
            type="submit"
            disabled={loading || !ultimaStagione}
            className="btn-primary w-full justify-center py-3 disabled:opacity-50"
          >
            {loading ? <Spinner size="sm" /> : 'Accedi'}
          </button>
        </form>

        {ultimaStagione && (
          <p className="text-center text-[11px] text-slate-700 mt-3">
            Stagione {ultimaStagione} / {ultimaStagione + 1}
          </p>
        )}
      </div>
    </div>
  )
}
