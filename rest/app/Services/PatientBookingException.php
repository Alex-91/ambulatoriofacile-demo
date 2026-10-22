<?php
namespace App\Services;

final class PatientBookingException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
