import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'
import { ConditionList } from './condition-list'
import { ruleDefaults, type RuleFormValues } from './rule-form-values'

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [] }),
}))

function Harness() {
  const form = useForm<RuleFormValues>({ defaultValues: ruleDefaults() })
  return <ConditionList form={form} />
}

function renderList() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <Harness />
    </QueryClientProvider>,
  )
}

async function chooseOption(triggerLabel: string, optionName: string | RegExp) {
  const trigger = screen.getByLabelText(triggerLabel, { exact: false })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

describe('ConditionList', () => {
  it('parte de uma condição de texto (Descrição contém)', () => {
    renderList()

    expect(screen.getByLabelText('Campo', { exact: false })).toHaveTextContent('Descrição')
    expect(screen.getByLabelText('Operador', { exact: false })).toHaveTextContent('contém')
    expect(screen.getByLabelText('Valor', { exact: false })).not.toHaveAttribute('inputmode', 'decimal')
  })

  it('trocar o campo para um numérico troca o operador e o controle de valor', async () => {
    renderList()

    await chooseOption('Campo', 'Valor')

    expect(screen.getByLabelText('Operador', { exact: false })).toHaveTextContent('é igual a')
    expect(screen.getByLabelText('Valor', { exact: false })).toHaveAttribute('inputmode', 'decimal')
  })

  it('mantém o operador quando ele continua válido no campo novo', async () => {
    renderList()

    // "Descrição" (contains) → "Favorecido": contains continua um operador de texto válido.
    await chooseOption('Campo', 'Favorecido')

    expect(screen.getByLabelText('Operador', { exact: false })).toHaveTextContent('contém')
  })

  it('adiciona e remove uma condição', () => {
    renderList()

    expect(screen.getAllByLabelText('Remover condição', { exact: false })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'Adicionar condição' }))
    expect(screen.getAllByLabelText('Remover condição', { exact: false })).toHaveLength(2)

    fireEvent.click(screen.getAllByLabelText('Remover condição', { exact: false })[0])
    expect(screen.getAllByLabelText('Remover condição', { exact: false })).toHaveLength(1)
  })

  it('adiciona um grupo com sua própria condição e permite adicionar mais dentro dele', () => {
    renderList()

    fireEvent.click(screen.getByRole('button', { name: 'Adicionar grupo' }))

    expect(screen.getByText(/Grupo: casar/)).toBeInTheDocument()
    expect(screen.getAllByLabelText('Remover condição', { exact: false })).toHaveLength(2)
    expect(screen.getByLabelText('Remover grupo', { exact: false })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Adicionar condição ao grupo' }))
    expect(screen.getAllByLabelText('Remover condição', { exact: false })).toHaveLength(3)
  })
})
