import type { ReactNode } from 'react'
import { Logo } from '@/components/layout/logo'
import { ThemeToggle } from '@/components/layout/theme-toggle'

export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      <section className="hidden flex-col justify-between bg-linear-to-br from-emerald-500 to-emerald-700 p-10 text-white lg:flex dark:from-emerald-600 dark:to-emerald-800">
        <Logo inverted />
        <div className="space-y-3">
          <h1 className="text-4xl font-bold tracking-tight">Suas finanças, organizadas.</h1>
          <p className="max-w-md text-white/80">Contas, transações e categorias num só lugar, no seu próprio servidor.</p>
        </div>
        <p className="text-sm text-white/60">Self-hosted e open source.</p>
      </section>
      <section className="relative flex items-center justify-center px-4 py-10">
        <ThemeToggle className="absolute right-4 top-4" />
        <div className="w-full max-w-sm space-y-6">
          <Logo className="justify-center lg:hidden" />
          {children}
        </div>
      </section>
    </div>
  )
}
