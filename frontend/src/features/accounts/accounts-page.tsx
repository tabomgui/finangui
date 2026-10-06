import { Landmark, Plus, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAccounts } from '@/api/queries/accounts'
import { useMe } from '@/api/queries/auth'
import { useBankConnections } from '@/api/queries/bank-connections'
import type { Account, BankConnection } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { ConnectBankButton } from '../banking/connect-bank-button'
import { ConnectionCard } from '../banking/connection-card'
import { LinkAccountsDialog } from '../banking/link-accounts-dialog'
import { ReauthBanner } from '../banking/reauth-banner'
import { useReconnectFlow } from '../banking/use-reconnect-flow'
import { AccountFormDialog } from './account-form-dialog'
import { AccountRow } from './account-row'

export function AccountsPage() {
  const [showArchived, setShowArchived] = useState(false)
  const { data: accounts, isPending, isError, refetch } = useAccounts(showArchived)
  const { data: me } = useMe()
  const {
    data: connections,
    isPending: connectionsPending,
    isError: connectionsError,
    refetch: refetchConnections,
  } = useBankConnections()
  const reconnectFlow = useReconnectFlow()

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Account | undefined>()
  // Retomar o vínculo de uma conexão pending_link (ex.: o usuário fechou o widget antes de
  // terminar); as contas pendentes já vêm em connection.pending_accounts.
  const [resuming, setResuming] = useState<BankConnection | null>(null)

  const openCreate = () => {
    setEditing(undefined)
    setFormOpen(true)
  }

  const openEdit = (account: Account) => {
    setEditing(account)
    setFormOpen(true)
  }

  const manualAccounts = accounts?.filter((account) => account.connection_id === null)
  const connectedAccounts = accounts?.filter((account) => account.connection_id !== null) ?? []
  const hasConnections = (connections?.length ?? 0) > 0

  // As linhas de conta do ConnectionCard vêm de `useAccounts()` (não do resumo embutido no
  // recurso da conexão): assim a conta completa está à mão para editar sem perder campos como
  // limite/dias do cartão, e o alternador "Mostrar arquivadas" já vale pra elas também, de graça.
  // Por isso as duas seções (conexões e contas manuais) só decidem o que mostrar depois que as
  // duas listas (contas e conexões) estiverem resolvidas — ver `bothSettled`.
  const accountsSettled = !isPending && !isError
  const connectionsSettled = !connectionsPending && !connectionsError
  const bothSettled = accountsSettled && connectionsSettled

  return (
    <>
      <PageHeader
        title="Contas"
        subtitle="Bancos, carteira e poupança"
        actions={
          <>
            {me && (me.banking_enabled ? (
              <ConnectBankButton className={headerButton} />
            ) : (
              // Sem credenciais da Pluggy cadastradas: leva para o card em Configurações em vez
              // de abrir o widget de conexão (a rota de connect-token responderia 409 banking_disabled).
              <Button className={headerButton} asChild>
                <Link to="/configuracoes#pluggy">
                  <Landmark className="h-4 w-4" />
                  <span className="sr-only sm:not-sr-only">Conectar banco</span>
                </Link>
              </Button>
            ))}
            <Button className={headerButton} onClick={openCreate}>
              <Plus className="h-4 w-4" />
              <span className="sr-only sm:not-sr-only">Nova conta</span>
            </Button>
          </>
        }
      />
      <PageBody>
        <ReauthBanner
          connections={connections ?? []}
          onReconnect={reconnectFlow.reconnect}
          reconnectDisabled={reconnectFlow.isPending}
          bankingEnabled={me?.banking_enabled}
        />

        {connectionsError ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar os bancos conectados."
            action={
              <Button variant="outline" onClick={() => refetchConnections()}>
                Tentar de novo
              </Button>
            }
          />
        ) : !bothSettled ? (
          <Skeleton className="h-28 w-full rounded-2xl" />
        ) : (
          connections?.map((connection) => (
            <ConnectionCard
              key={connection.id}
              connection={connection}
              accounts={connectedAccounts.filter((account) => account.connection_id === connection.id)}
              onReconnect={reconnectFlow.reconnect}
              onLinkAccounts={setResuming}
              onEditAccount={openEdit}
              bankingEnabled={me?.banking_enabled}
              reconnectDisabled={reconnectFlow.isPending}
            />
          ))
        )}

        {bothSettled && hasConnections && <h2 className="px-1 text-sm font-medium text-muted-foreground">Contas manuais</h2>}

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
          ) : !bothSettled ? (
            <div className="space-y-2 p-2">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-14 w-full rounded-xl" />
              ))}
            </div>
          ) : manualAccounts && manualAccounts.length > 0 ? (
            <ul className="divide-y divide-border">
              {manualAccounts.map((account) => (
                <li key={account.id}>
                  <AccountRow account={account} onEdit={() => openEdit(account)} primaryCurrency={me?.primary_currency} showImport />
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

      {reconnectFlow.widget}
      {resuming && <LinkAccountsDialog connection={resuming} open onOpenChange={(open) => !open && setResuming(null)} />}
    </>
  )
}
