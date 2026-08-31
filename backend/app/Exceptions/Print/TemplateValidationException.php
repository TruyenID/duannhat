<?php

declare(strict_types=1);

namespace App\Exceptions\Print;

use Illuminate\Http\JsonResponse;

/**
 * plan-053 (#1171) — a definition failed publish validation (DESIGN §4).
 *
 * Carries EVERY violation, not just the first: an editor that reports one
 * problem per round-trip is how a brand ends up publishing at 2am. Each
 * violation names a machine-readable `code`, the `path` it applies to and a
 * human message.
 *
 * There is deliberately no counterpart at PRINT time (TR-14): a definition
 * that somehow reaches a printer broken falls back to the system default and
 * logs loudly — a shop must never be unable to print because of a template.
 */
class TemplateValidationException extends \RuntimeException
{
    /** @param list<array{code: string, path: string, message: string}> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The print template definition is not publishable.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PRINT_TEMPLATE_INVALID',
            'errors' => $this->violations,
        ], 422);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->violations, 'code')));
    }
}
