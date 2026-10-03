import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateBankConnections, invalidateLedger, queryKeys } from '@/api/query-keys'
import type { BankConnection, ConnectTokenRequest, LinkAccountsRequest } from '@/api/types'

const PENDING_SYNC_WINDOW_MS = 2 * 60_000
const PENDING_SYNC_POLL_MS = 5_000

type PendingSync = { requestedAt: number; lastSyncedAt: string | null }

/**
 * Sincronizar não devolve a conexão atualizada (202, sem corpo útil): quem pediu guarda aqui o
 * instante do pedido e o `last_synced_at` de então, para `useBankConnections` saber quando vale
 * a pena cutucar o servidor de novo (ver `hasPendingSync`). Módulo (não estado de componente)
 * porque o pedido pode vir de um componente que desmonta antes do sync terminar (ex.: o menu de
 * um `ConnectionCard` numa lista que troca de página).
 */
const pendingSyncs = new Map<number, PendingSync>()

function markSyncRequested(connectionId: number, lastSyncedAt: string | null): void {
  pendingSyncs.set(connectionId, { requestedAt: Date.now(), lastSyncedAt })
}

function hasPendingSync(connections: BankConnection[] | undefined): boolean {
  if (!connections) return pendingSyncs.size > 0

  const now = Date.now()
  let pending = false

  for (const [connectionId, entry] of pendingSyncs) {
    const connection = connections.find((c) => c.id === connectionId)
    const expired = now - entry.requestedAt > PENDING_SYNC_WINDOW_MS
    const synced = connection !== undefined && connection.last_synced_at !== entry.lastSyncedAt

    if (expired || synced) {
      pendingSyncs.delete(connectionId)
      continue
    }

    pending = true
  }

  return pending
}

export function useBankConnections() {
  return useQuery({
    queryKey: queryKeys.bankConnections(),
    queryFn: async () => (await unwrap(api.GET('/bank-connections'))).data,
    refetchInterval: (query) => (hasPendingSync(query.state.data) ? PENDING_SYNC_POLL_MS : false),
  })
}

export function useConnectToken() {
  return useMutation({
    mutationFn: async (body: ConnectTokenRequest = {}) =>
      (await unwrap(api.POST('/bank-connections/connect-token', { body }))).data.connect_token,
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
    onSuccess: () => {
      invalidateBankConnections(queryClient)
      invalidateLedger(queryClient)
    },
  })
}

export function useMarkReconnected() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      (await unwrap(api.POST('/bank-connections/{connection}/reconnected', { params: { path: { connection: id } } }))).data,
    onSuccess: () => invalidateBankConnections(queryClient),
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
      markSyncRequested(id, current?.last_synced_at ?? null)
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
    onSuccess: () => {
      invalidateBankConnections(queryClient)
      invalidateLedger(queryClient)
    },
  })
}
