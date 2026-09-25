<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;

interface MailerInterface
{
    /**
     * Queue one validated, single-recipient message; implementations must not bypass the queue for delivery.
     */
    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult;
}
