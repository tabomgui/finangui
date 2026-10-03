import { EllipsisVertical, Landmark, Link2, RefreshCw, Unlink } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useDisconnect, useSyncConnection } from '@/api/queries/bank-connections'
import type { BankConnection, ConnectionStatus } from '@/api/types'
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
import { CONNECTION_STATUS_LABELS, syncedLabel } from './connection-labels'

const STATUS_BADGE_VARIANT: Record<ConnectionStatus, 'secondary' | 'destructive'> = {
  pending_link: 'secondary',
  active: 'secondary',
  needs_reauth: 'destructive',
  error: 'destructive',
}

type ConnectionCardProps = {
  connection: BankConnection
  /** Abre o widget em modo de atualização; sem a prop, o item do menu não faz nada. */
  onReconnect?: (connection: BankConnection) => void
}

export function ConnectionCard({ connection, onReconnect }: ConnectionCardProps) {
  const [logoFailed, setLogoFailed] = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)
  const sync = useSyncConnection()
  const disconnect = useDisconnect()

  const name = connection.institution_name ?? 'Banco'

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
            <img
              src={connection.institution_logo_url}
              alt={name}
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
            {connection.last_error && <p className="truncate text-xs text-destructive">{connection.last_error}</p>}
          </div>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" aria-label={`Ações da conexão ${name}`}>
              <EllipsisVertical className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={handleSync}>
              <RefreshCw className="h-4 w-4" />
              Sincronizar agora
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => onReconnect?.(connection)}>
              <Link2 className="h-4 w-4" />
              Reconectar
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem className="text-destructive" onSelect={() => setDisconnecting(true)}>
              <Unlink className="h-4 w-4" />
              Desconectar
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>

      {connection.accounts.length > 0 && (
        <CardContent className="space-y-1 p-2 pt-0">
          {connection.accounts.map((account) => {
            const showProviderBalance =
              account.type !== 'credit_card' && account.provider_balance !== null && account.provider_balance !== account.balance

            return (
              <div key={account.id} className="flex items-center justify-between gap-3 rounded-xl px-2 py-2">
                <p className="min-w-0 truncate text-sm">{account.name}</p>
                <div className="text-right">
                  <MoneyText cents={account.balance} className="block font-semibold" />
                  {showProviderBalance && (
                    <p className="text-xs text-muted-foreground">
                      Banco informa <MoneyText cents={account.provider_balance ?? 0} colored={false} />
                    </p>
                  )}
                </div>
              </div>
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
