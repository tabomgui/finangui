import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useConfirmOccurrence } from '@/api/queries/recurrences'
import type { Transaction } from '@/api/types'
import { Field } from '@/components/form/field'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { isDateOnly, today } from '@/lib/date'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'

const schema = z.object({
  amount: z.number().nullable().refine((value) => value !== null && value > 0, 'Informe um valor maior que zero.'),
  date: z
    .string()
    .refine(isDateOnly, 'Informe uma data válida.')
    .refine((value) => value <= today(), 'A data não pode ser no futuro.'),
})

export type ConfirmOccurrenceValues = z.input<typeof schema>

type ConfirmOccurrenceDialogProps = {
  /** `null`: diálogo fechado. */
  transaction: Transaction | null
  onOpenChange: (open: boolean) => void
}

function defaultsFor(transaction: Transaction | null): ConfirmOccurrenceValues {
  return { amount: transaction?.amount ?? null, date: transaction?.date ?? today() }
}

/**
 * Mini formulário de "Aconteceu": confirma a ocorrência prevista com valor e data opcionalmente
 * ajustados (padrão os previstos). Reabre resetado a cada transação diferente (ver CLAUDE.md
 * sobre o padrão de formulário em diálogo).
 */
export function ConfirmOccurrenceDialog({ transaction, onOpenChange }: ConfirmOccurrenceDialogProps) {
  const open = transaction !== null
  const confirm = useConfirmOccurrence()

  const form = useForm<ConfirmOccurrenceValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(transaction),
  })

  useEffect(() => {
    if (open) form.reset(defaultsFor(transaction))
  }, [open, transaction, form])

  const onSubmit = form.handleSubmit(async (values) => {
    if (!transaction) return

    try {
      await confirm.mutateAsync({ id: transaction.id, body: { amount: values.amount as number, date: values.date } })
      toast.success('Lançamento confirmado.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['amount', 'date'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !confirm.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Confirmar {transaction?.description}</DialogTitle>
          <DialogDescription>Ajuste o valor e a data se o lançamento aconteceu diferente do previsto.</DialogDescription>
        </DialogHeader>
        <form id="confirm-occurrence-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Valor" htmlFor="confirm-occurrence-amount" error={errors.amount?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="amount"
                render={({ field }) => <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />}
              />
            )}
          </Field>
          <Field label="Data" htmlFor="confirm-occurrence-date" error={errors.date?.message}>
            <Controller
              control={form.control}
              name="date"
              render={({ field }) => (
                <DateInput id="confirm-occurrence-date" value={field.value} onChange={field.onChange} max={today()} />
              )}
            />
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={confirm.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="confirm-occurrence-form" disabled={confirm.isPending}>
            {confirm.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Confirmar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
