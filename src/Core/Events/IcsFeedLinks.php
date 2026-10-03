<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

/**
 * Decides which calendar feeds to offer from the filters a visitor has applied.
 *
 * The feed endpoint understands a parish filter and a single event-type filter, but not a
 * combination of several types. Rather than quietly widening the scope to every event, each
 * selected type gets its own subscribe link, so what the visitor puts in their calendar is
 * exactly what the listing showed them. Pure logic, so it is unit-testable without WordPress.
 */
final class IcsFeedLinks
{
    /** A bounded number of links keeps the listing markup predictable however many types exist. */
    public const MAX_LINKS = 10;

    /** @var array<int, array{name: string, slug: string}> */
    private array $types;

    /**
     * @param array<int, array{name: string, slug: string}> $types Event types keyed by term id.
     */
    public function __construct(array $types)
    {
        foreach ($types as $id => $type) {
            if (! is_int($id) || $id < 1 || ! is_array($type)
                || ! is_string($type['name'] ?? null) || ! is_string($type['slug'] ?? null)
                || $type['slug'] === '') {
                throw new InvalidArgumentException('Invalid event type catalogue.');
            }
        }

        /** @var array<int, array{name: string, slug: string}> $types */
        $this->types = $types;
    }

    /**
     * @param list<int> $typeIds The event-type term ids the visitor selected, in listing order.
     * @return list<array{label: string, parish: int|null, type: string|null}>
     */
    public function links(?int $parish, ?string $parishName, array $typeIds): array
    {
        if ($parish !== null && $parish < 1) {
            throw new InvalidArgumentException('Invalid parish filter.');
        }
        foreach ($typeIds as $typeId) {
            if (! is_int($typeId)) {
                throw new InvalidArgumentException('Invalid event type filter.');
            }
        }

        $where = $parishName === null ? null : trim($parishName);
                $where = $where === '' ? null : $where;

                $links = [];
                foreach ($this->scopes($typeIds) as $type) {
                    $links[] = [
                        'label' => $this->label($where, $type === null ? null : $this->types[$type]['name'], $type),
                        'parish' => $parish,
                        'type' => $type === null ? null : $this->types[$type]['slug'],
                    ];
                    if (count($links) === self::MAX_LINKS) {
                        break;
                    }
                }

                return $links;
            }

    /**
     * Each selected type is its own feed; an unrecognised selection widens to every event rather
     * than offering nothing, because a visitor filtered the page and still expects a calendar.
     *
     * @param list<int> $typeIds
     * @return list<int|null>
     */
    private function scopes(array $typeIds): array
    {
        if ($typeIds === []) {
            return [null];
        }

        $scopes = [];
        foreach (array_unique($typeIds) as $typeId) {
            if (isset($this->types[$typeId])) {
                $scopes[] = $typeId;
            }
        }

        return $scopes === [] ? [null] : $scopes;
    }

    private function label(?string $parishName, ?string $typeName, ?int $type): string
    {
            $typed = $typeName !== null && $typeName !== '' && $type !== null;
            $label = $typed ? 'Subscribe to ' . $typeName . ' events' : 'Subscribe to all events';
            if ($parishName !== null) {
                $label .= ($typed ? ' at ' : ' for ') . $parishName;
            }

            return $label;
        }

        /** Swap an http(s) scheme for webcal, which is what calendar clients register as a subscription. */
        public static function webcal(string $url): string
        {
            return (string) preg_replace(
                ['/\Ahttps:/', '/\Ahttp:/'],
                ['webcal:', 'webcal:'],
                $url,
                1
            );
        }
    }