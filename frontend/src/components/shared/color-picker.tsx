import { Check } from 'lucide-react'
import { COLOR_PALETTE } from '@/lib/palette'
import { cn } from '@/lib/utils'

type ColorPickerProps = {
  value: string | null
  onChange: (hex: string) => void
  id?: string
}

export function ColorPicker({ value, onChange, id }: ColorPickerProps) {
  return (
    <div id={id} role="group" aria-label="Cor" className="flex flex-wrap gap-2">
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
              'flex h-8 w-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition',
              selected ? 'ring-2 ring-foreground' : 'hover:scale-110',
            )}
            style={{ backgroundColor: hex }}
          >
            {selected && <Check className="h-4 w-4 text-white" />}
          </button>
        )
      })}
    </div>
  )
}
