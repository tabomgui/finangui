import type { ComponentType } from 'react'
import { createBrowserRouter } from 'react-router-dom'
import { AppShell } from '@/components/layout/app-shell'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { LoginPage } from '@/features/auth/login-page'
import { ProtectedRoute } from '@/features/auth/protected-route'
import { RegisterPage } from '@/features/auth/register-page'
import { NotFoundPage } from '@/features/misc/not-found-page'

/** Adapta um módulo com export nomeado ao formato `lazy` do react-router. */
function lazyPage<K extends string>(load: () => Promise<Record<K, ComponentType>>, name: K) {
  return async () => ({ Component: (await load())[name] })
}

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/cadastro', element: <RegisterPage /> },
  {
    element: <ProtectedRoute />,
    hydrateFallbackElement: <FullPageSpinner />,
    children: [
      {
        element: <AppShell />,
        children: [
          { index: true, lazy: lazyPage(() => import('@/features/dashboard/dashboard-page'), 'DashboardPage') },
          { path: 'transacoes', lazy: lazyPage(() => import('@/features/transactions/transactions-page'), 'TransactionsPage') },
          {
            path: 'transacoes/nova',
            lazy: lazyPage(() => import('@/features/transactions/transaction-form-page'), 'TransactionFormPage'),
          },
          {
            path: 'transacoes/:id',
            lazy: lazyPage(() => import('@/features/transactions/transaction-form-page'), 'TransactionFormPage'),
          },
          { path: 'cartoes', lazy: lazyPage(() => import('@/features/cards/cards-page'), 'CardsPage') },
          { path: 'cartoes/:id', lazy: lazyPage(() => import('@/features/cards/card-detail-page'), 'CardDetailPage') },
          { path: 'contas', lazy: lazyPage(() => import('@/features/accounts/accounts-page'), 'AccountsPage') },
          { path: 'categorias', lazy: lazyPage(() => import('@/features/categories/categories-page'), 'CategoriesPage') },
          { path: 'tags', lazy: lazyPage(() => import('@/features/tags/tags-page'), 'TagsPage') },
          { path: 'configuracoes', lazy: lazyPage(() => import('@/features/settings/settings-page'), 'SettingsPage') },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
])
