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
export type Card = Schemas['CardResource']
export type CardStatement = Schemas['CardStatementResource']
export type StatementStatus = Schemas['StatementStatus']
export type InstallmentPlan = Schemas['InstallmentPlanResource']
export type Rule = Schemas['RuleResource']
export type RuleCondition = Rule['conditions'][number]
export type RuleSimpleCondition = Extract<RuleCondition, { field: string }>
export type RuleConditionGroup = Extract<RuleCondition, { conditions: unknown }>
export type RuleAction = Rule['actions'][number]
export type RulePreview = Schemas['RulePreviewResource']
export type LoginRequest = Schemas['LoginRequest']
export type RegisterRequest = Schemas['RegisterRequest']
export type UpdateProfileRequest = Schemas['UpdateProfileRequest']
