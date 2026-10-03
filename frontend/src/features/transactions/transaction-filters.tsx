import { Search, SlidersHorizontal, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { TransactionFilters as Filters } from '@/api/query-keys'
import { useTags } from '@/api/queries/tags'
import { AccountSelect } from '@/components/shared/account-select'
import { CategoryPicker } from '@/components/shared/category-picker'
import { DateInput } from '@/components/shared/date-input'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { activeFilterCount, paramsWithFilter } from './filters'

const ALL = 'all'

export function TransactionFilters({ filters }: { filters: Filters }) {
  const [, setParams] = useSearchParams()
  const urlSearch = filters.search ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [seenUrlSearch, setSeenUrlSearch] = useState(urlSearch)
  const debouncedSearch = useDebouncedValue(search, 350)
  const { data: tags = [] } = useTags()

  // A busca também pode mudar por fora (link compartilhado, voltar no histórico, "Limpar filtros").
  // Comparar com o último valor de URL visto, em vez de usar um efeito, evita um laço com o debounce.
  if (urlSearch !== seenUrlSearch) {
    setSeenUrlSearch(urlSearch)
    if (urlSearch !== debouncedSearch.trim()) setSearch(urlSearch)
  }

  const setFilter = <K extends keyof Omit<Filters, 'statement_id'>>(key: K, value: Filters[K] | undefined) =>
    setParams((current) => paramsWithFilter(current, key, value), { replace: true })

  useEffect(() => {
    if ((filters.search ?? '') !== debouncedSearch.trim()) setFilter('search', debouncedSearch.trim() || undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- só reage à busca digitada
  }, [debouncedSearch])

  const count = activeFilterCount(filters)

  return (
    <div className="flex gap-2">
      <div className="relative flex-1">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          aria-label="Buscar transações"
          placeholder="Buscar pela descrição"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          maxLength={100}
          className="bg-card pl-9 shadow-card dark:bg-card"
        />
      </div>
      <Sheet>
        <SheetTrigger asChild>
          <Button variant="outline" className="gap-2">
            <SlidersHorizontal className="h-4 w-4" />
            Filtros
            {count > 0 && <Badge>{count}</Badge>}
          </Button>
        </SheetTrigger>
        <SheetContent side="right" className="w-full max-w-sm overflow-y-auto">
          <SheetHeader>
            <SheetTitle>Filtros</SheetTitle>
          </SheetHeader>
          <div className="space-y-4 px-4 pb-6">
            <div className="space-y-2">
              <Label htmlFor="filter-account">Conta</Label>
              <div className="flex gap-2">
                <AccountSelect
                  id="filter-account"
                  value={filters.account_id ?? null}
                  onChange={(id) => setFilter('account_id', id)}
                  placeholder="Todas"
                  includeArchived
                />
                {filters.account_id && (
                  <Button variant="ghost" size="icon" aria-label="Limpar conta" onClick={() => setFilter('account_id', undefined)}>
                    <X className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="filter-category">Categoria</Label>
              <CategoryPicker
                id="filter-category"
                value={filters.category_id ?? null}
                onChange={(id) => setFilter('category_id', id ?? undefined)}
                placeholder="Todas"
                allowNone={false}
                includeArchived
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="filter-tag">Tag</Label>
              <Select value={filters.tag_id ? String(filters.tag_id) : ALL} onValueChange={(value) => setFilter('tag_id', value === ALL ? undefined : Number(value))}>
                <SelectTrigger id="filter-tag" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>Todas</SelectItem>
                  {tags.map((tag) => (
                    <SelectItem key={tag.id} value={String(tag.id)}>
                      #{tag.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="filter-direction">Tipo</Label>
              <Select value={filters.direction ?? ALL} onValueChange={(value) => setFilter('direction', value === ALL ? undefined : (value as 'in' | 'out'))}>
                <SelectTrigger id="filter-direction" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>Entradas e saídas</SelectItem>
                  <SelectItem value="out">Saídas</SelectItem>
                  <SelectItem value="in">Entradas</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div className="space-y-2">
                <Label htmlFor="filter-from">De</Label>
                <DateInput id="filter-from" value={filters.from ?? ''} max={filters.to} onChange={(value) => setFilter('from', value || undefined)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="filter-to">Até</Label>
                <DateInput id="filter-to" value={filters.to ?? ''} min={filters.from} onChange={(value) => setFilter('to', value || undefined)} />
              </div>
            </div>
            <Button
              variant="outline"
              className="w-full"
              onClick={() => {
                setSearch('')
                setParams(new URLSearchParams(), { replace: true })
              }}
            >
              Limpar filtros
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
