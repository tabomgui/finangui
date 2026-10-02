import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateTag, useUpdateTag } from '@/api/queries/tags'
import type { Tag } from '@/api/types'
import { Field } from '@/components/form/field'
import { ColorPicker } from '@/components/shared/color-picker'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'

const schema = z.object({
  name: z.string().trim().min(1, 'Informe o nome da tag.').max(40, 'Use no máximo 40 caracteres.'),
  color: z.string().nullable(),
})

type FormValues = z.infer<typeof schema>

type TagFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sem tag: criação. */
  tag?: Tag
}

function defaultsFor(tag?: Tag): FormValues {
  return {
    name: tag?.name ?? '',
    color: tag?.color ?? null,
  }
}

export function TagFormDialog({ open, onOpenChange, tag }: TagFormDialogProps) {
  const create = useCreateTag()
  const update = useUpdateTag()
  const pending = create.isPending || update.isPending

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(tag),
  })

  // `values` do RHF não reabre o formulário quando o objeto computado é igual ao anterior
  // (ex.: criar, fechar, criar de novo): reseta explicitamente toda vez que o diálogo abre.
  useEffect(() => {
    if (open) form.reset(defaultsFor(tag))
  }, [open, tag, form])

  const onSubmit = form.handleSubmit(async (values) => {
    const body = { name: values.name.trim(), color: values.color }
    try {
      if (tag) {
        await update.mutateAsync({ id: tag.id, body })
      } else {
        await create.mutateAsync(body)
      }
      toast.success(tag ? 'Tag atualizada.' : 'Tag criada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name', 'color'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{tag ? 'Editar tag' : 'Nova tag'}</DialogTitle>
        </DialogHeader>
        <form id="tag-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Nome" htmlFor="tag-name" error={errors.name?.message} hint="Ex.: viagem-2026">
            <Input id="tag-name" autoComplete="off" {...form.register('name')} />
          </Field>
          <Field label="Cor" htmlFor="tag-color">
            {(control) => (
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => <ColorPicker id={control.id} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="tag-form" disabled={pending}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {tag ? 'Salvar' : 'Criar tag'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
