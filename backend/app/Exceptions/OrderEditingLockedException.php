<?php

namespace App\Exceptions;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * plan-034 — POS staff opened an edit session on the order; customers
 * mustn't slip writes in until staff commits or the 60s soft-lock expires.
 *
 * Throws as a 409 Conflict so the customer-web FE can show a toast like
 * "Nhân viên đang xử lý đơn, vui lòng chờ" without the React Query
 * retry loop kicking in (it treats 409 as a permanent failure).
 */
class OrderEditingLockedException extends HttpException
{
    public function __construct(string $message = 'Order is being edited by staff.')
    {
        parent::__construct(Response::HTTP_CONFLICT, $message);
    }
}
