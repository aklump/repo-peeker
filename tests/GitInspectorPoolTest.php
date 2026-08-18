<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\GitInspector;
use RepoPeeker\GitSummary;
use RepoPeeker\Node;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class GitInspectorPoolTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir() . '/gitpeek-pool-test-' . uniqid();
        mkdir($this->fixtureRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_pooled_hydration_matches_the_sequential_reference_for_every_repo(): void
    {
        $root = $this->buildFixtureTree();

        (new GitInspector())->hydrateSummaries($root);

        foreach ($root->children as $node) {
            $expected = $this->sequentialSummaryFor($node->path);
            $actual = $node->summary;

            $this->assertNotNull($actual, "no summary hydrated for {$node->path}");
            $this->assertSame($expected->branch, $actual->branch, "branch mismatch for {$node->path}");
            $this->assertSame($expected->isDetached, $actual->isDetached, "isDetached mismatch for {$node->path}");
            $this->assertSame($expected->headSha, $actual->headSha, "headSha mismatch for {$node->path}");
            $this->assertSame($expected->changeCount, $actual->changeCount, "changeCount mismatch for {$node->path}");
            $this->assertSame($expected->aheadCount, $actual->aheadCount, "aheadCount mismatch for {$node->path}");
            $this->assertSame($expected->behindCount, $actual->behindCount, "behindCount mismatch for {$node->path}");
            $this->assertSame($expected->remoteUrl, $actual->remoteUrl, "remoteUrl mismatch for {$node->path}");
        }
    }

    public function test_pool_never_exceeds_its_fixed_concurrency_cap(): void
    {
        $root = $this->buildFixtureTree();

        $inspector = new ConcurrencyTrackingGitInspector();
        $inspector->hydrateSummaries($root);

        // 15 fixture repos x 4 independent calls each = 60 jobs queued up
        // front, comfortably more than the pool's cap, so the very first
        // fill-up is guaranteed to hit the cap exactly (never more) if the
        // pool's bookkeeping is correct.
        $this->assertSame(8, $inspector->peakConcurrency, 'pool did not reach its expected concurrency cap of 8');
        $this->assertLessThanOrEqual(8, $inspector->peakConcurrency, 'pool exceeded its fixed concurrency cap');
        $this->assertSame(0, $inspector->current, 'pool left processes marked as still in flight');
    }

    private function buildFixtureTree(): Node
    {
        $root = new Node($this->fixtureRoot, isGitRepo: false);
        $root->children = array_map(
            static fn (string $path): Node => new Node($path, isGitRepo: true),
            $this->buildFixtureRepos(),
        );

        return $root;
    }

    /**
     * Builds 15 real git repos covering clean/dirty/detached/ahead/behind
     * states, repeated across a few names each, large enough to actually
     * exercise pooling (more concurrent jobs than the fixed cap).
     *
     * @return string[] absolute repo paths
     */
    private function buildFixtureRepos(): array
    {
        $paths = [];

        for ($i = 1; $i <= 3; $i++) {
            $paths[] = $this->makeCleanRepo("clean-{$i}");
            $paths[] = $this->makeDirtyRepo("dirty-{$i}");
            $paths[] = $this->makeDetachedRepo("detached-{$i}");
            $paths[] = $this->makeAheadRepo("ahead-{$i}");
            $paths[] = $this->makeBehindRepo("behind-{$i}");
        }

        return $paths;
    }

    private function makeCleanRepo(string $name): string
    {
        return $this->initRepoAt($name);
    }

    private function makeDirtyRepo(string $name): string
    {
        $path = $this->initRepoAt($name);
        file_put_contents($path . '/file.txt', "modified\n");

        return $path;
    }

    private function makeDetachedRepo(string $name): string
    {
        $path = $this->initRepoAt($name);
        $sha = $this->gitOutput($path, ['rev-parse', 'HEAD']);
        $this->git($path, ['checkout', '--detach', $sha]);

        return $path;
    }

    private function makeAheadRepo(string $name): string
    {
        $path = $this->initRepoAt($name);
        $baseSha = $this->gitOutput($path, ['rev-parse', 'HEAD']);
        $this->git($path, ['remote', 'add', 'origin', '/nonexistent-remote']);
        $this->git($path, ['update-ref', 'refs/remotes/origin/main', $baseSha]);
        $this->git($path, ['branch', '--set-upstream-to=origin/main', 'main']);

        file_put_contents($path . '/file.txt', "second\n");
        $this->git($path, ['add', '.']);
        $this->git($path, ['commit', '-m', 'second commit']);

        return $path;
    }

    private function makeBehindRepo(string $name): string
    {
        $path = $this->initRepoAt($name);

        // Build a commit that is reachable only via the simulated upstream
        // ref, never via local HEAD, to produce a "behind" count.
        $this->git($path, ['checkout', '-b', 'temp-remote-branch']);
        file_put_contents($path . '/file.txt', "remote change\n");
        $this->git($path, ['add', '.']);
        $this->git($path, ['commit', '-m', 'remote-only commit']);
        $remoteSha = $this->gitOutput($path, ['rev-parse', 'HEAD']);

        $this->git($path, ['remote', 'add', 'origin', '/nonexistent-remote']);
        $this->git($path, ['update-ref', 'refs/remotes/origin/main', $remoteSha]);
        $this->git($path, ['checkout', 'main']);
        $this->git($path, ['branch', '-D', 'temp-remote-branch']);
        $this->git($path, ['branch', '--set-upstream-to=origin/main', 'main']);

        return $path;
    }

    private function initRepoAt(string $name): string
    {
        $path = $this->fixtureRoot . '/' . $name;
        mkdir($path, recursive: true);

        $this->git($path, ['init', '-b', 'main']);
        $this->git($path, ['config', 'user.email', 'test@example.com']);
        $this->git($path, ['config', 'user.name', 'Test']);

        file_put_contents($path . '/file.txt', "hello\n");
        $this->git($path, ['add', '.']);
        $this->git($path, ['commit', '-m', 'initial commit']);

        return $path;
    }

    /**
     * Recomputes a repo's {@see GitSummary} the way Phase 3's sequential
     * `GitInspector` did — four blocking, one-at-a-time `git` calls — so
     * the pooled implementation's output can be diffed against it field by
     * field, independent of `GitInspector` itself.
     */
    private function sequentialSummaryFor(string $path): GitSummary
    {
        $changeCount = $this->sequentialChangeCount($path);

        $branch = trim($this->runGit($path, ['rev-parse', '--abbrev-ref', 'HEAD']) ?? '');

        if ($branch === '' || $branch === 'HEAD') {
            $sha = trim($this->runGit($path, ['rev-parse', '--short', 'HEAD']) ?? '');
            $branchValue = null;
            $isDetached = true;
            $headSha = $sha !== '' ? $sha : null;
        } else {
            $branchValue = $branch;
            $isDetached = false;
            $headSha = null;
        }

        [$aheadCount, $behindCount] = $this->sequentialAheadBehind($path);

        $remoteUrl = trim($this->runGit($path, ['remote', 'get-url', 'origin']) ?? '');
        $remoteUrl = $remoteUrl !== '' ? $remoteUrl : null;

        return new GitSummary(
            branch: $branchValue,
            isDetached: $isDetached,
            headSha: $headSha,
            changeCount: $changeCount,
            aheadCount: $aheadCount,
            behindCount: $behindCount,
            remoteUrl: $remoteUrl,
        );
    }

    private function sequentialChangeCount(string $path): int
    {
        $output = $this->runGit($path, ['status', '--porcelain']);

        if ($output === null || trim($output) === '') {
            return 0;
        }

        return count(preg_split('/\R/', trim($output)));
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function sequentialAheadBehind(string $path): array
    {
        $output = $this->runGit($path, ['rev-list', '--left-right', '--count', 'HEAD...@{upstream}']);

        if ($output === null) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($output));

        if ($parts === false || count($parts) !== 2) {
            return [null, null];
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * @param string[] $arguments
     */
    private function runGit(string $path, array $arguments): ?string
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * @param string[] $arguments
     */
    private function git(string $repoPath, array $arguments): void
    {
        (new Process(['git', '-C', $repoPath, ...$arguments]))->mustRun();
    }

    /**
     * @param string[] $arguments
     */
    private function gitOutput(string $repoPath, array $arguments): string
    {
        $process = new Process(['git', '-C', $repoPath, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
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

/**
 * @internal
 *
 * Spies on {@see GitInspector}'s process-start/finish hooks to track how
 * many `git` subprocesses are running at once, so the pool's concurrency
 * cap can be verified without any timing-based assertions.
 */
final class ConcurrencyTrackingGitInspector extends GitInspector
{
    public int $current = 0;

    public int $peakConcurrency = 0;

    protected function startProcess(string $path, array $arguments): Process
    {
        $process = parent::startProcess($path, $arguments);

        $this->current++;
        $this->peakConcurrency = max($this->peakConcurrency, $this->current);

        return $process;
    }

    protected function onProcessFinished(Process $process): void
    {
        $this->current--;
    }
}
