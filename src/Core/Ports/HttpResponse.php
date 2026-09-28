<?php

namespace ADCT\ParishIntake\Core\Ports;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }
}
