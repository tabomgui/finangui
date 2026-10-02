<?php

namespace App\Console\Commands;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Importa categorias do finangui-js antigo (saída TSV do `mysql --batch`).
 * Hierarquias com mais de um nível são achatadas: toda categoria filha vai
 * para debaixo da raiz da sua árvore. Tipo `investment` vira despesa marcada
 * como transferência.
 */
final class ImportLegacyCategoriesCommand extends Command
{
    protected $signature = 'legacy:import-categories
        {file : TSV exportado do finangui-js (mysql --batch)}
        {email : Email do usuário destino}';

    protected $description = 'Importa categorias do finangui-js antigo';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Arquivo ilegível: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        $legacyToNew = [];
        $created = 0;

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                continue;
            }
            $category = $this->upsert($user, $row, null);
            $legacyToNew[$row['id']] = $category;
            $created += (int) $category->wasRecentlyCreated;
        }

        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                continue;
            }
            $rootId = $this->rootOf($row, $rows);
            $root = $rootId !== null ? ($legacyToNew[$rootId] ?? null) : null;
            if ($root === null) {
                $this->warn("Ignorada (pai ausente): {$row['name']}");

                continue;
            }
            $category = $this->upsert($user, $row, $root);
            $created += (int) $category->wasRecentlyCreated;
        }

        $this->info("{$created} categorias criadas.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{id: int, name: string, type: string, color: string|null, parent_id: int|null}>
     */
    private function readRows(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 5) {
                $this->warn("Linha ignorada (formato inválido): {$line}");

                continue;
            }

            [$id, $name, $type, $color, $parentId] = $fields;
            $id = trim($id);
            if (! ctype_digit($id)) {
                $this->warn("Linha ignorada (formato inválido): {$line}");

                continue;
            }

            $name = trim($this->unescape($name));
            if ($name === '') {
                $this->warn("Linha ignorada (formato inválido): {$line}");

                continue;
            }

            $rows[(int) $id] = [
                'id' => (int) $id,
                'name' => $name,
                'type' => trim($type),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) === 1 ? trim($color) : null,
                'parent_id' => trim($parentId) === 'NULL' ? null : (int) $parentId,
            ];
        }

        return $rows;
    }

    /**
     * Desfaz o escape que o `mysql --batch` aplica em tab, newline e barra
     * invertida na saída (ex.: um nome com tab literal sai como `\t`).
     */
    private function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/', fn (array $matches): string => match ($matches[1]) {
            't' => "\t",
            'n' => "\n",
            '\\' => '\\',
            default => $matches[0],
        }, $value) ?? $value;
    }

    /**
     * @param  array{id: int, name: string, type: string, color: string|null, parent_id: int|null}  $row
     * @param  array<int, array{id: int, name: string, type: string, color: string|null, parent_id: int|null}>  $rows
     */
    private function rootOf(array $row, array $rows): ?int
    {
        $seen = [];
        while ($row['parent_id'] !== null) {
            if (! isset($rows[$row['parent_id']]) || isset($seen[$row['id']])) {
                return null;
            }
            $seen[$row['id']] = true;
            $row = $rows[$row['parent_id']];
        }

        return $row['id'];
    }

    /**
     * @param  array{id: int, name: string, type: string, color: string|null, parent_id: int|null}  $row
     */
    private function upsert(User $user, array $row, ?Category $parent): Category
    {
        $kind = $parent !== null ? $parent->kind : ($row['type'] === 'income' ? CategoryKind::Income : CategoryKind::Expense);

        return Category::query()->withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $user->id, 'parent_id' => $parent?->id, 'name' => $row['name']],
            ['kind' => $kind, 'color' => $row['color'], 'is_transfer' => $row['type'] === 'investment'],
        );
    }
}
