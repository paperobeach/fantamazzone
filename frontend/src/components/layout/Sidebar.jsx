import { useEffect, useState } from 'react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useApp } from '../../context/AppContext'
import {
  Trophy, Calendar, Users, BarChart2,
  Star, Shield, LogOut,
  ChevronDown, X, Upload, ClipboardList, RefreshCw, ClipboardCheck, SlidersHorizontal, RotateCcw, CalendarDays, Radio, Eraser, ArchiveRestore, Sparkles, PlugZap, Newspaper, FileText, BookOpen, LayoutDashboard
} from 'lucide-react'

// Menu principale suddiviso in tre sezioni: Generale, Campionato, Champions.
const NAV_SECTIONS = [
  {
    title: 'Generale',
    items: [
      { to: '/squadre',      icon: Users,     label: 'Squadre'       },
      { to: '/live',         icon: Radio,     label: 'LIVE Giornata in corso' },
      { to: '/statistiche',  icon: BarChart2, label: 'Statistiche'   },
      { to: '/gazzetta',     icon: Newspaper, label: 'La Gazzetta'   },
    ],
  },
  {
    title: 'Campionato',
    items: [
      { to: '/calendario',   icon: Calendar,  label: 'Calendario'    },
      { to: '/',             icon: Trophy,    label: 'Classifica'    },
      { to: '/top-flop',     icon: Star,      label: 'Top / Flop 11' },
    ],
  },
  {
    title: 'Champions',
    items: [
      { to: '/champions',          icon: Shield, label: 'Risultati e classifica' },
      { to: '/champions/top-flop', icon: Star,   label: 'Top / Flop 11' },
    ],
  },
]

// Voci con rotta annidata (/champions e /champions/top-flop): evidenziano
// solo la propria pagina, non anche quella annidata.
const NAV_END = new Set(['/', '/champions'])

// Menu "Gestione squadra": disponibile solo per gli utenti loggati,
// per la gestione della propria squadra ("Formazione" è stata
// spostata qui e rinominata in "Inserimento formazione").
const TEAM_NAV = [
  { to: '/dashboard-squadra', icon: LayoutDashboard, label: 'Dashboard squadra' },
  { to: '/formazione',   icon: ClipboardList,label: 'Inserimento formazione' },
]

// Menu Admin: suddiviso in tre sezioni. "Avvio stagione" e "Utility"
// sono compresse di default (si aprono da sole se la pagina corrente
// appartiene alla sezione).
const ADMIN_SECTIONS = [
  {
    title: 'Gestione stagione',
    defaultOpen: true,
    items: [
      { to: '/gestione-voti',               icon: ClipboardCheck, label: 'Gestione voti' },
    ],
  },
  {
    title: 'Avvio stagione',
    defaultOpen: false,
    items: [
      { to: '/importa-calendario-serie-a',  icon: CalendarDays, label: 'Importa calendario Serie A' },
      { to: '/regole-calcolo',              icon: SlidersHorizontal, label: 'Gestisci regole di calcolo' },
      { to: '/inizializzazione-stagione',   icon: RefreshCw, label: 'Inizializza stagione' },
      { to: '/inserimento-rose',            icon: Upload,    label: 'Inserimento rose' },
    ],
  },
  {
    title: 'Utility',
    defaultOpen: false,
    items: [
      { to: '/riapertura-giornata',         icon: RotateCcw, label: 'Riapri giornata' },
      { to: '/reset-stagione',              icon: Eraser,    label: 'Reset stagione' },
      { to: '/ripristino-stagione',         icon: ArchiveRestore, label: 'Ripristino stagione' },
    ],
  },
]

// Menu "AI": visibile solo alle utenze con NEW_UTENZE.abilita_ai = 'Y'.
// La sezione "Configura" è compressa di default.
const AI_SECTIONS = [
  {
    title: 'Genera',
    defaultOpen: true,
    items: [
      { to: '/ai/articolo', icon: Newspaper, label: 'Genera articolo' },
    ],
  },
  {
    title: 'Configura',
    defaultOpen: false,
    items: [
      { to: '/ai/articoli', icon: FileText, label: 'Gestione articoli' },
      { to: '/ai/contesto', icon: BookOpen, label: 'Profili ed episodi' },
      { to: '/ai/test', icon: PlugZap, label: 'Test connessione AI' },
    ],
  },
]

