import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateBankConnections, queryKeys } from '@/api/query-keys'
import { meKey } from '@/api/queries/auth'
import type { SaveBankCredentialsRequest } from '@/api/types'

export function useBankCredentials() {
  return useQuery({
    queryKey: queryKeys.bankCredentials(),
    queryFn: async () => (await unwrap(api.GET('/bank-credentials'))).data,
  })
}

/**
 * Salva (ou troca) as credenciais da Pluggy do usuário; a API já testa contra o provedor antes
 * de gravar. Sucesso muda `banking_enabled` (ver `UserResource`) e pode liberar conexões que
 * estavam em erro por falta de credenciais — por isso invalida `me` e `bank-connections`, além
 * da própria query de credenciais.
 */
export function useSaveBankCredentials() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: SaveBankCredentialsRequest) => (await unwrap(api.PUT('/bank-credentials', { body }))).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.bankCredentials() })
      queryClient.invalidateQueries({ queryKey: meKey })
      invalidateBankConnections(queryClient)
    },
  })
}

/** Mesma invalidação de `useSaveBankCredentials`: remover também muda `banking_enabled`. */
export function useDeleteBankCredentials() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await expectOk(api.DELETE('/bank-credentials'))
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.bankCredentials() })
      queryClient.invalidateQueries({ queryKey: meKey })
      invalidateBankConnections(queryClient)
    },
  })
}
