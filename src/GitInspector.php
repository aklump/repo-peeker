<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * Detects git repositories on the filesystem.
 *
 * Phase 1 only performs the cheap filesystem check needed by the
 * {@see DirectoryWalker} to decide whether to stop recursing. Git
 * subprocess-based status/branch/ahead-behind hydration is added in Phase 2
 * (`getSummary()` / `hydrateSummaries()`).
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
}
