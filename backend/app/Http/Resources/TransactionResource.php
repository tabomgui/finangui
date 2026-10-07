<?php

namespace App\Http\Resources;

use App\Domain\Transactions\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
final class TransactionResource extends JsonResource
{
    /** Só setado por TransferResource (ver withDeletesOnlyThisLeg()); omitido fora desse contexto. */
    private ?bool $deletesOnlyThisLeg = null;

    /**
     * Regra de App\Domain\Transactions\Actions\DeleteTransaction: excluir esta perna só apaga
     * ela (a outra desliga e volta a ser lançamento comum) quando a OUTRA perna veio do banco
     * (`external_id` preenchido); senão, excluir qualquer uma apaga as duas. Calculado aqui pelo
     * chamador (que tem as duas pernas) para o frontend usar no texto de confirmação em vez de
     * reimplementar a regra.
     */
    public function withDeletesOnlyThisLeg(bool $value): self
    {
        $this->deletesOnlyThisLeg = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'color' => $this->account->color,
                'icon' => $this->account->icon,
            ]),
            'date' => $this->date->toDateString(),
            'amount' => $this->amount->cents,
            'direction' => $this->direction,
            'currency' => $this->currency,
            'description' => $this->description,
            'original_description' => $this->original_description,
            'description_locked' => $this->description_locked,
            'notes' => $this->notes,
            'payee' => $this->payee,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'parent_id' => $this->category->parent_id,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                'color' => $this->category->color,
                'is_transfer' => $this->category->is_transfer,
                // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que parent_id/parent são nullable; em runtime uma categoria raiz não tem parent)
                'is_transfer_effective' => (bool) ($this->category->is_transfer || ($this->category->parent?->is_transfer ?? false)),
            ] : null),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'status' => $this->status,
            'source' => $this->source,
            'categorized_by' => $this->categorized_by,
            'categorization' => $this->categorization(),
            'is_ignored' => $this->is_ignored,
            'ignored_reason' => $this->when($this->ignored_reason !== null, fn () => $this->ignored_reason),
            'transfer_id' => $this->transfer_id,
            'statement_id' => $this->statement_id,
            // Pagamento de fatura reconhecido (App\Domain\Cards\Actions\AssignStatement,
            // App\Domain\Banking\Actions\ReconcileCardPayments): nunca é cobrança/estorno
            // da fatura, mesmo sendo uma entrada no cartão.
            'is_card_payment' => $this->card_payment_statement_id !== null,
            'installment' => $this->relationLoaded('installmentPlan') && $this->installmentPlan ? [
                'plan_id' => $this->installmentPlan->id,
                'number' => (int) $this->installment_number,
                'total' => $this->installmentPlan->installments,
            ] : null,
            // Omitida quando não há recorrência (não só null): ver CLAUDE.md
            // sobre Readable<T> do openapi-fetch.
            'recurrence' => $this->when($this->relationLoaded('recurrence') && $this->recurrence !== null, fn () => [
                'id' => $this->recurrence->id,
                'description' => $this->recurrence->description,
            ]),
            'deletes_only_this_leg' => $this->when($this->deletesOnlyThisLeg !== null, fn (): bool => (bool) $this->deletesOnlyThisLeg),
        ];
    }

    /**
     * Decompõe categorized_by ("manual", "history", "pluggy" ou
     * "rule:{id}") para o frontend não precisar fazer parsing de string.
     * Sem categoria (category_id nulo — ex.: a categoria foi excluída
     * depois, o que zera category_id mas não categorized_by) ou valor
     * desconhecido: nulo. O id da regra é validado por um padrão estrito
     * (sem zero à esquerda, até 19 dígitos) para nunca estourar um int.
     *
     * `rule_id` só existe quando `source` é `rule` (omitido nos outros casos,
     * nunca `null`): uma propriedade tipada só como `null` desaparece do lado
     * do cliente — o `Readable<T>` do openapi-fetch remove qualquer chave cujo
     * tipo seja exatamente `null` (`NonNullable<null>` vira `never`).
     *
     * @return array{source: 'manual'|'history'|'pluggy'}|array{source: 'rule', rule_id: int}|null
     */
    private function categorization(): ?array
    {
        if ($this->category_id === null) {
            return null;
        }

        $categorizedBy = $this->categorized_by;
        $ruleId = is_string($categorizedBy) && preg_match('/^rule:([1-9]\d{0,18})$/', $categorizedBy, $matches) === 1
            ? (int) $matches[1]
            : 0;

        return match (true) {
            $categorizedBy === 'manual' => ['source' => 'manual'],
            $categorizedBy === 'history' => ['source' => 'history'],
            $categorizedBy === 'pluggy' => ['source' => 'pluggy'],
            $ruleId > 0 => ['source' => 'rule', 'rule_id' => $ruleId],
            default => null,
        };
    }
}
