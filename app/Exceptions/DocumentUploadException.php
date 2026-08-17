<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a document could not be stored on, or delivered from, Cloudinary.
 */
class DocumentUploadException extends Exception
{
    public function __construct(string $message, private readonly int $httpStatusCode = 502)
    {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public static function uploadFailed(string $reason): self
    {
        return new self("Could not upload the file to storage: {$reason}", 502);
    }

    /**
     * The Cloudinary product environment is refusing to deliver the stored file.
     * The message is deliberately actionable — this is almost always the
     * "Allow delivery of PDF and ZIP files" security setting being switched off.
     */
    public static function notDeliverable(string $url, int $status): self
    {
        return new self(
            "The uploaded file was stored but is not publicly downloadable "
            . "(HTTP {$status} for {$url}). If this is a PDF, enable "
            . '"Allow delivery of PDF and ZIP files" in the Cloudinary console '
            . 'under Settings → Security, or leave services.cloudinary.pdf_delivery_enabled '
            . 'disabled so PDFs are stored under a delivery-safe extension.',
            502
        );
    }
}
