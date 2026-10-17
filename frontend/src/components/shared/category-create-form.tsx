import { LoaderCircle } from 'lucide-react'
import { useId, useState, type FormEvent } from 'react'
import { ApiError } from '@/api/errors'
import { useCategories, useCreateCategory } from '@/api/queries/categories'
import type { Category, CategoryKind } from '@/api/types'
import { Field } from '@/components/form/field'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { notifyError } from '@/lib/form-errors'
import { DEFAULT_COLOR } from '@/lib/palette'

const NO_PARENT = 'none'
/** Mesmo limite do formulário de categoria (`category-form-dialog.tsx`). */
const MAX_NAME = 60

type CategoryCreateFormProps = {
  kind: CategoryKind
  initialName: string
  onBack: () => void
  onCreated: (category: Category) => void
}

/**
 * Mini formulário "Nova categoria" (nome + "Dentro de") mostrado pelo `CategoryPicker` com
 * `allowCreate`, no lugar da lista. Cria pelo `POST /categories` com o ícone e a cor padrão do
 * formulário completo de categoria; ajustes finos ficam para a tela de Categorias.
 */
export function CategoryCreateForm({ kind, initialName, onBack, onCreated }: CategoryCreateFormProps) {
  const id = useId()
  const { data: categories = [] } = useCategories(true)
  const create = useCreateCategory()
  const [name, setName] = useState(initialName)
  const [parentId, setParentId] = useState<number | null>(null)
  const [errors, setErrors] = useState<{ name?: string; parent_id?: string }>({})

  const parents = categories
    .filter((category) => category.kind === kind && category.parent_id === null && !category.is_archived)
    .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
  const parent = parents.find((category) => category.id === parentId)
  const trimmed = name.trim()

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    event.stopPropagation()
    if (trimmed === '') return setErrors({ name: 'Informe o nome da categoria.' })
    if (trimmed.length > MAX_NAME) return setErrors({ name: `Use no máximo ${MAX_NAME} caracteres.` })

    try {
      const category = await create.mutateAsync({ name: trimmed, kind, parent_id: parentId, icon: 'tag', color: DEFAULT_COLOR })
      onCreated(category)
    } catch (error) {
      if (error instanceof ApiError && error.status === 422 && (error.fieldErrors.name || error.fieldErrors.parent_id)) {
        setErrors({ name: error.fieldErrors.name?.[0], parent_id: error.fieldErrors.parent_id?.[0] })
      } else {
        notifyError(error)
      }
    }
  }

  return (
    <form className="space-y-3 p-3" onSubmit={submit} noValidate>
      <p className="text-sm font-semibold">Nova categoria</p>
      <Field label="Nome" htmlFor={`${id}-name`} error={errors.name}>
        <Input id={`${id}-name`} autoComplete="off" autoFocus value={name} onChange={(event) => setName(event.target.value)} />
      </Field>
      <Field label="Dentro de" htmlFor={`${id}-parent`} error={errors.parent_id}>
        {(control) => (
          <Select
            value={parentId === null ? NO_PARENT : String(parentId)}
            onValueChange={(value) => setParentId(value === NO_PARENT ? null : Number(value))}
          >
            <SelectTrigger {...control} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_PARENT}>Categoria principal</SelectItem>
              {parents.map((category) => (
                <SelectItem key={category.id} value={String(category.id)}>
                  {category.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>
      <p className="text-xs text-muted-foreground">
        {parent ? (
          <>
            Vai ficar como{' '}
            <span className="font-medium text-foreground">
              {parent.name} › {trimmed || '…'}
            </span>
          </>
        ) : (
          `Vai ficar como categoria principal de ${kind === 'income' ? 'receita' : 'despesa'}.`
        )}
      </p>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onBack} disabled={create.isPending}>
          Voltar
        </Button>
        <Button type="submit" size="sm" disabled={create.isPending}>
          {create.isPending && <LoaderCircle aria-hidden className="h-4 w-4 animate-spin" />}
          Criar e usar
        </Button>
      </div>
    </form>
  )
}
