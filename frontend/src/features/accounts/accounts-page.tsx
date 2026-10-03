import { Archive, ArchiveRestore, EllipsisVertical, FileUp, Landmark, Pencil, Plus, Trash2, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAccounts, useDeleteAccount, useUpdateAccount } from '@/api/queries/accounts'
import { useMe } from '@/api/queries/auth'
import { useBankConnections } from '@/api/queries/bank-connections'
import type { Account } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { CategoryIcon } from '@/components/shared/category-icon'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { notifyError } from '@/lib/form-errors'
import { ConnectionCard } from '../banking/connection-card'
import { ReauthBanner } from '../banking/reauth-banner'
import { AccountFormDialog } from './account-form-dialog'
import { ACCOUNT_TYPE_LABELS } from './account-labels'

export function AccountsPage() {
  const navigate = useNavigate()
  const [showArchived, setShowArchived] = useState(false)
  const { data: accounts, isPending, isError, refetch } = useAccounts(showArchived)
  const { data: me } = useMe()
  const { data: connections } = useBankConnections()
  const update = useUpdateAccount()
  const remove = useDeleteAccount()

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Account | undefined>()
  const [deleting, setDeleting] = useState<Account | null>(null)

  const openCreate = () => {
    setEditing(undefined)
    setFormOpen(true)
  }

  const manualAccounts = accounts?.filter((account) => account.connection_id === null)
  const hasConnections = (connections?.length ?? 0) > 0

  const toggleArchive = async (account: Account) => {
    try {
      await update.mutateAsync({ id: account.id, body: { is_archived: !account.is_archived } })
      toast.success(account.is_archived ? 'Conta desarquivada.' : 'Conta arquivada.')
    } catch (error) {
      notifyError(error)
    }
  }

  return (
    <>
      <PageHeader
        title="Contas"
        subtitle="Bancos, carteira e poupança"
        actions={
          <>
            {me?.banking_enabled && (
              <Button className={headerButton}>
                <Landmark className="h-4 w-4" />
                Conectar banco
              </Button>
            )}
            <Button className={headerButton} onClick={openCreate}>
              <Plus className="h-4 w-4" />
              Nova conta
            </Button>
          </>
        }
      />
      <PageBody>
        <ReauthBanner connections={connections ?? []} />

        {connections?.map((connection) => <ConnectionCard key={connection.id} connection={connection} />)}

        {hasConnections && <h2 className="px-1 text-sm font-medium text-muted-foreground">Contas manuais</h2>}

        <Card className="rounded-2xl p-2 shadow-card">
          {isError ? (
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar as contas."
              action={
                <Button variant="outline" onClick={() => refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          ) : isPending ? (
            <div className="space-y-2 p-2">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-14 w-full rounded-xl" />
              ))}
            </div>
          ) : manualAccounts && manualAccounts.length > 0 ? (
            <ul className="divide-y divide-border">
              {manualAccounts.map((account) => (
                <li key={account.id} className="flex items-center gap-3 px-3 py-3">
                  <CategoryIcon icon={account.icon} color={account.color} />
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 truncate font-medium">
                      {account.name}
                      {account.is_archived && <Badge variant="secondary">Arquivada</Badge>}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {ACCOUNT_TYPE_LABELS[account.type]}
                      {account.currency !== (me?.primary_currency ?? account.currency) && ` · ${account.currency}`}
                    </p>
                  </div>
                  <MoneyText cents={account.balance} currency={account.currency} className="font-semibold" />
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" aria-label={`Ações da conta ${account.name}`}>
                        <EllipsisVertical className="h-4 w-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem
                        onSelect={() => {
                          setEditing(account)
                          setFormOpen(true)
                        }}
                      >
                        <Pencil className="h-4 w-4" />
                        Editar
                      </DropdownMenuItem>
                      {!account.is_archived && (
                        <DropdownMenuItem onSelect={() => navigate(`/importar?conta=${account.id}`)}>
                          <FileUp className="h-4 w-4" />
                          Importar extrato
                        </DropdownMenuItem>
                      )}
                      <DropdownMenuItem onSelect={() => toggleArchive(account)}>
                        {account.is_archived ? <ArchiveRestore className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
                        {account.is_archived ? 'Desarquivar' : 'Arquivar'}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem className="text-destructive" onSelect={() => setDeleting(account)}>
                        <Trash2 className="h-4 w-4" />
                        Excluir
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </li>
              ))}
            </ul>
          ) : hasConnections ? (
            <EmptyState
              icon={Landmark}
              title="Nenhuma conta manual"
              description="Contas vinculadas a um banco aparecem acima."
              action={<Button onClick={openCreate}>Criar conta</Button>}
            />
          ) : (
            <EmptyState
              icon={Landmark}
              title="Nenhuma conta ainda"
              description="Cadastre suas contas para registrar lançamentos e acompanhar saldos."
              action={<Button onClick={openCreate}>Criar conta</Button>}
            />
          )}
        </Card>

        <div className="flex items-center justify-end gap-2 px-1">
          <Switch id="show-archived-accounts" checked={showArchived} onCheckedChange={setShowArchived} />
          <Label htmlFor="show-archived-accounts" className="text-sm text-muted-foreground">
            Mostrar arquivadas
          </Label>
        </div>
      </PageBody>

      <AccountFormDialog open={formOpen} onOpenChange={setFormOpen} account={editing} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Excluir ${deleting?.name ?? 'conta'}?`}
        description="Só contas sem lançamentos podem ser excluídas. Para guardar o histórico, arquive."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          if (deleting) await remove.mutateAsync(deleting.id)
          toast.success('Conta excluída.')
        }}
      />
    </>
  )
}
