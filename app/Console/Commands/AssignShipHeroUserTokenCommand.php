<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AssignShipHeroUserTokenCommand extends Command
{
    protected $signature = 'shiphero:assign-user-token
                            {email : User email address}
                            {--token= : ShipHero refresh token}
                            {--from-env : Copy SHIPHERO_REFRESH_TOKEN from config onto this user}';

    protected $description = 'Assign an encrypted ShipHero refresh token to a CRM user';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if ($email === '') {
            $this->error('Email is required.');

            return 1;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user === null) {
            $this->error('User not found: '.$this->argument('email'));

            return 1;
        }

        if ($this->option('from-env')) {
            $token = config('services.shiphero.refresh_token');
        } elseif ($this->option('token') !== null && $this->option('token') !== '') {
            $token = $this->option('token');
        } else {
            $token = $this->secret('ShipHero refresh token');
        }

        $token = trim((string) $token);
        if ($token === '') {
            $this->error('Refresh token is empty.');

            return 1;
        }

        $user->shiphero_refresh_token = $token;
        $user->save();

        $this->info('ShipHero refresh token assigned to '.$user->email.' (user #'.$user->id.').');

        return 0;
    }
}
