import { Monitor, Moon, Sun } from 'lucide-react'
import { useTheme } from 'next-themes'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

const OPTIONS = [
  { value: 'system', label: 'Sistema', icon: Monitor },
  { value: 'light', label: 'Claro', icon: Sun },
  { value: 'dark', label: 'Escuro', icon: Moon },
] as const

export function AppearanceCard() {
  const { theme, setTheme } = useTheme()

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle>Aparência</CardTitle>
        <CardDescription>Tema da interface neste dispositivo.</CardDescription>
      </CardHeader>
      <CardContent>
        <div role="radiogroup" aria-label="Tema" className="grid grid-cols-3 gap-2">
          {OPTIONS.map(({ value, label, icon: Icon }) => (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={theme === value}
              onClick={() => setTheme(value)}
              className={cn(
                'flex flex-col items-center gap-2 rounded-xl border-2 p-3 text-sm font-medium transition-colors',
                theme === value ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:border-primary/50',
              )}
            >
              <Icon className="h-5 w-5" />
              {label}
            </button>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}
