<?php

use App\Models\User;
use App\Support\UserContext;
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

it('escopa as consultas pelo usuário durante a execução', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    ScopedNote::create(['user_id' => $a->id, 'body' => 'a']);
    ScopedNote::create(['user_id' => $b->id, 'body' => 'b']);

    $bodies = UserContext::run($a, fn () => ScopedNote::pluck('body')->all());

    expect($bodies)->toBe(['a']);
});

it('preenche user_id de registros criados dentro do contexto', function () {
    $a = User::factory()->create();

    $note = UserContext::run($a, fn () => ScopedNote::create(['body' => 'x']));

    expect($note->user_id)->toBe($a->id);
});

it('restaura a ausência de usuário depois de rodar', function () {
    $a = User::factory()->create();

    UserContext::run($a, fn () => null);

    expect(Auth::hasUser())->toBeFalse();
});

it('restaura o usuário anterior, inclusive em contextos aninhados', function () {
    $outer = User::factory()->create();
    $inner = User::factory()->create();

    UserContext::run($outer, function () use ($outer, $inner) {
        UserContext::run($inner, fn () => expect(Auth::id())->toBe($inner->id));
        expect(Auth::id())->toBe($outer->id);
    });

    expect(Auth::hasUser())->toBeFalse();
});

it('restaura o estado mesmo quando o closure lança exceção', function () {
    $a = User::factory()->create();

    expect(fn () => UserContext::run($a, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Auth::hasUser())->toBeFalse();
});
