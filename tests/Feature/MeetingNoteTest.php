<?php

// =============================================================================
// MeetingNoteTest — POST /api/meetings/{id}/notes
// =============================================================================

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker]                          = userWithToken('employee');
    $this->meeting = createMeeting($this->employer, $this->seeker, ['status' => 'accepted']);
});

test('a participant can add a note', function () {
    $notes = $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", ['content' => 'Please prepare your portfolio.'])
        ->assertCreated()
        ->assertJsonStructure(['meeting' => ['notes']])
        ->json('meeting.notes');

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['content'])->toBe('Please prepare your portfolio.')
        ->and($notes[0]['author_id'])->toBe((string) $this->employer->_id);
});

test('a non-participant cannot add a note', function () {
    $outsiderToken = tokenFor('employee');

    $this->withToken($outsiderToken)
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", ['content' => 'Sneaking a note in.'])
        ->assertForbidden();
});

test('note content cannot be empty or whitespace only', function (string $content) {
    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", ['content' => $content])
        ->assertStatus(422);
})->with(['empty' => '', 'whitespace' => '   ']);

test('note content cannot exceed 2000 characters', function () {
    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", ['content' => str_repeat('a', 2001)])
        ->assertStatus(422);
});

test('adding a note to a non-existent meeting returns 404', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings/000000000000000000000000/notes', ['content' => 'For a ghost meeting.'])
        ->assertNotFound();
});
