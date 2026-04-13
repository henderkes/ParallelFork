<?php

namespace Henderkes\ParallelFork\Laravel;

use Henderkes\ParallelFork\Runtime;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel Service Provider that auto-registers atFork handlers.
 *
 * Discovered automatically via composer.json extra.laravel.providers.
 * No application-side configuration required.
 */
final class ParallelForkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Laravel: purge all DB connections after fork so each child
        // gets its own socket instead of sharing the parent's.
        Runtime::atFork('laravel.db', static function () {
            if (\class_exists(\Illuminate\Support\Facades\DB::class, false)) {
                try {
                    \Illuminate\Support\Facades\DB::purge();
                } catch (\Throwable) {
                }
            }
        });
    }
}
