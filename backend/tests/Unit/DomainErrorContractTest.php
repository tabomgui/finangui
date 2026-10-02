<?php

use App\Domain\Shared\DomainError;

it('toda subclasse concreta de DomainError tem errorCode() constante e em snake_case', function () {
    $files = glob(app_path('Domain/*/Errors/*.php'));

    expect($files)->not->toBeEmpty();

    $tested = 0;

    foreach ($files as $file) {
        $class = 'App\\Domain\\'.basename(dirname($file, 2)).'\\Errors\\'.basename($file, '.php');

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(DomainError::class)) {
            continue;
        }

        $tested++;

        $constructor = $reflection->getConstructor();
        expect($constructor === null || $constructor->getNumberOfRequiredParameters() === 0)
            ->toBeTrue("{$class}::__construct precisa ser chamável sem argumentos, já que a doc de OpenAPI lê errorCode() via newInstanceWithoutConstructor()");

        $code = (new $class)->errorCode();
        $codeViaReflection = $reflection->newInstanceWithoutConstructor()->errorCode();

        expect($code)
            ->toBe($codeViaReflection, "{$class}::errorCode() precisa ser uma constante por classe, não depender do construtor")
            ->toMatch('/^[a-z_]+$/', "{$class}::errorCode() precisa retornar algo em snake_case");
    }

    expect($tested)->toBeGreaterThan(0);
});
