import { PageHeader } from '@/components/layout/page-header'

// Editor completo (condições, grupos, ações, prévia ao vivo) é implementado em seguida; por ora
// a rota só existe para a navegação e a lista poderem apontar para ela.
export function RuleEditorPage() {
  return <PageHeader title="Regra" subtitle="Em construção" back="/regras" />
}
