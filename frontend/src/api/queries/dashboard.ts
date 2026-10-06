import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { queryKeys } from '@/api/query-keys'

export function useDashboard(month: string, day?: string) {
  return useQuery({
    queryKey: queryKeys.dashboard(month, day),
    queryFn: async () =>
      (await unwrap(api.GET('/dashboard', { params: { query: { month, ...(day ? { date: day } : {}) } } }))).data,
    placeholderData: keepPreviousData,
  })
}
