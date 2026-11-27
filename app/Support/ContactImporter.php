<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Reads a tenant-local CSV. Invalid rows are counted and skipped.
 * Rows whose email already exists are skipped and left unchanged.
 */
final class ContactImporter
{
    /**
     * @var array<int, string>
     */
    private const COLUMNS = [
        'name',
        'email',
        'phone',
        'company',
        'job_title',
        'website',
        'location',
        'tags',
    ];

    /**
     * @return array{created: int, skipped: int, failed: int, accepted: bool, errors: array<int, array{row: int, message: string}>}
     */
    public function import(string $path, ?int $ownerId = null): array
    {
        $absolute = Storage::path($path);

        if (! is_file($absolute)) {
            throw new RuntimeException('The import file is missing.');
        }

        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The import file could not be read.');
        }

        try {
            $header = fgetcsv($handle);

            if (! is_array($header)) {
                return $this->summary(0, 0, 0, false, [[
                    'row' => 1,
                    'message' => 'The file has no header row.',
                ]]);
            }

            $columns = $this->columns($header);

            if (isset($columns['error'])) {
                return $this->summary(0, 0, 0, false, [[
                    'row' => 1,
                    'message' => $columns['error'],
                ]]);
            }

            return $this->rows($handle, $columns['map'], $ownerId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array{map: array<string, int>}|array{error: string}
     */
    private function columns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $label) {
            $name = strtolower(trim((string) $label));
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

            if ($name === '') {
                continue;
            }

            if (! in_array($name, self::COLUMNS, true)) {
                return ['error' => 'Unknown column "'.$name.'". Use name, email, phone, company, job_title, website, location, and tags.'];
            }

            $map[$name] = $index;
        }

        if (! isset($map['name'])) {
            return ['error' => 'The name column is required.'];
        }

        return ['map' => $map];
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int>  $map
     * @return array{created: int, skipped: int, failed: int, accepted: bool, errors: array<int, array{row: int, message: string}>}
     */
    private function rows($handle, array $map, ?int $ownerId): array
    {
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $seen = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (! is_array($row) || $this->blank($row)) {
                continue;
            }

            $data = [];

            foreach ($map as $column => $index) {
                $data[$column] = trim((string) ($row[$index] ?? ''));
            }

            $email = strtolower($data['email'] ?? '');
            $payload = [
                'name' => $data['name'] ?? '',
                'email' => $email === '' ? null : $email,
                'phone' => ($data['phone'] ?? '') === '' ? null : $data['phone'],
                'company' => ($data['company'] ?? '') === '' ? null : $data['company'],
                'job_title' => ($data['job_title'] ?? '') === '' ? null : $data['job_title'],
                'website' => ($data['website'] ?? '') === '' ? null : $data['website'],
                'location' => ($data['location'] ?? '') === '' ? null : $data['location'],
            ];

            $validator = Validator::make($payload, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'company' => ['nullable', 'string', 'max:255'],
                'job_title' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'string', 'max:255'],
                'location' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                $failed++;
                $this->rememberError($errors, $rowNumber, $validator->errors()->first());

                continue;
            }

            if ($payload['email'] !== null && (isset($seen[$payload['email']]) || Contact::query()->where('email', $payload['email'])->exists())) {
                $skipped++;
                $seen[$payload['email']] = true;

                continue;
            }

            $tagNames = $this->tagNames($data['tags'] ?? '');
            $tagError = $this->invalidTag($tagNames);

            if ($tagError !== null) {
                $failed++;
                $this->rememberError($errors, $rowNumber, $tagError);

                continue;
            }

            if ($ownerId === null) {
                $failed++;
                $this->rememberError($errors, $rowNumber, 'The import has no owner.');

                continue;
            }

            try {
                DB::transaction(function () use ($payload, $tagNames, $ownerId): void {
                    $contact = new Contact($payload);
                    $contact->owner_id = $ownerId;
                    $contact->save();
                    $ids = [];

                    foreach ($tagNames as $name) {
                        $ids[] = Tag::query()->firstOrCreate(['name' => $name])->getKey();
                    }

                    if ($ids !== []) {
                        $contact->tags()->sync($ids);
                    }
                });
            } catch (UniqueConstraintViolationException) {
                $skipped++;

                if ($payload['email'] !== null) {
                    $seen[$payload['email']] = true;
                }

                continue;
            }

            if ($payload['email'] !== null) {
                $seen[$payload['email']] = true;
            }

            $created++;
        }

        return $this->summary($created, $skipped, $failed, true, $errors);
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function blank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function tagNames(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $names = [];

        foreach (preg_split('/[;,]/', $value) ?: [] as $name) {
            $name = trim($name);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int, string>  $names
     */
    private function invalidTag(array $names): ?string
    {
        foreach ($names as $name) {
            if (strlen($name) > 50) {
                return 'A tag name cannot be longer than 50 characters.';
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{row: int, message: string}>  $errors
     */
    private function rememberError(array &$errors, int $row, string $message): void
    {
        if (count($errors) < 50) {
            $errors[] = ['row' => $row, 'message' => $message];
        }
    }

    /**
     * @param  array<int, array{row: int, message: string}>  $errors
     * @return array{created: int, skipped: int, failed: int, accepted: bool, errors: array<int, array{row: int, message: string}>}
     */
    private function summary(int $created, int $skipped, int $failed, bool $accepted, array $errors): array
    {
        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'accepted' => $accepted,
            'errors' => $errors,
        ];
    }
}
