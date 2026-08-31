<?php

namespace App\Services\Order\Enums;

enum OrderItemMutation: string
{
    case Add = 'add';
    case Revise = 'revise';
    case Void = 'void';
    case Refund = 'refund';
    case ReplaceToppings = 'replace_toppings';
}
