<?php

namespace App\Domain\Categories\Actions;

use App\Domain\Categories\Models\Category;
use App\Models\User;

/**
 * Categorias iniciais (padrão brasileiro). Idempotente: pode rodar mais de uma vez.
 */
final class SeedDefaultCategories
{
    /**
     * @var array<string, array{kind: string, icon: string, color: string, is_transfer?: bool, children?: list<string>}>
     */
    private const DEFAULTS = [
        'Moradia' => ['kind' => 'expense', 'icon' => 'house', 'color' => '#6366f1', 'children' => ['Aluguel', 'Condomínio', 'Energia', 'Água', 'Internet']],
        'Alimentação' => ['kind' => 'expense', 'icon' => 'utensils', 'color' => '#f97316', 'children' => ['Mercado', 'Restaurantes', 'Delivery']],
        'Transporte' => ['kind' => 'expense', 'icon' => 'car', 'color' => '#0ea5e9', 'children' => ['Combustível', 'Aplicativos', 'Transporte público', 'Manutenção']],
        'Saúde' => ['kind' => 'expense', 'icon' => 'heart-pulse', 'color' => '#ef4444', 'children' => ['Farmácia', 'Consultas', 'Plano de saúde']],
        'Educação' => ['kind' => 'expense', 'icon' => 'graduation-cap', 'color' => '#8b5cf6', 'children' => ['Cursos', 'Livros']],
        'Lazer' => ['kind' => 'expense', 'icon' => 'party-popper', 'color' => '#ec4899', 'children' => ['Streaming', 'Viagens', 'Passeios']],
        'Compras' => ['kind' => 'expense', 'icon' => 'shopping-bag', 'color' => '#14b8a6', 'children' => ['Roupas', 'Eletrônicos', 'Casa']],
        'Impostos e taxas' => ['kind' => 'expense', 'icon' => 'landmark', 'color' => '#64748b', 'children' => ['Tarifas bancárias', 'IOF', 'Impostos']],
        'Pets' => ['kind' => 'expense', 'icon' => 'paw-print', 'color' => '#a16207'],
        'Outros' => ['kind' => 'expense', 'icon' => 'ellipsis', 'color' => '#71717a'],
        'Salário' => ['kind' => 'income', 'icon' => 'briefcase', 'color' => '#22c55e'],
        'Freelance' => ['kind' => 'income', 'icon' => 'laptop', 'color' => '#10b981'],
        'Rendimentos' => ['kind' => 'income', 'icon' => 'trending-up', 'color' => '#84cc16'],
        'Reembolsos' => ['kind' => 'income', 'icon' => 'undo-2', 'color' => '#06b6d4'],
        'Outras receitas' => ['kind' => 'income', 'icon' => 'circle-plus', 'color' => '#16a34a'],
        'Transferências' => ['kind' => 'expense', 'icon' => 'arrow-left-right', 'color' => '#94a3b8', 'is_transfer' => true],
        'Pagamento de fatura' => ['kind' => 'expense', 'icon' => 'credit-card', 'color' => '#94a3b8', 'is_transfer' => true],
        'Investimentos' => ['kind' => 'expense', 'icon' => 'piggy-bank', 'color' => '#eab308', 'is_transfer' => true],
    ];

    public function handle(User $user): void
    {
        foreach (self::DEFAULTS as $name => $definition) {
            $parent = Category::query()->withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $user->id, 'parent_id' => null, 'name' => $name],
                [
                    'kind' => $definition['kind'],
                    'icon' => $definition['icon'],
                    'color' => $definition['color'],
                    'is_transfer' => $definition['is_transfer'] ?? false,
                ],
            );

            foreach ($definition['children'] ?? [] as $childName) {
                Category::query()->withoutGlobalScopes()->firstOrCreate(
                    ['user_id' => $user->id, 'parent_id' => $parent->id, 'name' => $childName],
                    ['kind' => $definition['kind'], 'icon' => $definition['icon'], 'color' => $definition['color']],
                );
            }
        }
    }
}
