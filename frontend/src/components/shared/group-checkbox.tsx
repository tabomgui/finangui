import { Check, Minus } from 'lucide-react'
import { cn } from '@/lib/utils'

/** Nenhum, parte ou todos os itens de um grupo marcados. */
export type GroupCheckState = 'none' | 'partial' | 'all'

type GroupCheckboxProps = { state: GroupCheckState; label: string; onClick: () => void; disabled?: boolean }

/**
 * Checkbox de três estados do cabeçalho de um grupo: o `Checkbox` do shadcn (ui/checkbox.tsx) não
 * distingue "parcial" visualmente (sempre mostra o mesmo ícone de check), então o cabeçalho usa
 * um botão próprio com `role="checkbox"`/`aria-checked="mixed"`, o padrão de acessibilidade para
 * esse terceiro estado.
 */
export function GroupCheckbox({ state, label, onClick, disabled = false }: GroupCheckboxProps) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={state === 'all' ? true : state === 'partial' ? 'mixed' : false}
      aria-label={label}
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'flex h-5 w-5 shrink-0 items-center justify-center rounded-[4px] border outline-none transition-shadow focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
        state === 'none' ? 'border-input' : 'border-primary bg-primary text-primary-foreground',
      )}
    >
      {state === 'all' && <Check aria-hidden className="h-3.5 w-3.5" />}
      {state === 'partial' && <Minus aria-hidden className="h-3.5 w-3.5" />}
    </button>
  )
}
