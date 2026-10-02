import { Check, Plus } from 'lucide-react'
import { useState } from 'react'
import { useCreateTag, useTags } from '@/api/queries/tags'
import type { FieldControlProps } from '@/components/form/field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { notifyError } from '@/lib/form-errors'
import { accentInsensitiveFilter } from '@/lib/search'
import { cn } from '@/lib/utils'

type TagPickerProps = Partial<FieldControlProps> & {
  value: number[]
  onChange: (tagIds: number[]) => void
}

export function TagPicker({ value, onChange, ...control }: TagPickerProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const { data: tags = [] } = useTags()
  const createTag = useCreateTag()

  const selected = tags.filter((tag) => value.includes(tag.id))
  const term = search.trim()
  const canCreate = term !== '' && !tags.some((tag) => tag.name.toLowerCase() === term.toLowerCase())

  const toggle = (id: number) => onChange(value.includes(id) ? value.filter((each) => each !== id) : [...value, id])

  const create = async () => {
    try {
      const tag = await createTag.mutateAsync({ name: term })
      onChange([...value, tag.id])
      setSearch('')
    } catch (error) {
      notifyError(error)
    }
  }

  const openChange = (next: boolean) => {
    setOpen(next)
    if (!next) setSearch('')
  }

  return (
    <Popover open={open} onOpenChange={openChange}>
      <PopoverTrigger asChild>
        <Button
          {...control}
          type="button"
          variant="outline"
          aria-haspopup="listbox"
          aria-expanded={open}
          className="h-auto min-h-9 w-full justify-start gap-1 py-1.5 font-normal"
        >
          {selected.length > 0 ? (
            selected.map((tag) => (
              <Badge key={tag.id} variant="secondary">
                #{tag.name}
              </Badge>
            ))
          ) : (
            <span className="text-muted-foreground">Sem tags</span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command filter={accentInsensitiveFilter}>
          <CommandInput placeholder="Buscar ou criar tag" value={search} onValueChange={setSearch} />
          <CommandList>
            <CommandEmpty>Nenhuma tag encontrada.</CommandEmpty>
            <CommandGroup>
              {tags.map((tag) => (
                <CommandItem key={tag.id} value={String(tag.id)} keywords={[tag.name]} onSelect={() => toggle(tag.id)}>
                  <Check className={cn('h-4 w-4', value.includes(tag.id) ? 'opacity-100' : 'opacity-0')} />#{tag.name}
                </CommandItem>
              ))}
              {canCreate && (
                <CommandItem value={`criar ${term}`} onSelect={create} disabled={createTag.isPending}>
                  <Plus className="h-4 w-4" />
                  Criar tag "{term}"
                </CommandItem>
              )}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
