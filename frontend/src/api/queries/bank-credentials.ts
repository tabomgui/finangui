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
    // A resposta já vem no corpo de sucesso (ver onSuccess); não há motivo para guardar o
    // resultado da mutação em cache depois de usado.
    gcTime: 0,
    mutationFn: async (body: SaveBankCredentialsRequest) => (await unwrap(api.PUT('/bank-credentials', { body }))).data,
    onSuccess: (data) => {
      // Grava o resultado direto: evita o "flash" de volta ao estado anterior entre o sucesso
      // da mutação e o refetch disparado pela invalidação abaixo.
      queryClient.setQueryData(queryKeys.bankCredentials(), data)
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
