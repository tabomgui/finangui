import { ArrowLeft } from 'lucide-react'
import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { NotificationBell } from './notification-bell'
import { headerIconButton, ThemeToggle } from './theme-toggle'

export const headerButton = 'border-0 bg-white/20 text-white shadow-none hover:bg-white/30'

type PageHeaderProps = {
  title: string
  subtitle?: string
  /** true volta no histórico; uma string navega para o caminho. */
  back?: boolean | string
  actions?: ReactNode
  children?: ReactNode
}

export function PageHeader({ title, subtitle, back, actions, children }: PageHeaderProps) {
  const navigate = useNavigate()

  const goBack = () => {
    if (typeof back === 'string') {
      navigate(back)
      return
    }
    // react-router grava `idx` no estado do histórico; sem uma entrada anterior dentro do app
    // (ex.: chegou por link direto), `navigate(-1)` sairia do app — ir para o início em vez disso.
    const idx = (window.history.state as { idx?: number } | null)?.idx
    if (typeof idx === 'number' && idx > 0) {
      navigate(-1)
    } else {
      navigate('/')
    }
  }

  return (
    <header className="bg-linear-to-r from-emerald-500 to-emerald-600 text-white dark:from-emerald-600 dark:to-emerald-700">
      <div className="mx-auto max-w-5xl px-4 pb-10 pt-[calc(env(safe-area-inset-top)+1.25rem)] sm:px-6 md:pt-8">
        <div className="flex items-center gap-3">
          {back && (
            <button type="button" aria-label="Voltar" className={headerIconButton} onClick={goBack}>
              <ArrowLeft className="h-5 w-5" />
            </button>
          )}
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-xl font-bold sm:text-2xl">{title}</h1>
            {subtitle && <p className="truncate text-xs text-white/80 sm:text-sm">{subtitle}</p>}
          </div>
          <div className="flex items-center gap-2">
            {actions}
            <NotificationBell variant="header" className="md:hidden" />
            <ThemeToggle variant="header" className="md:hidden" />
          </div>
        </div>
        {children && <div className="mt-5">{children}</div>}
      </div>
    </header>
  )
}
