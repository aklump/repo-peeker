<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\Console;

/**
 * Renders either a raw {@see Node} tree or a compacted {@see DisplayNode}
 * tree as colored `tree`-style output.
 *
 * Phase 1 only distinguishes git repos from plain directories via a badge
 * (a green `●` for repos, no badge for plain directories). Branch/dirty
 * state/ahead-behind detail is added on top of the repo badge in Phase 3.
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
        $badge = $root->isGitRepo ? '  ' . self::REPO_BADGE : '';

        $console->writeln("<style='bold'>{$root->path}</style>{$badge}");

        $this->renderChildren($root->children, '', $console);
    }

    public function renderCompact(DisplayNode $root, Console $console): void
    {
        $badge = $root->isGitRepo ? '  ' . self::REPO_BADGE : '';

        $console->writeln("<style='bold'>{$root->label}</style>{$badge}");

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

    private function renderLine(Node $node): string
    {
        if ($node->isGitRepo) {
            return "<style='bold'>{$node->name()}</style>  " . self::REPO_BADGE;
        }

        if ($node->hasRepoDescendant()) {
            return "<style='bold'>{$node->name()}</style>";
        }

        return "<style='dim'>{$node->name()}</style>";
    }

    private function renderCompactLine(DisplayNode $node): string
    {
        if ($node->isGitRepo) {
            return "<style='bold'>{$node->label}</style>  " . self::REPO_BADGE;
        }

        return "<style='bold'>{$node->label}</style>";
    }
}
