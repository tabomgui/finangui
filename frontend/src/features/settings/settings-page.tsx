import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useMe } from '@/api/queries/auth'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { AppearanceCard } from './appearance-card'
import { BankCredentialsCard } from './bank-credentials-card'
import { GoogleCard } from './google-card'
import { PasswordCard } from './password-card'
import { ProfileCard } from './profile-card'

const PLUGGY_HIGHLIGHT_MS = 2_500

const GOOGLE_RESULTS: Record<string, { type: 'success' | 'error'; message: string }> = {
  linked: { type: 'success', message: 'Conta Google vinculada.' },
  taken: { type: 'error', message: 'Esta conta Google já está vinculada a outro usuário.' },
  already_linked: { type: 'error', message: 'Sua conta já está vinculada a outra conta Google.' },
  failed: { type: 'error', message: 'Não foi possível vincular a conta Google. Tente novamente.' },
}

export function SettingsPage() {
  const { data: user } = useMe()
  const [params, setParams] = useSearchParams()
  const [highlightPluggy, setHighlightPluggy] = useState(false)

  useEffect(() => {
    const result = GOOGLE_RESULTS[params.get('google') ?? '']
    if (!result) return
    toast[result.type](result.message)
    params.delete('google')
    setParams(params, { replace: true })
  }, [params, setParams])

  // Link de outras telas (ex.: "Conectar banco" em Contas, ou o erro de uma conexão sem
  // credenciais) pode trazer o usuário direto para o card da Pluggy via #pluggy; rola até lá e
  // realça por um instante, para não passar em branco no meio dos outros cards da página.
  useEffect(() => {
    if (window.location.hash !== '#pluggy') return
    document.getElementById('pluggy')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    setHighlightPluggy(true)
    const timeout = window.setTimeout(() => setHighlightPluggy(false), PLUGGY_HIGHLIGHT_MS)
    return () => window.clearTimeout(timeout)
  }, [])

  if (!user) return null

  return (
    <>
      <PageHeader title="Configurações" subtitle="Perfil, segurança e aparência" />
      <PageBody className="max-w-2xl">
        <ProfileCard user={user} />
        <PasswordCard user={user} />
        <GoogleCard user={user} />
        <BankCredentialsCard highlighted={highlightPluggy} />
        <AppearanceCard />
      </PageBody>
    </>
  )
}
