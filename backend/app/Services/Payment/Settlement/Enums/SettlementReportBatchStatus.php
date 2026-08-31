<?php

namespace App\Services\Payment\Settlement\Enums;

/** Plan-050 — per-file import audit status (all-or-nothing per file, S-03). */
enum SettlementReportBatchStatus: string
{
    case Imported = 'imported';
    case PartiallyMatched = 'partially_matched';
    case Failed = 'failed';
}
