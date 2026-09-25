<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;

final class WordPressDirectorySnapshotLoader implements DirectorySnapshotLoaderInterface
{
    public function __construct(
        private ParishRepository $parishes,
        private VenueRepository $venues,
        private ParishContactRepository $contacts
    ) {
    }

    public function load(): DirectorySnapshot
    {
        $parishes = [];

        foreach ($this->parishes->findAllForImport() as $parish) {
            $parishes[] = [
                'id' => (int) ($parish['id'] ?? 0),
                'name' => (string) ($parish['name'] ?? ''),
                'slug' => (string) ($parish['slug'] ?? ''),
                'area' => (string) ($parish['area'] ?? ''),
                'church' => (string) ($parish['church'] ?? ''),
                'suburb' => (string) ($parish['suburb'] ?? ''),
                'aliases' => [],
                'status' => (string) ($parish['status'] ?? 'active'),
            ];
        }

        return new DirectorySnapshot(
            $parishes,
            $this->venues->findActiveVenues(),
            $this->contacts->findAllForDirectory()
        );
    }
}
