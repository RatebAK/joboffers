<?php

// =============================================================================
// DirectOfferTest
//
// Exemplar of the project's test conventions (see tests/README.md):
//   - No manual cleanup: the database is reset before every test.
//   - Shared helpers (userWithToken, createJob) instead of per-file factories.
//   - beforeEach builds the common fixtures; each test asserts one behaviour.
//
// Covers the direct offer flow: sending, validation, duplicates, ownership,
// listing, accept/decline, and guards against acting on resolved offers.
// =============================================================================

use App\Models\Application;
use App\Models\DirectOffer;

/** Create a pending offer from $employer to $seeker for $job. */
function pendingOffer(string $employerId, string $seekerId, string $jobId, array $attributes = []): DirectOffer
{
    return DirectOffer::create(array_merge([
        'employer_id'   => $employerId,
        'job_seeker_id' => $seekerId,
        'job_post_id'   => $jobId,
        'message'       => 'We want you.',
        'status'        => 'pending',
    ], $attributes));
}

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
    $this->job = createJob($this->employer, ['title' => 'Offer Test Job']);
});

// ── Sending offers ───────────────────────────────────────────────────────

test('sending an offer requires all fields', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['job_seeker_id', 'job_post_id', 'message']);
});

test('sending an offer for a non-existent job post returns 404', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => (string) $this->seeker->_id,
            'job_post_id'   => '000000000000000000000000',
            'message'       => 'Hi.',
        ])
        ->assertNotFound();
});

test('sending an offer to a non-existent job seeker returns 422', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => '000000000000000000000000',
            'job_post_id'   => (string) $this->job->_id,
            'message'       => 'Hi.',
        ])
        ->assertStatus(422);
});

test('sending an offer to a user who is not a job seeker returns 422', function () {
    $otherEmployer = createUser('employer');

    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => (string) $otherEmployer->_id,
            'job_post_id'   => (string) $this->job->_id,
            'message'       => 'Hi.',
        ])
        ->assertStatus(422);
});

test('sending a valid offer returns 201 with pending status', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => (string) $this->seeker->_id,
            'job_post_id'   => (string) $this->job->_id,
            'message'       => 'Great fit for our team.',
        ])
        ->assertCreated()
        ->assertJsonPath('offer.status', 'pending')
        ->assertJsonStructure([
            'message',
            'offer' => ['employer_id', 'job_seeker_id', 'job_post_id', 'message', 'status'],
        ]);
});

test('sending a duplicate offer returns 409', function () {
    pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => (string) $this->seeker->_id,
            'job_post_id'   => (string) $this->job->_id,
            'message'       => 'Again.',
        ])
        ->assertStatus(409);
});

test('an employer cannot send an offer for another employers job post', function () {
    $otherEmployer = createUser('employer');
    $othersJob     = createJob($otherEmployer);

    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => (string) $this->seeker->_id,
            'job_post_id'   => (string) $othersJob->_id,
            'message'       => 'Sneaky.',
        ])
        ->assertForbidden();
});

// ── Listing offers ───────────────────────────────────────────────────────

test('the employer sent-offers list includes seeker name and job title', function () {
    pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);

    $items = $this->withToken($this->employerToken)
        ->getJson('/api/employer/offers')
        ->assertOk()
        ->json('offers.data');

    expect($items)->not->toBeEmpty()
        ->and($items[0])->toHaveKeys(['job_seeker_name', 'job_post_title']);
});

test('the seeker received-offers list includes job title and company name', function () {
    pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);

    $items = $this->withToken($this->seekerToken)
        ->getJson('/api/job-seeker/offers')
        ->assertOk()
        ->json('offers.data');

    expect($items)->not->toBeEmpty()
        ->and($items[0])->toHaveKeys(['job_post_title', 'employer_company_name']);
});

// ── Accept ───────────────────────────────────────────────────────────────

test('a seeker can accept a pending offer, auto-creating an application', function () {
    $offer = pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->seekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
        ->assertOk()
        ->assertJsonPath('offer.status', 'accepted');

    $application = Application::where('user_id', $this->seeker->_id)
        ->where('job_post_id', (string) $this->job->_id)
        ->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending');
});

test('a seeker cannot accept another seekers offer', function () {
    $offer = pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);
    $otherSeekerToken = tokenFor('employee');

    $this->withToken($otherSeekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
        ->assertForbidden();
});

test('accepting a non-existent offer returns 404', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/offers/000000000000000000000000/accept')
        ->assertNotFound();
});

test('a seeker cannot accept an already-resolved offer', function () {
    $offer = pendingOffer(
        (string) $this->employer->_id,
        (string) $this->seeker->_id,
        (string) $this->job->_id,
        ['status' => 'accepted'],
    );

    $this->withToken($this->seekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
        ->assertStatus(409);
});

// ── Decline ──────────────────────────────────────────────────────────────

test('a seeker can decline a pending offer', function () {
    $offer = pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->seekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
        ->assertOk()
        ->assertJsonPath('offer.status', 'declined');

    expect(DirectOffer::find($offer->_id)->status)->toBe('declined');
});

test('a seeker cannot decline another seekers offer', function () {
    $offer = pendingOffer((string) $this->employer->_id, (string) $this->seeker->_id, (string) $this->job->_id);
    $otherSeekerToken = tokenFor('employee');

    $this->withToken($otherSeekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
        ->assertForbidden();
});

test('declining a non-existent offer returns 404', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/offers/000000000000000000000000/decline')
        ->assertNotFound();
});

test('a seeker cannot decline an already-resolved offer', function () {
    $offer = pendingOffer(
        (string) $this->employer->_id,
        (string) $this->seeker->_id,
        (string) $this->job->_id,
        ['status' => 'declined'],
    );

    $this->withToken($this->seekerToken)
        ->postJson("/api/job-seeker/offers/{$offer->_id}/decline")
        ->assertStatus(409);
});
