import { QueryClient, QueryObserver } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { meKey, resetSession } from './auth'

describe('resetSession', () => {
  it('remove as demais queries em cache e zera o usuário atual, sem perder quem observa a sessão', () => {
    const queryClient = new QueryClient()
    const otherKey = ['accounts'] as const

    queryClient.setQueryData(meKey, {
      id: 1,
      name: 'Dev',
      email: 'dev@finangui.test',
      avatar: null,
      has_password: true,
      google_linked: false,
    })
    queryClient.setQueryData(otherKey, [{ id: 1 }])

    const observer = new QueryObserver(queryClient, { queryKey: meKey })
    const seen: unknown[] = []
    const unsubscribe = observer.subscribe((result) => seen.push(result.data))

    resetSession(queryClient)

    expect(queryClient.getQueryData(otherKey)).toBeUndefined()
    expect(queryClient.getQueryData(meKey)).toBeNull()
    expect(seen.at(-1)).toBeNull()

    unsubscribe()
  })
})
