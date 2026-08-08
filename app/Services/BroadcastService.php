<?php

namespace App\Services;

use App\Jobs\SendBroadcastJob;
use App\Models\User;
use Illuminate\Support\Collection;

class BroadcastService
{
    /**
     * Resolve the set of recipient users from an audience string or explicit IDs.
     * Property 8: resolved set exactly matches the audience definition.
     */
    public function resolveRecipients(string $audience, ?array $userIds): Collection
    {
        if (! empty($userIds)) {
            return User::whereIn('_id', $userIds)->get();
        }

        $all = User::all();

        return match ($audience) {
            'employees' => $all->filter(fn ($u) => in_array('employee', $u->roles ?? [])),
            'employers' => $all->filter(fn ($u) => in_array('employer', $u->roles ?? [])),
            'all'       => $all->filter(fn ($u) => ! in_array('admin', $u->roles ?? [])),
            default     => collect(),
        };
    }

    /**
     * Dispatch SendBroadcastJob for each recipient.
     *
     * @return int Number of recipients the broadcast was dispatched to
     */
    public function dispatch(Collection $recipients, string $subject, string $body): int
    {
        foreach ($recipients as $user) {
            SendBroadcastJob::dispatch((string) $user->_id, $subject, $body);
        }

        return $recipients->count();
    }
}
