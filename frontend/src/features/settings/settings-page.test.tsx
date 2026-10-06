import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BankCredentials, User } from '@/api/types'
import { SettingsPage } from './settings-page'

const baseUser: User = {
  id: 1,
  name: 'Gui',
  email: 'gui@example.com',
  avatar: null,
  has_password: true,
  google_linked: false,
  primary_currency: 'BRL',
  banking_enabled: false,
}

vi.mock('@/api/queries/auth', () => ({ useMe: () => ({ data: baseUser }) }))
vi.mock('./profile-card', () => ({ ProfileCard: () => null }))
vi.mock('./password-card', () => ({ PasswordCard: () => null }))
vi.mock('./google-card', () => ({ GoogleCard: () => null }))
vi.mock('./appearance-card', () => ({ AppearanceCard: () => null }))

let credentialsState: { data: BankCredentials | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/bank-credentials', () => ({
  useBankCredentials: () => ({ ...credentialsState, refetch: vi.fn() }),
  useSaveBankCredentials: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteBankCredentials: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

function renderPage(initialEntry: string) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <SettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  credentialsState = { data: { configured: false, provider: 'pluggy' }, isPending: false, isError: false }
})

describe('SettingsPage', () => {
  it('realça o card da Pluggy quando a URL abre com #pluggy, e some depois de um tempo', () => {
    vi.useFakeTimers()
    try {
      renderPage('/configuracoes#pluggy')

      expect(document.getElementById('pluggy')).toHaveClass('ring-2')

      act(() => {
        vi.advanceTimersByTime(3_000)
      })

      expect(document.getElementById('pluggy')).not.toHaveClass('ring-2')
    } finally {
      vi.useRealTimers()
    }
  })

  it('não realça o card sem o hash #pluggy', () => {
    renderPage('/configuracoes')

    expect(document.getElementById('pluggy')).not.toHaveClass('ring-2')
  })
})
