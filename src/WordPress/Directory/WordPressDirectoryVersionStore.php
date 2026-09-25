<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use RuntimeException;

final class WordPressDirectoryVersionStore implements DirectoryVersionStoreInterface
{
    private const OPTION_NAME = 'adct_pi_directory_version';

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function current(): int
    {
        $value = get_option(self::OPTION_NAME, '0');

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            throw new RuntimeException('The stored directory version is invalid.');
        }

        return (int) $value;
    }

    public function bump(): int
    {
        $optionsTable = $this->database->prefix() . 'options';
        $query = $this->database->prepare(
            "INSERT INTO {$optionsTable} (option_name, option_value, autoload) "
            . "VALUES (%s, '1', 'no') "
            . 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1',
            self::OPTION_NAME
        );
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $result === 0 || $this->database->lastError() !== '') {
            throw new RuntimeException(
                'The directory version could not be incremented: ' . $this->database->lastError()
            );
        }

        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        return $this->current();
    }
}
