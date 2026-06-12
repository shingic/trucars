<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class DealerScope implements Scope
{
    public function apply(Builder $query, Model $model): void
    {
        $signedInUser = Auth::user();

        if ($signedInUser && $signedInUser->dealer_id) {
            $query->where($model->getTable() . '.dealer_id', $signedInUser->dealer_id);
        }
    }
}
