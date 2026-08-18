<?php

declare(strict_types=1);

namespace RepoPeeker;

use Symfony\Component\Process\Process;

/**
 * Detects git repositories on the filesystem and hydrates their status.
 *
 * `isRepo()` performs the cheap filesystem check needed by the
 * {@see DirectoryWalker} to decide whether to stop recursing.
 * `hydrateSummaries()` walks an already-built {@see Node} tree and, for
 * every repo node found, shells out to `git` to attach a {@see GitSummary}.
 *
 * The underlying `git` calls run through a small bounded worker pool
 * (`CONCURRENCY_LIMIT` processes in flight at once, enforced across the
 * *entire* tree rather than per repo) using `symfony/process`'s
 * non-blocking API (`Process::start()` + polling `isRunning()`). Every
 * repo needs four independent calls (status, branch, ahead/behind, remote)
 * plus one call that's conditional on another's result (the detached-HEAD
 * sha, only needed once the branch call reveals `HEAD`); the four
 * independent calls for every repo are queued up front so unrelated repos'
 * calls run concurrently, while the conditional sha call is queued as soon
 * as its own repo's branch result comes back — no global synchronization
 * beyond the shared pool's concurrency cap is required.
 *
 * Deliberately not `final`: {@see startProcess()} and
 * {@see onProcessFinished()} are protected extension points a test can
 * override to spy on concurrency without timing-based assertions.
 */
class GitInspector
{
    /**
     * Maximum number of `git` subprocesses allowed to run at once, across
     * every repo being hydrated in a single {@see hydrateSummaries()} call.
     */
    private const int CONCURRENCY_LIMIT = 8;

    /**
     * Whether `$path` is a git repository, checking for a `.git` entry that
     * is either a directory (an ordinary repo) or a file (a worktree or
     * submodule's `gitdir:` pointer).
     */
    public function isRepo(string $path): bool
    {
        $gitPath = rtrim($path, '/') . '/.git';

        return file_exists($gitPath);
    }

    /**
     * Finds every `isGitRepo` node in `$tree` (regardless of depth —
     * `--nested` can surface repo nodes beneath other repo nodes) and
     * hydrates each with a {@see GitSummary}, running all of the
     * underlying `git` calls through a bounded concurrent pool rather than
     * one repo at a time.
     */
    public function hydrateSummaries(Node $tree): void
    {
        $repoNodes = $this->collectRepoNodes($tree);

        if ($repoNodes === []) {
            return;
        }

        $this->runPool($repoNodes);
    }

    /**
     * @return Node[]
     */
    private function collectRepoNodes(Node $node): array
    {
        $nodes = $node->isGitRepo ? [$node] : [];

        foreach ($node->children as $child) {
            array_push($nodes, ...$this->collectRepoNodes($child));
        }

        return $nodes;
    }

    /**
     * Queues every repo's independent git calls up front, drains the queue
     * through a fixed-size pool of concurrently running processes (topping
     * back up to the cap as slots free), and assembles each node's
     * {@see GitSummary} once all of its own calls — including any
     * conditional follow-up — have completed.
     *
     * @param Node[] $repoNodes
     */
    private function runPool(array $repoNodes): void
    {
        /** @var array<int, array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string}> $rows */
        $rows = [];
        $queue = [];

        foreach ($repoNodes as $node) {
            $rows[spl_object_id($node)] = [
                'node' => $node,
                'changeCount' => 0,
                'branch' => null,
                'isDetached' => false,
                'headSha' => null,
                'aheadCount' => null,
                'behindCount' => null,
                'remoteUrl' => null,
            ];

            $queue[] = $this->job($node, 'status', ['status', '--porcelain']);
            $queue[] = $this->job($node, 'branch', ['rev-parse', '--abbrev-ref', 'HEAD']);
            $queue[] = $this->job($node, 'aheadBehind', ['rev-list', '--left-right', '--count', 'HEAD...@{upstream}']);
            $queue[] = $this->job($node, 'remote', ['remote', 'get-url', 'origin']);
        }

        /** @var list<array{job: array{node: Node, kind: string, arguments: string[]}, process: Process}> $inFlight */
        $inFlight = [];

        while ($queue !== [] || $inFlight !== []) {
            while ($queue !== [] && count($inFlight) < self::CONCURRENCY_LIMIT) {
                $job = array_shift($queue);
                $inFlight[] = [
                    'job' => $job,
                    'process' => $this->startProcess($job['node']->path, $job['arguments']),
                ];
            }

            $stillRunning = [];
            $progressed = false;

            foreach ($inFlight as $entry) {
                if ($entry['process']->isRunning()) {
                    $stillRunning[] = $entry;

                    continue;
                }

                $progressed = true;
                $this->onProcessFinished($entry['process']);

                $row = &$rows[spl_object_id($entry['job']['node'])];
                $followUp = $this->applyResult($row, $entry['job']['kind'], $entry['process']);
                unset($row);

                if ($followUp !== null) {
                    $queue[] = $followUp;
                }
            }

            $inFlight = $stillRunning;

            if (! $progressed && $inFlight !== []) {
                // Nothing finished this pass; avoid busy-spinning while we
                // wait for at least one in-flight process to complete.
                usleep(1_000);
            }
        }

        foreach ($rows as $row) {
            $row['node']->summary = new GitSummary(
                branch: $row['branch'],
                isDetached: $row['isDetached'],
                headSha: $row['headSha'],
                changeCount: $row['changeCount'],
                aheadCount: $row['aheadCount'],
                behindCount: $row['behindCount'],
                remoteUrl: $row['remoteUrl'],
            );
        }
    }

