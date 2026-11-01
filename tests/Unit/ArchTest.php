<?php

declare(strict_types=1);

use App\Http\Controllers\ListListingsController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\ShowListingController;
use Illuminate\Database\Eloquent\Model;

arch('controllers are thin invokable classes')
    ->expect([PingController::class, ListListingsController::class, ShowListingController::class])
    ->toBeInvokable()
    ->toBeFinal()
    ->not->toUse(['App\Models', 'App\Repositories', 'Illuminate\Support\Facades\DB']);

arch('services are final readonly and depend on contracts only')
    ->expect('App\Services')
    ->toBeFinal()
    ->toBeReadonly()
    ->not->toUse(['App\Models', 'App\Repositories', 'App\Http', 'Illuminate\Http', 'Illuminate\Support\Facades\DB']);

arch('repositories are final readonly and implement a contract')
    ->expect('App\Repositories')
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Repository')
    ->not->toUse(['App\Services', 'App\Http']);

arch('contracts are interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('DTOs are final readonly')
    ->expect('App\DTOs')
    ->toBeFinal()
    ->toBeReadonly()
    ->not->toUse([Model::class, 'Illuminate\Support\Facades']);

arch('enums are backed enums')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('models are only reached through repositories')
    ->expect('App\Models')
    ->not->toBeUsedIn(['App\Services', 'App\DTOs', 'App\Http\Responses']);

it('declares no static properties in worker-reused layers', function (): void {
    $directories = ['Services', 'Repositories', 'DTOs', 'Http/Responses'];
    $withStatics = [];

    foreach ($directories as $directory) {
        foreach (glob(dirname(__DIR__, 2).'/app/'.$directory.'/*.php') as $file) {
            $class = 'App\\'.str_replace('/', '\\', $directory).'\\'.basename($file, '.php');
            if ((new ReflectionClass($class))->getStaticProperties() !== []) {
                $withStatics[] = $class;
            }
        }
    }

    expect($withStatics)->toBe([]);
});

it('keeps env() out of application code', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app')) as $file) {
        if ($file->isFile() && preg_match('/\benv\(/', file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

arch('MNC-2 lab classes are used only by lab controllers')
    ->expect('App\Debug')
    ->toOnlyBeUsedIn(['App\Debug', 'App\Http\Controllers\DebugLeakController']);
