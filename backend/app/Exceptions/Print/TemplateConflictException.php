<?php

declare(strict_types=1);

namespace App\Exceptions\Print;

use Illuminate\Http\JsonResponse;

/**
 * plan-053 (#1171) — the three ways a template write loses a race, all 409:
 *
 *   PRINT_TEMPLATE_IMMUTABLE   editing a published/retired version (TR-08).
 *                              Published is a fact; the way forward is a new
 *                              version, never an in-place edit.
 *   PRINT_TEMPLATE_DRAFT_STALE two people edited the same draft (TR-09). The
 *                              second one reloads and merges BY HAND —
 *                              auto-merging two JSON layouts produces a slip
 *                              neither author designed.
 *   PRINT_TEMPLATE_REBASE_REQUIRED
 *                              publishing a draft whose parent is no longer
 *                              the live version (TR-10) — someone published in
 *                              between and this draft would silently revert it.
 */
class TemplateConflictException extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function immutable(string $status): self
    {
        return new self(
            'PRINT_TEMPLATE_IMMUTABLE',
            "A {$status} template version is immutable — create a new draft from it instead.",
            ['status' => $status],
        );
    }

    public static function staleDraft(?string $expected, ?string $actual): self
    {
        return new self(
            'PRINT_TEMPLATE_DRAFT_STALE',
            'This draft changed since you loaded it. Reload and re-apply your edit.',
            ['expected_updated_at' => $expected, 'actual_updated_at' => $actual],
        );
    }

    public static function rebaseRequired(?string $expectedParent, ?string $livePublished): self
    {
        return new self(
            'PRINT_TEMPLATE_REBASE_REQUIRED',
            'A newer version was published while this draft was open. Rebase the draft onto it before publishing.',
            ['draft_parent_version_id' => $expectedParent, 'current_published_id' => $livePublished],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'data' => $this->context,
        ], 409);
    }
}
