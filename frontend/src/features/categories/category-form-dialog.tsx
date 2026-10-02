import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCategories, useCreateCategory, useUpdateCategory } from '@/api/queries/categories'
import type { Category, CategoryKind } from '@/api/types'
import { Field } from '@/components/form/field'
import { ColorPicker } from '@/components/shared/color-picker'
import { IconPicker } from '@/components/shared/icon-picker'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { DEFAULT_COLOR } from '@/lib/palette'

const NO_PARENT = 'none'

const schema = z.object({
  name: z.string().trim().min(1, 'Informe o nome da categoria.').max(60, 'Use no máximo 60 caracteres.'),
  parent_id: z.number().nullable(),
  color: z.string().nullable(),
  icon: z.string().nullable(),
  is_transfer: z.boolean(),
})

type FormValues = z.infer<typeof schema>

type CategoryFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  kind: CategoryKind
  category?: Category
  /** Pai pré-selecionado ao criar uma subcategoria. */
  parentId?: number | null
}

function defaultsFor(category: Category | undefined, parentId: number | null): FormValues {
  return {
    name: category?.name ?? '',
    parent_id: category?.parent_id ?? parentId,
    color: category?.color ?? DEFAULT_COLOR,
    icon: category?.icon ?? 'tag',
    is_transfer: category?.is_transfer ?? false,
  }
}

export function CategoryFormDialog({ open, onOpenChange, kind, category, parentId = null }: CategoryFormDialogProps) {
  const { data: categories = [] } = useCategories(false)
  const create = useCreateCategory()
  const update = useUpdateCategory()
  const pending = create.isPending || update.isPending

  const hasChildren = category ? categories.some((each) => each.parent_id === category.id) : false
  const parentOptions = categories.filter(
    (each) => each.kind === kind && each.parent_id === null && each.id !== category?.id,
  )

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(category, parentId),
  })

  // `values` do RHF não reabre o formulário quando o objeto computado é igual ao anterior
  // (ex.: criar, fechar, criar de novo): reseta explicitamente toda vez que o diálogo abre.
  useEffect(() => {
    if (open) form.reset(defaultsFor(category, parentId))
  }, [open, category, parentId, form])

  const onSubmit = form.handleSubmit(async (values) => {
    const body = { ...values, name: values.name.trim() }
    try {
      if (category) {
        await update.mutateAsync({ id: category.id, body })
      } else {
        await create.mutateAsync({ ...body, kind })
      }
      toast.success(category ? 'Categoria atualizada.' : 'Categoria criada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name', 'parent_id', 'color', 'icon', 'is_transfer'])) notifyError(error)
    }
  })

  const { errors } = form.formState
  const color = useWatch({ control: form.control, name: 'color' })

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{category ? 'Editar categoria' : 'Nova categoria'}</DialogTitle>
          <DialogDescription>{kind === 'expense' ? 'Categoria de despesa.' : 'Categoria de receita.'}</DialogDescription>
        </DialogHeader>
        <form id="category-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Nome" htmlFor="category-name" error={errors.name?.message}>
            <Input id="category-name" autoComplete="off" {...form.register('name')} />
          </Field>
          <Field
            label="Categoria pai"
            htmlFor="category-parent"
            error={errors.parent_id?.message}
            hint={hasChildren ? 'Categorias com subcategorias não podem virar subcategoria.' : undefined}
          >
            {(control) => (
              <Controller
                control={form.control}
                name="parent_id"
                render={({ field }) => (
                  <Select
                    disabled={hasChildren}
                    value={field.value === null ? NO_PARENT : String(field.value)}
                    onValueChange={(value) => field.onChange(value === NO_PARENT ? null : Number(value))}
                  >
                    <SelectTrigger {...control} className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_PARENT}>Nenhuma (categoria principal)</SelectItem>
                      {parentOptions.map((parent) => (
                        <SelectItem key={parent.id} value={String(parent.id)}>
                          {parent.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </Field>
          <Field label="Cor" htmlFor="category-color">
            {(control) => (
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => <ColorPicker id={control.id} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
          <Field label="Ícone" htmlFor="category-icon">
            {(control) => (
              <Controller
                control={form.control}
                name="icon"
                render={({ field }) => (
                  <IconPicker id={control.id} value={field.value} color={color} onChange={field.onChange} />
                )}
              />
            )}
          </Field>
          <Controller
            control={form.control}
            name="is_transfer"
            render={({ field }) => (
              <div className="flex items-start gap-3 rounded-xl border border-border p-3">
                <Switch id="category-is-transfer" checked={field.value} onCheckedChange={field.onChange} />
                <div className="space-y-1">
                  <label htmlFor="category-is-transfer" className="text-sm font-medium">
                    É transferência
                  </label>
                  <p className="text-xs text-muted-foreground">
                    Fora de receitas e despesas nos relatórios (ex.: aportes, pagamento de fatura). Vale também para as
                    subcategorias.
                  </p>
                </div>
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="category-form" disabled={pending}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {category ? 'Salvar' : 'Criar categoria'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
