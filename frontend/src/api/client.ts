import createClient, { type Middleware } from 'openapi-fetch'
import { toApiError } from './errors'
import type { paths } from './schema'

const MUTATING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

/** Sanctum SPA: o Laravel valida o header X-XSRF-TOKEN contra o cookie XSRF-TOKEN. */
export const xsrfMiddleware: Middleware = {
  onRequest({ request }) {
    if (MUTATING_METHODS.has(request.method)) {
      const token = readCookie('XSRF-TOKEN')
      if (token) request.headers.set('X-XSRF-TOKEN', token)
    }
    return request
  },
}

type Listener = () => void
const unauthorizedListeners = new Set<Listener>()

export function onUnauthorized(listener: Listener): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

/** Qualquer 401 encerra a sessão no cliente; o ProtectedRoute leva para o login. */
export const unauthorizedMiddleware: Middleware = {
  onResponse({ response }) {
    if (response.status === 401) unauthorizedListeners.forEach((listener) => listener())
    return response
  },
}

export const api = createClient<paths>({
  baseUrl: '/api/v1',
  credentials: 'include',
  headers: { Accept: 'application/json' },
})

api.use(xsrfMiddleware, unauthorizedMiddleware)

let pendingCsrf: Promise<void> | null = null

/** Garante o cookie XSRF-TOKEN antes da primeira requisição que altera estado (login, cadastro). */
export function ensureCsrf(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return Promise.resolve()
  pendingCsrf ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    .then(() => undefined)
    .finally(() => {
      pendingCsrf = null
    })
  return pendingCsrf
}

type ApiResult<T> = { data?: T; error?: unknown; response: Response }

/** Devolve o corpo de sucesso ou lança ApiError com status, código e erros de campo. */
export async function unwrap<T>(request: Promise<ApiResult<T>>): Promise<T> {
  const { data, error, response } = await awaitRequest(request)
  if (!response.ok) throw toApiError(response.status, error)
  return data as T
}

/** Para respostas sem corpo (204): só valida o status. */
export async function expectOk(request: Promise<ApiResult<unknown>>): Promise<void> {
  const { error, response } = await awaitRequest(request)
  if (!response.ok) throw toApiError(response.status, error)
}

/** A requisição em si pode rejeitar (ex.: `fetch` falha por falta de rede): sem resposta, status 0. */
async function awaitRequest<T>(request: Promise<ApiResult<T>>): Promise<ApiResult<T>> {
  try {
    return await request
  } catch {
    throw toApiError(0, undefined)
  }
}
