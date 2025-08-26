<?php

namespace LaraPack\ModelHistory\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

trait HasCompleteHistory
{
    public static function bootHasCompleteHistory()
    {
        self::creating(function ($model) {
            $userId = Auth::id();

            if ($userId) {
                //--- Created By User ---
                $model->created_by = $userId;
                $model->updated_by = $userId;
            } else {
                //--- Created By System ---
                $model->created_by = 0;
                $model->updated_by = 0;
            }
        });

        self::updating(function ($model) {
            $userId = Auth::id();
            if ($userId) {
                //--- Created By User ---
                $model->updated_by = $userId;
            } else {
                //--- Created By System ---
                $model->updated_by = 0;
            }
        });

        self::deleting(function ($model) {
            $userId = Auth::id();
            $model->deleted_by = $userId;
            $model->deleted_at = Carbon::now();
            $model->save();
        });
    }
}
