<?php

namespace LaraPack\ModelHistory\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

trait HasHistory
{
    public static function bootHasHistory()
    {
        static::created(function ($model) {
            $model->storeHistory('created');
        });

        static::updated(function ($model) {
            $model->storeHistory('updated');
        });

        static::deleted(function ($model) {
            $model->storeHistory('deleted');
        });
    }

    protected function storeHistory(string $action)
    {
        $table = $this->getTable();
        $historyTable = "_history_{$table}";

        $data = $this->getAttributes();
        $data['recorded_at'] = now();
        $data['recorded_by'] = Auth::check() ? Auth::id() : null;
        $data['recorded_action'] = $action;
        $data['recorded_url'] = URL::full();

        try {
            DB::table($historyTable)->insert($data);
        } catch (\Throwable $e) {
            Log::error("Failed to insert history for {$table}: " . $e->getMessage());
        }
    }
}
