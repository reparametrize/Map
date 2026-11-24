<?php

namespace App\Imports;

use App\Models\PreRegisteredUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class PreRegisteredUsersImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public const ALLOWED_DEPARTMENTS = [
        'ADMIN DEPARTMENT',
        'DESIGN DEPARTMENT',
        'EDUCATION DEPARTMENT',
        'FUNDRAISING & NETWORKING DEPARTMENT',
        'FUNDRAISING DEPARTMENT',
        'IT & AI DEPARTMENT',
        'MEDIA DEPARTMENT',
        'RESEARCH DEPARTMENT',
    ];

    public const ALLOWED_POSITIONS = [
        'Co-Head',
        'Co-Head, Senior Leader',
        'Corrdinator',
        'Corrdinator, Junior Leader',
        'Head',
        'Junior',
        'Junior Leader',
        'Junior Leader, Corrdinator',
        'Senior Leader',
        'Senior Leader, Co-Head',
    ];

    public const ALLOWED_LAYERS = [
        'Culture and heritage',
        'Other',
        'building code',
        'data collection and analysis',
        'ecological factor',
        'economic factor',
        'public health',
        'resources management',
        'social factor',
        'technology and infrastructure',
        'urban planning',
    ];

    public const ALLOWED_STATUSES = [
        'Active',
        'Pause',
        'Stop',
    ];

    protected bool $updateExisting;

    protected array $summary = [
        'total_rows' => 0,
        'imported' => 0,
        'updated' => 0,
        'already_registered' => 0,
        'duplicates_in_file' => 0,
        'skipped_invalid' => 0,
    ];

    protected array $errors = [];

    public function __construct(bool $updateExisting = true)
    {
        $this->updateExisting = $updateExisting;
    }

    public function collection(Collection $rows)
    {
        $this->summary['total_rows'] = $rows->count();

        // 1. Collect all non-empty email candidates to pre-fetch DB records in bulk
        $candidateEmails = [];
        foreach ($rows as $row) {
            $raw = is_array($row) ? $row : $row->toArray();
            $email = $this->extractEmail($raw);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $candidateEmails[] = strtolower($email);
            }
        }
        $candidateEmails = array_values(array_unique($candidateEmails));

        // 2. Pre-fetch existing registered users and pre-registered invitations in single queries
        $registeredEmails = !empty($candidateEmails)
            ? User::whereIn('email', $candidateEmails)->pluck('email')->map(fn($e) => strtolower($e))->flip()->all()
            : [];

        $existingPreRegUsers = !empty($candidateEmails)
            ? PreRegisteredUser::whereIn('email', $candidateEmails)->get()->keyBy(fn($u) => strtolower($u->email))
            : collect();

        $seenInFile = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $raw = is_array($row) ? $row : $row->toArray();

            $email = $this->extractEmail($raw);

            // Skip rows where email is empty or null
            if (empty($email) || trim($email) === '') {
                $this->summary['skipped_invalid']++;
                continue;
            }

            $email = strtolower(trim($email));

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->summary['skipped_invalid']++;
                $this->errors[] = "Row {$rowNumber}: Invalid email format ({$email})";
                continue;
            }

            // Skip duplicates within the file itself
            if (isset($seenInFile[$email])) {
                $this->summary['duplicates_in_file']++;
                continue;
            }
            $seenInFile[$email] = true;

            // Check if user has already completed registration in the users table
            if (isset($registeredEmails[$email])) {
                $this->summary['already_registered']++;
                continue;
            }

            $name = $this->extractName($raw, $email);
            $department = $this->sanitizeDepartment($this->extractField($raw, ['department', 'dept']));
            $position = $this->sanitizePosition($this->extractField($raw, ['your_position', 'position', 'role']));
            $layer = $this->sanitizeLayer($this->extractField($raw, ['layer']));
            $status = $this->sanitizeStatus($this->extractField($raw, ['status']));

            $existing = $existingPreRegUsers->get($email);

            if ($existing) {
                if ($this->updateExisting) {
                    $existing->update([
                        'name' => $name,
                        'department' => $department,
                        'position' => $position,
                        'layer' => $layer,
                        'status' => $status,
                    ]);
                    $this->summary['updated']++;
                } else {
                    $this->summary['skipped_existing'] = ($this->summary['skipped_existing'] ?? 0) + 1;
                }
            } else {
                $newRecord = PreRegisteredUser::create([
                    'name' => $name,
                    'email' => $email,
                    'department' => $department,
                    'position' => $position,
                    'layer' => $layer,
                    'status' => $status,
                ]);
                $existingPreRegUsers->put($email, $newRecord);
                $this->summary['imported']++;
            }
        }
    }

    protected function extractEmail(array $row): ?string
    {
        $val = $row['email'] ?? $row['e_mail'] ?? $row['mail'] ?? $row['user_email'] ?? null;
        if ($val === null && isset($row[2]) && is_string($row[2]) && strpos($row[2], '@') !== false) {
            $val = $row[2];
        }
        return is_string($val) ? trim($val) : null;
    }

    protected function extractName(array $row, string $email): string
    {
        $val = $row['name'] ?? $row['full_name'] ?? $row['user_name'] ?? null;
        if ($val === null && isset($row[1])) {
            $val = $row[1];
        }
        $name = is_string($val) ? trim($val) : '';
        return $name !== '' ? $name : explode('@', $email)[0];
    }

    protected function extractField(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== null) {
                return (string) $row[$key];
            }
        }
        return null;
    }

    protected function sanitizeDepartment(?string $val): string
    {
        if (empty($val)) {
            return 'RESEARCH DEPARTMENT';
        }

        $val = trim($val);

        foreach (self::ALLOWED_DEPARTMENTS as $dept) {
            if (strcasecmp($val, $dept) === 0) {
                return $dept;
            }
        }

        $withDept = $val . ' DEPARTMENT';
        foreach (self::ALLOWED_DEPARTMENTS as $dept) {
            if (strcasecmp($withDept, $dept) === 0) {
                return $dept;
            }
        }

        $aliases = [
            'IT & AI' => 'IT & AI DEPARTMENT',
            'IT' => 'IT & AI DEPARTMENT',
            'MEDIA' => 'MEDIA DEPARTMENT',
            'RESEARCH' => 'RESEARCH DEPARTMENT',
            'ADMIN' => 'ADMIN DEPARTMENT',
            'DESIGN' => 'DESIGN DEPARTMENT',
            'EDUCATION' => 'EDUCATION DEPARTMENT',
            'FUNDRAISING' => 'FUNDRAISING DEPARTMENT',
            'FUNDRAISING & NETWORKING' => 'FUNDRAISING & NETWORKING DEPARTMENT',
        ];

        foreach ($aliases as $alias => $dept) {
            if (strcasecmp($val, $alias) === 0) {
                return $dept;
            }
        }

        return 'RESEARCH DEPARTMENT';
    }

    protected function sanitizePosition(?string $val): ?string
    {
        if ($val === null || trim($val) === '' || strtolower(trim($val)) === 'null') {
            return null;
        }

        $val = trim($val);

        foreach (self::ALLOWED_POSITIONS as $pos) {
            if (strcasecmp($val, $pos) === 0) {
                return $pos;
            }
        }

        if (strcasecmp($val, 'Coordinator') === 0) {
            return 'Corrdinator';
        }

        return $val;
    }

    protected function sanitizeLayer(?string $val): string
    {
        if (empty($val)) {
            return 'Other';
        }

        $val = trim($val);

        foreach (self::ALLOWED_LAYERS as $layer) {
            if (strcasecmp($val, $layer) === 0) {
                return $layer;
            }
        }

        return 'Other';
    }

    protected function sanitizeStatus(?string $val): string
    {
        if (empty($val)) {
            return 'Active';
        }

        $val = ucfirst(strtolower(trim($val)));
        return in_array($val, self::ALLOWED_STATUSES, true) ? $val : 'Active';
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
