<?php

namespace App\Exceptions;

use App\Services\ReportingExclusions;
use RuntimeException;

/** Operator-only reason; the HTTP message deliberately remains generic. */
class ReportingConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(ReportingExclusions::UNAVAILABLE);
    }
}
