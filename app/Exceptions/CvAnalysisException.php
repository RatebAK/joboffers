<?php

namespace App\Exceptions;

use Exception;

class CvAnalysisException extends Exception
{
    protected int $httpStatusCode;

    public function __construct(string $message, int $httpStatusCode = 502)
    {
        parent::__construct($message);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
