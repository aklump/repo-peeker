<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * A single row in the compacted display tree produced by {@see TreeCompactor}.
 *
 * Unlike {@see Node}, whose name is always derived from its filesystem path,
 * a DisplayNode's label may be a slash-joined chain of merged directory
 * names (e.g. `directio/app`) when {@see TreeCompactor} has folded a run of
 * plain pass-through directories into a single line ahead of a repo (or a
 * subtree containing one).
 */
final class DisplayNode
{
    /** @var DisplayNode[] */
    public array $children = [];

    public function __construct(
        public readonly string $label,
        public readonly bool $isGitRepo,
    ) {}
}
