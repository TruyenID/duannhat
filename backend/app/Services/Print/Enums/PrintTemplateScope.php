<?php

declare(strict_types=1);

namespace App\Services\Print\Enums;

/**
 * plan-053 (#1171) — the three layers of DESIGN.md §1.
 *
 * `System` exists as a scope value so a future migration can promote the
 * code-shipped defaults into rows, but the resolver reads layer 0 from
 * `config/print_templates.php` (code), never the database: a brand-new
 * workstation that has never been online must still print (TR-05), which
 * only a definition shipped IN the binary/codebase can guarantee.
 */
enum PrintTemplateScope: string
{
    case System = 'system';
    case Brand = 'brand';
    case Shop = 'shop';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
