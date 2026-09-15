import { AlertCircle, RefreshCw, Inbox } from 'lucide-react'

// ── Spinner ───────────────────────────────────────────────────
export function Spinner({ size = 'md', className = '' }) {
  const s = { sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-8 h-8' }[size]
  return (
    <svg className={`${s} animate-spin text-grass-500 ${className}`} viewBox="0 0 24 24" fill="none">
      <circle className="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3"/>
      <path className="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
    </svg>
  )
}

// ── Loading state ─────────────────────────────────────────────
export function LoadingState({ label = 'Caricamento...' }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-slate-500">
      <Spinner size="lg" />
      <span className="text-sm font-mono tracking-widest uppercase">{label}</span>
    </div>
  )
}

// ── Error state ───────────────────────────────────────────────
export function ErrorState({ message, onRetry }) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-20 text-center">
      <AlertCircle className="w-8 h-8 text-red-400/70" />
      <div>
        <p className="text-slate-300 font-medium">Errore nel caricamento</p>
        <p className="text-sm text-slate-600 mt-1">{message}</p>
      </div>
      {onRetry && (
        <button onClick={onRetry} className="btn-ghost text-xs">
          <RefreshCw className="w-3.5 h-3.5" /> Riprova
        </button>
      )}
    </div>
  )
}

// ── Empty state ───────────────────────────────────────────────
export function EmptyState({ label = 'Nessun dato disponibile' }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-slate-600">
      <Inbox className="w-8 h-8 opacity-40" />
      <span className="text-sm">{label}</span>
    </div>
  )
}

// ── Role badge ────────────────────────────────────────────────
const ROLE_MAP = { '1': { label: 'P', cls: 'badge-role-p' }, '2': { label: 'D', cls: 'badge-role-d' }, '3': { label: 'C', cls: 'badge-role-c' }, '4': { label: 'A', cls: 'badge-role-a' } }

export function RoleBadge({ ruolo }) {
  const r = ROLE_MAP[String(ruolo)] ?? { label: '?', cls: '' }
  return <span className={`stat-pill ${r.cls}`}>{r.label}</span>
}

// ── Sign badge (W/N/L) ────────────────────────────────────────
export function SignBadge({ segno }) {
  const map = { W: { cls: 'badge-win', label: 'V' }, N: { cls: 'badge-draw', label: 'P' }, L: { cls: 'badge-loss', label: 'S' } }
  const s = map[segno] ?? { cls: '', label: segno }
  return <span className={`stat-pill ${s.cls}`}>{s.label}</span>
}

// ── Skeleton rows ─────────────────────────────────────────────
export function SkeletonRows({ rows = 5, cols = 4 }) {
  return Array.from({ length: rows }).map((_, i) => (
    <tr key={i} className="border-b border-white/[0.03]">
      {Array.from({ length: cols }).map((_, j) => (
        <td key={j} className="px-4 py-3">
          <div className="skeleton h-3 rounded" style={{ width: `${60 + Math.random() * 30}%` }} />
        </td>
      ))}
    </tr>
  ))
}

// ── Page header ───────────────────────────────────────────────
export function PageHeader({ label, title, subtitle, children }) {
  return (
    <div className="mb-6 sm:mb-8 animate-fade-up">
      {label && <p className="section-label">{label}</p>}
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-3 sm:gap-4">
        <div className="min-w-0">
          <h1 className="text-display text-3xl sm:text-4xl font-bold tracking-wide text-white truncate">{title}</h1>
          {subtitle && <p className="text-slate-500 text-sm mt-1">{subtitle}</p>}
        </div>
        {children && <div className="flex-shrink-0">{children}</div>}
      </div>
    </div>
  )
}

// ── Stat card ─────────────────────────────────────────────────
export function StatCard({ label, value, sub, accent = false }) {
  return (
    <div className={`card p-5 ${accent ? 'border-grass-500/20 bg-grass-500/5' : ''}`}>
      <p className="text-xs text-mono tracking-widest uppercase text-slate-600 mb-1">{label}</p>
      <p className={`text-display text-3xl font-bold ${accent ? 'text-grass-400' : 'text-white'}`}>{value}</p>
      {sub && <p className="text-xs text-slate-600 mt-1">{sub}</p>}
    </div>
  )
}

// ── Divider ───────────────────────────────────────────────────
export function Divider({ className = '' }) {
  return <div className={`border-t border-white/5 ${className}`} />
}

// ── Select ───────────────────────────────────────────────────
export function Select({ value, onChange, options, className = '' }) {
  return (
    <select
      value={value}
      onChange={e => onChange(e.target.value)}
      className={`fanta-input cursor-pointer ${className}`}
    >
      {options.map(o => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
  )
}

// ── Tab bar ───────────────────────────────────────────────────
export function TabBar({ tabs, active, onChange }) {
  return (
    <div className="flex gap-1 p-1 bg-pitch-900 rounded-xl border border-white/5 w-fit">
      {tabs.map(t => (
        <button
          key={t.value}
          onClick={() => onChange(t.value)}
          className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all duration-150 ${
            active === t.value
              ? 'bg-grass-500 text-pitch-950'
              : 'text-slate-400 hover:text-slate-200'
          }`}
        >
          {t.label}
        </button>
      ))}
    </div>
  )
}
