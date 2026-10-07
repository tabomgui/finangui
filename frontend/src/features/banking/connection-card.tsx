import { EllipsisVertical, Landmark, Link2, RefreshCw, Unlink } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useDisconnect, useSyncConnection } from '@/api/queries/bank-connections'
import type { Account, BankConnection, ConnectionStatus } from '@/api/types'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { notifyError } from '@/lib/form-errors'
import { AccountRow } from '../accounts/account-row'
import { CONNECTION_STATUS_LABELS, syncedLabel } from './connection-labels'

const STATUS_BADGE_VARIANT: Record<ConnectionStatus, 'secondary' | 'destructive'> = {
  pending_link: 'secondary',
  active: 'secondary',
  needs_reauth: 'destructive',
  error: 'destructive',
}

type ConnectionCardProps = {
  connection: BankConnection
  /** Contas vinculadas a esta conexão (objeto completo de `/accounts` — `account.connection_id === connection.id` —, não o resumo embutido no recurso da conexão): editar sem perder campos que só existem na conta de verdade, como limite e dias do cartão. */
  accounts: Account[]
  /** Abre o widget em modo de atualização; sem a prop, o item do menu não faz nada. */
  onReconnect?: (connection: BankConnection) => void
  /** Reabre o diálogo de vínculo (ex.: o usuário fechou o widget antes de terminar); sem a prop, o botão não faz nada. */
  onLinkAccounts?: (connection: BankConnection) => void
  onEditAccount: (account: Account) => void
  /** Sem provedor configurado, sincronizar e reconectar não fazem sentido (a API responde 409 banking_disabled) — escondidos nesse caso. */
  bankingEnabled?: boolean
  /** Desabilita "Reconectar" enquanto um pedido de token/o widget de reconexão já está em andamento (ver `useReconnectFlow`). */
  reconnectDisabled?: boolean
}