    /**
     * @param string[] $arguments
     * @return array{node: Node, kind: string, arguments: string[]}
     */
    private function job(Node $node, string $kind, array $arguments): array
    {
        return ['node' => $node, 'kind' => $kind, 'arguments' => $arguments];
    }

    /**
     * Applies a finished process's result to its repo's accumulating row,
     * returning a follow-up job to enqueue (the detached-HEAD sha lookup)
     * when one is needed, or `null` otherwise.
     *
     * Mirrors the parsing rules of Phase 3's sequential implementation
     * exactly, so pooling changes only *how* results are gathered, never
     * what they are.
     *
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     * @return array{node: Node, kind: string, arguments: string[]}|null
     */
    private function applyResult(array &$row, string $kind, Process $process): ?array
    {
        $output = $process->isSuccessful() ? $process->getOutput() : null;

        return match ($kind) {
            'status' => $this->applyStatus($row, $output),
            'branch' => $this->applyBranch($row, $output),
            'sha' => $this->applySha($row, $output),
            'aheadBehind' => $this->applyAheadBehind($row, $output),
            'remote' => $this->applyRemote($row, $output),
        };
    }

    /**
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     */
    private function applyStatus(array &$row, ?string $output): ?array
    {
        if ($output === null || trim($output) === '') {
            $row['changeCount'] = 0;

            return null;
        }

        $row['changeCount'] = count(preg_split('/\R/', trim($output)));

        return null;
    }

    /**
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     * @return array{node: Node, kind: string, arguments: string[]}|null
     */
    private function applyBranch(array &$row, ?string $output): ?array
    {
        $branch = trim($output ?? '');

        if ($branch === '' || $branch === 'HEAD') {
            $row['isDetached'] = true;

            // The sha is only needed for detached HEAD; queue it as a
            // follow-up rather than starting it unconditionally.
            return $this->job($row['node'], 'sha', ['rev-parse', '--short', 'HEAD']);
        }

        $row['branch'] = $branch;

        return null;
    }

    /**
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     */
    private function applySha(array &$row, ?string $output): ?array
    {
        $sha = trim($output ?? '');
        $row['headSha'] = $sha !== '' ? $sha : null;

        return null;
    }

    /**
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     */
    private function applyAheadBehind(array &$row, ?string $output): ?array
    {
        if ($output === null) {
            $row['aheadCount'] = null;
            $row['behindCount'] = null;

            return null;
        }

        $parts = preg_split('/\s+/', trim($output));

        if ($parts === false || count($parts) !== 2) {
            $row['aheadCount'] = null;
            $row['behindCount'] = null;

            return null;
        }

        $row['aheadCount'] = (int) $parts[0];
        $row['behindCount'] = (int) $parts[1];

        return null;
    }

    /**
     * @param array{node: Node, changeCount: int, branch: ?string, isDetached: bool, headSha: ?string, aheadCount: ?int, behindCount: ?int, remoteUrl: ?string} $row
     */
    private function applyRemote(array &$row, ?string $output): ?array
    {
        $url = trim($output ?? '');
        $row['remoteUrl'] = $url !== '' ? $url : null;

        return null;
    }

    /**
     * Starts `git -C $path <arguments>` without blocking, leaving it to the
     * pool loop in {@see runPool()} to poll for completion.
     *
     * Overridable so tests can spy on how many processes are started
     * concurrently, paired with {@see onProcessFinished()}, without relying
     * on timing-based assertions.
     *
     * @param string[] $arguments
     */
    protected function startProcess(string $path, array $arguments): Process
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->start();

        return $process;
    }

    /**
     * Called once per process as soon as the pool loop observes it has
     * finished (whether it succeeded or not). No-op by default; overridable
     * so tests can pair it with {@see startProcess()} to track how many
     * processes are in flight at once.
     */
    protected function onProcessFinished(Process $process): void
    {
        // Intentionally empty; a hook point for subclasses/tests.
    }
}
