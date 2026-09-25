<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenHandlerRegistryInterface;
use InvalidArgumentException;

final class ActionTokenHandlerRegistry implements ActionTokenHandlerRegistryInterface
{
    /**
     * @var array<string, ActionTokenActionHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @param iterable<ActionTokenActionHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(ActionTokenActionHandlerInterface $handler): void
    {
        $purpose = $handler->purpose()->value;

        if (isset($this->handlers[$purpose])) {
            throw new InvalidArgumentException('Only one action token handler can be registered per purpose.');
        }

        $this->handlers[$purpose] = $handler;
    }

    public function forPurpose(ActionTokenPurpose $purpose): ?ActionTokenActionHandlerInterface
    {
        return $this->handlers[$purpose->value] ?? null;
    }
}
