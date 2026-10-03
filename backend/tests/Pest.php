<?php

use App\Domain\Imports\Support\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function actingAsUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    test()->actingAs($user);

    return $user;
}

/** Conteúdo normalizado de um arquivo em tests/Fixtures/imports. */
function importFixture(string $name): string
{
    return Content::normalize(file_get_contents(base_path("tests/Fixtures/imports/{$name}")));
}

/**
 * Corpo decodificado de um arquivo em tests/Fixtures/pluggy.
 *
 * @return array<string, mixed>
 */
function pluggyFixture(string $name): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(file_get_contents(base_path("tests/Fixtures/pluggy/{$name}")), true);

    return $decoded;
}
