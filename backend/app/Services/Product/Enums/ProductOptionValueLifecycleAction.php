<?php

namespace App\Services\Product\Enums;

enum ProductOptionValueLifecycleAction: string
{
    case Archive = 'archive';
    case ForceArchive = 'force_archive';
}
