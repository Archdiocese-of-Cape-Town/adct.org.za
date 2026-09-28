<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use Closure;
use InvalidArgumentException;

final class ConfirmActionHandler implements ActionTokenActionHandlerInterface
{
    /**
     * @var null|Closure(ActionTokenBinding): ActionTokenOutcome
     */
    private ?Closure $callback;

    public function __construct(
        private string $title = 'Confirm submission',
        private string $summary = 'Please confirm these event details.',
        private string $submitLabel = 'Confirm',
        callable|null $callback = null,
        private string $defaultMessage = 'Thank you. Your confirmation has been recorded.'
    ) {
        $this->callback = $callback === null ? null : Closure::fromCallable($callback);
    }

    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::CONFIRM;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        if ($binding->purpose !== $this->purpose()) {
            return null;
        }

        return new ActionTokenPreview($this->title, $this->summary, $this->submitLabel);
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        if ($binding->purpose !== $this->purpose()) {
            throw new InvalidArgumentException('The action token binding does not match the confirm handler.');
        }

        return $this->callback !== null
            ? ($this->callback)($binding)
            : new ActionTokenOutcome($this->defaultMessage);
    }
}
