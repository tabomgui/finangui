import { Moon, Sun } from 'lucide-react'
import { useTheme } from 'next-themes'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export const headerIconButton =
  'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/20 text-white outline-none transition-colors hover:bg-white/30 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50'

export function ThemeToggle({ variant = 'ghost', className }: { variant?: 'ghost' | 'header'; className?: string }) {
  const { resolvedTheme, setTheme } = useTheme()
  const isDark = resolvedTheme === 'dark'
  const label = isDark ? 'Usar tema claro' : 'Usar tema escuro'
  const Icon = isDark ? Sun : Moon
  const toggle = () => setTheme(isDark ? 'light' : 'dark')

  if (variant === 'header') {
    return (
      <button type="button" aria-label={label} title={label} onClick={toggle} className={cn(headerIconButton, className)}>
        <Icon className="h-5 w-5" aria-hidden="true" />
      </button>
    )
  }

  return (
    <Button type="button" variant="ghost" size="icon" aria-label={label} title={label} onClick={toggle} className={className}>
      <Icon className="h-4 w-4" aria-hidden="true" />
    </Button>
  )
}
