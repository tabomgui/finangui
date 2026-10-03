import { TriangleAlert } from 'lucide-react'
import type { BankConnection } from '@/api/types'
import { Button } from '@/components/ui/button'

type ReauthBannerProps = {
  connections: BankConnection[]
  /** Abre o widget em modo de atualização; sem a prop, o botão não faz nada. */
  onReconnect?: (connection: BankConnection) => void
}

/** Faixa de aviso para conexões `needs_reauth`; aparece no Início e em Contas. */
export function ReauthBanner({ connections, onReconnect }: ReauthBannerProps) {
  const needsReauth = connections.filter((connection) => connection.status === 'needs_reauth')
  if (needsReauth.length === 0) return null

  return (
    <div className="space-y-2">
      {needsReauth.map((connection) => (
        <div
          key={connection.id}
          className="flex items-center justify-between gap-3 rounded-xl bg-amber-100 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
          <span className="flex min-w-0 items-center gap-2">
            <TriangleAlert className="h-4 w-4 shrink-0" />
            <span className="truncate">O {connection.institution_name ?? 'banco'} pediu para reconectar.</span>
          </span>
          <Button size="sm" variant="outline" className="shrink-0 bg-card" onClick={() => onReconnect?.(connection)}>
            Reconectar
          </Button>
        </div>
      ))}
    </div>
  )
}
