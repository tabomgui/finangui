import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateNotifications, queryKeys } from '@/api/query-keys'

const PAGE_SIZE = 20
const UNREAD_COUNT_REFETCH_INTERVAL = 5 * 60 * 1000

/** Paginada por cursor, mais recentes primeiro (ver `useTransactions`/`useTransferSuggestions`). */
export function useNotifications() {
  return useInfiniteQuery({
    queryKey: queryKeys.notifications(),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) =>
      unwrap(
        api.GET('/notifications', {
          params: { query: { per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) } },
        }),
      ),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })
}

/**
 * Só a primeira página (`per_page: 1`), pra ler `meta.unread_count` sem carregar a lista: o sino
 * mostra a contagem mesmo fechado. Atualiza sozinha a cada 5 minutos e quando a aba ganha foco
 * (o padrão do `queryClient` desliga `refetchOnWindowFocus`; aqui liga de volta de propósito).
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: queryKeys.notificationsUnreadCount(),
    queryFn: async () => (await unwrap(api.GET('/notifications', { params: { query: { per_page: 1 } } }))).meta.unread_count,
    refetchInterval: UNREAD_COUNT_REFETCH_INTERVAL,
    refetchOnWindowFocus: true,
  })
}

export function useMarkRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      await expectOk(api.POST('/notifications/{notification}/read', { params: { path: { notification: id } } }))
    },
    onSuccess: () => invalidateNotifications(queryClient),
  })
}

export function useMarkAllRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await expectOk(api.POST('/notifications/read-all'))
    },
    onSuccess: () => invalidateNotifications(queryClient),
  })
}
