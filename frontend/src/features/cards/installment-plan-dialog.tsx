import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useUpdateInstallmentPlan } from '@/api/queries/cards'
import type { InstallmentPlan } from '@/api/types'
import { Field } from '@/components/form/field'
import { CategoryPicker } from '@/components/shared/category-picker'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'

const schema = z.object({
  description: z.string().trim().min(1, 'Informe a descrição.').max(255, 'Use no máximo 255 caracteres.'),
  category_id: z.number().nullable(),
})

type InstallmentPlanFormValues = z.input<typeof schema>

function defaultsFor(plan: InstallmentPlan): InstallmentPlanFormValues {
  return { description: plan.description, category_id: plan.category_id }
}

type InstallmentPlanDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  plan: InstallmentPlan
}

export function InstallmentPlanDialog({ open, onOpenChange, plan }: InstallmentPlanDialogProps) {
  const update = useUpdateInstallmentPlan()
  const categoryEditable = plan.projected_count > 0

  const form = useForm<InstallmentPlanFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(plan),
  })

  // Reage ao id do parcelamento, não à identidade do objeto: um refetch em segundo plano pode
  // trazer uma nova referência de `plan` com os mesmos valores sem resetar o formulário à toa.
  useEffect(() => {
    if (open) form.reset(defaultsFor(plan))
    // eslint-disable-next-line react-hooks/exhaustive-deps -- ver comentário acima: só reage a open/plan.id
  }, [open, plan.id])

  const onSubmit = form.handleSubmit(async (values) => {
    const categoryChanged = values.category_id !== plan.category_id
    const body =
      categoryEditable && categoryChanged
        ? { description: values.description.trim(), category_id: values.category_id }
        : { description: values.description.trim() }
    try {
      await update.mutateAsync({ id: plan.id, body })
      toast.success('Parcelamento atualizado.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['description', 'category_id'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !update.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Editar parcelamento</DialogTitle>
          <DialogDescription>Vale para as parcelas futuras. As já lançadas não mudam.</DialogDescription>
        </DialogHeader>
        <form id="installment-plan-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Descrição" htmlFor="installment-plan-description" error={errors.description?.message}>
            <Input id="installment-plan-description" autoComplete="off" {...form.register('description')} />
          </Field>
          <Field
            label="Categoria"
            htmlFor="installment-plan-category"
            error={errors.category_id?.message}
            hint={categoryEditable ? undefined : 'Sem parcelas futuras: a categoria não pode mais mudar.'}
          >
            {(control) => (
              <Controller
                control={form.control}
                name="category_id"
                render={({ field }) => (
                  <CategoryPicker
                    {...control}
                    kind="expense"
                    allowNone
                    disabled={!categoryEditable}
                    value={field.value}
                    onChange={field.onChange}
                  />
                )}
              />
            )}
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={update.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="installment-plan-form" disabled={update.isPending}>
            {update.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
