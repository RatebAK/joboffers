<?php

namespace App\Exceptions;

use Exception;

class CvAnalysisException extends Exception
{
    protected int $httpStatusCode;

    /**
     * Bounded diagnostic details from the upstream service, when available.
     *
     * This is intentionally optional because the exception is shared by several
     * AI services. Only the resume-matching controller currently exposes it.
     *
     * @var array<string, mixed>
     */
    protected array $diagnostic;

    /**
     * @param array<string, mixed> $diagnostic
     */
    public function __construct(string $message, int $httpStatusCode = 502, array $diagnostic = [])
    {
        parent::__construct($message);
        $this->httpStatusCode = $httpStatusCode;
        $this->diagnostic = $diagnostic;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiagnostic(): array
    {
        return $this->diagnostic;
    }
}
