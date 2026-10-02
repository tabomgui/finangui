import type { Middleware } from 'openapi-fetch'
import { afterEach, describe, expect, it } from 'vitest'
import { unauthorizedMiddleware, onUnauthorized, xsrfMiddleware } from './client'

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
