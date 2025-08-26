<?php

namespace LaraPack\ModelHistory;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;

class ModelHistoryProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
            database_path('migrations/histories/*'),
        ]);

        // Tambahkan macro ke Blueprint
        Blueprint::macro('completeHistory', function () {
            /** @var \Illuminate\Database\Schema\Blueprint $this */
            $this->timestamps();
            $this->softDeletes();
            $this->unsignedBigInteger('created_by')->nullable();
            $this->unsignedBigInteger('updated_by')->nullable();
            $this->unsignedBigInteger('deleted_by')->nullable();

            $this->index(['created_at']);
            $this->index(['updated_at']);
            $this->index(['deleted_at']);
            $this->index(['created_by']);
            $this->index(['updated_by']);
            $this->index(['deleted_by']);
        });
    }
}
