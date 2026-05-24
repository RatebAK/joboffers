<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for the direct offer flow.
// Covers: sending, validation, duplicates, ownership, listing,
// accept/decline by correct and incorrect seekers, and
// attempting to act on already-resolved offers.
// ============================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function offerEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function offerSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function offerJob(string $employerId): JobPost
{
    return JobPost::create([
        'title'        => 'Offer Test Job',
        'description'  => 'Test.',
        'requirements' => 'Test.',
        'company_name' => 'OfferCo',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => $employerId,
        'is_active'    => true,
    ]);
}

function pendingOffer(string $employerId, string $seekerId, string $jobId): DirectOffer
{
    return DirectOffer::create([
        'employer_id'   => $employerId,
        'job_seeker_id' => $seekerId,
        'job_post_id'   => $jobId,
        'message'       => 'We want you.',
        'status'        => 'pending',
    ]);
}

// ── Sending Offers ────────────────────────────────────────────

test('offer send requires all fields', function () {
    [$employer, $token] = offerEmployer();

    $this->withToken($token)->postJson('/api/employer/offers', [])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['job_seeker_id', 'job_post_id', 'message']]);

    $employer->delete();
});

test('offer send returns 404 for non-existent job post', function () {
    [$employer, $token] = offerEmployer();
    [$seeker]           = offerSeeker();

    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => '000000000000000000000000',
        'message'       => 'Hi.',
    ])->assertStatus(404);

    $seeker->delete();
    $employer->delete();
});

test('offer send returns 422 for non-existent job seeker', function () {
    [$employer, $token] = offerEmployer();
    $job = offerJob((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => '000000000000000000000000',
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Hi.',
    ])->assertStatus(422);

    $job->delete();
    $employer->delete();
});

test('offer send returns 422 when target user is not a job seeker', function () {
    [$employer, $token] = offerEmployer();
    [$otherEmployer]    = offerEmployer();
    $job = offerJob((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $otherEmployer->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Hi.',
    ])->assertStatus(422);

    $job->delete();
    $otherEmployer->delete();
    $employer->delete();
});

test('offer send returns 201 with pending status and offer structure', function () {
    [$employer, $token] = offerEmployer();
    [$seeker]           = offerSeeker();
    $job = offerJob((string) $employer->_id);

    $response = $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Great fit for our team.',
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('offer.status', 'pending')
             ->assertJsonStructure(['message', 'offer' => ['employer_id', 'job_seeker_id', 'job_post_id', 'message', 'status']]);

    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

test('duplicate offer returns 409', function () {
    [$employer, $token] = offerEmployer();
    [$seeker]           = offerSeeker();
    $job = offerJob((string) $employer->_id);
    pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Again.',
    ])->assertStatus(409);

    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

test('employer cannot send offer for another employers job post', function () {
    [$employer, $token] = offerEmployer();
    [$other]            = offerEmployer();
    [$seeker]           = offerSeeker();
    $job = offerJob((string) $other->_id);

    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Sneaky.',
    ])->assertStatus(403);

    $job->delete();
    $seeker->delete();
    $other->delete();
    $employer->delete();
});

// ── Listing Offers ────────────────────────────────────────────

test('employer sent offers list includes job_seeker_name and job_post_title', function () {
    [$employer, $token] = offerEmployer();
    [$seeker]           = offerSeeker();
    $job = offerJob((string) $employer->_id);
    pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $response = $this->withToken($token)->getJson('/api/employer/offers')
                     ->assertStatus(200)
                     ->assertJsonStructure(['offers']);

    $items = $response->json('offers.data');
    expect($items)->not->toBeEmpty();
    expect($items[0])->toHaveKey('job_seeker_name');
    expect($items[0])->toHaveKey('job_post_title');

    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

test('seeker received offers list includes job_post_title and employer_company_name', function () {
    [$employer]         = offerEmployer();
    [$seeker, $token]   = offerSeeker();
    $job = offerJob((string) $employer->_id);
    pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $response = $this->withToken($token)->getJson('/api/job-seeker/offers')
                     ->assertStatus(200)
                     ->assertJsonStructure(['offers']);

    $items = $response->json('offers.data');
    expect($items)->not->toBeEmpty();
    expect($items[0])->toHaveKey('job_post_title');
    expect($items[0])->toHaveKey('employer_company_name');

    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

// ── Accept ────────────────────────────────────────────────────

test('seeker can accept a pending offer and application is auto-created', function () {
    [$employer]       = offerEmployer();
    [$seeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
         ->assertStatus(200)
         ->assertJsonPath('offer.status', 'accepted');

    $application = Application::where('user_id', $seeker->_id)
                               ->where('job_post_id', (string) $job->_id)
                               ->first();
    expect($application)->not->toBeNull();
    expect($application->status)->toBe('pending');

    Application::where('user_id', $seeker->_id)->delete();
    $offer->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

test('seeker cannot accept another seekers offer', function () {
    [$employer]         = offerEmployer();
    [$seeker]           = offerSeeker();
    [$otherSeeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
         ->assertStatus(403);

    $offer->delete();
    $job->delete();
    $seeker->delete();
    $otherSeeker->delete();
    $employer->delete();
});

test('accept returns 404 for non-existent offer', function () {
    [, $token] = offerSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/offers/000000000000000000000000/accept')
         ->assertStatus(404);
});

// ── Decline ───────────────────────────────────────────────────

test('seeker can decline a pending offer', function () {
    [$employer]       = offerEmployer();
    [$seeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
         ->assertStatus(200)
         ->assertJsonPath('offer.status', 'declined');

    expect(DirectOffer::find($offer->_id)->status)->toBe('declined');

    $offer->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});

test('seeker cannot decline another seekers offer', function () {
    [$employer]            = offerEmployer();
    [$seeker]              = offerSeeker();
    [$otherSeeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = pendingOffer((string) $employer->_id, (string) $seeker->_id, (string) $job->_id);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
         ->assertStatus(403);

    $offer->delete();
    $job->delete();
    $seeker->delete();
    $otherSeeker->delete();
    $employer->delete();
});

test('decline returns 404 for non-existent offer', function () {
    [, $token] = offerSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/offers/000000000000000000000000/decline')
         ->assertStatus(404);
});

// ── Already-resolved offer guards ────────────────────────────

test('seeker cannot accept an already accepted offer', function () {
    [$employer]       = offerEmployer();
    [$seeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Already accepted.',
        'status'        => 'accepted',
    ]);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
         ->assertStatus(409);

    $offer->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('seeker cannot decline an already declined offer', function () {
    [$employer]       = offerEmployer();
    [$seeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Already declined.',
        'status'        => 'declined',
    ]);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
         ->assertStatus(409);

    $offer->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('seeker cannot accept a declined offer', function () {
    [$employer]       = offerEmployer();
    [$seeker, $token] = offerSeeker();
    $job = offerJob((string) $employer->_id);
    $offer = DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'Was declined.',
        'status'        => 'declined',
    ]);

    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
         ->assertStatus(409);

    $offer->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});
