import { Check } from 'lucide-react'
import { COLOR_PALETTE, isLightColor } from '@/lib/palette'
import { cn } from '@/lib/utils'

type ColorPickerProps = {
  value: string | null
  onChange: (hex: string) => void
  id?: string
  /** Quando informado (ex.: por um `Field` envolvente), substitui o aria-label próprio para evitar anúncio duplicado. */
  'aria-labelledby'?: string
}

export function ColorPicker({ value, onChange, id, 'aria-labelledby': ariaLabelledBy }: ColorPickerProps) {
  return (
    <div
      id={id}
      role="group"
      aria-label={ariaLabelledBy ? undefined : 'Cor'}
      aria-labelledby={ariaLabelledBy}
      className="flex flex-wrap gap-2"
    >
      {COLOR_PALETTE.map(({ hex, name }) => {
        const selected = value?.toLowerCase() === hex
        return (
          <button
            key={hex}
            type="button"
            aria-label={name}
            aria-pressed={selected}
            onClick={() => onChange(hex)}
            className={cn(
              'flex h-8 w-8 items-center justify-center rounded-full outline-none ring-offset-2 ring-offset-background transition focus-visible:ring-[3px] focus-visible:ring-foreground/60',
              selected ? 'ring-2 ring-foreground' : 'hover:scale-110',
            )}
            style={{ backgroundColor: hex }}
          >
            {selected && <Check className={cn('h-4 w-4', isLightColor(hex) ? 'text-neutral-900' : 'text-white')} />}
          </button>
        )
      })}
    </div>
  )
}
