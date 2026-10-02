import { describe, expect, it } from 'vitest'
import { CATEGORY_ICON_LABELS, CATEGORY_ICONS } from './category-icons'

describe('CATEGORY_ICON_LABELS', () => {
  it('tem um rótulo em português para cada ícone do catálogo', () => {
    for (const name of Object.keys(CATEGORY_ICONS)) {
      expect(CATEGORY_ICON_LABELS[name], `rótulo ausente para "${name}"`).toBeTruthy()
    }
  })
})
