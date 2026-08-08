<?php

namespace App\Observers;

use App\Models\Employer;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Auth;

class EmployerObserver
{
    /**
     * Write audit log when an employer application is approved or rejected.
     */
    public function updated(Employer $employer): void
    {
        if (! $employer->wasChanged('status')) {
            return;
        }

        $status = $employer->status;
        if (! in_array($status, ['approved', 'rejected'])) {
            return;
        }

        $action = $status === 'approved' ? 'employer_approved' : 'employer_rejected';

        // Resolve actor — may be null during seeding/testing
        $actor     = Auth::user();
        $actorId   = $actor ? (string) $actor->_id : ($employer->reviewed_by ?? 'system');
        $actorName = $actor ? $actor->name : 'system';

        AuditLogService::log(
            action:     $action,
            actorId:    $actorId,
            actorName:  $actorName,
            targetId:   (string) $employer->_id,
            targetType: 'Employer',
            metadata:   ['user_id' => (string) $employer->user_id]
        );
    }
}
