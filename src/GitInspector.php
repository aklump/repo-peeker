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
 * every repo node found, shells out to `git` (sequentially, in this phase)
 * to attach a {@see GitSummary}.
 */
final class GitInspector
{
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
     * Recursively walks `$tree`, attaching a {@see GitSummary} to every
     * `isGitRepo` node found (regardless of depth — `--nested` can surface
     * repo nodes beneath other repo nodes).
     */
    public function hydrateSummaries(Node $tree): void
    {
        if ($tree->isGitRepo) {
            $tree->summary = $this->getSummary($tree->path);
        }

        foreach ($tree->children as $child) {
            $this->hydrateSummaries($child);
        }
    }

    private function getSummary(string $path): GitSummary
    {
        $changeCount = $this->countPendingChanges($path);
        [$branch, $isDetached, $headSha] = $this->resolveBranch($path);
        [$aheadCount, $behindCount] = $this->resolveAheadBehind($path);

        return new GitSummary(
            branch: $branch,
            isDetached: $isDetached,
            headSha: $headSha,
            changeCount: $changeCount,
            aheadCount: $aheadCount,
            behindCount: $behindCount,
            remoteUrl: $this->resolveRemoteUrl($path),
        );
    }

    private function countPendingChanges(string $path): int
    {
        $output = $this->run($path, ['status', '--porcelain']);

        if ($output === null || trim($output) === '') {
            return 0;
        }

        return count(preg_split('/\R/', trim($output)));
    }

    /**
     * @return array{0: ?string, 1: bool, 2: ?string} [branch, isDetached, headSha]
     */
    private function resolveBranch(string $path): array
    {
        $branch = trim($this->run($path, ['rev-parse', '--abbrev-ref', 'HEAD']) ?? '');

        if ($branch === '' || $branch === 'HEAD') {
            $sha = trim($this->run($path, ['rev-parse', '--short', 'HEAD']) ?? '');

            return [null, true, $sha !== '' ? $sha : null];
        }

        return [$branch, false, null];
    }

    /**
     * @return array{0: ?int, 1: ?int} [aheadCount, behindCount], both `null`
     *                                 when there is no upstream configured
     */
    private function resolveAheadBehind(string $path): array
    {
        $output = $this->run($path, ['rev-list', '--left-right', '--count', 'HEAD...@{upstream}']);

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
     * The `origin` remote's URL, or `null` when there is none configured.
     */
    private function resolveRemoteUrl(string $path): ?string
    {
        $url = trim($this->run($path, ['remote', 'get-url', 'origin']) ?? '');

        return $url !== '' ? $url : null;
    }

    /**
     * Runs `git -C $path <arguments>`, returning its stdout on success or
     * `null` when the command exits non-zero (e.g. no upstream configured).
     *
     * @param string[] $arguments
     */
    private function run(string $path, array $arguments): ?string
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }
}
