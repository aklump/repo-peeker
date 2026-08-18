<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * A single directory visited by the {@see DirectoryWalker}.
 */
final class Node
{
    /** @var Node[] */
    public array $children = [];

    /**
     * Populated by {@see GitInspector::hydrateSummaries()} for repo nodes
     * only; remains `null` for plain directories and until hydration runs.
     */
    public ?GitSummary $summary = null;

    public function __construct(
        public readonly string $path,
        public readonly bool $isGitRepo,
    ) {}

    /**
     * The directory's basename, used as the display label in the rendered tree.
     */
    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * Whether a git repo appears anywhere among this node's rendered descendants.
     *
     * Used to keep plain container directories (e.g. `packages/`, `cli/`) bold
     * rather than dimmed when they hold a repo further down the tree — dimming
     * is reserved for subtrees with nothing of interest in them at all.
     */
    public function hasRepoDescendant(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isGitRepo || $child->hasRepoDescendant()) {
                return true;
            }
        }

        return false;
    }
}
