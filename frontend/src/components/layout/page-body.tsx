import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/** Conteúdo da página, sobreposto ao cabeçalho em gradiente. */
export function PageBody({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('relative z-10 mx-auto -mt-6 w-full max-w-5xl space-y-4 px-4 pb-6 sm:px-6', className)}>
      {children}
    </div>
  )
}
