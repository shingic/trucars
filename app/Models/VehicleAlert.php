<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\VehicleAvailableAgain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The bell on a reserved car. When the reservation is cancelled and the car
 * is back on sale, everyone waiting is emailed once (notifyWaiting).
 */
class VehicleAlert extends Model
{
    protected $fillable = ['user_id', 'vehicle_id'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Email everyone waiting on this car — only if it really is available again. */
    public static function notifyWaiting(Vehicle $vehicle): int
    {
        if (! $vehicle->isListed() || $vehicle->isReserved()) {
            return 0;
        }

        $sent = 0;

        self::query()->where('vehicle_id', $vehicle->id)->whereNull('notified_at')->with('user')->each(function (VehicleAlert $alert) use ($vehicle, &$sent) {
            // Marked first so a retry can never email twice.
            $alert->forceFill(['notified_at' => now()])->save();

            if ($alert->user === null) {
                return;
            }

            try {
                Mail::to($alert->user->email)->send(new VehicleAvailableAgain($alert->user, $vehicle));
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Vehicle available email failed', ['vehicle_alert_id' => $alert->id, 'error' => $e->getMessage()]);
            }
        });

        return $sent;
    }
}
