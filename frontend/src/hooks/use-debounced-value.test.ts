import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDebouncedValue } from './use-debounced-value'

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

describe('useDebouncedValue', () => {
  it('só propaga o valor depois do intervalo', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value, 300), {
      initialProps: { value: 'a' },
    })

    rerender({ value: 'ab' })
    expect(result.current).toBe('a')

    act(() => vi.advanceTimersByTime(300))
    expect(result.current).toBe('ab')
  })
})
