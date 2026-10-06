import { zodResolver } from '@hookform/resolvers/zod'
import { format } from 'date-fns'
import { CircleCheck, Eye, EyeOff, LoaderCircle, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ApiError } from '@/api/errors'
import { useBankCredentials, useDeleteBankCredentials, useSaveBankCredentials } from '@/api/queries/bank-credentials'
import { Field } from '@/components/form/field'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'

const schema = z.object({
  // O backend aceita qualquer string no formato 8-4-4-4-12 em hexadecimal (não exige os nibbles
  // de versão/variante do UUID "de verdade" — ver SaveBankCredentialsRequest); z.guid() valida
  // só o formato, sem essa exigência extra que z.uuid() teria.
  client_id: z.string().trim().min(1, 'Informe o Client ID.').pipe(z.guid('Informe um Client ID válido.')),
  client_secret: z.string().min(1, 'Informe o Client Secret.').max(200),
})

type FormValues = z.infer<typeof schema>

function formatVerifiedAt(value: string): string {
  return format(new Date(value), 'dd/MM/yyyy')
}

type BankCredentialsCardProps = {
  /** Realça o card por um instante — usado quando a tela abre com o hash #pluggy na URL. */
  highlighted?: boolean
}

/**
 * Card "Integração bancária (Pluggy)" em Configurações: mostra o estado atual (sem credenciais /
 * configurado com o client id mascarado) e o formulário para cadastrar ou trocar. Salvar já testa
 * as credenciais na Pluggy antes de gravar (ver `useSaveBankCredentials`); o secret nunca é
 * pré-preenchido, mesmo trocando credenciais já cadastradas.
 */
export function BankCredentialsCard({ highlighted = false }: BankCredentialsCardProps) {
  const { data: credentials, isPending, isError, refetch } = useBankCredentials()
  const save = useSaveBankCredentials()
  const remove = useDeleteBankCredentials()
  const [editing, setEditing] = useState(false)
  const [showSecret, setShowSecret] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [conflict, setConflict] = useState<string | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { client_id: '', client_secret: '' },
  })

  const configured = credentials?.configured ?? false
  const showForm = !isPending && !isError && (!configured || editing)

  /** Volta ao estado de repouso: formulário limpo, secret oculto, sem conflito pendente. */
  function closeForm() {
    form.reset({ client_id: '', client_secret: '' })
    setShowSecret(false)
    setConflict(null)
    setEditing(false)
    save.reset()
  }

  function startEditing() {
    form.reset({ client_id: '', client_secret: '' })
    setShowSecret(false)
    setConflict(null)
    setEditing(true)
  }

  const onSubmit = form.handleSubmit(async (values) => {
    setConflict(null)
    try {
      await save.mutateAsync(values)
      closeForm()
      toast.success('Credenciais salvas e verificadas.')
    } catch (error) {
      if (error instanceof ApiError && error.code === 'bank_credentials_in_use') {
        setConflict(error.message)
        return
      }
      if (error instanceof ApiError && error.code === 'provider_unavailable') {
        toast.error('A Pluggy não respondeu. Tente de novo em instantes.')
        return
      }
      if (!applyFieldErrors(error, form.setError, ['client_id', 'client_secret'])) {
        notifyError(error)
      }
    }
  })

  return (
    <Card id="pluggy" className={cn('rounded-2xl shadow-card scroll-mt-20 transition-shadow', highlighted && 'ring-2 ring-primary')}>
      <CardHeader>
        <CardTitle>Integração bancária (Pluggy)</CardTitle>
        <CardDescription>Conecte seus bancos usando as credenciais da sua própria aplicação na Pluggy.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {isError ? (
          <div className="flex items-center justify-between gap-3 rounded-xl bg-destructive/10 px-4 py-3 text-sm text-destructive">
            <span className="flex items-center gap-2">
              <TriangleAlert className="h-4 w-4 shrink-0" />
              Não foi possível carregar as credenciais.
            </span>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              Tentar de novo
            </Button>
          </div>
        ) : (
          <>
            {isPending && <Skeleton className="h-16 w-full rounded-xl" />}

            {!isPending && configured && !editing && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/50 px-4 py-3">
                <div className="min-w-0">
                  <p className="flex items-center gap-2 text-sm font-medium text-income">
                    <CircleCheck className="h-4 w-4 shrink-0" />
                    Pluggy conectada
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    Client ID ••••{credentials?.client_id_hint}
                    {credentials?.verified_at && ` · verificado em ${formatVerifiedAt(credentials.verified_at)}`}
                  </p>
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button variant="outline" size="sm" onClick={startEditing}>
                    Trocar credenciais
                  </Button>
                  <Button variant="outline" size="sm" className="text-destructive" onClick={() => setDeleting(true)}>
                    Remover
                  </Button>
                </div>
              </div>
            )}

            {showForm && (
              <>
                <p className="text-sm text-muted-foreground">
                  Crie uma aplicação no{' '}
                  <a
                    href="https://dashboard.pluggy.ai"
                    target="_blank"
                    rel="noreferrer"
                    className="font-medium text-primary underline-offset-4 hover:underline"
                  >
                    painel da Pluggy
                  </a>{' '}
                  e copie o Client ID e o Client Secret dela.
                </p>

                {conflict && (
                  <p role="alert" className="rounded-xl bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    {conflict}
                  </p>
                )}

                <form className="space-y-4" onSubmit={onSubmit} noValidate>
                  <Field label="Client ID" htmlFor="pluggy_client_id" error={form.formState.errors.client_id?.message}>
                    <Input
                      id="pluggy_client_id"
                      autoComplete="off"
                      placeholder="00000000-0000-0000-0000-000000000000"
                      {...form.register('client_id')}
                    />
                  </Field>
                  <Field
                    label="Client Secret"
                    htmlFor="pluggy_client_secret"
                    error={form.formState.errors.client_secret?.message}
                    hint={configured ? 'Preencha de novo: o secret cadastrado nunca é mostrado.' : undefined}
                  >
                    {(control) => (
                      <div className="relative">
                        <Input
                          {...control}
                          {...form.register('client_secret')}
                          type={showSecret ? 'text' : 'password'}
                          autoComplete="off"
                          data-1p-ignore
                          data-lpignore="true"
                          className="pr-10"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon-sm"
                          onClick={() => setShowSecret((current) => !current)}
                          className="absolute right-1 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                          aria-label={showSecret ? 'Ocultar Client Secret' : 'Mostrar Client Secret'}
                        >
                          {showSecret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    )}
                  </Field>
                  <div className="flex gap-2">
                    <Button type="submit" disabled={save.isPending}>
                      {save.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
                      Salvar e testar
                    </Button>
                    {configured && (
                      <Button type="button" variant="outline" onClick={closeForm} disabled={save.isPending}>
                        Cancelar
                      </Button>
                    )}
                  </div>
                </form>
              </>
            )}
          </>
        )}
      </CardContent>

      <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title="Remover credenciais da Pluggy?"
        description="Sem credenciais, não é possível conectar ou sincronizar bancos até cadastrar outras."
        confirmLabel="Remover"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync()
          toast.success('Credenciais removidas.')
        }}
      />
    </Card>
  )
}
