<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResumeCoachService
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history  Previous messages for context
     */
    public function chat(string $message, array $history = []): string
    {
        $apiUrl = config('services.resume_coach.url');

        try {
            $response = Http::timeout(60)->asForm()->post($apiUrl, [
                'message' => $message,
                'history' => json_encode($history),
            ]);
        } catch (\Throwable $e) {
            Log::error('Resume coach HTTP error', ['error' => $e->getMessage()]);
            throw new CvAnalysisException('Resume coach service unavailable', 502);
        }

        if ($response->failed()) {
            Log::error('Resume coach service returned HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new CvAnalysisException('Resume coach service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown chat failure';
            Log::warning('Resume coach returned non-success status', ['reason' => $reason]);
            throw new CvAnalysisException($reason, 422);
        }

        return $data['response'] ?? '';
    }
}