export function ConnectionCard({
  connection,
  accounts,
  onReconnect,
  onLinkAccounts,
  onEditAccount,
  bankingEnabled = true,
  reconnectDisabled = false,
}: ConnectionCardProps) {
  const [logoFailed, setLogoFailed] = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)
  const sync = useSyncConnection()
  const disconnect = useDisconnect()

  const name = connection.institution_name ?? 'Banco'
  const isPendingLink = connection.status === 'pending_link'
  const isActive = connection.status === 'active'
  // Enquanto pending_link não há nada ativo para sincronizar/reconectar (a conexão ainda nem
  // escolheu as contas); sem provedor configurado, as próprias rotas respondem 409
  // banking_disabled — esconde os dois de propósito, não só desabilita. O menu em si (e
  // "Desconectar", que só limpa o lado local) continua disponível nos dois casos.
  const showProviderActions = bankingEnabled && !isPendingLink

  async function handleSync() {
    try {
      await sync.mutateAsync(connection.id)
      toast.success('Sincronização iniciada.')
    } catch (error) {
      if (error instanceof ApiError && error.code === 'connection_sync_in_progress') {
        toast.error('Sincronização em andamento.')
        return
      }
      notifyError(error)
    }
  }

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          {connection.institution_logo_url && !logoFailed ? (
            // alt="": decorativo — o nome do banco já está em texto ao lado.
            <img
              src={connection.institution_logo_url}
              alt=""
              className="h-10 w-10 shrink-0 rounded-full object-contain"
              onError={() => setLogoFailed(true)}
            />
          ) : (
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted">
              <Landmark className="h-5 w-5 text-muted-foreground" />
            </span>
          )}
          <div className="min-w-0">
            <p className="flex items-center gap-2 truncate font-medium">
              <span className="truncate">{name}</span>
              <Badge variant={STATUS_BADGE_VARIANT[connection.status]}>{CONNECTION_STATUS_LABELS[connection.status]}</Badge>
            </p>
            <p className="truncate text-xs text-muted-foreground">{syncedLabel(connection.last_synced_at)}</p>
            {connection.last_error && (
              <p className="text-xs text-destructive">
                <span className="block truncate">{connection.last_error}</span>
                {/* Caso comum: conexão feita com a conta global antiga, sem credenciais próprias cadastradas ainda. */}
                {!bankingEnabled && (
                  <Link to="/configuracoes#pluggy" className="font-medium underline-offset-2 hover:underline">
                    Configurar credenciais da Pluggy
                  </Link>
                )}
              </p>
            )}
          </div>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" aria-label={`Ações da conexão ${name}`}>
              <EllipsisVertical className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {showProviderActions && (
              <>
                <DropdownMenuItem onSelect={handleSync}>
                  <RefreshCw className="h-4 w-4" />
                  Sincronizar agora
                </DropdownMenuItem>
                <DropdownMenuItem disabled={reconnectDisabled} onSelect={() => onReconnect?.(connection)}>
                  <Link2 className="h-4 w-4" />
                  Reconectar
                </DropdownMenuItem>
                <DropdownMenuSeparator />
              </>
            )}
            <DropdownMenuItem className="text-destructive" onSelect={() => setDisconnecting(true)}>
              <Unlink className="h-4 w-4" />
              Desconectar
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>

      {/* disabled, não escondido: sem provedor configurado (bankingEnabled false) a rota de vínculo
          responde 409 banking_disabled, mesma razão de showProviderActions acima — mas aqui o botão
          é a única ação do bloco, então desabilitar deixa claro que ele volta a funcionar depois de
          cadastrar as credenciais, em vez de a conta pendente simplesmente desaparecer da tela. */}
      {isPendingLink && connection.pending_accounts.length > 0 && (
        <CardContent className="pt-0">
          <Button size="sm" disabled={!bankingEnabled} onClick={() => onLinkAccounts?.(connection)}>
            <Link2 className="h-4 w-4" />
            Vincular contas
          </Button>
        </CardContent>
      )}

      {isActive && connection.unlinked_accounts.length > 0 && (
        <CardContent className="flex items-center justify-between gap-3 pt-0">
          <p className="text-sm text-muted-foreground">Novas contas no banco</p>
          <Button size="sm" variant="outline" disabled={!bankingEnabled} onClick={() => onLinkAccounts?.(connection)}>
            <Link2 className="h-4 w-4" />
            Vincular
          </Button>
        </CardContent>
      )}

      {accounts.length > 0 && (
        <CardContent className="space-y-1 p-2 pt-0">
          {accounts.map((account) => {
            // Cartão fica de fora: o "saldo informado pelo banco" de um cartão é a fatura em
            // aberto na hora (inclui autorizações e parcelas que o banco já conta, mas nosso
            // lançamento só lança no fechamento/compra) — ele quase sempre diverge um pouco do
            // nosso, sem isso ser sinal de nada errado. Numa conta corrente/poupança, divergir é
            // raro e vale avisar (pode ser um lançamento manual duplicado ou que falta). `balance`
            // já é o saldo informado pelo banco numa conta conectada (ver Account::balance()) —
            // mostrar esse mesmo valor de novo na linha extra só repetiria o saldo principal; a
            // linha extra mostra ledger_balance (o saldo pelo nosso razão, sempre "hoje"), o
            // número que de fato diverge do saldo principal.
            const showLedgerBalance =
              account.type !== 'credit_card' &&
              account.provider_balance !== null &&
              account.ledger_balance !== undefined &&
              account.provider_balance !== account.ledger_balance

            return (
              <AccountRow
                key={account.id}
                account={account}
                onEdit={() => onEditAccount(account)}
                extra={
                  showLedgerBalance ? (
                    <p className="text-xs text-muted-foreground">
                      Pelos lançamentos: <MoneyText cents={account.ledger_balance ?? 0} colored={false} />
                    </p>
                  ) : undefined
                }
              />
            )
          })}
        </CardContent>
      )}

      <ConfirmDialog
        open={disconnecting}
        onOpenChange={setDisconnecting}
        title={`Desconectar ${name}?`}
        description="As contas continuam no app com todo o histórico, como contas manuais."
        confirmLabel="Desconectar"
        destructive
        onConfirm={async () => {
          await disconnect.mutateAsync(connection.id)
          toast.success('Banco desconectado.')
        }}
      />
    </Card>
  )
}
