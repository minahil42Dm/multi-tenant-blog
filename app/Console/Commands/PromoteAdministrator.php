<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdministrator extends Command
{
    protected $signature = 'admin:promote
        {email : Email of an existing central user}
        {--force : Promote this one user even when another administrator already exists}';

    protected $description = 'Promote one existing central user to administrator';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No central user exists with that email.');

            return self::FAILURE;
        }

        $anotherAdministratorExists = User::query()
            ->where('is_admin', true)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($anotherAdministratorExists && ! $this->option('force')) {
            $this->error('An administrator already exists. Re-run with --force to promote this one user.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info("Promoted {$user->email} to administrator.");

        return self::SUCCESS;
    }
}
