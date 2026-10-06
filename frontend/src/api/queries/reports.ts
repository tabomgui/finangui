import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, unwrap } from '@/api/client'
import { queryKeys } from '@/api/query-keys'
import type { ReportBasis } from '@/api/types'

export function useMonthlyReport(from: string, to: string, basis: ReportBasis) {
  return useQuery({
    queryKey: queryKeys.reportMonthly(from, to, basis),
    queryFn: async () => (await unwrap(api.GET('/reports/monthly', { params: { query: { from, to, basis } } }))).data,
    placeholderData: keepPreviousData,
  })
}

export function useCategoryComparison(aFrom: string, aTo: string, bFrom: string, bTo: string, basis: ReportBasis) {
  return useQuery({
    queryKey: queryKeys.reportCategories(aFrom, aTo, bFrom, bTo, basis),
    queryFn: async () =>
      (
        await unwrap(
          api.GET('/reports/categories', {
            params: { query: { a_from: aFrom, a_to: aTo, b_from: bFrom, b_to: bTo, basis } },
          }),
        )
      ).data,
    placeholderData: keepPreviousData,
  })
}

export function useSpendingBreakdown(from: string, to: string) {
  return useQuery({
    queryKey: queryKeys.reportSpending(from, to),
    queryFn: async () => (await unwrap(api.GET('/reports/spending', { params: { query: { from, to } } }))).data,
    placeholderData: keepPreviousData,
  })
}
