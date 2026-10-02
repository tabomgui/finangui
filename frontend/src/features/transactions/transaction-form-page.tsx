import { Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useAccounts } from '@/api/queries/accounts'
import { useCreateTransaction, useDeleteTransaction, useTransaction, useUpdateTransaction } from '@/api/queries/transactions'
import { useCreateTransfer, useTransfer, useUpdateTransfer } from '@/api/queries/transfers'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { headerIconButton } from '@/components/layout/theme-toggle'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { today } from '@/lib/date'
import { EntryForm } from './entry-form'
import { entryDefaults, toTransactionBody, toTransferBody, transferDefaults } from './form-values'
import { KindToggle, type TransactionKind } from './kind-toggle'
import { TransferForm } from './transfer-form'

const KIND_FROM_PARAM: Record<string, TransactionKind> = { despesa: 'out', receita: 'in', transferencia: 'transfer' }

export function TransactionFormPage() {
  const params = useParams()
  const transactionId = params.id ? Number(params.id) : null
  return transactionId === null ? <NewTransactionPage /> : <EditTransactionPage id={transactionId} />
}

function NewTransactionPage() {
  const [searchParams] = useSearchParams()
  const [kind, setKind] = useState<TransactionKind>(KIND_FROM_PARAM[searchParams.get('tipo') ?? ''] ?? 'out')
  const { data: accounts, isPending } = useAccounts(false)
  const createTransaction = useCreateTransaction()
  const createTransfer = useCreateTransfer()
  const navigate = useNavigate()

  if (isPending) return <FullPageSpinner />
  const firstAccountId = accounts?.[0]?.id ?? null

  const done = () => {
    toast.success('Lançamento salvo.')
    navigate('/transacoes')
  }

  return (
    <>
      <PageHeader title="Nova transação" back="/transacoes" />
      <PageBody className="max-w-2xl">
        <KindToggle value={kind} onChange={setKind} />
        {kind === 'transfer' ? (
          <TransferForm
            key="transfer"
            defaultValues={transferDefaults({ today: today(), fromAccountId: firstAccountId })}
            submitLabel="Salvar transferência"
            onSubmit={async (values) => {
              await createTransfer.mutateAsync(toTransferBody(values))
              done()
            }}
          />
        ) : (
          <EntryForm
            key={kind}
            defaultValues={entryDefaults({ direction: kind, accountId: firstAccountId, today: today() })}
            submitLabel={kind === 'out' ? 'Salvar despesa' : 'Salvar receita'}
            onSubmit={async (values) => {
              await createTransaction.mutateAsync(toTransactionBody(values))
              done()
            }}
          />
        )}
      </PageBody>
    </>
  )
}

function EditTransactionPage({ id }: { id: number }) {
  const { data: transaction, isPending, isError } = useTransaction(id)
  const transferId = transaction?.transfer_id ?? null
  const { data: transfer, isPending: transferPending } = useTransfer(transferId)
  const updateTransaction = useUpdateTransaction()
  const updateTransfer = useUpdateTransfer()
  const remove = useDeleteTransaction()
  const navigate = useNavigate()
  const [confirmDelete, setConfirmDelete] = useState(false)

  if (isError) return <Navigate to="/transacoes" replace />
  if (isPending || (transferId !== null && transferPending)) return <FullPageSpinner />

  const isTransfer = transferId !== null && transfer !== undefined
  const done = () => {
    toast.success('Lançamento atualizado.')
    navigate('/transacoes')
  }

  return (
    <>
      <PageHeader
        title={isTransfer ? 'Editar transferência' : 'Editar lançamento'}
        back="/transacoes"
        actions={
          <button type="button" aria-label="Excluir" className={headerIconButton} onClick={() => setConfirmDelete(true)}>
            <Trash2 className="h-5 w-5" />
          </button>
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
            onSubmit={async (values) => {
              await updateTransaction.mutateAsync({ id, body: toTransactionBody(values) })
              done()
            }}
          />
        )}
      </PageBody>
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={isTransfer ? 'Excluir transferência?' : 'Excluir lançamento?'}
        description={isTransfer ? 'As duas pernas da transferência serão excluídas.' : undefined}
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync(id)
          toast.success('Lançamento excluído.')
          navigate('/transacoes')
        }}
      />
    </>
  )
}
