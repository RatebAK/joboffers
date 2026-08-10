<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResumeMatchingService
{
    public function matchResumeToJobs(string $resumeId, int $limit = 10): array
    {
        $apiUrl = config('services.resume_matching.url');

        try {
            $response = Http::timeout(300)->connectTimeout(120)->asForm()->post($apiUrl, [
                'resume_id' => $resumeId,
                'limit'     => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::error('Resume matching HTTP error', ['error' => $e->getMessage()]);
            throw new CvAnalysisException('Resume matching service unavailable', 502);
        }

        if ($response->failed()) {
            Log::error('Resume matching service returned HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new CvAnalysisException('Resume matching service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown matching failure';
            Log::warning('Resume matching returned non-success status', ['reason' => $reason]);
            throw new CvAnalysisException($reason, 422);
        }

        return [
            'matches_found' => $data['matches_found'] ?? 0,
            'jobs'          => $data['jobs'] ?? [],
        ];
    }
}
