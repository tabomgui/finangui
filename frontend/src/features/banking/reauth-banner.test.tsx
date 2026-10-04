import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { BankConnection } from '@/api/types'
import { ReauthBanner } from './reauth-banner'

function connection(overrides: Partial<BankConnection> = {}): BankConnection {
  return {
    id: 1,
    provider: 'pluggy',
    status: 'active',
    institution_name: 'Nubank',
    institution_logo_url: null,
    last_synced_at: null,
    last_error: null,
    accounts: [],
    pending_accounts: [],
    unlinked_accounts: [],
    ...overrides,
  }
}

describe('ReauthBanner', () => {
  it('não mostra nada sem conexão precisando de reconexão', () => {
    const { container } = render(<ReauthBanner connections={[connection({ status: 'active' })]} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('avisa só das conexões needs_reauth, uma faixa por conexão', () => {
    render(
      <ReauthBanner
        connections={[
          connection({ id: 1, institution_name: 'Nubank', status: 'needs_reauth' }),
          connection({ id: 2, institution_name: 'Inter', status: 'active' }),
          connection({ id: 3, institution_name: 'C6', status: 'needs_reauth' }),
        ]}
      />,
    )

    expect(screen.getByText('O Nubank pediu para reconectar.')).toBeInTheDocument()
    expect(screen.getByText('O C6 pediu para reconectar.')).toBeInTheDocument()
    expect(screen.queryByText(/Inter/)).not.toBeInTheDocument()
  })

  it('chama onReconnect com a conexão ao clicar em Reconectar', () => {
    const onReconnect = vi.fn()
    const target = connection({ id: 9, institution_name: 'Nubank', status: 'needs_reauth' })
    render(<ReauthBanner connections={[target]} onReconnect={onReconnect} />)

    fireEvent.click(screen.getByRole('button', { name: 'Reconectar' }))

    expect(onReconnect).toHaveBeenCalledWith(target)
  })

  it('tem role="status" para o leitor de tela anunciar sozinho', () => {
    render(<ReauthBanner connections={[connection({ status: 'needs_reauth' })]} />)

    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('desabilita "Reconectar" quando reconnectDisabled', () => {
    render(<ReauthBanner connections={[connection({ status: 'needs_reauth' })]} reconnectDisabled />)

    expect(screen.getByRole('button', { name: 'Reconectar' })).toBeDisabled()
  })

  it('esconde o botão "Reconectar" quando o banking está desligado, mas mantém o aviso', () => {
    render(
      <ReauthBanner
        connections={[connection({ institution_name: 'Nubank', status: 'needs_reauth' })]}
        bankingEnabled={false}
      />,
    )

    expect(screen.getByText('O Nubank pediu para reconectar.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reconectar' })).not.toBeInTheDocument()
  })
})
