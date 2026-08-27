<?php

// =============================================================================
// Deep Notification Tests
//
// Covers:
//   A. Auth & access control
//   B. Listing — response shape, pagination, ordering, isolation
//   C. The core bug — ObjectId vs string user_id storage
//   D. Unread count
//   E. Mark single as read
//   F. Mark all as read
//   G. Observer-driven creation (what actually creates notifications)
//      1. Application created  → employer gets new_application
//      2. Application status changed → seeker gets application_status_changed
//      3. DirectOffer created  → seeker gets direct_offer_received
//      4. Employer approved    → user gets employer_decision (approved)
//      5. Employer rejected    → user gets employer_decision (rejected)
// =============================================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\User;
use MongoDB\BSON\ObjectId;

// ── Shared helpers ────────────────────────────────────────────────────────────

function notif(string $userId, array $extra = []): Notification
{
    return Notification::create(array_merge([
        'user_id' => $userId,
        'type'    => 'broadcast',
        'message' => 'Test',
        'read_at' => null,
    ], $extra));
}

function job(User $employer): JobPost
{
    return JobPost::create([
        'title'       => 'Test Job',
        'description' => 'Desc',
        'employer_id' => (string) $employer->_id,
        'is_active'   => true,
    ]);
}

afterEach(function () {
    Notification::truncate();
});

// =============================================================================
// A. Auth & access control
// =============================================================================

test('all notification endpoints require authentication', function (string $method, string $uri) {
    $this->{$method . 'Json'}($uri)->assertStatus(401);
})->with([
    ['get',  '/api/notifications'],
    ['get',  '/api/notifications/unread-count'],
    ['post', '/api/notifications/read-all'],
    ['post', '/api/notifications/000000000000000000000000/read'],
]);

test('all roles can access notifications', function (string $factory) {
    $user  = User::factory()->{$factory}()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);

    $user->delete();
})->with(['employee', 'employer', 'admin']);

// =============================================================================
// B. Listing — response shape, pagination, ordering, isolation
// =============================================================================

test('index returns correct JSON structure', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    notif($id, ['type' => 'broadcast', 'message' => 'Hello', 'related_entity_id' => 'eid', 'related_entity_type' => 'Application']);

    $res = $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);

    $res->assertJsonStructure([
        'data' => [['id', 'type', 'message', 'read_at', 'related_entity_id', 'related_entity_type', 'created_at']],
        'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page',
    ]);

    $user->delete();
});

test('index returns empty data for user with no notifications', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('data', []);

    $user->delete();
});

test('index does not leak other users notifications', function () {
    $userA = User::factory()->employee()->create();
    $userB = User::factory()->employee()->create();
    $token = auth('api')->login($userA);

    notif((string) $userB->_id);
    notif((string) $userB->_id);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 0);

    $userA->delete();
    $userB->delete();
});

test('index returns notifications ordered newest first', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    $old  = notif($id, ['message' => 'old',    'created_at' => now()->subHours(2)]);
    $mid  = notif($id, ['message' => 'middle', 'created_at' => now()->subHour()]);
    $new  = notif($id, ['message' => 'new',    'created_at' => now()]);

    $data = $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->json('data');

    expect($data[0]['message'])->toBe('new');
    expect($data[1]['message'])->toBe('middle');
    expect($data[2]['message'])->toBe('old');

    $user->delete();
});

test('index paginates correctly', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    foreach (range(1, 5) as $i) {
        notif($id, ['message' => "Notification $i"]);
    }

    $res = $this->withToken($token)->getJson('/api/notifications?per_page=2&page=1')
        ->assertStatus(200);

    expect($res->json('total'))->toBe(5);
    expect($res->json('per_page'))->toBe(2);
    expect($res->json('total_pages'))->toBe(3);
    expect($res->json('current_page'))->toBe(1);
    expect($res->json('next_page'))->toBe(2);
    expect($res->json('prev_page'))->toBeNull();
    expect($res->json('data'))->toHaveCount(2);

    $user->delete();
});

test('index pagination last page has no next_page', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    foreach (range(1, 3) as $i) {
        notif($id);
    }

    $res = $this->withToken($token)->getJson('/api/notifications?per_page=2&page=2')
        ->assertStatus(200);

    expect($res->json('next_page'))->toBeNull();
    expect($res->json('prev_page'))->toBe(1);

    $user->delete();
});

