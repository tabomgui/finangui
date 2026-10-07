import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { queryKeys } from '@/api/query-keys'

const PAGE_SIZE = 20
const RUNNING_POLL_MS = 3_000

/** true enquanto alguma run já carregada está `running` — mantém o polling leve da lista ligado
 * só enquanto há sync em andamento (ver `useBankSyncRuns`). */
function hasRunningRun(pages: { data: { status: string }[] }[] | undefined): boolean {
  return pages?.some((page) => page.data.some((run) => run.status === 'running')) ?? false
}

export function useBankSyncRuns(connectionId: number) {
  return useInfiniteQuery({
    queryKey: queryKeys.bankSyncRuns(connectionId),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) =>
      unwrap(
        api.GET('/bank-connections/{connection}/sync-runs', {
          params: {
            path: { connection: connectionId },
            query: { per_page: PAGE_SIZE, ...(pageParam ? { cursor: pageParam } : {}) },
          },
        }),
      ),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    refetchInterval: (query) => (hasRunningRun(query.state.data?.pages) ? RUNNING_POLL_MS : false),
  })
}

export function useBankSyncRun(id: number | null) {
  return useQuery({
    queryKey: queryKeys.bankSyncRun(id ?? 0),
    enabled: id !== null,
    queryFn: async () => (await unwrap(api.GET('/bank-sync-runs/{run}', { params: { path: { run: id ?? 0 } } }))).data,
  })
}
