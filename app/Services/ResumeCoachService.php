<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResumeCoachService
{
    public function chat(string $userId, string $message, ?string $sessionId = null): array
    {
        $apiUrl = config('services.resume_coach.url');

        $payload = [
            'user_id' => $userId,
            'message' => $message,
        ];

        if ($sessionId) {
            $payload['session_id'] = $sessionId;
        }

        try {
            $response = Http::timeout(60)->asForm()->post($apiUrl, $payload);
        } catch (\Throwable $e) {
            Log::error('Resume coach HTTP error', ['error' => $e->getMessage()]);
            throw new CvAnalysisException('Resume coach service unavailable', 502);
        }

        if ($response->failed()) {
            Log::error('Resume coach service returned HTTP error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new CvAnalysisException('Resume coach service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown chat failure';
            Log::warning('Resume coach returned non-success status', ['reason' => $reason]);
            throw new CvAnalysisException($reason, 422);
        }

        return [
            'response'   => $data['response'] ?? '',
            'session_id' => $data['session_id'] ?? null,
        ];
    }

    /**
     * Fetch the authenticated user's sessions from the AI service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(string $userId): array
    {
        $data = $this->getJson(sprintf(
            '%s/users/%s/sessions',
            rtrim((string) config('services.ai.base_url'), '/'),
            rawurlencode($userId)
        ));

        return is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
    }

    /**
     * Fetch messages for an AI coach session.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSessionMessages(string $sessionId): array
    {
        $data = $this->getJson(sprintf(
            '%s/sessions/%s/messages',
            rtrim((string) config('services.ai.base_url'), '/'),
            rawurlencode($sessionId)
        ));

        return is_array($data['messages'] ?? null) ? $data['messages'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $apiUrl): array
    {
        try {
            $response = Http::timeout(60)->get($apiUrl);
        } catch (\Throwable $e) {
            Log::error('Resume coach GET error', [
                'url'   => $apiUrl,
                'error' => $e->getMessage(),
            ]);

            throw new CvAnalysisException('Resume coach service unavailable', 502);
        }

        if ($response->failed()) {
            $data = $response->json();
            $reason = is_array($data)
                ? ($data['detail'] ?? $data['message'] ?? 'Resume coach service unavailable')
                : 'Resume coach service unavailable';

            Log::error('Resume coach GET returned an HTTP error', [
                'url'    => $apiUrl,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 2000),
            ]);

            throw new CvAnalysisException((string) $reason, $response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
            $reason = is_array($data)
                ? ($data['reason'] ?? $data['message'] ?? $data['detail'] ?? 'Unknown coach failure')
                : 'Unknown coach failure';

            throw new CvAnalysisException((string) $reason, 422);
        }

        return $data;
    }
}
