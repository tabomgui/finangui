import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useUpdateStatement } from '@/api/queries/cards'
import type { CardStatement } from '@/api/types'
import { Field } from '@/components/form/field'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { isDateOnly } from '@/lib/date'

const schema = z
  .object({
    closing_date: z.string().refine(isDateOnly, 'Informe a data.'),
    due_date: z.string().refine(isDateOnly, 'Informe a data.'),
    reported_total: z.number().nullable(),
  })
  .refine((values) => values.closing_date < values.due_date, {
    message: 'O vencimento precisa ser depois do fechamento.',
    path: ['due_date'],
  })

type StatementDatesFormValues = z.input<typeof schema>

function defaultsFor(statement: CardStatement): StatementDatesFormValues {
  return {
    closing_date: statement.closing_date,
    due_date: statement.due_date,
    reported_total: statement.reported_total,
  }
}

type StatementDatesDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  statement: CardStatement
}

export function StatementDatesDialog({ open, onOpenChange, statement }: StatementDatesDialogProps) {
  const update = useUpdateStatement()

  const form = useForm<StatementDatesFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(statement),
  })

  // Reage à troca de fatura (id), não à identidade do objeto: um refetch em segundo plano
  // pode trazer uma nova referência de `statement` com os mesmos valores e não deve resetar
  // o formulário enquanto o usuário edita.
  useEffect(() => {
    if (open) form.reset(defaultsFor(statement))
    // eslint-disable-next-line react-hooks/exhaustive-deps -- ver comentário acima: só reage a open/statement.id
  }, [open, statement.id, form])

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await update.mutateAsync({
        id: statement.id,
        body: {
          closing_date: values.closing_date,
          due_date: values.due_date,
          reported_total: values.reported_total,
        },
      })
      toast.success('Fatura atualizada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['closing_date', 'due_date', 'reported_total'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !update.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Editar datas da fatura</DialogTitle>
          <DialogDescription>
            Ajuste para as datas reais da fatura. Os lançamentos não mudam de fatura; mova-os pela edição do lançamento.
          </DialogDescription>
        </DialogHeader>
        <form id="statement-dates-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Fechamento" htmlFor="statement-closing-date" error={errors.closing_date?.message}>
            <Controller
              control={form.control}
              name="closing_date"
              render={({ field }) => <DateInput id="statement-closing-date" value={field.value} onChange={field.onChange} />}
            />
          </Field>
          <Field label="Vencimento" htmlFor="statement-due-date" error={errors.due_date?.message}>
            <Controller
              control={form.control}
              name="due_date"
              render={({ field }) => <DateInput id="statement-due-date" value={field.value} onChange={field.onChange} />}
            />
          </Field>
          <Field
            label="Total informado pelo banco"
            htmlFor="statement-reported-total"
            error={errors.reported_total?.message}
            hint="Opcional. Serve para comparar com o total calculado."
          >
            {(control) => (
              <Controller
                control={form.control}
                name="reported_total"
                render={({ field }) => (
                  <MoneyInput {...control} allowNegative value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                )}
              />
            )}
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={update.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="statement-dates-form" disabled={update.isPending}>
            {update.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
