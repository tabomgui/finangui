import { CreditCard, House, Landmark, List, Plus, Settings, Shapes, Tags, type LucideIcon } from 'lucide-react'

export type NavItem = { to: string; label: string; icon: LucideIcon; end?: boolean }

export const primaryNav: NavItem[] = [
  { to: '/', label: 'Início', icon: House, end: true },
  { to: '/transacoes', label: 'Transações', icon: List, end: true },
  { to: '/cartoes', label: 'Cartões', icon: CreditCard },
]

export const newTransactionItem: NavItem = { to: '/transacoes/nova', label: 'Nova transação', icon: Plus }

export const moreNav: NavItem[] = [
  { to: '/contas', label: 'Contas', icon: Landmark },
  { to: '/categorias', label: 'Categorias', icon: Shapes },
  { to: '/tags', label: 'Tags', icon: Tags },
  { to: '/configuracoes', label: 'Configurações', icon: Settings },
]
