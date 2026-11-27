<?php

namespace App\Jobs;

use App\Models\ContactImport;
use App\Models\Tenant;
use App\Support\ContactImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportContacts implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importId, public string $tenantId) {}

    public function handle(ContactImporter $importer): void
    {
        $startedTenancy = $this->initializeTenant();

        if (! tenancy()->initialized || (string) tenant()->getTenantKey() !== $this->tenantId) {
            return;
        }

        $path = null;

        try {
            $import = ContactImport::query()->find($this->importId);

            if ($import === null || $import->status !== 'pending') {
                return;
            }

            $path = $import->path;
            $tenant = Tenant::query()->find($this->tenantId);

            if ($tenant === null || $tenant->isSuspended()) {
                $import->forceFill([
                    'status' => 'failed',
                    'summary' => $this->failure('This tenant cannot import contacts while it is suspended.'),
                ])->save();

                return;
            }

            if ($import->user_id === null) {
                $import->forceFill([
                    'status' => 'failed',
                    'summary' => $this->failure('The user who started this import no longer exists.'),
                ])->save();

                return;
            }

            try {
                $summary = $importer->import($import->path, (int) $import->user_id);
                $import->forceFill([
                    'status' => $summary['accepted'] ? 'completed' : 'failed',
                    'summary' => $summary,
                ])->save();
            } catch (Throwable $exception) {
                $import->forceFill([
                    'status' => 'failed',
                    'summary' => $this->failure('The import file could not be read.'),
                ])->save();

                report($exception);
            }
        } finally {
            if ($path !== null) {
                Storage::delete($path);
            }

            if ($startedTenancy && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function initializeTenant(): bool
    {
        if (tenancy()->initialized && (string) tenant()->getTenantKey() === $this->tenantId) {
            return false;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $tenant = tenancy()->find($this->tenantId);

        if ($tenant === null) {
            return false;
        }

        tenancy()->initialize($tenant);

        return true;
    }

    /**
     * @return array{created: int, skipped: int, failed: int, accepted: bool, errors: array<int, array{row: int, message: string}>}
     */
    private function failure(string $message): array
    {
        return [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'accepted' => false,
            'errors' => [[
                'row' => 0,
                'message' => $message,
            ]],
        ];
    }
}
