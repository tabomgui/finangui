<?php

use App\Models\Concerns\MissingUserContext;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ScopedNote;

beforeEach(function () {
    Schema::create('scoped_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('body');
        $table->timestamps();
    });
});

it('preenche user_id com o usuário autenticado', function () {
    $user = actingAsUser();

    $note = ScopedNote::create(['body' => 'oi']);

    expect($note->user_id)->toBe($user->id);
});

it('só retorna registros do usuário autenticado', function () {
    $other = User::factory()->create();
    ScopedNote::create(['user_id' => $other->id, 'body' => 'do outro']);

    actingAsUser();
    ScopedNote::create(['body' => 'meu']);

    expect(ScopedNote::pluck('body')->all())->toBe(['meu']);
});

it('falha fechado quando não há usuário autenticado', function () {
    $a = User::factory()->create();
    ScopedNote::create(['user_id' => $a->id, 'body' => 'a']);

    expect(Auth::hasUser())->toBeFalse();
    expect(fn () => ScopedNote::count())->toThrow(MissingUserContext::class);
});

it('permite bypass explícito com withoutGlobalScopes', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    ScopedNote::create(['user_id' => $a->id, 'body' => 'a']);
    ScopedNote::create(['user_id' => $b->id, 'body' => 'b']);

    expect(ScopedNote::query()->withoutGlobalScopes()->count())->toBe(2);
});
