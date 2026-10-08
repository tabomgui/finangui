import { Trash2, Undo2, Wand2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Navigate, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useAccounts } from '@/api/queries/accounts'
import { useCreateRecurrence } from '@/api/queries/recurrences'
import { useUnlinkTransfer } from '@/api/queries/transfer-suggestions'
import { useCreateTransaction, useDeleteTransaction, useTransaction, useUpdateTransaction } from '@/api/queries/transactions'
import { useCreateTransfer, useTransfer, useUpdateTransfer } from '@/api/queries/transfers'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { headerIconButton } from '@/components/layout/theme-toggle'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { today } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { pickDefaultAccountId, rememberLastUsedAccountId } from '@/lib/last-used-account'
import { EntryForm } from './entry-form'
import { editKind } from './edit-kind'
import { entryDefaults, toTransactionBody, toTransferBody, transferDefaults } from './form-values'
import { KindToggle, type TransactionKind } from './kind-toggle'
import { TransferForm } from './transfer-form'

/** Destino de volta (botão Voltar, "Cancelar", depois de salvar/excluir): a tela de origem quando é um
 * caminho interno conhecido (ex.: a fatura do cartão), senão a lista de transações. Rejeita "//..."
 * (URL relativa de protocolo: o navegador trataria como outro host, não como caminho interno). */
function backDestination(from: unknown): string {
  return typeof from === 'string' && from.startsWith('/') && !from.startsWith('//') ? from : '/transacoes'
}

const KIND_FROM_PARAM: Record<string, TransactionKind> = { despesa: 'out', receita: 'in', transferencia: 'transfer' }

export function TransactionFormPage() {
  const params = useParams()
  const transactionId = params.id ? Number(params.id) : null
  return transactionId === null ? <NewTransactionPage /> : <EditTransactionPage id={transactionId} />
}

function NewTransactionPage() {
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const [kind, setKind] = useState<TransactionKind>(KIND_FROM_PARAM[searchParams.get('tipo') ?? ''] ?? 'out')
  const { data: accounts, isPending } = useAccounts(false)
  // O "?conta=" pode apontar para uma conta já arquivada (ex.: link antigo); busca entre
  // todas para aceitar, mas o default (sem "?conta=" válido) continua só entre as ativas.
  const { data: allAccounts, isPending: isPendingAll } = useAccounts(true)
  const createTransaction = useCreateTransaction()
  const createTransfer = useCreateTransfer()
  const createRecurrence = useCreateRecurrence()
  const navigate = useNavigate()

  if (isPending || isPendingAll) return <FullPageSpinner />
  const contaParam = Number(searchParams.get('conta'))
  const accountFromParam = allAccounts?.find((account) => account.id === contaParam)
  // Sem "?conta=" válido: a última conta usada num lançamento, não a primeira em ordem
  // alfabética (ver lib/last-used-account.ts) — item "conta padrão errada" da auditoria.
  const firstAccountId = accountFromParam?.id ?? pickDefaultAccountId(accounts)
  const backTo = backDestination(location.state?.from)

  const done = (message = 'Lançamento salvo.') => {
    toast.success(message)
    navigate(backTo, { replace: true })
  }

  return (
    <>
      <PageHeader title="Nova transação" back={backTo} />
      <PageBody className="max-w-2xl">
        <KindToggle value={kind} onChange={setKind} />
        {kind === 'transfer' ? (
          <TransferForm
            key="transfer"
            defaultValues={transferDefaults({ today: today(), fromAccountId: firstAccountId })}
            submitLabel="Salvar transferência"
            autoFocusAmount
            onSubmit={async (values) => {
              await createTransfer.mutateAsync(toTransferBody(values))
              rememberLastUsedAccountId(values.from_account_id as number)
              done()
            }}
          />
        ) : (
          <EntryForm
            key={kind}
            defaultValues={entryDefaults({ direction: kind, accountId: firstAccountId, today: today() })}
            submitLabel={kind === 'out' ? 'Salvar despesa' : 'Salvar receita'}
            autoFocusAmount
            onSubmit={async (values) => {
              const transaction = await createTransaction.mutateAsync(toTransactionBody(values))
              rememberLastUsedAccountId(values.account_id as number)
              // Já tem recorrência (casou com uma prevista): criar outra a partir dela não faz
              // sentido e o backend rejeitaria (recurrence_transaction_ineligible).
              if (values.repeat && !transaction.recurrence) {
                try {
                  await createRecurrence.mutateAsync({ transaction_id: transaction.id, frequency: values.repeat_frequency })
                  toast.success('Recorrência criada.')
                } catch (error) {
                  // O lançamento já foi salvo: um erro aqui não pode travar a navegação nem
                  // dar a impressão de que nada foi salvo.
                  notifyError(error, 'Lançamento salvo, mas não foi possível criar a recorrência.')
                }
              }
              done(transaction.recurrence && `Lançamento previsto de ${transaction.recurrence.description} confirmado.`)
            }}
          />
        )}
      </PageBody>
    </>
  )
}

