<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CvAnalysisService
{
    public function analyze(string $filePath, string $resumeId): array
    {
        $apiUrl = config('services.cv_analysis.url');

        try {
            $response = Http::attach(
                'file',
                file_get_contents(storage_path('app/'.$filePath)),
                basename($filePath)
            )->post($apiUrl, [
                'resume_id' => $resumeId,
            ]);
        } catch (\Throwable $e) {
            Log::error('CV analysis HTTP error', ['error' => $e->getMessage()]);
            throw new CvAnalysisException('CV analysis service unavailable', 502);
        }

        if ($response->failed()) {
            Log::error('CV analysis service returned HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new CvAnalysisException('CV analysis service unavailable', 502);
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? 'Unknown analysis failure';
            Log::warning('CV analysis returned non-success status', ['reason' => $reason]);
            throw new CvAnalysisException($reason, 422);
        }

        return $data['analysis'];
    }
}
