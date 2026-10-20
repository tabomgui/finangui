import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ensureCsrf, expectOk, unwrap } from '@/api/client'
import { toApiError } from '@/api/errors'
import { invalidateLedger, queryKeys } from '@/api/query-keys'
import type { LoginRequest, RegisterRequest, UpdateProfileRequest, User } from '@/api/types'

export const meKey = ['me'] as const
export const authStatusKey = ['auth-status'] as const

/**
 * Encerra a sessão no cliente: descarta todo cache que dependia do usuário logado, mas preserva
 * a própria query `me` (só zera seu valor) para quem a observa — ex. `ProtectedRoute` — ver a
 * mudança em vez de perder a assinatura. `auth-status` também sobrevive: não depende do usuário
 * logado (ex.: se o cadastro está habilitado), então não precisa ser refeita a cada login/logout.
 */
export function resetSession(queryClient: QueryClient): void {
  queryClient.removeQueries({
    predicate: (query) => query.queryKey[0] !== meKey[0] && query.queryKey[0] !== authStatusKey[0],
  })
  queryClient.setQueryData(meKey, null)
}

export function useMe() {
  return useQuery({
    queryKey: meKey,
    queryFn: async (): Promise<User | null> => {
      const { data, error, response } = await api.GET('/me')
      if (response.status === 401) return null
      if (!response.ok || !data) throw toApiError(response.status, error)
      return data.data
    },
    staleTime: Infinity,
  })
}

export function useAuthStatus() {
  return useQuery({
    queryKey: authStatusKey,
    queryFn: async () => (await unwrap(api.GET('/auth/status'))).data,
    staleTime: 5 * 60_000,
  })
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: LoginRequest) => {
      await ensureCsrf()
      return (await unwrap(api.POST('/auth/login', { body }))).data
    },
    onSuccess: (user) => queryClient.setQueryData(meKey, user),
  })
}

export function useRegister() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: RegisterRequest) => {
      await ensureCsrf()
      return (await unwrap(api.POST('/auth/register', { body }))).data
    },
    onSuccess: (user) => queryClient.setQueryData(meKey, user),
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await expectOk(api.POST('/auth/logout'))
    },
    onSettled: () => resetSession(queryClient),
  })
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: UpdateProfileRequest) => (await unwrap(api.PATCH('/me', { body }))).data,
    onSuccess: (user) => queryClient.setQueryData(meKey, user),
  })
}

export type UpdateProviderCategoriesVariables = {
  use_provider_categories: boolean
  /** Só ao desligar: tira também a categoria dos lançamentos categorizados pelo banco. */
  clear_provider_categories?: boolean
}

/**
 * Liga/desliga "Usar categorias do banco" (`PATCH /me`). Devolve o usuário atualizado e quantos
 * lançamentos perderam a categoria do banco (`meta.cleared_provider_categories`); quando a limpeza
 * foi pedida, invalida o razão — os lançamentos limpos ficam sem categoria. Em qualquer caso a
 * contagem de lançamentos categorizados pelo banco é refeita.
 */
export function useUpdateProviderCategories() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: UpdateProviderCategoriesVariables) => {
      const result = await unwrap(api.PATCH('/me', { body }))
      return { user: result.data, cleared: result.meta.cleared_provider_categories }
    },
    onSuccess: ({ user }, variables) => {
      queryClient.setQueryData(meKey, user)
      // `invalidateLedger` já cobre a contagem (raiz `categorization`); sem limpeza, invalida só ela.
      if (variables.clear_provider_categories) void invalidateLedger(queryClient)
      else void queryClient.invalidateQueries({ queryKey: queryKeys.providerCategorizedCount() })
    },
  })
}