function EditTransactionPage({ id }: { id: number }) {
  const location = useLocation()
  const { data: transaction, isPending, isError } = useTransaction(id)
  const transferId = transaction?.transfer_id ?? null
  const { data: transfer, isPending: transferPending, isError: transferError } = useTransfer(transferId)
  const updateTransaction = useUpdateTransaction()
  const updateTransfer = useUpdateTransfer()
  const remove = useDeleteTransaction()
  const unlinkTransfer = useUnlinkTransfer()
  const navigate = useNavigate()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [confirmUnlink, setConfirmUnlink] = useState(false)

  const notFound = isError || (transferId !== null && transferError)
  // O toast dispara num efeito, guardado por ref, para não duplicar sob StrictMode
  // (que roda o efeito duas vezes na mesma montagem) nem a cada nova renderização.
  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Lançamento não encontrado.')
    }
  }, [notFound])

  if (notFound) return <Navigate to="/transacoes" replace />
  if (isPending || (transferId !== null && transferPending)) return <FullPageSpinner />

  const isTransfer = transferId !== null && transfer !== undefined
  const kind = isTransfer ? 'transfer' : editKind(transaction)
  const backTo = backDestination(location.state?.from)
  const done = () => {
    toast.success('Lançamento atualizado.')
    navigate(backTo, { replace: true })
  }

  // Depois de uma edição que colocou categoria onde não tinha a mesma categoria (ou mudou para
  // outra), oferece criar uma regra a partir deste lançamento direto no toast de sucesso.
  const doneAfterEntryEdit = (newCategoryId: number | null) => {
    if (newCategoryId !== null && newCategoryId !== transaction.category_id) {
      toast.success('Lançamento atualizado.', {
        description: 'Aplicar esta categoria a lançamentos parecidos?',
        action: { label: 'Criar regra', onClick: () => navigate(`/regras/nova?transacao=${id}`) },
      })
    } else {
      toast.success('Lançamento atualizado.')
    }
    navigate(backTo, { replace: true })
  }

  const title = isTransfer ? 'Editar transferência' : kind === 'installment' ? 'Editar parcela' : 'Editar lançamento'
  const lockedReason =
    kind === 'installment' && transaction.installment
      ? `Parcela ${transaction.installment.number} de ${transaction.installment.total}. Valor, data e conta seguem o parcelamento; para mudar a compra inteira, use a aba Parcelamentos do cartão.`
      : undefined

  // `deletes_only_this_leg` já vem calculado por perna do backend (TransferResource, mesma
  // regra de DeleteTransaction::handle()): nunca reimplementado aqui a partir de external_id.
  const deletesOnlyThisLeg = isTransfer && (transfer.from.id === id ? transfer.from : transfer.to).deletes_only_this_leg === true

  return (
    <>
      <PageHeader
        title={title}
        back={backTo}
        actions={
          <>
            {!isTransfer && (
              <button
                type="button"
                aria-label="Criar regra a partir deste lançamento"
                className={headerIconButton}
                onClick={() => navigate(`/regras/nova?transacao=${id}`)}
              >
                <Wand2 className="h-5 w-5" />
              </button>
            )}
            {isTransfer && (
              <button
                type="button"
                aria-label="Desfazer transferência"
                className={headerIconButton}
                onClick={() => setConfirmUnlink(true)}
              >
                <Undo2 className="h-5 w-5" />
              </button>
            )}
            <button type="button" aria-label="Excluir" className={headerIconButton} onClick={() => setConfirmDelete(true)}>
              <Trash2 className="h-5 w-5" />
            </button>
          </>
        }
      />
      <PageBody className="max-w-2xl">
        <KindToggle value={isTransfer ? 'transfer' : transaction.direction} onChange={() => {}} disabled />
        {isTransfer ? (
          <TransferForm
            defaultValues={transferDefaults({ transfer })}
            submitLabel="Salvar transferência"
            onSubmit={async (values) => {
              await updateTransfer.mutateAsync({ id: transfer.transfer_id, body: toTransferBody(values) })
              done()
            }}
          />
        ) : (
          <EntryForm
            defaultValues={entryDefaults({ transaction })}
            submitLabel="Salvar"
            showIgnore
            mode="edit"
            lockedReason={lockedReason}
            onSubmit={async (values) => {
              await updateTransaction.mutateAsync({
                id,
                body: toTransactionBody(values, { initialStatementId: transaction.statement_id }),
              })
              doneAfterEntryEdit(values.category_id)
            }}
          />
        )}
      </PageBody>
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={isTransfer ? 'Excluir transferência?' : kind === 'installment' ? 'Excluir parcelamento?' : 'Excluir lançamento?'}
        description={
          isTransfer
            ? deletesOnlyThisLeg
              ? 'Só este lançamento será excluído; o da outra conta volta a ser um lançamento comum.'
              : 'As duas pernas serão excluídas.'
            : kind === 'installment'
              ? 'Todas as parcelas desta compra serão excluídas, inclusive as já lançadas. Para encerrar só as futuras, cancele o parcelamento na tela do cartão.'
              : undefined
        }
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync(id)
          toast.success(kind === 'installment' ? 'Parcelamento excluído.' : 'Lançamento excluído.')
          navigate(backTo, { replace: true })
        }}
      />
      {isTransfer && (
        <ConfirmDialog
          open={confirmUnlink}
          onOpenChange={setConfirmUnlink}
          title="Desfazer transferência?"
          description="As duas pernas viram lançamentos comuns, sem categoria. A detecção automática não vai juntá-las de novo."
          confirmLabel="Desfazer"
          onConfirm={async () => {
            await unlinkTransfer.mutateAsync(transfer.transfer_id)
            toast.success('Transferência desfeita.')
            navigate(backTo, { replace: true })
          }}
        />
      )}
    </>
  )
}
