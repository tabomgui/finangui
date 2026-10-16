import { Check, ChevronsUpDown, Plus } from 'lucide-react'
import { useState } from 'react'
import { useCategories } from '@/api/queries/categories'
import type { Category, CategoryKind } from '@/api/types'
import type { FieldControlProps } from '@/components/form/field'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { flattenCategoryOptions } from '@/features/categories/category-tree'
import { accentInsensitiveFilter, normalizeSearch } from '@/lib/search'
import { cn } from '@/lib/utils'
import { CategoryCreateForm } from './category-create-form'
import { CategoryIcon } from './category-icon'

type CategoryPickerProps = Partial<FieldControlProps> & {
  value: number | null
  onChange: (categoryId: number | null) => void
  /** Filtra por tipo; sem tipo mostra despesas e receitas. */
  kind?: CategoryKind
  placeholder?: string
  /** Permite escolher "Sem categoria". */
  allowNone?: boolean
  /**
   * Distingue "Sem categoria" escolhida de propósito de "nada escolhido ainda" — os dois chegam
   * com `value: null`, mas só o primeiro deve aparecer como selecionado no gatilho/marcado na
   * lista. Sem informar, cai no comportamento de sempre (`value === null` já é "Sem categoria"),
   * correto para um campo de formulário; um filtro com um terceiro estado "nenhum filtro" (ex.:
   * `transaction-filters.tsx`) precisa informar este prop à parte.
   */
  noneSelected?: boolean
  /** Lista também categorias arquivadas (ex.: filtros, que precisam achar lançamentos antigos). */
  includeArchived?: boolean
  disabled?: boolean
  'aria-label'?: string
  /**
   * Oferece "Criar “<busca>”…" quando a busca não bate exatamente com o nome de nenhuma
   * categoria listada: abre, no próprio popover, um mini formulário (nome + "Dentro de") que
   * cria a categoria e já a seleciona. Precisa de `kind` (a categoria nova é desse tipo).
   */
  allowCreate?: boolean
  /** Chamado com a categoria recém-criada pelo "Criar…", logo depois do `onChange` com o id dela. */
  onCreate?: (category: Category) => void
}

export function CategoryPicker({
  value,
  onChange,
  kind,
  placeholder = 'Escolha a categoria',
  allowNone = true,
  noneSelected,
  includeArchived = false,
  disabled = false,
  'aria-label': ariaLabel,
  allowCreate = false,
  onCreate,
  ...control
}: CategoryPickerProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  // Nome de uma categoria sendo criada pelo "Criar…": troca a lista pelo mini formulário.
  const [creating, setCreating] = useState<string | null>(null)
  // A recém-criada, até a lista de categorias recarregar com ela: o gatilho já mostra o nome.
  const [created, setCreated] = useState<Category | null>(null)
  const { data: categories = [] } = useCategories(true)
  const options = flattenCategoryOptions(categories, { kind, keepId: value, includeArchived })
  const selected = categories.find((category) => category.id === value) ?? (created?.id === value ? created : undefined)
  const query = search.trim()
  const canCreate =
    allowCreate &&
    kind !== undefined &&
    query !== '' &&
    !options.some(({ category }) => normalizeSearch(category.name) === normalizeSearch(query))
  // Marca "Sem categoria" na lista sempre que `value` for null (comportamento de sempre, um campo
  // de formulário já trata assim); só o GATILHO precisa do terceiro estado, por isso só mostra
  // "Sem categoria" ali quando o chamador pede de propósito (`noneSelected === true`), nunca por
  // inferência — senão todo formulário com `allowNone` passaria a mostrar "Sem categoria" em vez
  // do placeholder sempre que o campo começasse vazio.
  const isNoneChecked = noneSelected ?? value === null
  const showNoneInTrigger = noneSelected === true

  const changeOpen = (next: boolean) => {
    setOpen(next)
    if (!next) {
      setSearch('')
      setCreating(null)
    }
  }

  const choose = (id: number | null) => {
    onChange(id)
    changeOpen(false)
  }

  const handleCreated = (category: Category) => {
    setCreated(category)
    choose(category.id)
    onCreate?.(category)
  }

  return (
    // `modal`: o Popover fica dentro de um Sheet (ex.: filtros de transações); sem isso, o scroll-lock
    // do Sheet intercepta a roda do mouse sobre a lista flutuante e o scroll não funciona.
    <Popover modal open={open} onOpenChange={changeOpen}>
      <PopoverTrigger asChild>
        <Button
          {...control}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-label={ariaLabel}
          disabled={disabled}
          className="w-full justify-between font-normal"
        >
          {selected ? (
            <span className="flex items-center gap-2 truncate">
              <CategoryIcon icon={selected.icon} color={selected.color} size="sm" className="h-6 w-6" />
              {selected.name}
            </span>
          ) : showNoneInTrigger ? (
            <span className="flex items-center gap-2 truncate">
              <CategoryIcon icon={null} color={null} size="sm" className="h-6 w-6" />
              Sem categoria
            </span>
          ) : (
            <span className="text-muted-foreground">{placeholder}</span>
          )}
          <ChevronsUpDown className="h-4 w-4 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className={cn('w-(--radix-popover-trigger-width) p-0', allowCreate && 'min-w-72')} align="start">
        {creating !== null && kind !== undefined ? (
          <CategoryCreateForm kind={kind} initialName={creating} onBack={() => setCreating(null)} onCreated={handleCreated} />
        ) : (
          <Command filter={accentInsensitiveFilter}>
            <CommandInput placeholder={allowCreate ? 'Buscar ou criar categoria' : 'Buscar categoria'} value={search} onValueChange={setSearch} />
            <CommandList>
              <CommandEmpty>Nenhuma categoria encontrada.</CommandEmpty>
              <CommandGroup>
                {allowNone && (
                  <CommandItem value="none" keywords={['Sem categoria']} onSelect={() => choose(null)}>
                    <Check className={cn('h-4 w-4', isNoneChecked ? 'opacity-100' : 'opacity-0')} />
                    Sem categoria
                  </CommandItem>
                )}
                {options.map(({ category, depth }) => (
                  <CommandItem
                    key={category.id}
                    value={String(category.id)}
                    keywords={[category.name]}
                    onSelect={() => choose(category.id)}
                    className={cn(depth === 1 && 'pl-8')}
                  >
                    <Check className={cn('h-4 w-4', value === category.id ? 'opacity-100' : 'opacity-0')} />
                    <CategoryIcon icon={category.icon} color={category.color} size="sm" className="h-6 w-6" />
                    {category.name}
                  </CommandItem>
                ))}
              </CommandGroup>
              {canCreate && (
                <>
                  <CommandSeparator alwaysRender />
                  <CommandGroup forceMount>
                    <CommandItem forceMount value="__create__" onSelect={() => setCreating(query)} className="font-medium text-primary">
                      <Plus aria-hidden className="h-4 w-4 text-primary" />
                      Criar “{query}”…
                    </CommandItem>
                  </CommandGroup>
                </>
              )}
            </CommandList>
          </Command>
        )}
      </PopoverContent>
    </Popover>
  )
}
