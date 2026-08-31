<?php

namespace App\Exceptions\Till;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 036 — thrown by ShopTillTrackingService::renderZReport when the
 * caller asks for a Z-report PDF on a session whose status is still
 * `open` or `closing`. Mapped to 422 with code Z_REPORT_NOT_READY in the
 * JSON envelope — admin-web disables the print button when
 * `links.z_report_available === false`, but the backend re-checks the
 * status under-the-hood so direct API calls don't bypass the guard.
 */
class ZReportNotReadyException extends HttpException
{
    public function __construct(string $sessionStatus)
    {
        parent::__construct(
            422,
            "Z-report is only available for settled, expired, or abandoned sessions (status={$sessionStatus}).",
        );
    }

    public function errorCode(): string
    {
        return 'Z_REPORT_NOT_READY';
    }
}
