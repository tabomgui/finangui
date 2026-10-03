import type { ConnectionStatus } from '@/api/types'

export const CONNECTION_STATUS_LABELS: Record<ConnectionStatus, string> = {
  pending_link: 'Aguardando vínculo',
  active: 'Conectado',
  needs_reauth: 'Reconectar',
  error: 'Erro na sincronização',
}

/** "Sincronizado há 5 min" / "Nunca sincronizado". `reference` existe só para os testes. */
export function syncedLabel(lastSyncedAt: string | null, reference: Date = new Date()): string {
  if (!lastSyncedAt) return 'Nunca sincronizado'

  const minutes = Math.floor((reference.getTime() - new Date(lastSyncedAt).getTime()) / 60_000)
  if (minutes < 1) return 'Sincronizado agora'
  if (minutes < 60) return `Sincronizado há ${minutes} min`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `Sincronizado há ${hours} h`

  const days = Math.floor(hours / 24)
  return `Sincronizado há ${days} ${days === 1 ? 'dia' : 'dias'}`
}
