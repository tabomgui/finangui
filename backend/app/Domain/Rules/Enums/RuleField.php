<?php

namespace App\Domain\Rules\Enums;

enum RuleField: string
{
    case Description = 'description';
    case OriginalDescription = 'original_description';
    case Payee = 'payee';
    case Notes = 'notes';
    case Amount = 'amount';
    case Direction = 'direction';
    case AccountId = 'account_id';
    case Date = 'date';

    public function isText(): bool
    {
        return in_array($this, [self::Description, self::OriginalDescription, self::Payee, self::Notes], true);
    }

    /**
     * @return list<RuleOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Description, self::OriginalDescription, self::Payee, self::Notes => [
                RuleOperator::Contains, RuleOperator::NotContains, RuleOperator::StartsWith, RuleOperator::EndsWith,
                RuleOperator::Equals, RuleOperator::NotEquals, RuleOperator::Regex,
            ],
            self::Amount, self::Date => [
                RuleOperator::Equals, RuleOperator::NotEquals, RuleOperator::Gt, RuleOperator::Gte, RuleOperator::Lt, RuleOperator::Lte,
            ],
            self::Direction, self::AccountId => [RuleOperator::Equals, RuleOperator::NotEquals],
        };
    }
}
