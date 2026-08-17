<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudinary' => [
        /*
         * Cloudinary blocks delivery of `.pdf` and `.zip` URLs by default
         * (Console → Settings → Security → "Allow delivery of PDF and ZIP files").
         * While that setting is off, every `.pdf` delivery URL returns
         * HTTP 401 "deny or ACL failure", so the CV analysis service receives
         * zero bytes and reports that it could not extract any text.
         *
         * Leave this false to store PDFs under a delivery-safe extension so
         * uploads keep working. Enable the Cloudinary setting and flip this to
         * true to serve PDFs under their real `.pdf` URL.
         */
        'pdf_delivery_enabled' => env('CLOUDINARY_PDF_DELIVERY_ENABLED', false),

        /*
         * Extension used for PDFs while `pdf_delivery_enabled` is false.
         * Must be a format the product environment both allows on upload and
         * delivers, so neither "pdf"/"zip" (restricted delivery) nor "bin"
         * (rejected on upload). The stored bytes are untouched — the CV
         * analysis service detects the real type from the file contents — and
         * `resume_original_name` keeps the filename the user uploaded.
         */
        'pdf_fallback_extension' => env('CLOUDINARY_PDF_FALLBACK_EXTENSION', 'txt'),
    ],

    'cv_analysis' => [
        'url' => env('CV_ANALYSIS_API_URL'),
    ],

    'job_matching' => [
        'url' => env('JOB_MATCHING_API_URL'),
    ],

    'resume_matching' => [
        'url' => env('RESUME_MATCHING_API_URL'),
    ],

    'resume_coach' => [
        'url' => env('RESUME_COACH_API_URL'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

];
