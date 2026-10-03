import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, expectOk, unwrap } from '@/api/client'
import { invalidateImports, invalidateLedger, queryKeys } from '@/api/query-keys'
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
    onSuccess: () => invalidateImports(queryClient),
  })
}

export function useConfirmImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number; body?: ConfirmImportBatchRequest }) =>
      (await unwrap(api.POST('/import-batches/{batch}/confirm', { params: { path: { batch: id } }, body: body ?? {} })))
        .data,
    onSuccess: () => Promise.all([invalidateLedger(queryClient), invalidateImports(queryClient)]),
  })
}

export function useCancelImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await expectOk(api.DELETE('/import-batches/{batch}', { params: { path: { batch: id } } }))
    },
    onSuccess: () => invalidateImports(queryClient),
  })
}

export function useRevertImport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      (await unwrap(api.POST('/import-batches/{batch}/revert', { params: { path: { batch: id } } }))).data,
    onSuccess: () => Promise.all([invalidateLedger(queryClient), invalidateImports(queryClient)]),
  })
}
