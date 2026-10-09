import type { ReactNode } from 'react'
import { Logo } from '@/components/layout/logo'
import { ThemeToggle } from '@/components/layout/theme-toggle'
import { WallpaperCarousel } from './wallpaper-carousel'

export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="relative isolate grid min-h-dvh overflow-hidden lg:grid-cols-2">
      <WallpaperCarousel />
      <section className="hidden flex-col justify-between p-10 text-white lg:flex">
        <Logo inverted />
        <div className="space-y-3">
          <h1 className="text-4xl font-bold tracking-tight">Suas finanças, organizadas.</h1>
          <p className="max-w-md text-white/80">Contas, transações e categorias num só lugar, no seu próprio servidor.</p>
        </div>
        <p className="text-sm text-white/60">Self-hosted e open source.</p>
      </section>
      <section className="relative flex items-center justify-center px-4 pt-10 pb-16">
        {/* O anel de foco do `headerIconButton` tem offset esmeralda; aqui o fundo é foto, então sem offset colorido. */}
        <ThemeToggle
          variant="header"
          className="absolute top-4 right-4 focus-visible:ring-offset-black/50 dark:focus-visible:ring-offset-black/50"
        />
        <div className="w-full max-w-sm space-y-6">
          <Logo inverted className="justify-center lg:hidden" />
          {children}
        </div>
      </section>
    </div>
  )
}
