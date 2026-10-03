import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateBankConnections, invalidateLedger, queryKeys } from '@/api/query-keys'
import type { BankConnection, ConnectionStatus, ConnectTokenRequest, LinkAccountsRequest } from '@/api/types'

const PENDING_SYNC_WINDOW_MS = 2 * 60_000
const PENDING_SYNC_POLL_MS = 5_000

type PendingSync = { requestedAt: number; lastSyncedAt: string | null; status: ConnectionStatus }

/**
 * Sincronizar, vincular e reconectar não devolvem o resultado final do sync (o job roda à parte):
 * quem pediu guarda aqui o instante do pedido e o `last_synced_at`/`status` de então, para
 * `useBankConnections` saber quando vale a pena cutucar o servidor de novo (ver `hasPendingSync`).
 *
 * Por `QueryClient` (WeakMap, não um único Map module-level): cada teste cria seu próprio
 * `QueryClient`, e um Map compartilhado entre eles faria uma conexão marcada por um teste
 * "escapar" para o próximo que reusa o mesmo id. Em produção só existe um QueryClient, então o
 * efeito prático é o mesmo de antes.
 */
const pendingSyncsByClient = new WeakMap<QueryClient, Map<number, PendingSync>>()

function pendingSyncsFor(queryClient: QueryClient): Map<number, PendingSync> {
  let pendingSyncs = pendingSyncsByClient.get(queryClient)
  if (!pendingSyncs) {
    pendingSyncs = new Map()
    pendingSyncsByClient.set(queryClient, pendingSyncs)
  }
  return pendingSyncs
}

function markSyncRequested(queryClient: QueryClient, connectionId: number, connection: BankConnection | undefined): void {
  pendingSyncsFor(queryClient).set(connectionId, {
    requestedAt: Date.now(),
    lastSyncedAt: connection?.last_synced_at ?? null,
    status: connection?.status ?? 'active',
  })
}

/**
 * true enquanto alguma conexão marcada ainda não deu sinal de ter terminado o sync. Uma conexão
 * sai da lista (e o razão é invalidado, porque o saldo das contas pode ter mudado) quando
 * `last_synced_at` ou `status` mudam desde o pedido, ou quando passa da janela de espera — ver
 * `PENDING_SYNC_WINDOW_MS` — porque nesse ponto não vale mais a pena continuar cutucando.
 */
function hasPendingSync(queryClient: QueryClient, connections: BankConnection[] | undefined): boolean {
  const pendingSyncs = pendingSyncsFor(queryClient)
  if (!connections) return pendingSyncs.size > 0

  const now = Date.now()
  let pending = false

  for (const [connectionId, entry] of pendingSyncs) {
    const connection = connections.find((c) => c.id === connectionId)
    const expired = now - entry.requestedAt > PENDING_SYNC_WINDOW_MS
    const changed = connection !== undefined && (connection.last_synced_at !== entry.lastSyncedAt || connection.status !== entry.status)

    if (expired || changed) {
      pendingSyncs.delete(connectionId)
      if (changed) invalidateLedger(queryClient)
      continue
    }

    pending = true
  }

  return pending
}

export function useBankConnections() {
  const queryClient = useQueryClient()
  return useQuery({
    queryKey: queryKeys.bankConnections(),
    queryFn: async () => (await unwrap(api.GET('/bank-connections'))).data,
    refetchInterval: (query) => (hasPendingSync(queryClient, query.state.data) ? PENDING_SYNC_POLL_MS : false),
  })
}

export function useConnectToken() {
  return useMutation({
    mutationFn: async (body: ConnectTokenRequest = {}) => {
      const data = (await unwrap(api.POST('/bank-connections/connect-token', { body }))).data
      // item_id só vem no corpo quando o pedido incluiu connection_id (modo atualização) — ver
      // BankConnectionController::connectTokenData() no backend.
      return { token: data.connect_token, itemId: 'item_id' in data ? data.item_id : undefined }
    },
  })
}

export function useCreateConnection() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (itemId: string) => (await unwrap(api.POST('/bank-connections', { body: { item_id: itemId } }))).data,
    onSuccess: () => invalidateBankConnections(queryClient),
  })
}

export function useLinkAccounts() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body: LinkAccountsRequest }) =>
      (await unwrap(api.POST('/bank-connections/{connection}/link-accounts', { params: { path: { connection: id } }, body }))).data,
    onSuccess: (connection) => {
      markSyncRequested(queryClient, connection.id, connection)
      // invalidateLedger já inclui bank-connections (ver query-keys.ts).
      invalidateLedger(queryClient)
    },
  })
}

export function useMarkReconnected() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, itemId }: { id: number; itemId: string }) =>
      (
        await unwrap(
          api.POST('/bank-connections/{connection}/reconnected', { params: { path: { connection: id } }, body: { item_id: itemId } }),
        )
      ).data,
    onSuccess: (connection) => {
      markSyncRequested(queryClient, connection.id, connection)
      invalidateBankConnections(queryClient)
    },
  })
}

export function useSyncConnection() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.POST('/bank-connections/{connection}/sync', { params: { path: { connection: id } } }))
      return id
    },
    onSuccess: (id) => {
      const connections = queryClient.getQueryData<BankConnection[]>(queryKeys.bankConnections())
      const current = connections?.find((connection) => connection.id === id)
      markSyncRequested(queryClient, id, current)
      invalidateBankConnections(queryClient)
    },
  })
}

export function useDisconnect() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/bank-connections/{connection}', { params: { path: { connection: id } } }))
    },
    onSuccess: () => invalidateLedger(queryClient),
  })
}
