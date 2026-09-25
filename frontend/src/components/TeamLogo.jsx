import { useState, useEffect } from 'react'

// Logo squadra con fallback sull'iniziale del nome.
// I file si trovano in /public/logos/ e il nome file è il valore
// del campo `logo` del DB (es. NEW_SQUADRE.logo = "papero.jpg").
//
// size: 'sm' (righe compatte), 'md' (Incontri), 'lg' (card Squadre)
// I loghi sono su sfondo chiaro (tema scuro dell'app): vengono mostrati
// in una piastrella bianca arrotondata, larga quanto serve al logo.
const BOX = {
  sm: 'h-7 max-w-[5.5rem]',
  md: 'h-7 sm:h-8 max-w-[5.5rem] sm:max-w-[6.5rem]',
  lg: 'h-12 max-w-[7.5rem]',
}
const FALLBACK = {
  sm: 'w-7 h-7 text-[10px] text-slate-500',
  md: 'w-7 h-7 sm:w-8 sm:h-8 text-xs text-slate-500',
  lg: 'w-12 h-12 text-xl text-grass-400',
}

export default function TeamLogo({ logo, nome, size = 'sm', className = '' }) {
  const [failed, setFailed] = useState(false)
  useEffect(() => setFailed(false), [logo])

  if (!logo || failed) {
    return (
      <div className={`${FALLBACK[size]} rounded-lg bg-pitch-800 border border-white/10 flex items-center justify-center font-bold flex-shrink-0 ${className}`}>
        {nome?.[0]}
      </div>
    )
  }

  return (
    <div className={`${BOX[size]} rounded-lg bg-white border border-white/10 p-0.5 flex items-center justify-center flex-shrink-0 overflow-hidden ${className}`}>
      <img
        src={`${import.meta.env.BASE_URL}logos/${logo}`}
        alt={nome ?? ''}
        loading="lazy"
        onError={() => setFailed(true)}
        className="h-full w-auto max-w-full object-contain"
      />
    </div>
  )
}
