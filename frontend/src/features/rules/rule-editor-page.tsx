import { zodResolver } from '@hookform/resolvers/zod'
import { isCancelledError } from '@tanstack/react-query'
import { LoaderCircle, Trash2, TriangleAlert } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useCreateRule, useDeleteRule, useFetchRuleReconcile, useRule, useUpdateRule } from '@/api/queries/rules'
import { useTransaction } from '@/api/queries/transactions'
import type { Rule, RuleReconcile } from '@/api/types'
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
import { nonEmptyReconcileGroups } from './category-change-labels'
import { ConditionList } from './condition-list'
import { ReconcileRuleDialog } from './reconcile-rule-dialog'
import { isCoverablePath } from './rule-labels'
import { ruleDefaults, ruleDefaultsFromTransaction, ruleSchema, toRuleBody, type RuleFormValues } from './rule-form-values'
import { RulePreviewCard } from './rule-preview-card'

const POSITIVE_INTEGER = /^[1-9]\d*$/

/** O que define o efeito da regra nos lançamentos: condições, ações e "ativa" (não o nome). */
function definitionKey(values: RuleFormValues): string {
  return JSON.stringify({ ...toRuleBody(values), is_active: values.is_active })
}

function ruleCategoryId(rule: Rule): number | null {
  return rule.actions.find((action) => action.type === 'set_category')?.category_id ?? null
}

export function RuleEditorPage() {
  const params = useParams()
  const [searchParams] = useSearchParams()
  const ruleId = params.id ? Number(params.id) : null

  if (ruleId !== null) return <EditRulePage key={ruleId} id={ruleId} />
  // Troca de transação de origem (ex.: "Criar regra a partir desta" de outro lançamento) remonta
  // o formulário do zero — nunca reaproveita estado de uma transação para outra.
  return <NewRulePage key={searchParams.get('transacao') ?? 'blank'} />
}

type RuleFormBodyProps = {
  form: ReturnType<typeof useForm<RuleFormValues>>
}

function RuleFormBody({ form }: RuleFormBodyProps) {
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

        <RulePreviewCard form={form} />
      </CardContent>
    </Card>
  )
}

/**
 * Lê "?transacao=" (prefill de "Criar regra a partir desta"), valida e busca a transação antes
 * de montar o formulário de fato — `NewRuleForm` só monta quando já se sabe o `defaultValues`
 * certo, pra nunca mostrar a regra vazia por um instante e trocar sozinha embaixo dos dedos.
 */
function NewRulePage() {
  const [searchParams] = useSearchParams()
  const rawTransactionId = searchParams.get('transacao')
  const validParam = rawTransactionId === null || POSITIVE_INTEGER.test(rawTransactionId)
  const transactionId = rawTransactionId !== null && validParam ? Number(rawTransactionId) : null
  const { data: transaction, isPending, isError } = useTransaction(transactionId)

  const notFound = (rawTransactionId !== null && !validParam) || (transactionId !== null && isError)

  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Lançamento não encontrado.')
    }
  }, [notFound])

  if (transactionId !== null && isPending) return <FullPageSpinner />

  const initialValues = transaction ? ruleDefaultsFromTransaction(transaction) : ruleDefaults()
  return <NewRuleForm initialValues={initialValues} />
}

function NewRuleForm({ initialValues }: { initialValues: RuleFormValues }) {
  const create = useCreateRule()
  const navigate = useNavigate()

  const form = useForm<RuleFormValues>({ resolver: zodResolver(ruleSchema), defaultValues: initialValues })

  const submit = form.handleSubmit(async (values) => {
    try {
      const rule = await create.mutateAsync({ ...toRuleBody(values), name: values.name.trim(), is_active: values.is_active })
      toast.success('Regra salva.')
      navigate(`/regras/${rule.id}`, { replace: true })
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, '*', isCoverablePath)) notifyError(error)
    }
  })

  return (
    <>
      <PageHeader title="Nova regra" back="/regras" />
      <PageBody className="max-w-2xl space-y-4">
        <form className="space-y-4" onSubmit={submit} noValidate>
          <RuleFormBody form={form} />
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
  // `seq` remonta o diálogo a cada busca (a marcação inicial dos grupos sai da resposta nova).
  const [reconcile, setReconcile] = useState<{ seq: number; data: RuleReconcile } | null>(null)
  const fetchReconcile = useFetchRuleReconcile()
  // Cada gravação que muda o efeito da regra ganha um número novo (0: nenhuma ainda).
  const [reconcileRequest, setReconcileRequest] = useState(0)
  const applyOpenRef = useRef(false)

  // Sem segurar a gravação: o botão de salvar já volta ao normal. A limpeza do efeito descarta a
  // resposta de uma gravação anterior (salvar de novo) e a que chega depois de sair da página; o
  // diálogo só abre se algo mudaria e se o diálogo de aplicar às existentes não estiver na frente.
  useEffect(() => {
    if (reconcileRequest === 0) return
    let current = true
    fetchReconcile(id).then(
      (data) => {
        if (current && !applyOpenRef.current && nonEmptyReconcileGroups(data).length > 0) setReconcile({ seq: reconcileRequest, data })
      },
      (error: unknown) => {
        if (current && !isCancelledError(error)) toast.error('Não foi possível conferir os lançamentos desta regra.')
      },
    )
    return () => {
      current = false
    }
  }, [reconcileRequest, fetchReconcile, id])

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
    // Só condições, ações ou "ativa" mudam o que a regra faz com os lançamentos: renomear não.
    const definitionChanged = definitionKey(values) !== definitionKey(ruleDefaults(rule))
    try {
      const updated = await update.mutateAsync({
        id,
        body: { ...toRuleBody(values), name: values.name.trim(), is_active: values.is_active },
      })
      // Sem isto, o formulário continuaria "sujo" (o `loadedRef` acima só reage à primeira carga
      // da regra, de propósito, pra não atropelar o que o usuário está digitando) — e por
      // "isDirty" ficar true, "Aplicar às existentes" continuaria desabilitado mesmo recém-salvo.
      form.reset(ruleDefaults(updated))
      toast.success('Regra salva.')
      if (definitionChanged) setReconcileRequest((value) => value + 1)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, '*', isCoverablePath)) notifyError(error)
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
          <RuleFormBody form={form} />
          <Button type="submit" className="w-full rounded-2xl py-6 text-base" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar regra
          </Button>
        </form>

        <ApplyRuleDialog
          rule={rule}
          disabled={form.formState.isDirty}
          onOpenChange={(open) => {
            applyOpenRef.current = open
            if (open) setReconcile(null)
          }}
        />
      </PageBody>

      {reconcile && (
        <ReconcileRuleDialog
          key={reconcile.seq}
          ruleId={id}
          categoryId={ruleCategoryId(rule)}
          reconcile={reconcile.data}
          onClose={() => setReconcile(null)}
        />
      )}

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
