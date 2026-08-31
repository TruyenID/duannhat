<?php

namespace App\Services\Payment\Orchestration\Enums;

enum RefundReason: string
{
    case CustomerRequest = 'customer_request';
    case Duplicate = 'duplicate';
    case ItemUnavailable = 'item_unavailable';
    case QualityIssue = 'quality_issue';
    case Fraud = 'fraud';
    case OperatorCorrection = 'operator_correction';
}
