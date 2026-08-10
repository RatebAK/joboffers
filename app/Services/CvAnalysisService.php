<?php

namespace App\Services;

use App\Exceptions\CvAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CvAnalysisService
{
    /**
     * Analyze a CV from a publicly accessible URL.
     *
     * @param  string  $fileUrl   Cloudinary (or any public) URL of the CV file
     * @param  string  $resumeId  Identifier sent to the AI service (user ID)
     */
    public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
    {
        $apiUrl = config('services.cv_analysis.url');

        // Log file type for debugging
        $fileExtension = pathinfo(parse_url($fileUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
        Log::info('CV analysis request', [
            'file_url'       => $fileUrl,
            'file_extension' => $fileExtension,
            'mime_type'      => $mimeType,
            'resume_id'      => $resumeId,
            'api_url'        => $apiUrl,
        ]);

        $payload = [
            'file_url'  => $fileUrl,
            'resume_id' => $resumeId,
        ];

        try {
            $response = Http::timeout(120)->asForm()->post($apiUrl, $payload);
        } catch (\Throwable $e) {
            Log::error('CV analysis HTTP error', [
                'error' => $e->getMessage(),
                'file_url' => $fileUrl,
                'file_extension' => $fileExtension,
                'api_url' => $apiUrl
            ]);
            throw new CvAnalysisException('CV analysis service unavailable: ' . $e->getMessage(), 502);
        }

        if ($response->failed()) {
            $errorBody = $response->body();
            Log::error('CV analysis service returned HTTP error', [
                'status' => $response->status(),
                'body' => $errorBody,
                'file_url' => $fileUrl,
                'file_extension' => $fileExtension,
            ]);
            
            $errorMessage = 'CV analysis service unavailable';
            try {
                $errorData = json_decode($errorBody, true);
                if (isset($errorData['detail'])) {
                    $errorMessage = $errorData['detail'];
                } elseif (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                }
            } catch (\Exception $e) {
                // Keep default error message
            }
            
            throw new CvAnalysisException($errorMessage, $response->status());
        }

        $data = $response->json();

        if (($data['status'] ?? null) !== 'success') {
            $reason = $data['reason'] ?? $data['message'] ?? $data['detail'] ?? 'Unknown analysis failure';
            Log::warning('CV analysis returned non-success status', [
                'reason' => $reason,
                'file_url' => $fileUrl,
                'file_extension' => $fileExtension,
                'response_data' => $data
            ]);
            throw new CvAnalysisException($reason, 422);
        }

        // Log successful analysis summary
        Log::info('CV analysis completed successfully', [
            'resume_id' => $resumeId,
            'has_analysis_data' => isset($data['analysis']),
            'analysis_fields_count' => isset($data['analysis']) ? count($data['analysis']) : 0,
            'file_extension' => $fileExtension,
        ]);

        return $data['analysis'] ?? [];
    }
}
