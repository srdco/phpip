<?php

namespace App\Services;

use App\Models\Actor;
use App\Models\AutheliaAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AutheliaAccounts
{
    public function resolve(string $issuer, string $subject, ?string $name): ?User
    {
        try {
            return DB::transaction(function () use ($issuer, $subject, $name) {
                $account = AutheliaAccount::where('issuer', $issuer)->where('subject', $subject)->first();
                if ($account) {
                    return $account->user;
                }

                $role = config('services.authelia.default_role');
                if (! config('services.authelia.auto_provision') || ! in_array($role, ['DBRO', 'DBRW', 'CLI'], true)) {
                    return null;
                }

                // A username/email match must never take over an existing account.
                // phpIP logins are limited to 16 characters, so use a separate local
                // identifier and retain the stable issuer + subject as the SSO identity.
                $actor = Actor::create([
                    'name' => mb_substr(trim($name ?? '') ?: 'Authelia user', 0, 100),
                    'login' => 'sso_'.bin2hex(random_bytes(6)),
                    'default_role' => $role,
                    'phy_person' => 1,
                ]);
                AutheliaAccount::create([
                    'issuer' => $issuer,
                    'subject' => $subject,
                    'actor_id' => $actor->id,
                ]);

                return User::find($actor->id);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent first sign-ins must converge on one account. The failed
            // transaction rolls back its actor as well as its identity link.
            return AutheliaAccount::where('issuer', $issuer)->where('subject', $subject)->first()?->user;
        }
    }
}
