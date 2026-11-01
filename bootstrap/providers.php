<?php

use App\Debug\Leaks\LeakLabServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    LeakLabServiceProvider::class,
];
