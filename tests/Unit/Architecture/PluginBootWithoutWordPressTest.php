<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The release build loads the plugin under bare PHP, with no WordPress present
 * (scripts/check-release-bootstrap.php). Plugin::boot() must survive that.
 *
 * A confidence setting read during construction once called get_option()
 * unguarded and made the release zip unbuildable, while every unit test stayed
 * green because they all load WordPress or a stub. This test reproduces the
 * bare-PHP load in a subprocess so that class of regression fails the unit suite
 * rather than only the release job.
 */
final class PluginBootWithoutWordPressTest extends TestCase
{
    private const BOOTSTRAP = <<<'PHP'
        <?php

        declare(strict_types=1);

        // Bare PHP: the Composer dev autoloader, no WordPress, no stubs for
        // get_option()/add_action() and friends. Anything the constructor calls
        // unguarded is a fatal here, exactly as it was in the release check.
        require $argv[1] . '/vendor/autoload.php';

        define('ABSPATH', $argv[1] . '/');

        function register_activation_hook($file, $callback): void
        {
        }

        function register_deactivation_hook($file, $callback): void
        {
        }

        ADCT\ParishIntake\WordPress\Plugin::boot($argv[1] . '/adct-parish-intake.php');

        // Reaching here means no unguarded WordPress call was made.
        fwrite(STDOUT, "BOOTED\n");
        PHP;

    public function testPluginBootsWithoutWordPressFunctionsDefined(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $autoloader = $repositoryRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        self::assertFileExists($autoloader, 'Run composer install before the unit suite.');

        $script = tempnam(sys_get_temp_dir(), 'adct-boot-');

        self::assertIsString($script);

        try {
            file_put_contents($script, self::BOOTSTRAP);

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, $script, $repositoryRoot],
                $descriptors,
                $pipes,
                $repositoryRoot
            );

            self::assertIsResource($process, 'Could not start the bare-PHP bootstrap process.');

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            self::assertSame(
                0,
                $exitCode,
                "Plugin::boot() must not call a WordPress function that is not defined.\n"
                . "stdout: " . $stdout . "\nstderr: " . $stderr
            );
            self::assertStringContainsString('BOOTED', $stdout);
        } finally {
            if (is_file($script)) {
                unlink($script);
            }
        }
    }
}
