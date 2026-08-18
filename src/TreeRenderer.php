<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\Console;

/**
 * Renders either a raw {@see Node} tree or a compacted {@see DisplayNode}
 * tree as colored `tree`-style output.
 *
 * Every git repo row (whether from the raw or compact tree) renders the
 * full status line hydrated by {@see GitInspector::hydrateSummaries()}: a
 * green/yellow `●`/branch pair (or a neutral `⎇ detached @<sha>` for
 * detached HEAD), a `✓ clean`/`✚ N changes` cue, and cyan `↑N`/`↓N`
 * ahead/behind counts when non-zero. Plain directories never carry a
 * {@see GitSummary} and render as a bare label.
 *
 * The raw-tree renderer (`render()`) keeps the Phase 1 dim/bold distinction
 * (dim for a subtree with no repo anywhere beneath it) since it is used for
 * `--tree`, the only view where "no repo anywhere beneath it" directories
 * still render at all. The compact-tree renderer (`renderCompact()`) never
 * dims: every surviving {@see DisplayNode} is either a repo itself or leads
 * to one, since {@see TreeCompactor} prunes everything else.
 */
final class TreeRenderer
{
    /** Badge shown before a git repository's name. */
    public const string REPO_BADGE = "<style='fg-green'>●</style>";

    public function render(Node $root, Console $console): void
    {
        $console->writeln($this->renderRootLine($root->path, $root->isGitRepo, $root->summary));

        $this->renderChildren($root->children, '', $console);
    }

    public function renderCompact(DisplayNode $root, Console $console): void
    {
        $console->writeln($this->renderRootLine($root->label, $root->isGitRepo, $root->summary));

        $this->renderCompactChildren($root->children, '', $console);
    }

    /**
     * @param Node[] $children
     */
    private function renderChildren(array $children, string $prefix, Console $console): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;

            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');

            $console->writeln($prefix . $branch . $this->renderLine($child));

            $this->renderChildren($child->children, $childPrefix, $console);
        }
    }

    /**
     * @param DisplayNode[] $children
     */
    private function renderCompactChildren(array $children, string $prefix, Console $console): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;

            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');

            $console->writeln($prefix . $branch . $this->renderCompactLine($child));

            $this->renderCompactChildren($child->children, $childPrefix, $console);
        }
    }

    private function renderRootLine(string $label, bool $isGitRepo, ?GitSummary $summary): string
    {
        if ($isGitRepo) {
            return $this->renderRepoLine($label, $summary);
        }

        return "<style='bold'>{$label}</style>";
    }

    private function renderLine(Node $node): string
    {
        if ($node->isGitRepo) {
            return $this->renderRepoLine($node->name(), $node->summary);
        }

        if ($node->hasRepoDescendant()) {
            return "<style='bold'>{$node->name()}</style>";
        }

        return "<style='dim'>{$node->name()}</style>";
    }

    private function renderCompactLine(DisplayNode $node): string
    {
        if ($node->isGitRepo) {
            return $this->renderRepoLine($node->label, $node->summary);
        }

        return "<style='bold'>{$node->label}</style>";
    }

    /**
     * Renders a git repo's full status line: bold name, branch/detached
     * badge, clean/dirty cue, and ahead/behind counts, matching the
     * approved output mock exactly.
     */
    private function renderRepoLine(string $label, ?GitSummary $summary): string
    {
        $name = "<style='bold'>{$label}</style>";

        if ($summary === null) {
            // Defensive fallback: hydration didn't run for this node.
            return "{$name}  " . self::REPO_BADGE;
        }

        $statusColor = $summary->isClean() ? 'fg-green' : 'fg-yellow';

        $branchSegment = $summary->isDetached
            ? "⎇ detached @{$summary->headSha}"
            : "<style='{$statusColor}'>●</style> <style='{$statusColor}'>{$summary->branch}</style>";

        $changeCue = $summary->isClean()
            ? "<style='fg-green'>✓ clean</style>"
            : "<style='fg-yellow'>✚ {$summary->changeCount} changes</style>";

        return "{$name}  {$branchSegment}  {$changeCue}" . $this->renderAheadBehind($summary);
    }

    private function renderAheadBehind(GitSummary $summary): string
    {
        $suffix = '';

        if ($summary->aheadCount !== null && $summary->aheadCount > 0) {
            $suffix .= "  <style='fg-cyan'>↑{$summary->aheadCount}</style>";
        }

        if ($summary->behindCount !== null && $summary->behindCount > 0) {
            $suffix .= "  <style='fg-cyan'>↓{$summary->behindCount}</style>";
        }

        return $suffix;
    }
}
