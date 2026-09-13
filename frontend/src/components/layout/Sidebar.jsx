import { NavLink, useNavigate } from 'react-router-dom'
import { useApp } from '../../context/AppContext'
import {
  Trophy, Calendar, Users, Swords, BarChart2,
  Star, Zap, Shield, MessageSquare, LogOut,
  Settings, ChevronDown, Medal
} from 'lucide-react'

const NAV = [
  { to: '/',              icon: Trophy,       label: 'Classifica'    },
  { to: '/calendario',   icon: Calendar,     label: 'Calendario'    },
  { to: '/squadre',      icon: Users,        label: 'Squadre'       },
  { to: '/incontri',     icon: Swords,       label: 'Incontri'      },
  { to: '/statistiche',  icon: BarChart2,    label: 'Statistiche'   },
  { to: '/marcatori',    icon: Medal,        label: 'Marcatori'     },
  { to: '/top-flop',     icon: Star,         label: 'Top / Flop 11' },
  { to: '/kulovic',      icon: Zap,          label: 'Kulovic'       },
  { to: '/champions',    icon: Shield,       label: 'Champions'     },
  { to: '/schedina',     icon: Medal,        label: 'Schedina'      },
  { to: '/messaggi',     icon: MessageSquare,label: 'Messaggi'      },
]

const ADMIN_NAV = [
  { to: '/admin',        icon: Settings,     label: 'Pannello Admin' },
]

export default function Sidebar() {
  const { stagioni, stagione, changeStagione, utente, doLogout, isAdmin } = useApp()
  const navigate = useNavigate()

  const handleLogout = () => {
    doLogout()
    navigate('/login')
  }

  return (
    <aside className="fixed left-0 top-0 h-screen w-56 flex flex-col bg-pitch-900 border-r border-white/5 z-40">

      {/* Logo */}
      <div className="px-5 pt-6 pb-5 border-b border-white/5">
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
              <option key={s} value={s}>{s} / {s + 1}</option>
            ))}
          </select>
          <ChevronDown className="absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-600 pointer-events-none" />
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto py-3 px-3 space-y-0.5">
        {NAV.map(({ to, icon: Icon, label }) => (
          <NavLink
            key={to}
            to={to}
            end={to === '/'}
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

        {isAdmin && (
          <>
            <div className="pt-3 pb-1 px-2">
              <p className="text-[9px] text-mono tracking-widest uppercase text-slate-700">Admin</p>
            </div>
            {ADMIN_NAV.map(({ to, icon: Icon, label }) => (
              <NavLink
                key={to}
                to={to}
                className={({ isActive }) =>
                  `flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
                    isActive
                      ? 'bg-gold-500/10 text-gold-400 font-medium'
                      : 'text-slate-500 hover:text-slate-300 hover:bg-white/[0.03]'
                  }`
                }
              >
                {({ isActive }) => (
                  <>
                    <Icon className={`w-4 h-4 flex-shrink-0 ${isActive ? 'text-gold-400' : ''}`} />
                    {label}
                  </>
                )}
              </NavLink>
            ))}
          </>
        )}
      </nav>

      {/* User footer */}
      <div className="px-3 py-3 border-t border-white/5">
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
            <button onClick={handleLogout} className="p-1.5 rounded-lg text-slate-600 hover:text-red-400 hover:bg-red-400/5 transition-all">
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
  )
}
