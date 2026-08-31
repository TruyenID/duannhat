<?php

/**
 * OrderItemTopping Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * Append-only snapshot of a topping selected on a customer_order_item.
 * The shop POS reads chosen toppings via CustomerOrderItemResource's
 * inline `toppings` field (snake_case wire contract); this resource is
 * the canonical schema serializer used wherever the relation is
 * surfaced raw (admin debug tooling, future audit endpoints, …).
 */

namespace App\Http\Resources;

use App\Omnify\Modules\OrderItemTopping\Resources\OrderItemToppingResourceBase;

class OrderItemToppingResource extends OrderItemToppingResourceBase
{
    // Inherits toArray() from OrderItemToppingResourceBase. No project-specific
    // overrides — the snake_case wire contract for POS consumers lives on
    // CustomerOrderItemResource so we can join up the topping_group_name +
    // modifier_type fields without N+1 queries here.
}
