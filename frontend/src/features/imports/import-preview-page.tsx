import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'

/** Placeholder: a prévia completa (linhas, resumo, confirmação) é implementada depois. */
export function ImportPreviewPage() {
  return (
    <>
      <PageHeader title="Prévia da importação" back="/importar" />
      <PageBody>
        <p className="text-sm text-muted-foreground">Carregando prévia...</p>
      </PageBody>
    </>
  )
}
