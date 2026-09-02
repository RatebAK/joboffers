<?php

// =============================================================================
// MeetingListTest — GET /api/meetings and /api/meetings/upcoming
// =============================================================================

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker]                          = userWithToken('employee');
});

test('the meeting list is paginated with the standard shape', function () {
    createMeeting($this->employer, $this->seeker);

    $this->withToken($this->employerToken)
        ->getJson('/api/meetings')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page'])
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('per_page', 15);
});

test('the list can be filtered by status', function () {
    createMeeting($this->employer, $this->seeker, ['status' => 'pending']);
    createMeeting($this->employer, $this->seeker, ['status' => 'accepted', 'proposed_date' => now()->addDays(5)->format('Y-m-d')]);

    $data = $this->withToken($this->employerToken)
        ->getJson('/api/meetings?status=pending')
        ->assertOk()
        ->json('data');

    expect(collect($data)->pluck('status')->unique()->all())->toBe(['pending']);
});

test('the list can be filtered by date range', function () {
    createMeeting($this->employer, $this->seeker, ['proposed_date' => now()->addDays(10)->format('Y-m-d')]);

    $from = now()->addDays(8)->format('Y-m-d');
    $to   = now()->addDays(12)->format('Y-m-d');

    expect(
        $this->withToken($this->employerToken)->getJson("/api/meetings?from_date={$from}&to_date={$to}")->assertOk()->json('total')
    )->toBeGreaterThanOrEqual(1);
});

test('the list can be sorted in descending order', function () {
    createMeeting($this->employer, $this->seeker, ['proposed_date' => now()->addDays(3)->format('Y-m-d')]);
    createMeeting($this->employer, $this->seeker, ['proposed_date' => now()->addDays(10)->format('Y-m-d')]);

    $data = $this->withToken($this->employerToken)
        ->getJson('/api/meetings?sort_direction=desc')
        ->assertOk()
        ->json('data');

    expect($data[0]['proposed_date'])->toBeGreaterThanOrEqual($data[1]['proposed_date']);
});

test('the list is empty when no meetings match the filter', function () {
    $this->withToken($this->employerToken)
        ->getJson('/api/meetings?status=completed')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('total', 0);
});

test('each listed meeting includes the participant', function () {
    createMeeting($this->employer, $this->seeker);

    $data = $this->withToken($this->employerToken)->getJson('/api/meetings')->assertOk()->json('data');

    expect($data[0])->toHaveKey('participant')
        ->and($data[0]['participant'])->toHaveKeys(['name', 'email']);
});

test('the upcoming endpoint returns at most five accepted future meetings', function () {
    for ($i = 1; $i <= 6; $i++) {
        createMeeting($this->employer, $this->seeker, [
            'status'        => 'accepted',
            'proposed_date' => now()->addDays($i + 1)->format('Y-m-d'),
        ]);
    }

    $meetings = $this->withToken($this->employerToken)
        ->getJson('/api/meetings/upcoming')
        ->assertOk()
        ->assertJsonStructure(['meetings'])
        ->json('meetings');

    expect(count($meetings))->toBeLessThanOrEqual(5);
});

test('an unauthenticated meeting list request returns 401', function () {
    $this->getJson('/api/meetings')->assertUnauthorized();
});
