import { Check, ChevronsUpDown } from 'lucide-react'
import { useState } from 'react'
import { useCategories } from '@/api/queries/categories'
import type { CategoryKind } from '@/api/types'
import type { FieldControlProps } from '@/components/form/field'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { flattenCategoryOptions } from '@/features/categories/category-tree'
import { cn } from '@/lib/utils'
import { CategoryIcon } from './category-icon'

type CategoryPickerProps = Partial<FieldControlProps> & {
  value: number | null
  onChange: (categoryId: number | null) => void
  /** Filtra por tipo; sem tipo mostra despesas e receitas. */
  kind?: CategoryKind
  placeholder?: string
  /** Permite escolher "Sem categoria". */
  allowNone?: boolean
}

export function CategoryPicker({
  value,
  onChange,
  kind,
  placeholder = 'Escolha a categoria',
  allowNone = true,
  ...control
}: CategoryPickerProps) {
  const [open, setOpen] = useState(false)
  const { data: categories = [] } = useCategories(true)
  const options = flattenCategoryOptions(categories, { kind, keepId: value })
  const selected = categories.find((category) => category.id === value)

  const choose = (id: number | null) => {
    onChange(id)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button {...control} type="button" variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
          {selected ? (
            <span className="flex items-center gap-2 truncate">
              <CategoryIcon icon={selected.icon} color={selected.color} size="sm" className="h-6 w-6" />
              {selected.name}
            </span>
          ) : (
            <span className="text-muted-foreground">{placeholder}</span>
          )}
          <ChevronsUpDown className="h-4 w-4 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command>
          <CommandInput placeholder="Buscar categoria" />
          <CommandList>
            <CommandEmpty>Nenhuma categoria encontrada.</CommandEmpty>
            <CommandGroup>
              {allowNone && (
                <CommandItem value="Sem categoria" onSelect={() => choose(null)}>
                  <Check className={cn('h-4 w-4', value === null ? 'opacity-100' : 'opacity-0')} />
                  Sem categoria
                </CommandItem>
              )}
              {options.map(({ category, depth }) => (
                <CommandItem
                  key={category.id}
                  value={`${category.name} ${category.id}`}
                  onSelect={() => choose(category.id)}
                  className={cn(depth === 1 && 'pl-8')}
                >
                  <Check className={cn('h-4 w-4', value === category.id ? 'opacity-100' : 'opacity-0')} />
                  <CategoryIcon icon={category.icon} color={category.color} size="sm" className="h-6 w-6" />
                  {category.name}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
