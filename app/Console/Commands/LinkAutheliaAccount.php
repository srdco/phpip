<?php

namespace App\Console\Commands;

use App\Models\AutheliaAccount;
use App\Models\User;
use Illuminate\Console\Command;

class LinkAutheliaAccount extends Command
{
    protected $signature = 'authelia:link {login : Existing phpIP login} {subject : Authelia OIDC sub} {--unlink : Remove this account link}';

    protected $description = 'Link an Authelia subject to an existing phpIP account without changing its permissions';

    public function handle(): int
    {
        $issuer = rtrim((string) config('services.authelia.base_url'), '/');
        $subject = $this->argument('subject');
        $user = User::where('login', $this->argument('login'))->first();

        if (! $user || strlen($issuer) > 255 || ! filter_var($issuer, FILTER_VALIDATE_URL)
            || parse_url($issuer, PHP_URL_SCHEME) !== 'https'
            || ! preg_match('/\A[\x21-\x7E]{1,255}\z/', $subject)) {
            $this->error('Provide an existing phpIP login, a configured HTTPS Authelia base URL, and a valid OIDC subject.');

            return self::FAILURE;
        }

        $account = AutheliaAccount::firstOrNew(['issuer' => $issuer, 'subject' => $subject]);
        if ($account->exists && (int) $account->actor_id !== (int) $user->id) {
            $this->error('This subject is linked to another account. Unlink it from that account first.');

            return self::FAILURE;
        }

        if ($this->option('unlink')) {
            if ($account->exists) {
                $account->delete();
            }
            $this->info('Authelia account link removed.');
        } else {
            $account->actor_id = $user->id;
            $account->save();
            $this->info('Authelia account linked. Existing phpIP permissions are unchanged.');
        }

        return self::SUCCESS;
    }
}
