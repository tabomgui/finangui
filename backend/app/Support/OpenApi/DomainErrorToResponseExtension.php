<?php

namespace App\Support\OpenApi;

use App\Domain\Shared\DomainError;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use ReflectionClass;
use Throwable;

/**
 * Documenta qualquer App\Domain\Shared\DomainError (e subclasses) como HTTP 409
 * com {code, message} (ver DomainError::class e bootstrap/app.php `dontReport`).
 *
 * Sem `reference()`: a resposta fica inline, não vira componente nomeado. Assim,
 * quando uma rota pode lançar mais de uma subclasse, o ResponseExtension do
 * Scramble funde as respostas 409 inline num `anyOf` com o enum de cada uma,
 * em vez de uma sobrescrever a outra (o que aconteceria se fossem referências
 * nomeadas, já que cada referência vira uma entrada separada e a última
 * processada vence na serialização final por código HTTP).
 *
 * O `code` é lido chamando `errorCode()` via reflection (sem construtor), não
 * por uma lista hardcoded de classes: qualquer DomainError novo já sai
 * documentado com o enum certo. Só cai para string livre (sem enum) se a
 * subclasse não puder ser instanciada/refletida.
 */
final class DomainErrorToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(DomainError::class);
    }

    public function toResponse(Type $type): Response
    {
        /** @var ObjectType $type */
        $codeSchema = new OpenApiStringType;

        if ($code = $this->errorCode($type->name)) {
            $codeSchema->enum([$code]);
        }

        $schema = (new OpenApiObjectType)
            ->addProperty('code', $codeSchema)
            ->addProperty('message', new OpenApiStringType)
            ->setRequired(['code', 'message']);

        // Descrição vazia: com mais de uma subclasse por rota, o Scramble junta as
        // descrições de todas com "\n\n" (ver ResponseExtension::mergeResponses),
        // e repetir a mesma frase por `code` só gera ruído.
        return Response::make(409)
            ->setDescription('')
            ->setContent('application/json', Schema::fromType($schema));
    }

    private function errorCode(string $className): ?string
    {
        if (! is_subclass_of($className, DomainError::class)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        if ($reflection->isAbstract()) {
            return null;
        }

        try {
            /** @var DomainError $instance */
            $instance = $reflection->newInstanceWithoutConstructor();

            return $instance->errorCode();
        } catch (Throwable) {
            return null;
        }
    }
}
