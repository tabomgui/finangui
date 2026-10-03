import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useAccounts } from '@/api/queries/accounts'
import { usePayStatement } from '@/api/queries/cards'
import type { CardStatement } from '@/api/types'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { isDateOnly, today } from '@/lib/date'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { formatMoney } from '@/lib/money'

const schema = z.object({
  from_account_id: z.number().nullable().refine((value) => value !== null, 'Escolha a conta de origem.'),
  amount: z.number().nullable().refine((value) => value !== null && value > 0, 'Informe um valor maior que zero.'),
  date: z.string().refine(isDateOnly, 'Informe a data.'),
})

type PayStatementFormValues = z.input<typeof schema>

type PayStatementDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  statement: CardStatement
  currency: string
}

export function PayStatementDialog({ open, onOpenChange, statement, currency }: PayStatementDialogProps) {
  const pay = usePayStatement()
  // Mesma chamada (`useAccounts(true)`) que o `AccountSelect` usa por baixo: reaproveita o cache
  // do React Query em vez de disparar um segundo fetch com uma chave diferente.
  const { data: accounts = [] } = useAccounts(true)
  const payableAccounts = accounts.filter((account) => !account.is_archived && account.type !== 'credit_card')
  const defaultAccountId = payableAccounts[0]?.id ?? null
  const hasPayableAccount = defaultAccountId !== null

  function defaultsFor(): PayStatementFormValues {
    return {
      from_account_id: defaultAccountId,
      amount: statement.remaining > 0 ? statement.remaining : null,
      date: today(),
    }
  }

  const form = useForm<PayStatementFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(),
  })

  // Reage ao id da fatura, não à identidade do objeto: um refetch em segundo plano pode trazer
  // uma nova referência de `statement` com os mesmos valores sem resetar o formulário à toa.
  useEffect(() => {
    if (open) form.reset(defaultsFor())
    // eslint-disable-next-line react-hooks/exhaustive-deps -- ver comentário acima: só reage a open/statement.id
  }, [open, statement.id])

  // As contas podem não ter chegado ainda quando o diálogo abre (primeira abertura, sem cache):
  // se o campo ainda está vazio e uma conta padrão passa a existir, preenche sem sobrescrever
  // uma escolha que o usuário já tenha feito.
  const fromAccountId = useWatch({ control: form.control, name: 'from_account_id' })
  useEffect(() => {
    if (open && fromAccountId === null && defaultAccountId !== null) {
      form.setValue('from_account_id', defaultAccountId)
    }
  }, [open, fromAccountId, defaultAccountId, form])

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await pay.mutateAsync({
        id: statement.id,
        body: { from_account_id: values.from_account_id as number, amount: values.amount as number, date: values.date },
      })
      toast.success('Pagamento registrado.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['from_account_id', 'amount', 'date'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !pay.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Pagar fatura</DialogTitle>
          <DialogDescription>O pagamento entra como transferência da conta escolhida para o cartão.</DialogDescription>
        </DialogHeader>
        <form id="pay-statement-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field
            label="Pagar com"
            htmlFor="pay-statement-account"
            error={errors.from_account_id?.message}
            hint={hasPayableAccount ? undefined : 'Cadastre uma conta (corrente, poupança ou dinheiro) para pagar a fatura.'}
          >
            {(control) => (
              <Controller
                control={form.control}
                name="from_account_id"
                render={({ field }) => (
                  <AccountSelect
                    {...control}
                    excludeTypes={['credit_card']}
                    value={field.value}
                    onChange={field.onChange}
                  />
                )}
              />
            )}
          </Field>
          <Field
            label="Valor"
            htmlFor="pay-statement-amount"
            error={errors.amount?.message}
            hint={`Restante da fatura: ${formatMoney(statement.remaining, currency)}`}
          >
            {(control) => (
              <Controller
                control={form.control}
                name="amount"
                render={({ field }) => <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />}
              />
            )}
          </Field>
          <Field label="Data" htmlFor="pay-statement-date" error={errors.date?.message}>
            <Controller
              control={form.control}
              name="date"
              render={({ field }) => <DateInput id="pay-statement-date" value={field.value} onChange={field.onChange} />}
            />
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pay.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="pay-statement-form" disabled={pay.isPending || !hasPayableAccount}>
            {pay.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Pagar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
