<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResumeCoachService
{
    public function chat(string $userId, string $message): array
    {
        $apiUrl = config('services.resume_coach.url');

        try {
            $response = Http::timeout(60)->asForm()->post($apiUrl, [
                'user_id' => $userId,
                'message' => $message,
            ]);
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
}