test('notification item has correct field values', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $n = notif((string) $user->_id, [
        'type'                => 'application_status_changed',
        'message'             => 'Status changed to reviewed.',
        'related_entity_id'   => '123abc',
        'related_entity_type' => 'Application',
    ]);

    $item = $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->json('data.0');

    expect($item['id'])->toBe((string) $n->_id);
    expect($item['type'])->toBe('application_status_changed');
    expect($item['message'])->toBe('Status changed to reviewed.');
    expect($item['related_entity_id'])->toBe('123abc');
    expect($item['related_entity_type'])->toBe('Application');
    expect($item['read_at'])->toBeNull();
    expect($item['created_at'])->not->toBeNull();

    $user->delete();
});

// =============================================================================
// C. The core bug — ObjectId vs string user_id
// =============================================================================

test('index finds notifications stored with BSON ObjectId user_id', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    // Simulate storage as ObjectId (the bug scenario)
    Notification::create([
        'user_id' => new ObjectId((string) $user->_id),
        'type'    => 'broadcast',
        'message' => 'Stored as ObjectId',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 1);

    $user->delete();
});

test('index finds notifications stored with string user_id', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    notif((string) $user->_id, ['message' => 'Stored as string']);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 1);

    $user->delete();
});

test('index finds mix of ObjectId and string stored notifications', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $strId = (string) $user->_id;

    notif($strId, ['message' => 'String stored']);

    Notification::create([
        'user_id' => new ObjectId($strId),
        'type'    => 'broadcast',
        'message' => 'ObjectId stored',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 2);

    $user->delete();
});

test('unread-count works for ObjectId stored notifications', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    Notification::create([
        'user_id' => new ObjectId((string) $user->_id),
        'type'    => 'broadcast',
        'message' => 'Unread ObjectId',
        'read_at' => null,
    ]);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('unread_count', 1);

    $user->delete();
});

// =============================================================================
// D. Unread count
// =============================================================================

test('unread-count returns 0 when all notifications are read', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    notif($id, ['read_at' => now()]);
    notif($id, ['read_at' => now()]);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('unread_count', 0);

    $user->delete();
});

test('unread-count counts only unread', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    notif($id);                          // unread
    notif($id);                          // unread
    notif($id, ['read_at' => now()]);    // read

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('unread_count', 2);

    $user->delete();
});

test('unread-count does not include other users unread notifications', function () {
    $userA = User::factory()->employee()->create();
    $userB = User::factory()->employee()->create();
    $token = auth('api')->login($userA);

    notif((string) $userB->_id);
    notif((string) $userB->_id);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('unread_count', 0);

    $userA->delete();
    $userB->delete();
});

// =============================================================================
// E. Mark single notification as read
// =============================================================================

test('mark single notification as read sets read_at', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $n     = notif((string) $user->_id);

    $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertStatus(200)
        ->assertJsonPath('id', (string) $n->_id);

    expect(Notification::find($n->_id)->read_at)->not->toBeNull();

    $user->delete();
});

test('mark single already-read notification is idempotent', function () {
    $user    = User::factory()->employee()->create();
    $token   = auth('api')->login($user);
    $readAt  = now()->subMinutes(5);
    $n       = notif((string) $user->_id, ['read_at' => $readAt]);

    $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertStatus(200);

    // read_at should not be updated
    $fresh = Notification::find($n->_id);
    expect($fresh->read_at->toDateTimeString())->toBe($readAt->toDateTimeString());

    $user->delete();
});

test('mark single notification returns 404 for another users notification', function () {
    $userA = User::factory()->employee()->create();
    $userB = User::factory()->employee()->create();
    $token = auth('api')->login($userA);

    $n = notif((string) $userB->_id);

    $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Notification not found.');

    $userA->delete();
    $userB->delete();
});

test('mark single notification returns 404 for non-existent id', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)
        ->postJson('/api/notifications/000000000000000000000000/read')
        ->assertStatus(404);

    $user->delete();
});

test('mark single read returns correct response shape', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $n     = notif((string) $user->_id, [
        'type'                => 'broadcast',
        'related_entity_id'   => 'eid',
        'related_entity_type' => 'Application',
    ]);

    $res = $this->withToken($token)
        ->postJson("/api/notifications/{$n->_id}/read")
        ->assertStatus(200);

    $res->assertJsonStructure(['id', 'type', 'message', 'read_at', 'related_entity_id', 'related_entity_type', 'created_at']);
    expect($res->json('read_at'))->not->toBeNull();

    $user->delete();
});

// =============================================================================
// F. Mark all as read
// =============================================================================

