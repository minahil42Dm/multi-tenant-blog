<?php

namespace App\Console\Commands;

use App\Enums\CentralRole;
use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdministrator extends Command
{
    protected $signature = 'admin:promote
        {email : Email of an existing central user}
        {--role=super_admin : Central role to grant (super_admin or support)}
        {--force : Grant the role even when another central administrator already exists}';

    protected $description = 'Grant one existing central user a central role (super admin by default)';

    public function handle(): int
    {
        $role = CentralRole::tryFrom((string) $this->option('role'));

        if (! $role) {
            $this->error('Unknown role. Use one of: '.implode(', ', array_column(CentralRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No central user exists with that email.');

            return self::FAILURE;
        }

        $anotherAdministratorExists = User::query()
            ->whereNotNull('role')
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($anotherAdministratorExists && ! $this->option('force')) {
            $this->error('A central administrator already exists. Re-run with --force to grant this one user a role.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("Granted {$user->email} the {$role->label()} role.");

        return self::SUCCESS;
    }
}
