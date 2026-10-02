import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateAccount, useUpdateAccount } from '@/api/queries/accounts'
import type { Account } from '@/api/types'
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

const schema = z.object({
  name: z.string().trim().min(1, 'Informe o nome da conta.').max(60, 'Use no máximo 60 caracteres.'),
  type: z.enum(['checking', 'savings', 'cash']),
  opening_balance: z.number().nullable().refine((value) => value !== null, 'Informe um valor válido.'),
  color: z.string().nullable(),
  icon: z.string().nullable(),
})

// A entrada ainda aceita `opening_balance: null` (enquanto o usuário digita); o `refine`
// garante um número na saída, usada pelo `handleSubmit` depois da validação.
type FormValues = z.input<typeof schema>

type AccountFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sem conta: criação. */
  account?: Account
}

function defaultsFor(account?: Account): FormValues {
  return {
    name: account?.name ?? '',
    type: account?.type ?? 'checking',
    opening_balance: account?.opening_balance ?? 0,
    color: account?.color ?? DEFAULT_COLOR,
    icon: account?.icon ?? 'landmark',
  }
}

export function AccountFormDialog({ open, onOpenChange, account }: AccountFormDialogProps) {
  const create = useCreateAccount()
  const update = useUpdateAccount()
  const pending = create.isPending || update.isPending

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(account),
  })

  // `values` do RHF não reabre o formulário quando o objeto computado é igual ao anterior
  // (ex.: criar, fechar, criar de novo): reseta explicitamente toda vez que o diálogo abre.
  useEffect(() => {
    if (open) form.reset(defaultsFor(account))
  }, [open, account, form])

  const onSubmit = form.handleSubmit(async (values) => {
    const body = { ...values, name: values.name.trim(), opening_balance: values.opening_balance ?? 0 }
    try {
      if (account) {
        await update.mutateAsync({ id: account.id, body })
      } else {
        await create.mutateAsync(body)
      }
      toast.success(account ? 'Conta atualizada.' : 'Conta criada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name', 'type', 'opening_balance', 'color', 'icon'])) notifyError(error)
    }
  })

  const { errors } = form.formState
  const color = useWatch({ control: form.control, name: 'color' })

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{account ? 'Editar conta' : 'Nova conta'}</DialogTitle>
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
                      {ACCOUNT_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {ACCOUNT_TYPE_LABELS[type]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </Field>
          <Field
            label="Saldo inicial"
            htmlFor="account-opening-balance"
            error={errors.opening_balance?.message}
            hint="Saldo da conta antes do primeiro lançamento registrado aqui."
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