// Sezione di menu comprimibile. Si apre automaticamente quando la pagina
// corrente è una delle sue voci.
function NavSection({ title, icon: TitleIcon, items, defaultOpen, accent }) {
  const { pathname } = useLocation()
  const containsActive = items.some(i => pathname === i.to || pathname.startsWith(i.to + '/'))
  const [open, setOpen] = useState(defaultOpen || containsActive)

  useEffect(() => {
    if (containsActive) setOpen(true)
  }, [containsActive])

  const activeCls = accent === 'gold'
    ? 'bg-gold-500/10 text-gold-400 font-medium'
    : 'bg-grass-500/10 text-grass-400 font-medium'
  const iconCls = accent === 'gold' ? 'text-gold-400' : 'text-grass-500'

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        className="w-full pt-3 pb-1 px-2 flex items-center justify-between text-slate-700 hover:text-slate-500 transition-colors"
      >
        <span className="text-[9px] text-mono tracking-widest uppercase flex items-center gap-1">
          {TitleIcon && <TitleIcon className="w-2.5 h-2.5" />} {title}
        </span>
        <ChevronDown className={`w-3 h-3 transition-transform duration-150 ${open ? '' : '-rotate-90'}`} />
      </button>
      {open && items.map(({ to, icon: Icon, label }) => (
        <NavLink
          key={to}
          to={to}
          className={({ isActive }) =>
            `flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
              isActive
                ? activeCls
                : 'text-slate-500 hover:text-slate-300 hover:bg-white/[0.03]'
            }`
          }
        >
          {({ isActive }) => (
            <>
              <Icon className={`w-4 h-4 flex-shrink-0 ${isActive ? iconCls : ''}`} />
              {label}
            </>
          )}
        </NavLink>
      ))}
    </div>
  )
}