test('mark-all-read sets read_at on all unread notifications', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    notif($id);
    notif($id);
    notif($id);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('updated', 3)
        ->assertJsonPath('message', 'All notifications marked as read.');

    $unread = Notification::where('user_id', $id)->whereNull('read_at')->count();
    expect($unread)->toBe(0);

    $user->delete();
});

test('mark-all-read skips already read notifications', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $id    = (string) $user->_id;

    notif($id);
    notif($id, ['read_at' => now()]);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('updated', 1);

    $user->delete();
});

test('mark-all-read returns 0 when nothing to update', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('updated', 0);

    $user->delete();
});

test('mark-all-read does not affect other users notifications', function () {
    $userA = User::factory()->employee()->create();
    $userB = User::factory()->employee()->create();
    $token = auth('api')->login($userA);

    notif((string) $userB->_id);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('updated', 0);

    // UserB's notification should still be unread
    $bUnread = Notification::where('user_id', (string) $userB->_id)->whereNull('read_at')->count();
    expect($bUnread)->toBe(1);

    $userA->delete();
    $userB->delete();
});

// =============================================================================
// G. Observer-driven notification creation
// =============================================================================

// G1 — New application notifies the employer
test('creating an application notifies the employer with new_application', function () {
    $employer = User::factory()->employer()->create();
    $seeker   = User::factory()->employee()->create();
    $post     = job($employer);

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

    // Cleanup
    Application::where('user_id', (string) $seeker->_id)->delete();
    $post->delete();
    $seeker->delete();
    $employer->delete();
});

// G2 — Application status update notifies the seeker
test('updating application status notifies the seeker with application_status_changed', function () {
    $employer = User::factory()->employer()->create();
    $seeker   = User::factory()->employee()->create();
    $post     = job($employer);

    $app = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    // Clear the new_application notification so we can isolate status-change one
    Notification::where('user_id', (string) $employer->_id)->delete();

    $app->update(['status' => 'reviewed']);

    $n = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'application_status_changed')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('reviewed');
    expect($n->related_entity_type)->toBe('Application');

    Application::where('user_id', (string) $seeker->_id)->delete();
    $post->delete();
    $seeker->delete();
    $employer->delete();
});

test('updating application without status change does not create notification', function () {
    $employer = User::factory()->employer()->create();
    $seeker   = User::factory()->employee()->create();
    $post     = job($employer);

    $app = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    Notification::truncate();

    // Update a non-status field
    $app->update(['cover_letter' => 'Updated cover letter']);

    $n = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'application_status_changed')
        ->first();

    expect($n)->toBeNull();

    Application::where('user_id', (string) $seeker->_id)->delete();
    $post->delete();
    $seeker->delete();
    $employer->delete();
});

// G3 — Direct offer notifies the seeker
test('creating a direct offer notifies the seeker with direct_offer_received', function () {
    $employer = User::factory()->employer()->create();
    $seeker   = User::factory()->employee()->create();
    $post     = job($employer);

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

    DirectOffer::where('job_seeker_id', (string) $seeker->_id)->delete();
    $post->delete();
    $seeker->delete();
    $employer->delete();
});

// G4 — Employer approved
test('approving employer application notifies user with employer_decision approved', function () {
    $user     = User::factory()->employee()->create();
    $employer = Employer::create([
        'user_id' => (string) $user->_id,
        'status'  => 'pending',
    ]);

    $employer->update(['status' => 'approved']);

    $n = Notification::where('user_id', (string) $user->_id)
        ->where('type', 'employer_decision')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('approved');

    $employer->delete();
    $user->delete();
});

// G5 — Employer rejected
test('rejecting employer application notifies user with employer_decision rejected', function () {
    $user     = User::factory()->employee()->create();
    $employer = Employer::create([
        'user_id' => (string) $user->_id,
        'status'  => 'pending',
    ]);

    $employer->update(['status' => 'rejected']);

    $n = Notification::where('user_id', (string) $user->_id)
        ->where('type', 'employer_decision')
        ->first();

    expect($n)->not->toBeNull();
    expect($n->message)->toContain('rejected');

    $employer->delete();
    $user->delete();
});

// G6 — Observer-created notifications are visible via the API
test('observer-created notification is returned by GET /api/notifications', function () {
    $employer = User::factory()->employer()->create();
    $seeker   = User::factory()->employee()->create();
    $token    = auth('api')->login($seeker);
    $post     = job($employer);

    DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $post->_id,
        'message'       => 'Offer for you.',
        'status'        => 'pending',
    ]);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.type', 'direct_offer_received');

    DirectOffer::where('job_seeker_id', (string) $seeker->_id)->delete();
    $post->delete();
    $seeker->delete();
    $employer->delete();
});
