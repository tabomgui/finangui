import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateAccount, useUpdateAccount } from '@/api/queries/accounts'
import type { Account, AccountType } from '@/api/types'
import { Field } from '@/components/form/field'
import { ColorPicker } from '@/components/shared/color-picker'
import { IconPicker } from '@/components/shared/icon-picker'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { DEFAULT_COLOR } from '@/lib/palette'
import { ACCOUNT_TYPE_LABELS, ACCOUNT_TYPES } from './account-labels'
import { CardFields } from './card-fields'

const DAY_PATTERN = /^\d{1,2}$/
const LAST_FOUR_PATTERN = /^\d{4}$/

const schema = z
  .object({
    name: z.string().trim().min(1, 'Informe o nome da conta.').max(60, 'Use no máximo 60 caracteres.'),
    type: z.enum(ACCOUNT_TYPES as [AccountType, ...AccountType[]]),
    opening_balance: z.number().nullable().refine((value) => value !== null, 'Informe um valor válido.'),
    color: z.string().nullable(),
    icon: z.string().nullable(),
    credit_limit: z.number().nullable(),
    closing_day: z.string(),
    due_day: z.string(),
    last_four: z.string(),
  })
  .superRefine((values, ctx) => {
    if (values.type !== 'credit_card') return

    if (values.credit_limit === null) {
      ctx.addIssue({ code: 'custom', message: 'Informe o limite.', path: ['credit_limit'] })
    }

    for (const field of ['closing_day', 'due_day'] as const) {
      const raw = values[field]
      const day = Number(raw)
      if (!DAY_PATTERN.test(raw) || day < 1 || day > 31) {
        ctx.addIssue({ code: 'custom', message: 'Informe um dia entre 1 e 31.', path: [field] })
      }
    }

    if (values.last_four !== '' && !LAST_FOUR_PATTERN.test(values.last_four)) {
      ctx.addIssue({ code: 'custom', message: 'Use os 4 últimos dígitos.', path: ['last_four'] })
    }
  })

// A entrada ainda aceita `opening_balance: null` (enquanto o usuário digita); o `refine`
// garante um número na saída, usada pelo `handleSubmit` depois da validação.
export type AccountFormValues = z.input<typeof schema>

type AccountFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sem conta: criação. */
  account?: Account
  /** Tipo inicial na criação (ex.: abrir o diálogo já como "Novo cartão"). */
  defaultType?: AccountType
}

function defaultsFor(account: Account | undefined, defaultType: AccountType | undefined): AccountFormValues {
  return {
    name: account?.name ?? '',
    type: account?.type ?? defaultType ?? 'checking',
    opening_balance: account?.opening_balance ?? 0,
    color: account?.color ?? DEFAULT_COLOR,
    icon: account?.icon ?? (defaultType === 'credit_card' ? 'credit-card' : 'landmark'),
    credit_limit: account?.credit_limit ?? null,
    closing_day: account?.closing_day ? String(account.closing_day) : '',
    due_day: account?.due_day ? String(account.due_day) : '',
    last_four: account?.last_four ?? '',
  }
}

export function AccountFormDialog({ open, onOpenChange, account, defaultType }: AccountFormDialogProps) {
  const create = useCreateAccount()
  const update = useUpdateAccount()
  const pending = create.isPending || update.isPending

  const form = useForm<AccountFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(account, defaultType),
  })

  // `values` do RHF não reabre o formulário quando o objeto computado é igual ao anterior
  // (ex.: criar, fechar, criar de novo): reseta explicitamente toda vez que o diálogo abre.
  useEffect(() => {
    if (open) form.reset(defaultsFor(account, defaultType))
  }, [open, account, defaultType, form])

  const onSubmit = form.handleSubmit(async (values) => {
    const base = {
      name: values.name.trim(),
      type: values.type,
      opening_balance: values.opening_balance ?? 0,
      color: values.color,
      icon: values.icon,
    }
    const body =
      values.type === 'credit_card'
        ? {
            ...base,
            credit_limit: values.credit_limit ?? 0,
            closing_day: Number(values.closing_day),
            due_day: Number(values.due_day),
            last_four: values.last_four.trim() === '' ? null : values.last_four.trim(),
          }
        : base
    try {
      if (account) {
        await update.mutateAsync({ id: account.id, body })
      } else {
        await create.mutateAsync(body)
      }
      toast.success(account ? 'Conta atualizada.' : 'Conta criada.')
      onOpenChange(false)
    } catch (error) {
      if (
        !applyFieldErrors(error, form.setError, [
          'name',
          'type',
          'opening_balance',
          'color',
          'icon',
          'credit_limit',
          'closing_day',
          'due_day',
          'last_four',
        ])
      )
        notifyError(error)
    }
  })

  const { errors } = form.formState
  const color = useWatch({ control: form.control, name: 'color' })
  const type = useWatch({ control: form.control, name: 'type' })

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{account ? 'Editar conta' : type === 'credit_card' ? 'Novo cartão' : 'Nova conta'}</DialogTitle>
          <DialogDescription>O saldo da conta é o saldo inicial mais os lançamentos.</DialogDescription>
        </DialogHeader>
        <form id="account-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Nome" htmlFor="account-name" error={errors.name?.message}>
            <Input id="account-name" autoComplete="off" {...form.register('name')} />
          </Field>
          <Field label="Tipo" htmlFor="account-type" error={errors.type?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="type"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger {...control} className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {ACCOUNT_TYPES.map((accountType) => (
                        <SelectItem key={accountType} value={accountType}>
                          {ACCOUNT_TYPE_LABELS[accountType]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </Field>
          {type === 'credit_card' && <CardFields form={form} />}
          <Field
            label="Saldo inicial"
            htmlFor="account-opening-balance"
            error={errors.opening_balance?.message}
            hint={
              type === 'credit_card'
                ? 'Use valor negativo para uma fatura em aberto antes do primeiro lançamento.'
                : 'Saldo da conta antes do primeiro lançamento registrado aqui.'
            }
          >
            {(control) => (
              <Controller
                control={form.control}
                name="opening_balance"
                render={({ field }) => (
                  <MoneyInput {...control} allowNegative value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                )}
              />
            )}
          </Field>
          <Field label="Cor" htmlFor="account-color">
            {(control) => (
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => <ColorPicker id={control.id} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
          <Field label="Ícone" htmlFor="account-icon">
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
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="account-form" disabled={pending}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {account ? 'Salvar' : 'Criar conta'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
