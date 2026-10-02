import type { Middleware } from 'openapi-fetch'
import { afterEach, describe, expect, it } from 'vitest'
import { ApiError } from './errors'
import { expectOk, unauthorizedMiddleware, onUnauthorized, unwrap, xsrfMiddleware } from './client'

type RequestParams = Parameters<NonNullable<Middleware['onRequest']>>[0]
type ResponseParams = Parameters<NonNullable<Middleware['onResponse']>>[0]

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('xsrfMiddleware', () => {
  it('envia o token XSRF em requisições que alteram estado', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const request = new Request('http://localhost/api/v1/tags', { method: 'POST' })

    const result = (await xsrfMiddleware.onRequest!({ request } as RequestParams)) as Request

    expect(result.headers.get('X-XSRF-TOKEN')).toBe('abc=')
  })

  it('não envia o token em GET', async () => {
    document.cookie = 'XSRF-TOKEN=abc'
    const request = new Request('http://localhost/api/v1/tags')

    const result = (await xsrfMiddleware.onRequest!({ request } as RequestParams)) as Request

    expect(result.headers.get('X-XSRF-TOKEN')).toBeNull()
  })
})

describe('falha de rede', () => {
  it('unwrap lança ApiError(0) quando a requisição rejeita (ex.: fetch indisponível)', async () => {
    const request = Promise.reject(new TypeError('Failed to fetch'))

    await expect(unwrap(request)).rejects.toSatisfy((error: unknown) => {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(0)
      expect((error as ApiError).message).toBe('Sem conexão com o servidor.')
      return true
    })
  })

  it('expectOk lança ApiError(0) quando a requisição rejeita', async () => {
    const request = Promise.reject(new TypeError('Failed to fetch'))

    await expect(expectOk(request)).rejects.toSatisfy((error: unknown) => {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(0)
      expect((error as ApiError).message).toBe('Sem conexão com o servidor.')
      return true
    })
  })
})

describe('unauthorizedMiddleware', () => {
  it('avisa os ouvintes quando a API responde 401', async () => {
    let calls = 0
    const unsubscribe = onUnauthorized(() => {
      calls += 1
    })

    await unauthorizedMiddleware.onResponse!({ response: new Response(null, { status: 401 }) } as ResponseParams)
    await unauthorizedMiddleware.onResponse!({ response: new Response(null, { status: 200 }) } as ResponseParams)
    unsubscribe()

    expect(calls).toBe(1)
  })
})
