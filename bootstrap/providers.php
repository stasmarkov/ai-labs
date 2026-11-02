<?php

use App\Debug\Leaks\LeakLabServiceProvider;
use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    LeakLabServiceProvider::class,
];
