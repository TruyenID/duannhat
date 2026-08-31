<?php

use App\Providers\AppServiceProvider;
use App\Providers\CustomerServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\McpServiceProvider;
use App\Providers\OmnifyServiceProvider;
use App\Providers\OrderServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\ProductServiceProvider;

return [
    AppServiceProvider::class,
    McpServiceProvider::class,
    OmnifyServiceProvider::class,
    CustomerServiceProvider::class,
    ProductServiceProvider::class,
    OrderServiceProvider::class,
    PaymentServiceProvider::class,
    InventoryServiceProvider::class,
];
