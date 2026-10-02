import { createBrowserRouter } from 'react-router-dom'
import { AppShell } from '@/components/layout/app-shell'
import { LoginPage } from '@/features/auth/login-page'
import { ProtectedRoute } from '@/features/auth/protected-route'
import { RegisterPage } from '@/features/auth/register-page'
import { ComingSoonPage } from '@/features/misc/coming-soon-page'
import { NotFoundPage } from '@/features/misc/not-found-page'
import { SettingsPage } from '@/features/settings/settings-page'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/cadastro', element: <RegisterPage /> },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppShell />,
        children: [
          { index: true, element: <ComingSoonPage title="Início" /> },
          { path: 'transacoes', element: <ComingSoonPage title="Transações" /> },
          { path: 'transacoes/nova', element: <ComingSoonPage title="Nova transação" /> },
          { path: 'contas', element: <ComingSoonPage title="Contas" /> },
          { path: 'categorias', element: <ComingSoonPage title="Categorias" /> },
          { path: 'tags', element: <ComingSoonPage title="Tags" /> },
          { path: 'configuracoes', element: <SettingsPage /> },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
])
