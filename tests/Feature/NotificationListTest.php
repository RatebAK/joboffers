<?php

// Deep coverage of the notification endpoints and the observers that create
// notifications. Covers auth/access control, listing shape/pagination/ordering/
// isolation, the ObjectId-vs-string user_id storage bug, unread count, marking
// single/all as read, and observer-driven creation (applications, status
// changes, direct offers, employer approve/reject).

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\Notification;
use App\Models\User;
use MongoDB\BSON\ObjectId;

// No shared Notification builder exists, so keep a minimal local one.
function notif(string $userId, array $extra = []): Notification
{
    return Notification::create(array_merge([
        'user_id' => $userId,
        'type'    => 'broadcast',
        'message' => 'Test',
        'read_at' => null,
    ], $extra));
}

// ── A. Auth & access control ───────────────────────────────────────

test('all notification endpoints require authentication', function (string $method, string $uri) {
    $this->{$method.'Json'}($uri)->assertUnauthorized();
})->with([
    ['get',  '/api/notifications'],
    ['get',  '/api/notifications/unread-count'],
    ['post', '/api/notifications/read-all'],
    ['post', '/api/notifications/000000000000000000000000/read'],
]);

test('all roles can access notifications', function (string $role) {
    $this->withToken(tokenFor($role))->getJson('/api/notifications')->assertOk();
})->with(['employee', 'employer', 'admin']);

// ── B. Listing — shape, pagination, ordering, isolation ────────────

test('index returns correct JSON structure', function () {
    [$user, $token] = userWithToken('employee');

    notif((string) $user->_id, ['type' => 'broadcast', 'message' => 'Hello', 'related_entity_id' => 'eid', 'related_entity_type' => 'Application']);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'type', 'message', 'read_at', 'related_entity_id', 'related_entity_type', 'created_at']],
            'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page',
        ]);
});

test('index returns empty data for user with no notifications', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonPath('data', []);
});

test('index does not leak other users notifications', function () {
    $other = createUser('employee');
    notif((string) $other->_id);
    notif((string) $other->_id);

    $this->withToken(tokenFor('employee'))->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('index returns notifications ordered newest first', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    notif($id, ['message' => 'old',    'created_at' => now()->subHours(2)]);
    notif($id, ['message' => 'middle', 'created_at' => now()->subHour()]);
    notif($id, ['message' => 'new',    'created_at' => now()]);

    $data = $this->withToken($token)->getJson('/api/notifications')->assertOk()->json('data');

    expect($data[0]['message'])->toBe('new');
    expect($data[1]['message'])->toBe('middle');
    expect($data[2]['message'])->toBe('old');
});

test('index paginates correctly', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    foreach (range(1, 5) as $i) {
        notif($id, ['message' => "Notification $i"]);
    }

    $res = $this->withToken($token)->getJson('/api/notifications?per_page=2&page=1')->assertOk();

    expect($res->json('total'))->toBe(5);
    expect($res->json('per_page'))->toBe(2);
    expect($res->json('total_pages'))->toBe(3);
    expect($res->json('current_page'))->toBe(1);
    expect($res->json('next_page'))->toBe(2);
    expect($res->json('prev_page'))->toBeNull();
    expect($res->json('data'))->toHaveCount(2);
});

test('index pagination last page has no next_page', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    foreach (range(1, 3) as $i) {
        notif($id);
    }

    $res = $this->withToken($token)->getJson('/api/notifications?per_page=2&page=2')->assertOk();

    expect($res->json('next_page'))->toBeNull();
    expect($res->json('prev_page'))->toBe(1);
});

test('notification item has correct field values', function () {
    [$user, $token] = userWithToken('employee');

    $n = notif((string) $user->_id, [
        'type'                => 'application_status_changed',
        'message'             => 'Status changed to reviewed.',
        'related_entity_id'   => '123abc',
        'related_entity_type' => 'Application',
    ]);

    $item = $this->withToken($token)->getJson('/api/notifications')->assertOk()->json('data.0');

    expect($item['id'])->toBe((string) $n->_id);
    expect($item['type'])->toBe('application_status_changed');
    expect($item['message'])->toBe('Status changed to reviewed.');
    expect($item['related_entity_id'])->toBe('123abc');
    expect($item['related_entity_type'])->toBe('Application');
    expect($item['read_at'])->toBeNull();
    expect($item['created_at'])->not->toBeNull();
});

// ── C. The core bug — ObjectId vs string user_id ───────────────────

