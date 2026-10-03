<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Events\KnownPlace;

/**
 * The parish and venue coordinates the suburb lookup reads from.
 *
 * A port, so Core never touches WordPress or $wpdb: the WordPress layer supplies an adapter over
 * the parish and venue tables. Implementations must return places that already carry coordinates,
 * because a place without a pin cannot place anybody.
 */
interface PlaceCoordinateLookupInterface
{
    /**
     * Parish, area and venue names that have coordinates attached.
     *
     * @param int $limit Upper bound on rows returned, so a mis-seeded archive cannot pull the
     *                   whole table into one request.
     * @return list<KnownPlace>
     */
    public function placesWithCoordinates(int $limit): array;

    /**
     * The distinct suburb, area and venue names worth offering in the filter's datalist.
     *
     * @return list<string>
     */
    public function suburbNames(int $limit): array;
}