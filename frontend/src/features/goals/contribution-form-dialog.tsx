import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateGoalContribution } from '@/api/queries/goals'
import { Field } from '@/components/form/field'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { today } from '@/lib/date'

const schema = z
  .object({
    isWithdrawal: z.boolean(),
    amount: z.number().nullable(),
    date: z.string(),
    note: z.string().max(120, 'Use no máximo 120 caracteres.'),
  })
  .superRefine((values, ctx) => {
    if (values.amount === null || values.amount <= 0) {
      ctx.addIssue({ code: 'custom', message: 'Informe um valor maior que zero.', path: ['amount'] })
    }
    if (values.date === '') {
      ctx.addIssue({ code: 'custom', message: 'Informe a data.', path: ['date'] })
    }
  })

type ContributionFormValues = z.input<typeof schema>

function defaultValues(): ContributionFormValues {
  return { isWithdrawal: false, amount: null, date: today(), note: '' }
}

type ContributionFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  goalId: number
}

export function ContributionFormDialog({ open, onOpenChange, goalId }: ContributionFormDialogProps) {
  const create = useCreateGoalContribution(goalId)

  const form = useForm<ContributionFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultValues(),
  })

  // Reseta ao abrir: `values` do RHF não reabriria o form quando o objeto computado for igual ao
  // anterior (ex.: registrar, fechar, registrar de novo).
  useEffect(() => {
    if (open) form.reset(defaultValues())
  }, [open, form])

  const isWithdrawal = useWatch({ control: form.control, name: 'isWithdrawal' })

  const onSubmit = form.handleSubmit(async (values) => {
    const amount = values.amount as number
    const note = values.note.trim()
    try {
      await create.mutateAsync({
        amount: isWithdrawal ? -amount : amount,
        date: values.date,
        ...(note === '' ? {} : { note }),
      })
      toast.success(isWithdrawal ? 'Retirada registrada.' : 'Aporte registrado.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['amount', 'date', 'note'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !create.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Novo aporte ou retirada</DialogTitle>
          <DialogDescription>Registre quanto guardou ou retirou desta meta.</DialogDescription>
        </DialogHeader>
        <form id="goal-contribution-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <div className="flex items-start gap-3 rounded-xl border border-border p-3">
            <Controller
              control={form.control}
              name="isWithdrawal"
              render={({ field }) => (
                <Switch id="goal-contribution-withdrawal" checked={field.value} onCheckedChange={field.onChange} />
              )}
            />
            <label htmlFor="goal-contribution-withdrawal" className="text-sm font-medium">
              Retirada
            </label>
          </div>

          <Field label="Valor" htmlFor="goal-contribution-amount" error={errors.amount?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="amount"
                render={({ field }) => (
                  <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                )}
              />
            )}
          </Field>

          <Field label="Data" htmlFor="goal-contribution-date" error={errors.date?.message}>
            <Controller
              control={form.control}
              name="date"
              render={({ field }) => <DateInput id="goal-contribution-date" value={field.value} onChange={field.onChange} />}
            />
          </Field>

          <Field label="Nota (opcional)" htmlFor="goal-contribution-note" error={errors.note?.message}>
            <Input id="goal-contribution-note" autoComplete="off" {...form.register('note')} />
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={create.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="goal-contribution-form" disabled={create.isPending}>
            {create.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
