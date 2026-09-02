<?php

// =============================================================================
// AdminMeetingTest — GET /api/admin/meetings and /api/admin/meetings/{id}
// =============================================================================

beforeEach(function () {
    [$this->admin, $this->adminToken]       = userWithToken('admin');
    [$this->employer]                        = userWithToken('employer');
    [$this->seeker]                          = userWithToken('employee');
    $this->employerToken                     = auth('api')->login($this->employer);
});

test('an admin can list all meetings regardless of ownership', function () {
    createMeeting($this->employer, $this->seeker);

    $this->withToken($this->adminToken)
        ->getJson('/api/admin/meetings')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page'])
        ->assertJsonPath('total', 1);
});

test('an admin can view any meeting by id', function () {
    $meeting = createMeeting($this->employer, $this->seeker, ['title' => 'Admin viewable meeting', 'status' => 'accepted']);

    $this->withToken($this->adminToken)
        ->getJson("/api/admin/meetings/{$meeting->_id}")
        ->assertOk()
        ->assertJsonStructure(['meeting'])
        ->assertJsonPath('meeting.title', 'Admin viewable meeting');
});

test('a non-admin cannot list admin meetings', function () {
    $this->withToken($this->employerToken)
        ->getJson('/api/admin/meetings')
        ->assertForbidden();
});

test('a non-admin cannot view an admin meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken(tokenFor('employee'))
        ->getJson("/api/admin/meetings/{$meeting->_id}")
        ->assertForbidden();
});

test('an admin gets 404 for a non-existent meeting', function () {
    $this->withToken($this->adminToken)
        ->getJson('/api/admin/meetings/000000000000000000000000')
        ->assertNotFound();
});
