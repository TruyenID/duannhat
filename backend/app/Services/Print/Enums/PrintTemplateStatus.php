<?php

declare(strict_types=1);

namespace App\Services\Print\Enums;

/**
 * plan-053 (#1171) — version lifecycle (DESIGN.md §4).
 *
 *   draft ──(validate)──▶ published ──(explicit retire)──▶ retired
 *
 * `published` is IMMUTABLE (TR-08): the only way to change a published
 * definition is to publish a new version. `retired` removes a version from
 * NEW prints only — the row is kept forever so a reprint of an old job can
 * still render with the version it was printed under (TR-13/TR-28).
 */
enum PrintTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
