<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Builds the real gitpeek.phar with `box compile` and runs it as a genuine
 * subprocess (no `php` prefix, no project checkout on the include path) to
 * prove the packaged artifact actually works end to end, not just the
 * in-process console commands exercised by the rest of the test suite.
 *
 * @internal
 */
final class PharSmokeTest extends TestCase
{
    private static string $projectRoot;

    private static string $pharPath;

    private string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__);
        self::$pharPath = self::$projectRoot . '/gitpeek.phar';

        self::runOrFail([self::$projectRoot . '/vendor/bin/box', 'compile'], self::$projectRoot);

        chmod(self::$pharPath, 0755);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_file(self::$pharPath)) {
            unlink(self::$pharPath);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir() . '/gitpeek-phar-smoke-' . uniqid();

        $repoPath = $this->fixtureRoot . '/repo-a';
        mkdir($repoPath, recursive: true);

        self::runOrFail(['git', 'init', '-q', '-b', 'main'], $repoPath);
        self::runOrFail(['git', 'config', 'user.email', 'test@example.com'], $repoPath);
        self::runOrFail(['git', 'config', 'user.name', 'Test'], $repoPath);
        file_put_contents($repoPath . '/file.txt', "hello\n");
        self::runOrFail(['git', 'add', '.'], $repoPath);
        self::runOrFail(['git', 'commit', '-q', '-m', 'initial commit'], $repoPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_status_subcommand_runs_as_a_standalone_phar_and_finds_the_fixture_repo(): void
    {
        $process = new Process([self::$pharPath, 'status', $this->fixtureRoot, '-L', '2'], self::$projectRoot);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('repo-a', $process->getOutput());
        $this->assertStringContainsString('main', $process->getOutput());
    }

    public function test_legend_subcommand_runs_as_a_standalone_phar(): void
    {
        $process = new Process([self::$pharPath, 'legend'], self::$projectRoot);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('not a git repository', $process->getOutput());
        $this->assertStringContainsString('detached HEAD', $process->getOutput());
    }

    private static function runOrFail(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->run();

        if (! $process->isSuccessful()) {
            self::fail(sprintf(
                "Command failed: %s\n%s",
                implode(' ', $command),
                $process->getErrorOutput(),
            ));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
