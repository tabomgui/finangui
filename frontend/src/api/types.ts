import type { components } from './schema'

type Schemas = components['schemas']

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
export type LoginRequest = Schemas['LoginRequest']
export type RegisterRequest = Schemas['RegisterRequest']
export type UpdateProfileRequest = Schemas['UpdateProfileRequest']
