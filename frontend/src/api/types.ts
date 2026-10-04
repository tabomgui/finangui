import type { components, paths } from './schema'

type Schemas = components['schemas']

export type DashboardSummary = paths['/dashboard']['get']['responses'][200]['content']['application/json']['data']
export type DashboardAccount = DashboardSummary['accounts'][number]
export type TopCategory = DashboardSummary['top_categories'][number]

export type User = Schemas['UserResource']
export type Account = Schemas['AccountResource']
export type AccountType = Schemas['AccountType']
export type Category = Schemas['CategoryResource']
export type CategoryKind = Schemas['CategoryKind']
export type Tag = Schemas['TagResource']
export type Transaction = Schemas['TransactionResource']
export type TransactionStatus = Schemas['TransactionStatus']
export type TransactionSource = Schemas['TransactionSource']
export type Direction = Schemas['Direction']
export type Transfer = Schemas['TransferResource']
export type TransferSuggestion = Schemas['TransferSuggestionResource']
export type Card = Schemas['CardResource']
export type CardStatement = Schemas['CardStatementResource']
export type StatementStatus = Schemas['StatementStatus']
export type InstallmentPlan = Schemas['InstallmentPlanResource']
export type ImportBatch = Schemas['ImportBatchResource']
export type ImportBatchStatus = Schemas['ImportBatchStatus']
export type ImportFormat = Schemas['ImportFormat']
export type ImportOutcome = Schemas['RowOutcome']
export type ImportPreview = Schemas['ImportPreviewResource']
export type ImportPreviewRow = ImportPreview['rows'][number]
export type Rule = Schemas['RuleResource']
export type RuleCondition = Rule['conditions'][number]
export type RuleSimpleCondition = Extract<RuleCondition, { field: string }>
export type RuleConditionGroup = Extract<RuleCondition, { conditions: unknown }>
export type RuleAction = Rule['actions'][number]
export type RulePreview = Schemas['RulePreviewResource']

// O Scramble documenta conditions/actions das requisições (Store/Update/PreviewRuleRequest) a partir
// das regras de validação, que são deliberadamente soltas (a forma de fato é checada pelo
// RuleDefinitionValidator no backend, não pelas regras do FormRequest) — por isso o OpenAPI emitido
// para o corpo da requisição tem todo campo opcional, achatado (sem discriminar condição de grupo) e
// `value` só como string, nunca `string | number`. `RuleResource` (a *resposta*) não tem esse problema
// — vem do `@property` do model, com union literal e discriminação corretas — então derivamos os
// nomes de campo/operador/ação precisos dali, em vez de duplicar as strings à mão.
export type RuleFieldName = RuleSimpleCondition['field']
export type RuleOperatorName = RuleSimpleCondition['op']
export type RuleActionTypeName = RuleAction['type']

export type RuleConditionInput = { field: RuleFieldName; op: RuleOperatorName; value: string | number }
export type RuleGroupConditionInput = { match: Rule['match']; conditions: RuleConditionInput[] }
export type RuleConditionNodeInput = RuleConditionInput | RuleGroupConditionInput

export type RuleActionInput =
  | { type: 'set_category'; category_id: number }
  | { type: 'set_description'; value: string }
  | { type: 'set_payee'; value: string }
  | { type: 'add_tag'; tag_id: number }
  | { type: 'ignore' }

/** Forma precisa do corpo de uma regra (match/conditions/actions), usada no editor e na prévia. */
export type RuleBody = {
  match: Rule['match']
  conditions: RuleConditionNodeInput[]
  actions: RuleActionInput[]
}
export type Recurrence = Schemas['RecurrenceResource']
export type Frequency = Schemas['Frequency']

export type MonthBudget = Schemas['MonthBudgetResource']
export type BudgetItem = MonthBudget['items'][number]
export type BudgetSource = BudgetItem['source']

export type Goal = Schemas['GoalResource']
export type GoalContribution = Schemas['GoalContributionResource']

export type ReportBasis = Schemas['ReportBasis']
export type MonthlyEvolution = Schemas['MonthlyEvolutionResource']
export type MonthlyEvolutionMonth = MonthlyEvolution['months'][number]
export type CategoryComparison = Schemas['CategoryComparisonResource']
export type CategoryComparisonItem = CategoryComparison['items'][number]

export type BankConnection = Schemas['BankConnectionResource']

export type Notification = Schemas['NotificationResource']
export type ConnectionStatus = Schemas['ConnectionStatus']
export type BankProviderName = Schemas['BankProviderName']
export type ConnectionAccount = BankConnection['accounts'][number]
export type ProviderAccount = Schemas['ProviderAccountResource']
export type LinkAccountsRequest = Schemas['LinkAccountsRequest']
export type ConnectTokenRequest = Schemas['ConnectTokenRequest']

export type LoginRequest = Schemas['LoginRequest']
export type RegisterRequest = Schemas['RegisterRequest']
export type UpdateProfileRequest = Schemas['UpdateProfileRequest']
