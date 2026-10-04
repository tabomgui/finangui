import { Archive, ArchiveRestore, EllipsisVertical, FileUp, Pencil, Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useDeleteAccount, useUpdateAccount } from '@/api/queries/accounts'
import type { AccountType } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { notifyError } from '@/lib/form-errors'
import { ACCOUNT_TYPE_LABELS } from './account-labels'

/** Campos que a linha precisa — tanto `Account` quanto `ConnectionAccount` (contas vinculadas, dentro de um `ConnectionCard`) satisfazem isso. */
type RowAccount = {
  id: number
  name: string
  type: AccountType
  currency: string
  color: string | null
  icon: string | null
  is_archived: boolean
  balance: number
}

type AccountRowProps = {
  account: RowAccount
  onEdit: () => void
  /** Moeda só aparece ao lado do tipo quando difere desta (ex.: moeda principal do usuário). */
  primaryCurrency?: string
  /** Contas conectadas a um banco não oferecem "Importar extrato" (o sync já cobre isso). */
  showImport?: boolean
  /** Linha extra abaixo do saldo (ex.: "Banco informa R$ x", só em contas conectadas). */
  extra?: ReactNode
}

/**
 * Uma linha de conta (ícone, nome, badge de arquivada, saldo, menu Editar/Arquivar/Excluir),
 * reusada pela lista de contas manuais e pelo `ConnectionCard` (contas vinculadas a um banco) —
 * ver `features/accounts/accounts-page.tsx` e `features/banking/connection-card.tsx`.
 */
export function AccountRow({ account, onEdit, primaryCurrency, showImport = false, extra }: AccountRowProps) {
  const navigate = useNavigate()
  const update = useUpdateAccount()
  const remove = useDeleteAccount()
  const [deleting, setDeleting] = useState(false)

  async function toggleArchive() {
    try {
      await update.mutateAsync({ id: account.id, body: { is_archived: !account.is_archived } })
      toast.success(account.is_archived ? 'Conta desarquivada.' : 'Conta arquivada.')
    } catch (error) {
      notifyError(error)
    }
  }

  return (
    <div className="flex items-center gap-3 px-3 py-3">
      <CategoryIcon icon={account.icon} color={account.color} />
      <div className="min-w-0 flex-1">
        <p className="flex items-center gap-2 truncate font-medium">
          {account.name}
          {account.is_archived && <Badge variant="secondary">Arquivada</Badge>}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {ACCOUNT_TYPE_LABELS[account.type]}
          {primaryCurrency !== undefined && account.currency !== primaryCurrency && ` · ${account.currency}`}
        </p>
      </div>
      <div className="text-right">
        <MoneyText cents={account.balance} currency={account.currency} className="font-semibold" />
        {extra}
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={`Ações da conta ${account.name}`}>
            <EllipsisVertical className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={onEdit}>
            <Pencil className="h-4 w-4" />
            Editar
          </DropdownMenuItem>
          {showImport && !account.is_archived && (
            <DropdownMenuItem onSelect={() => navigate(`/importar?conta=${account.id}`)}>
              <FileUp className="h-4 w-4" />
              Importar extrato
            </DropdownMenuItem>
          )}
          <DropdownMenuItem onSelect={toggleArchive}>
            {account.is_archived ? <ArchiveRestore className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
            {account.is_archived ? 'Desarquivar' : 'Arquivar'}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem className="text-destructive" onSelect={() => setDeleting(true)}>
            <Trash2 className="h-4 w-4" />
            Excluir
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title={`Excluir ${account.name}?`}
        description="Só contas sem lançamentos podem ser excluídas. Para guardar o histórico, arquive."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          await remove.mutateAsync(account.id)
          toast.success('Conta excluída.')
        }}
      />
    </div>
  )
}
