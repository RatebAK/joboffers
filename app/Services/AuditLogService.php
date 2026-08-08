<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    /**
     * Write an immutable audit log entry synchronously.
     *
     * @param string      $action     One of: employer_approved, employer_rejected, broadcast_sent,
     *                                cv_reanalysis_triggered, bulk_employer_onboarded
     * @param string      $actorId    The admin user's _id
     * @param string      $actorName  Denormalised display name of the admin
     * @param string|null $targetId   ID of the affected entity (nullable)
     * @param string|null $targetType Class name of the affected entity e.g. "User", "Employer" (nullable)
     * @param array       $metadata   Arbitrary extra context
     */
    public static function log(
        string $action,
        string $actorId,
        string $actorName,
        ?string $targetId = null,
        ?string $targetType = null,
        array $metadata = []
    ): void {
        AuditLog::create([
            'action'      => $action,
            'actor_id'    => $actorId,
            'actor_name'  => $actorName,
            'target_id'   => $targetId,
            'target_type' => $targetType,
            'metadata'    => $metadata,
        ]);
    }
}
