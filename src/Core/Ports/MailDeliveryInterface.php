<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Mail\MailDeliveryResult;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;

interface MailDeliveryInterface
{
    public function deliver(OutboundEmail $email): MailDeliveryResult;
}
