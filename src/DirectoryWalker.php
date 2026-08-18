<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * Walks a directory tree to a `tree -L`-style depth limit, stopping
 * recursion the moment a directory is identified as a git repository
 * (unless `$nested` is `true`, in which case recursion continues past that
 * boundary up to the normal depth cap).
 */
final class DirectoryWalker
{
    public function __construct(
        private readonly GitInspector $gitInspector,
    ) {}

    public function walk(string $path, ?int $maxDepth, bool $nested = false, int $currentDepth = 0): Node
    {
        $node = new Node($path, isGitRepo: $this->gitInspector->isRepo($path));

        $atRepoBoundary = $node->isGitRepo && ! $nested;
        if ($atRepoBoundary || ($maxDepth !== null && $currentDepth >= $maxDepth)) {
            return $node; // stop: either a repo boundary (unless --nested) or the depth cap
        }

        foreach ($this->listChildDirectories($path) as $child) {
            $node->children[] = $this->walk($child, $maxDepth, $nested, $currentDepth + 1);
        }

        return $node;
    }

    /**
     * @return string[] Absolute paths of `$path`'s direct subdirectories,
     *                   sorted for deterministic output, always excluding
     *                   `.git` itself (never descended into directly).
     */
    private function listChildDirectories(string $path): array
    {
        $entries = @scandir($path);

        if ($entries === false) {
            return [];
        }

        $directories = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') {
                continue;
            }

            $fullPath = rtrim($path, '/') . '/' . $entry;

            if (is_dir($fullPath)) {
                $directories[] = $fullPath;
            }
        }

        sort($directories, SORT_STRING);

        return $directories;
    }
}
