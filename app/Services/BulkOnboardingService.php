<?php

namespace App\Services;

use App\Jobs\SendBulkInviteJob;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use League\Csv\Reader;

class BulkOnboardingService
{
    private const REQUIRED_COLUMNS = ['name', 'email', 'company_name'];
    private const VALID_PARTNER_TYPES = ['agency', 'university', 'enterprise'];

    /**
     * Process a CSV file of employer accounts.
     *
     * Property 6: created + skipped = total_rows; no duplicate emails after processing.
     *
     * @return array{total_rows: int, created: int, skipped: int, skipped_rows: array}
     */
    public function process(UploadedFile $file, string $actorId, string $actorName): array
    {
        $csv = Reader::createFromPath($file->getRealPath(), 'r');
        $csv->setHeaderOffset(0);

        $headers = array_map('strtolower', array_map('trim', $csv->getHeader()));

        // Validate required columns exist
        foreach (self::REQUIRED_COLUMNS as $required) {
            if (! in_array($required, $headers)) {
                throw new \InvalidArgumentException("CSV is missing required column: {$required}");
            }
        }

        $records     = iterator_to_array($csv->getRecords());
        $totalRows   = count($records);
        $created     = 0;
        $skipped     = 0;
        $skippedRows = [];

        foreach ($records as $record) {
            // Normalise keys to lowercase
            $row = array_combine(
                array_map('strtolower', array_map('trim', array_keys($record))),
                array_map('trim', array_values($record))
            );

            $name        = $row['name']         ?? '';
            $email       = $row['email']        ?? '';
            $companyName = $row['company_name'] ?? '';
            $partnerType = isset($row['partner_type']) && in_array($row['partner_type'], self::VALID_PARTNER_TYPES)
                ? $row['partner_type']
                : null;

            // Skip rows with missing required fields
            if (empty($name) || empty($email) || empty($companyName)) {
                $skipped++;
                $skippedRows[] = ['email' => $email, 'reason' => 'missing_required_fields'];
                continue;
            }

            // Skip duplicate emails
            if (User::where('email', $email)->exists()) {
                $skipped++;
                $skippedRows[] = ['email' => $email, 'reason' => 'email_exists'];
                continue;
            }

            // Create user with temp password using bcrypt-safe approach
            $tempPassword = Str::random(12);
            try {
                $hashedPassword = \Illuminate\Support\Facades\Hash::make($tempPassword);
            } catch (\Throwable) {
                $hashedPassword = hash('sha256', $tempPassword . 'salt');
            }
            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => $hashedPassword,
                'roles'    => ['employer'],
            ]);

            // Create pending employer record
            Employer::create([
                'user_id'      => (string) $user->_id,
                'status'       => Employer::STATUS_PENDING,
                'partner_type' => $partnerType,
            ]);

            // Dispatch invite email asynchronously
            SendBulkInviteJob::dispatch((string) $user->_id, $companyName, $tempPassword);

            $created++;
        }

        // Write audit log
        AuditLogService::log(
            action:     'bulk_employer_onboarded',
            actorId:    $actorId,
            actorName:  $actorName,
            targetId:   null,
            targetType: null,
            metadata:   ['total_rows' => $totalRows, 'created_count' => $created, 'skipped_count' => $skipped]
        );

        return [
            'total_rows'   => $totalRows,
            'created'      => $created,
            'skipped'      => $skipped,
            'skipped_rows' => $skippedRows,
        ];
    }
}
