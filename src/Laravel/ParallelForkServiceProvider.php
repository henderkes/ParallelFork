<?php

namespace Henderkes\ParallelFork\Laravel;

use Henderkes\ParallelFork\Runtime;
use Illuminate\Support\ServiceProvider;

final class ParallelForkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Runtime::class, function () {
            $runtime = new Runtime;
            $runtime->before(name: 'laravel.db', child: static function () {
                if (\class_exists(\Illuminate\Support\Facades\DB::class, false)) {
                    try {
                        \Illuminate\Support\Facades\DB::purge();
                    } catch (\Throwable) {
                    }
                }
            });

            return $runtime;
        });
    }
}
