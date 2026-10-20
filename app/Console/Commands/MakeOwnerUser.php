<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class MakeOwnerUser extends Command
{
    protected $signature = 'users:make-owner
        {email : The owner account to create, or an existing customer account to promote}
        {--name= : Name for a new account}
        {--revoke : Turn an owner back into a customer account}';

    protected $description = 'Create or promote a TruCars owner (admin host) account';

    /**
     * Deliberately console-only. role isn't mass-assignable and no screen grants
     * it, so owner access can't be self-served from any web form. A new owner's
     * password is typed here (hidden) and never echoed or logged.
     */
    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($this->option('revoke')) {
            if ($user === null || ! $user->isOwner()) {
                $this->error("{$email} isn't an owner.");

                return self::FAILURE;
            }

            $user->forceFill(['role' => User::ROLE_CUSTOMER])->save();
            AuditLog::record('owner.revoked', $user, ['role' => User::ROLE_OWNER], ['role' => User::ROLE_CUSTOMER]);
            $this->info("{$email} is no longer an owner.");

            return self::SUCCESS;
        }

        if ($user !== null && $user->dealer_id !== null) {
            $this->error("{$email} is dealer staff. Use a separate email for the owner account.");

            return self::FAILURE;
        }

        if ($user === null) {
            $password = (string) $this->secret('Password for the new owner (min 12 characters)');

            if (mb_strlen($password) < 12) {
                $this->error('The password must be at least 12 characters.');

                return self::FAILURE;
            }

            $user = new User([
                'name'     => $this->option('name') ?: 'TruCars owner',
                'email'    => $email,
                'password' => $password,
            ]);
            $user->email_verified_at = now();
        }

        $previousRole = $user->exists ? $user->role : null;

        // Owners hold every permission implicitly, so no explicit list is kept.
        $user->forceFill(['role' => User::ROLE_OWNER, 'permissions' => null, 'suspended_at' => null])->save();
        AuditLog::record('owner.granted', $user, ['role' => $previousRole], ['role' => User::ROLE_OWNER]);

        $this->info("{$email} is a TruCars owner. Sign in to the Command Centre at " . route('admin.login') . '.');

        return self::SUCCESS;
    }
}