export default function Sidebar({ open = false, onClose = () => {} }) {
  const { stagioni, stagione, changeStagione, utente, doLogout, isAdmin, canAi } = useApp()
  const navigate = useNavigate()
  const location = useLocation()

  // Chiude il drawer mobile ad ogni cambio pagina
  useEffect(() => {
    onClose()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname])

  // Blocca lo scroll del body quando il drawer mobile è aperto
  useEffect(() => {
    if (open) {
      document.body.style.overflow = 'hidden'
      return () => { document.body.style.overflow = '' }
    }
  }, [open])

  const handleLogout = () => {
    doLogout()
    navigate('/login')
  }

  return (
    <>
      {/* Overlay mobile */}
      <div
        onClick={onClose}
        aria-hidden="true"
        className={`fixed inset-0 bg-black/60 backdrop-blur-[1px] z-40 lg:hidden transition-opacity duration-200 ${
          open ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'
        }`}
      />

      <aside
        className={`fixed inset-y-0 left-0 w-64 sm:w-56 flex flex-col bg-pitch-900 border-r border-white/5 z-50
                    transition-transform duration-300 ease-out
                    ${open ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
      >

      {/* Logo */}
      <div className="px-5 pt-6 pb-5 border-b border-white/5 flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2 mb-0.5">
            <div className="w-7 h-7 rounded-lg bg-grass-500 flex items-center justify-center flex-shrink-0">
              <Trophy className="w-4 h-4 text-pitch-950" />
            </div>
            <span className="text-display text-lg font-bold tracking-wide text-white leading-none">
              LFM
            </span>
          </div>
          <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600 mt-1 pl-9">
            FantaMazzone
          </p>
        </div>
        <button
          onClick={onClose}
          aria-label="Chiudi menu"
          className="lg:hidden p-1.5 -mt-1 -mr-1.5 rounded-lg text-slate-500 hover:text-slate-300 hover:bg-white/[0.05] transition-colors"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Stagione selector */}
      <div className="px-3 py-3 border-b border-white/5">
        <p className="text-[9px] text-mono tracking-widest uppercase text-slate-700 mb-1.5 px-2">Stagione</p>
        <div className="relative">
          <select
            value={stagione ?? ''}
            onChange={e => changeStagione(Number(e.target.value))}
            className="w-full appearance-none bg-pitch-800 border border-white/10 rounded-lg
                       px-3 py-2 text-sm text-slate-300 cursor-pointer pr-8
                       focus:outline-none focus:border-grass-500/40"
          >
            {stagioni.map(s => (
              <option key={s} value={s}>{s} / {Number(s) + 1}</option>
            ))}
          </select>
          <ChevronDown className="absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-600 pointer-events-none" />
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 min-h-0 overflow-y-auto py-3 px-3 space-y-0.5">
        {NAV_SECTIONS.map((sec, idx) => (
          <div key={sec.title}>
            <div className={`${idx === 0 ? 'pt-0' : 'pt-3'} pb-1 px-2`}>
              <p className="text-[9px] text-mono tracking-widest uppercase text-slate-700">{sec.title}</p>
            </div>
            {sec.items.map(({ to, icon: Icon, label }) => (
              <NavLink
                key={to}
                to={to}
                end={NAV_END.has(to)}
                className={({ isActive }) =>
                  `flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
                    isActive
                      ? 'bg-grass-500/10 text-grass-400 font-medium'
                      : 'text-slate-500 hover:text-slate-300 hover:bg-white/[0.03]'
                  }`
                }
              >
                {({ isActive }) => (
                  <>
                    <Icon className={`w-4 h-4 flex-shrink-0 ${isActive ? 'text-grass-500' : ''}`} />
                    {label}
                  </>
                )}
              </NavLink>
            ))}
          </div>
        ))}

        {utente && (
          <>
            <div className="pt-3 pb-1 px-2">
              <p className="text-[9px] text-mono tracking-widest uppercase text-slate-700">Gestione squadra</p>
            </div>
            {TEAM_NAV.map(({ to, icon: Icon, label }) => (
              <NavLink
                key={to}
                to={to}
                className={({ isActive }) =>
                  `flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
                    isActive
                      ? 'bg-grass-500/10 text-grass-400 font-medium'
                      : 'text-slate-500 hover:text-slate-300 hover:bg-white/[0.03]'
                  }`
                }
              >
                {({ isActive }) => (
                  <>
                    <Icon className={`w-4 h-4 flex-shrink-0 ${isActive ? 'text-grass-500' : ''}`} />
                    {label}
                  </>
                )}
              </NavLink>
            ))}
          </>
        )}

        {canAi && AI_SECTIONS.map(sec => (
          <NavSection key={sec.title} {...sec} title={`AI · ${sec.title}`} icon={Sparkles} accent="grass" />
        ))}

        {isAdmin && ADMIN_SECTIONS.map(sec => (
          <NavSection key={sec.title} {...sec} title={`Admin · ${sec.title}`} accent="gold" />
        ))}
      </nav>

      {/* User footer */}
      <div className="flex-shrink-0 px-3 py-3 border-t border-white/5 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        {utente ? (
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-full bg-pitch-700 border border-white/10 flex items-center justify-center flex-shrink-0">
              <span className="text-xs font-bold text-grass-400">
                {utente.descrizione?.[0]?.toUpperCase() ?? '?'}
              </span>
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-medium text-slate-300 truncate">{utente.descrizione}</p>
              <p className="text-[10px] text-slate-600">{isAdmin ? 'Admin' : 'Utente'}</p>
            </div>
            <button onClick={handleLogout} aria-label="Esci" title="Esci" className="p-1.5 rounded-lg text-slate-600 hover:text-red-400 hover:bg-red-400/5 transition-all">
              <LogOut className="w-3.5 h-3.5" />
            </button>
          </div>
        ) : (
          <NavLink
            to="/login"
            className="flex items-center justify-center gap-2 w-full py-2 rounded-lg
                       border border-white/10 text-sm text-slate-500 hover:text-slate-300
                       hover:border-white/20 transition-all"
          >
            Accedi
          </NavLink>
        )}
      </div>
      </aside>
    </>
  )
}
