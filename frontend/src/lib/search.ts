import { defaultFilter } from 'cmdk'

const COMBINING_MARKS = /[\u0300-\u036f]/g

/** Remove acentos (NFD + marcas combinantes) e normaliza para minúsculas, para buscas que ignoram acentuação. */
export function normalizeSearch(text: string): string {
  return text.normalize('NFD').replace(COMBINING_MARKS, '').toLowerCase()
}

/**
 * Filtro para o `filter` do `<Command>` (cmdk) que ignora acentuação: normaliza `value`,
 * `search` e `keywords` e delega a pontuação para o `defaultFilter` do próprio cmdk.
 */
export function accentInsensitiveFilter(value: string, search: string, keywords?: string[]): number {
  return defaultFilter(normalizeSearch(value), normalizeSearch(search), keywords?.map(normalizeSearch))
}
