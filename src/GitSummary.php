<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * The hydrated git status of a single repository, as gathered by
 * {@see GitInspector::hydrateSummaries()}.
 */
final class GitSummary
{
    public function __construct(
        public readonly ?string $branch,
        public readonly bool $isDetached,
        public readonly ?string $headSha,
        public readonly int $changeCount,
        public readonly ?int $aheadCount,
        public readonly ?int $behindCount,
    ) {}

    /**
     * Whether the repo has zero pending changes.
     */
    public function isClean(): bool
    {
        return $this->changeCount === 0;
    }
}
