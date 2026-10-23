<?php

namespace App\Models\Scopes;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Fences staff queries to the dealer the portal is showing, and — for staff
 * limited to chosen stores — to those stores' deals, enquiries and campaigns.
 * Buyers, owners and the console have no dealer_id and are not fenced here.
 */
class DealerScope implements Scope
{
    public function apply(Builder $query, Model $model): void
    {
        $signedInUser = Auth::user();

        if (! $signedInUser || ! $signedInUser->dealer_id) {
            return;
        }

        $table = $model->getTable();
        $query->where("{$table}.dealer_id", $signedInUser->portalDealerId() ?? 0);

        if (! $signedInUser->isLimitedToStores()) {
            return;
        }

        $stores = $signedInUser->accessibleRooftopIds();

        match (true) {
            $model instanceof Deal     => $query->whereIn("{$table}.rooftop_id", $stores),
            $model instanceof Lead     => $query->whereIn("{$table}.vehicle_id", Vehicle::query()->select('id')->whereIn('rooftop_id', $stores)),
            $model instanceof Campaign => $query->where(fn (Builder $campaigns) => $campaigns->whereNull("{$table}.rooftop_id")->orWhereIn("{$table}.rooftop_id", $stores)),
            default                    => null,
        };
    }
}
