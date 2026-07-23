<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobMatchingService
{
    public function matchJobToCandidates(string $jobDescription): array
    {
        $apiUrl = config('services.job_matching.url');

        try {
            $response = Http::asForm()->post($apiUrl, [
                'job_description' => $jobDescription,
            ]);
        } catch (\Throwable $e) {
            Log::error('Job matching HTTP error', ['error' => $e->getMessage()]);
            throw new CvAnalysisException('Job matching service unavailable', 502);
        }

        if ($response->failed()) {
            Log::error('Job matching service returned HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new CvAnalysisException('Job matching service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown matching failure';
            Log::warning('Job matching returned non-success status', ['reason' => $reason]);
            throw new CvAnalysisException($reason, 422);
        }

        return [
            'extracted_requirements' => $data['extracted_requirements'] ?? [],
            'candidates' => $data['candidates'] ?? [],
        ];
    }
}
