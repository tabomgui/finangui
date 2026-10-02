import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { CATEGORY_ICONS, categoryIconNames } from '@/lib/category-icons'
import { cn } from '@/lib/utils'
import { CategoryIcon } from './category-icon'

type IconPickerProps = {
  id?: string
  value: string | null
  color: string | null
  onChange: (icon: string) => void
}

export function IconPicker({ id, value, color, onChange }: IconPickerProps) {
  const [open, setOpen] = useState(false)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button id={id} type="button" variant="outline" aria-label="Escolher ícone" className="h-auto gap-3 py-2">
          <CategoryIcon icon={value} color={color} size="sm" />
          Ícone
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72">
        <div className="grid max-h-64 grid-cols-6 gap-1 overflow-y-auto">
          {categoryIconNames.map((name) => {
            const Icon = CATEGORY_ICONS[name]
            return (
              <button
                key={name}
                type="button"
                aria-label={`Ícone ${name}`}
                aria-pressed={value === name}
                onClick={() => {
                  onChange(name)
                  setOpen(false)
                }}
                className={cn(
                  'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                  value === name ? 'bg-primary/15 text-primary' : 'hover:bg-muted',
                )}
              >
                <Icon className="h-5 w-5" />
              </button>
            )
          })}
        </div>
      </PopoverContent>
    </Popover>
  )
}
