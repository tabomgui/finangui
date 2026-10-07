import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { BankSyncRun } from '@/api/types'
import { SyncRunRow } from './sync-run-row'

function run(overrides: Partial<BankSyncRun> = {}): BankSyncRun {
  return {
    id: 1,
    connection_id: 10,
    trigger: 'scheduled',
    status: 'success',
    started_at: '2026-10-05T14:30:00Z',
    finished_at: '2026-10-05T14:30:42Z',
    refresh_requested: true,
    added_count: 3,
    updated_count: 0,
    bills_count: 0,
    warnings: [],
    ...overrides,
  }
}

describe('SyncRunRow', () => {
  it('mostra gatilho, contagem e duração', () => {
    render(
      <ul>
        <SyncRunRow run={run()} onSelect={vi.fn()} />
      </ul>,
    )

    expect(screen.getByText('Agendada')).toBeInTheDocument()
    expect(screen.getByText('Sucesso')).toBeInTheDocument()
    expect(screen.getByText('3 adicionados')).toBeInTheDocument()
    expect(screen.getByText('42s')).toBeInTheDocument()
  })

  it('mostra "M atualizados" só quando updated_count > 0', () => {
    render(
      <ul>
        <SyncRunRow run={run({ added_count: 5, updated_count: 2 })} onSelect={vi.fn()} />
      </ul>,
    )

    expect(screen.getByText('5 adicionados · 2 atualizados')).toBeInTheDocument()
  })

  it('mostra o erro em vermelho quando a run falhou', () => {
    render(
      <ul>
        <SyncRunRow run={run({ status: 'error', error: 'Credencial recusada pelo banco.', finished_at: '2026-10-05T14:30:10Z' })} onSelect={vi.fn()} />
      </ul>,
    )

    expect(screen.getByText('Erro')).toBeInTheDocument()
    expect(screen.getByText('Credencial recusada pelo banco.')).toBeInTheDocument()
  })

  it('mostra os avisos quando a run terminou parcial', () => {
    render(
      <ul>
        <SyncRunRow
          run={run({ status: 'partial', warnings: ['Não foi possível pedir atualização ao banco.'], finished_at: '2026-10-05T14:30:10Z' })}
          onSelect={vi.fn()}
        />
      </ul>,
    )

    expect(screen.getByText('Com avisos')).toBeInTheDocument()
    expect(screen.getByText('Não foi possível pedir atualização ao banco.')).toBeInTheDocument()
  })

  it('não mostra duração para uma run em andamento', () => {
    render(
      <ul>
        <SyncRunRow run={run({ status: 'running', finished_at: undefined })} onSelect={vi.fn()} />
      </ul>,
    )

    expect(screen.getByText('Em andamento')).toBeInTheDocument()
    expect(screen.queryByText(/\d+s$/)).not.toBeInTheDocument()
  })

  it('aciona onSelect ao clicar na linha', () => {
    const onSelect = vi.fn()
    const target = run({ id: 7 })
    render(
      <ul>
        <SyncRunRow run={target} onSelect={onSelect} />
      </ul>,
    )

    fireEvent.click(screen.getByRole('button'))

    expect(onSelect).toHaveBeenCalledWith(target)
  })
})