test('index finds notifications stored with BSON ObjectId user_id', function () {
    [$user, $token] = userWithToken('employee');

    Notification::create([
        'user_id' => new ObjectId((string) $user->_id),
        'type'    => 'broadcast',
        'message' => 'Stored as ObjectId',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('index finds notifications stored with string user_id', function () {
    [$user, $token] = userWithToken('employee');

    notif((string) $user->_id, ['message' => 'Stored as string']);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('index finds mix of ObjectId and string stored notifications', function () {
    [$user, $token] = userWithToken('employee');
    $strId = (string) $user->_id;

    notif($strId, ['message' => 'String stored']);

    Notification::create([
        'user_id' => new ObjectId($strId),
        'type'    => 'broadcast',
        'message' => 'ObjectId stored',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 2);
});

test('unread-count works for ObjectId stored notifications', function () {
    [$user, $token] = userWithToken('employee');

    Notification::create([
        'user_id' => new ObjectId((string) $user->_id),
        'type'    => 'broadcast',
        'message' => 'Unread ObjectId',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
});

// ── D. Unread count ────────────────────────────────────────────────

test('unread-count returns 0 when all notifications are read', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    notif($id, ['read_at' => now()]);
    notif($id, ['read_at' => now()]);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 0);
});

test('unread-count counts only unread', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    notif($id);
    notif($id);
    notif($id, ['read_at' => now()]);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 2);
});

test('unread-count does not include other users unread notifications', function () {
    $other = createUser('employee');
    notif((string) $other->_id);
    notif((string) $other->_id);

    $this->withToken(tokenFor('employee'))->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 0);
});

// ── E. Mark single notification as read ────────────────────────────

test('mark single notification as read sets read_at', function () {
    [$user, $token] = userWithToken('employee');
    $n = notif((string) $user->_id);

    $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertOk()
        ->assertJsonPath('id', (string) $n->_id);

    expect(Notification::find($n->_id)->read_at)->not->toBeNull();
});

test('mark single already-read notification is idempotent', function () {
    [$user, $token] = userWithToken('employee');
    $readAt = now()->subMinutes(5);
    $n = notif((string) $user->_id, ['read_at' => $readAt]);

    $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertOk();

    $fresh = Notification::find($n->_id);
    expect($fresh->read_at->toDateTimeString())->toBe($readAt->toDateTimeString());
});

test('mark single notification returns 404 for another users notification', function () {
    $other = createUser('employee');
    $n = notif((string) $other->_id);

    $this->withToken(tokenFor('employee'))
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertNotFound()
        ->assertJsonPath('message', 'Notification not found.');
});

test('mark single notification returns 404 for non-existent id', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/notifications/000000000000000000000000/read')
        ->assertNotFound();
});

test('mark single read returns correct response shape', function () {
    [$user, $token] = userWithToken('employee');
    $n = notif((string) $user->_id, [
        'type'                => 'broadcast',
        'related_entity_id'   => 'eid',
        'related_entity_type' => 'Application',
    ]);

    $res = $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertOk();

    $res->assertJsonStructure(['id', 'type', 'message', 'read_at', 'related_entity_id', 'related_entity_type', 'created_at']);
    expect($res->json('read_at'))->not->toBeNull();
});

// ── F. Mark all as read ────────────────────────────────────────────

test('mark-all-read sets read_at on all unread notifications', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    notif($id);
    notif($id);
    notif($id);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('updated', 3)
        ->assertJsonPath('message', 'All notifications marked as read.');

    expect(Notification::where('user_id', $id)->whereNull('read_at')->count())->toBe(0);
});

test('mark-all-read skips already read notifications', function () {
    [$user, $token] = userWithToken('employee');
    $id = (string) $user->_id;

    notif($id);
    notif($id, ['read_at' => now()]);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('updated', 1);
});

test('mark-all-read returns 0 when nothing to update', function () {
    $this->withToken(tokenFor('employee'))->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('updated', 0);
});

test('mark-all-read does not affect other users notifications', function () {
    $other = createUser('employee');
    notif((string) $other->_id);

    $this->withToken(tokenFor('employee'))->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('updated', 0);

    expect(Notification::where('user_id', (string) $other->_id)->whereNull('read_at')->count())->toBe(1);
});

// ── G. Observer-driven notification creation ───────────────────────

test('creating an application notifies the employer with new_application', function () {
    $employer = createUser('employer');
    $seeker   = createUser('employee');
    $post     = createJob($employer);

    Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    $n = Notification::where('user_id', (string) $employer->_id)
        ->where('type', 'new_application')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->related_entity_type)->toBe('Application');
});

test('updating application status notifies the seeker with application_status_changed', function () {
    $employer = createUser('employer');
    $seeker   = createUser('employee');
    $post     = createJob($employer);

    $app = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    // Isolate the status-change notification from the new_application one.
    Notification::where('user_id', (string) $employer->_id)->delete();

    $app->update(['status' => 'reviewed']);

    $n = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'application_status_changed')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('reviewed');
    expect($n->related_entity_type)->toBe('Application');
});

test('updating application without status change does not create notification', function () {
    $employer = createUser('employer');
    $seeker   = createUser('employee');
    $post     = createJob($employer);

    $app = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    Notification::query()->delete();

    $app->update(['cover_letter' => 'Updated cover letter']);

    $n = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'application_status_changed')
        ->first();

    expect($n)->toBeNull();
});

test('creating a direct offer notifies the seeker with direct_offer_received', function () {
    $employer = createUser('employer');
    $seeker   = createUser('employee');
    $post     = createJob($employer);

    DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $post->_id,
        'message'       => 'We would like to offer you this position.',
        'status'        => 'pending',
    ]);

    $n = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'direct_offer_received')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->related_entity_type)->toBe('DirectOffer');
});

test('approving employer application notifies user with employer_decision approved', function () {
    $user     = createUser('employee');
    $employer = Employer::create(['user_id' => (string) $user->_id, 'status' => 'pending']);

    $employer->update(['status' => 'approved']);

    $n = Notification::where('user_id', (string) $user->_id)
        ->where('type', 'employer_decision')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('approved');
});

test('rejecting employer application notifies user with employer_decision rejected', function () {
    $user     = createUser('employee');
    $employer = Employer::create(['user_id' => (string) $user->_id, 'status' => 'pending']);

    $employer->update(['status' => 'rejected']);

    $n = Notification::where('user_id', (string) $user->_id)
        ->where('type', 'employer_decision')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('rejected');
});

test('observer-created notification is returned by GET /api/notifications', function () {
    $employer = createUser('employer');
    [$seeker, $token] = userWithToken('employee');
    $post = createJob($employer);

    DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $post->_id,
        'message'       => 'Offer for you.',
        'status'        => 'pending',
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.type', 'direct_offer_received');
});
