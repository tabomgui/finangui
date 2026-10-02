import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import '@testing-library/jest-dom/vitest'

// `globals` do vitest está desligado (testes importam describe/it/expect explicitamente),
// então a limpeza automática do Testing Library entre testes não é detectada; registramos à mão.
afterEach(cleanup)
