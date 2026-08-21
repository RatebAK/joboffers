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

        $payload = [
            'resume_id' => $resumeId,
            'limit'     => $limit,
        ];

        Log::info('Resume matching: starting request', [
            'url'     => $apiUrl,
            'method'  => 'POST',
            'format'  => 'form-urlencoded',
            'payload' => $payload,
        ]);

        $startTime = microtime(true);

        try {
            $response = Http::timeout(300)->connectTimeout(120)->asForm()->post($apiUrl, $payload);
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $startTime, 2);
            Log::error('Resume matching: HTTP exception', [
                'url'          => $apiUrl,
                'payload'      => $payload,
                'error'        => $e->getMessage(),
                'exception'    => get_class($e),
                'elapsed_secs' => $elapsed,
            ]);
            throw new CvAnalysisException('Resume matching service unavailable', 502);
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        Log::info('Resume matching: response received', [
            'url'           => $apiUrl,
            'status'        => $response->status(),
            'elapsed_secs'  => $elapsed,
            'response_body' => mb_substr($response->body(), 0, 2000),
            'headers'       => $response->headers(),
        ]);

        if ($response->failed()) {
            Log::error('Resume matching: service returned HTTP error', [
                'url'     => $apiUrl,
                'status'  => $response->status(),
                'body'    => mb_substr($response->body(), 0, 2000),
                'payload' => $payload,
            ]);
            throw new CvAnalysisException('Resume matching service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown matching failure';
            Log::warning('Resume matching: non-success status returned', [
                'url'     => $apiUrl,
                'payload' => $payload,
                'reason'  => $reason,
                'data'    => $data,
            ]);
            throw new CvAnalysisException($reason, 422);
        }

        Log::info('Resume matching: success', [
            'matches_found' => $data['matches_found'] ?? 0,
            'job_count'     => count($data['jobs'] ?? []),
        ]);

        return [
            'matches_found' => $data['matches_found'] ?? 0,
            'jobs'          => $data['jobs'] ?? [],
        ];
    }
}
