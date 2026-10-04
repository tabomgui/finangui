import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateImportBatchesList, invalidateImports, invalidateLedger, queryKeys } from '@/api/query-keys'
import type { components } from '@/api/schema'
import type { ImportFormat } from '@/api/types'

type StoreImportBatchRequest = components['schemas']['StoreImportBatchRequest']
type ConfirmImportBatchRequest = components['schemas']['ConfirmImportBatchRequest']

export function useImportBatches(accountId?: number) {
  return useQuery({
    queryKey: queryKeys.importBatches(accountId),
    queryFn: async () =>
      (await unwrap(api.GET('/import-batches', { params: { query: { account_id: accountId } } }))).data,
  })
}

export function useImportBatch(id: number) {
  return useQuery({
    queryKey: queryKeys.importBatch(id),
    queryFn: async () => (await unwrap(api.GET('/import-batches/{batch}', { params: { path: { batch: id } } }))).data,
  })
}

export type UploadStatementBody = { account_id: number; file: File; format?: ImportFormat }

/**
 * Multipart puro: `openapi-fetch` já devolve `FormData` como está quando o corpo é uma instância
 * dela (ver `defaultBodySerializer`), então não precisa de `bodySerializer` nem de tocar no
 * `client.ts` — o middleware de XSRF já roda para qualquer método mutante, igual aos outros POSTs.
 */
export function useUploadStatement() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (body: UploadStatementBody) => {
      const form = new FormData()
      form.set('account_id', String(body.account_id))
      form.set('file', body.file)
      if (body.format) form.set('format', body.format)
      return (await unwrap(api.POST('/import-batches', { body: form as unknown as StoreImportBatchRequest }))).data
    },
    // Pré-popula o cache do detalhe com a prévia já calculada: a tela de prévia abre direto com
    // ela (useImportBatch(id)), sem recalcular a mesma prévia de novo com outra chamada ao servidor.
    onSuccess: async (data) => {
      await invalidateImportBatchesList(queryClient)
      queryClient.setQueryData(queryKeys.importBatch(data.batch.id), data)
    },
  })
}

export function useConfirmImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body?: ConfirmImportBatchRequest }) =>
      (await unwrap(api.POST('/import-batches/{batch}/confirm', { params: { path: { batch: id } }, body: body ?? {} })))
        .data,
    // Remove o detalhe em cache em vez de só invalidar: a tela de prévia navega pra fora assim
    // que a confirmação termina, e um `invalidateQueries` ativo refetchiaria o detalhe por uma
    // fração de segundo antes do unmount, por nada. Ver `invalidateImportBatchesList`.
    onSuccess: (_data, { id }) => {
      queryClient.removeQueries({ queryKey: queryKeys.importBatch(id) })
      return Promise.all([invalidateLedger(queryClient), invalidateImportBatchesList(queryClient)])
    },
  })
}

export function useCancelImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/import-batches/{batch}', { params: { path: { batch: id } } }))
    },
    // Idem useConfirmImport, e por um motivo ainda mais direto aqui: o lote já não existe mais
    // depois do cancelamento, então um `invalidateQueries` ativo buscaria de novo e voltaria 404
    // — a tela de prévia (ainda montada por um instante, a caminho do unmount) mostraria um toast
    // de erro por um cancelamento que, do ponto de vista do usuário, deu certo.
    onSuccess: (_data, id) => {
      queryClient.removeQueries({ queryKey: queryKeys.importBatch(id) })
      return invalidateImportBatchesList(queryClient)
    },
  })
}

export function useRevertImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      (await unwrap(api.POST('/import-batches/{batch}/revert', { params: { path: { batch: id } } }))).data,
    // `onSettled`, não `onSuccess`: se a reversão falhar (ex.: outra aba já reverteu esse lote),
    // o histórico precisa recarregar para mostrar o estado atual, não só quando dá certo.
    onSettled: () => Promise.all([invalidateLedger(queryClient), invalidateImports(queryClient)]),
  })
}
