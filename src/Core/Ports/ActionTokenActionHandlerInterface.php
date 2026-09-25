<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;

interface ActionTokenActionHandlerInterface
{
    public function purpose(): ActionTokenPurpose;

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview;

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome;
}
