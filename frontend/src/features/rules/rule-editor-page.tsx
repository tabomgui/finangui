import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle, Trash2, TriangleAlert } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useCreateRule, useDeleteRule, useRule, useUpdateRule } from '@/api/queries/rules'
import { useTransaction } from '@/api/queries/transactions'
import { PageBody } from '@/components/layout/page-body'
import { headerIconButton } from '@/components/layout/theme-toggle'
import { PageHeader } from '@/components/layout/page-header'
import { Field } from '@/components/form/field'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { ActionList } from './action-list'
import { ApplyRuleDialog } from './apply-rule-dialog'
import { ConditionList } from './condition-list'
import { ruleDefaults, ruleDefaultsFromTransaction, ruleSchema, toRuleBody, type RuleFormValues } from './rule-form-values'
import { RulePreviewCard } from './rule-preview-card'

export function RuleEditorPage() {
  const params = useParams()
  const ruleId = params.id ? Number(params.id) : null
  return ruleId === null ? <NewRulePage /> : <EditRulePage id={ruleId} />
}

type RuleFormBodyProps = {
  form: ReturnType<typeof useForm<RuleFormValues>>
  overwrite: boolean
  onOverwriteChange: (value: boolean) => void
}

function RuleFormBody({ form, overwrite, onOverwriteChange }: RuleFormBodyProps) {
  const { errors } = form.formState

  return (
    <Card className="rounded-2xl shadow-card">
      <CardContent className="space-y-5 pt-6">
        <Field label="Nome" htmlFor="rule-name" error={errors.name?.message}>
          <Input id="rule-name" autoComplete="off" {...form.register('name')} />
        </Field>

        <Controller
          control={form.control}
          name="is_active"
          render={({ field }) => (
            <div className="flex items-start gap-3 rounded-xl border border-border p-3">
              <Switch id="rule-is-active" checked={field.value} onCheckedChange={field.onChange} />
              <div className="space-y-1">
                <label htmlFor="rule-is-active" className="text-sm font-medium">
                  Ativa
                </label>
                <p className="text-xs text-muted-foreground">Regras inativas não entram na categorização de novos lançamentos.</p>
              </div>
            </div>
          )}
        />

        <div className="space-y-2">
          <p className="text-sm font-medium">Condições</p>
          <ConditionList form={form} />
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium">Ações</p>
          <ActionList form={form} />
        </div>

        <RulePreviewCard form={form} overwrite={overwrite} onOverwriteChange={onOverwriteChange} />
      </CardContent>
    </Card>
  )
}

function NewRulePage() {
  const [searchParams] = useSearchParams()
  const transactionId = searchParams.get('transacao') ? Number(searchParams.get('transacao')) : null
  const { data: transaction, isPending } = useTransaction(transactionId)
  const create = useCreateRule()
  const navigate = useNavigate()
  const [overwrite, setOverwrite] = useState(false)

  const form = useForm<RuleFormValues>({ resolver: zodResolver(ruleSchema), defaultValues: ruleDefaults() })

  // A transação (quando há "?transacao=") só chega depois da primeira renderização: assim que
  // carregar, preenche o formulário do zero com o prefill — sem isso o usuário veria o formulário
  // vazio por um instante e depois ele mudaria sozinho embaixo dos dedos.
  const prefilled = useRef(false)
  useEffect(() => {
    if (transaction && !prefilled.current) {
      prefilled.current = true
      form.reset(ruleDefaultsFromTransaction(transaction))
    }
  }, [transaction, form])

  if (transactionId !== null && isPending) return <FullPageSpinner />

  const submit = form.handleSubmit(async (values) => {
    try {
      const rule = await create.mutateAsync({ ...toRuleBody(values), name: values.name.trim(), is_active: values.is_active })
      toast.success('Regra salva.')
      navigate(`/regras/${rule.id}`, { replace: true })
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, '*')) notifyError(error)
    }
  })

  return (
    <>
      <PageHeader title="Nova regra" back="/regras" />
      <PageBody className="max-w-2xl space-y-4">
        <form className="space-y-4" onSubmit={submit} noValidate>
          <RuleFormBody form={form} overwrite={overwrite} onOverwriteChange={setOverwrite} />
          <Button type="submit" className="w-full rounded-2xl py-6 text-base" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar regra
          </Button>
        </form>
      </PageBody>
    </>
  )
}

function EditRulePage({ id }: { id: number }) {
  const { data: rule, error, isError, refetch } = useRule(id)
  const update = useUpdateRule()
  const remove = useDeleteRule()
  const navigate = useNavigate()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [overwrite, setOverwrite] = useState(false)

  const notFound = isError && error instanceof ApiError && error.status === 404

  // Toast uma vez (ref) + <Navigate replace />, mesmo padrão de `transaction-form-page.tsx` e
  // `card-detail-page.tsx`: evita duplicar sob StrictMode. Só para 404 real — outros erros (500,
  // rede) não significam que a regra não existe, não devem mandar o usuário de volta para a lista.
  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Regra não encontrada.')
    }
  }, [notFound])

  const form = useForm<RuleFormValues>({ resolver: zodResolver(ruleSchema), defaultValues: ruleDefaults(rule) })

  const loadedRef = useRef(false)
  useEffect(() => {
    if (rule && !loadedRef.current) {
      loadedRef.current = true
      form.reset(ruleDefaults(rule))
    }
  }, [rule, form])

  if (notFound) return <Navigate to="/regras" replace />

  if (isError && !rule) {
    return (
      <>
        <PageHeader title="Editar regra" back="/regras" />
        <PageBody className="max-w-2xl">
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar a regra."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        </PageBody>
      </>
    )
  }

  if (!rule) return <FullPageSpinner />

  const submit = form.handleSubmit(async (values) => {
    try {
      await update.mutateAsync({
        id,
        body: { ...toRuleBody(values), name: values.name.trim(), is_active: values.is_active },
      })
      toast.success('Regra salva.')
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, '*')) notifyError(error)
    }
  })

  return (
    <>
      <PageHeader
        title="Editar regra"
        back="/regras"
        actions={
          <button type="button" aria-label="Excluir" className={headerIconButton} onClick={() => setConfirmDelete(true)}>
            <Trash2 className="h-5 w-5" />
          </button>
        }
      />
      <PageBody className="max-w-2xl space-y-4">
        <form className="space-y-4" onSubmit={submit} noValidate>
          <RuleFormBody form={form} overwrite={overwrite} onOverwriteChange={setOverwrite} />
          <Button type="submit" className="w-full rounded-2xl py-6 text-base" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar regra
          </Button>
        </form>

        <ApplyRuleDialog rule={rule} overwrite={overwrite} onOverwriteChange={setOverwrite} disabled={form.formState.isDirty} />
      </PageBody>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={`Excluir ${rule.name}?`}
        description="Os lançamentos já categorizados por ela continuam como estão."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync(id)
          toast.success('Regra excluída.')
          navigate('/regras', { replace: true })
        }}
      />
    </>
  )
}
