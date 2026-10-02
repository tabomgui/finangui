import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { User } from '@/api/types'
import { PasswordCard } from './password-card'

const baseUser: User = {
  id: 1,
  name: 'Gui',
  email: 'gui@example.com',
  avatar: null,
  has_password: true,
  google_linked: false,
  primary_currency: 'BRL',
}

function renderCard(user: User) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <PasswordCard user={user} />
    </QueryClientProvider>,
  )
}

describe('PasswordCard', () => {
  it('pede a senha atual quando a conta já tem senha', () => {
    renderCard(baseUser)

    expect(screen.getByLabelText('Senha atual')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Alterar senha' })).toBeInTheDocument()
  })

  it('não pede a senha atual em conta só-Google', () => {
    renderCard({ ...baseUser, has_password: false, google_linked: true })

    expect(screen.queryByLabelText('Senha atual')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Definir senha' })).toBeInTheDocument()
  })
})
