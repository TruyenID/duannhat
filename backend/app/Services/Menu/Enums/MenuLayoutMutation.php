<?php

namespace App\Services\Menu\Enums;

enum MenuLayoutMutation: string
{
    case ReorderSections = 'reorder_sections';
    case ReorderProducts = 'reorder_products';
    case ReorderLayout = 'reorder_layout';
    case ReplaceLayout = 'replace_layout';
}
