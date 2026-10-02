import { ArrowLeft } from 'lucide-react'
import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { headerIconButton, ThemeToggle } from './theme-toggle'

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

  return (
    <header className="bg-linear-to-r from-emerald-500 to-emerald-600 text-white dark:from-emerald-600 dark:to-emerald-700">
      <div className="mx-auto max-w-5xl px-4 pb-10 pt-[calc(env(safe-area-inset-top)+1.25rem)] sm:px-6 md:pt-8">
        <div className="flex items-center gap-3">
          {back && (
            <button
              type="button"
              aria-label="Voltar"
              className={headerIconButton}
              onClick={() => (typeof back === 'string' ? navigate(back) : navigate(-1))}
            >
              <ArrowLeft className="h-5 w-5" />
            </button>
          )}
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-xl font-bold sm:text-2xl">{title}</h1>
            {subtitle && <p className="truncate text-xs text-white/80 sm:text-sm">{subtitle}</p>}
          </div>
          <div className="flex items-center gap-2">
            {actions}
            <ThemeToggle variant="header" className="md:hidden" />
          </div>
        </div>
        {children && <div className="mt-5">{children}</div>}
      </div>
    </header>
  )
}
