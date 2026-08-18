<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\Console;

/**
 * Renders a {@see Node} tree as colored `tree`-style output.
 *
 * Phase 1 only distinguishes git repos from plain directories via a badge
 * (a green `●` for repos, no badge for plain directories). Branch/dirty
 * state/ahead-behind detail is added on top of the repo badge in Phase 2.
 */
final class TreeRenderer
{
    /** Badge shown before a git repository's name. */
    public const string REPO_BADGE = "<style='fg-green'>●</style>";

    public function render(Node $root, Console $console): void
    {
        $console->writeln("<style='bold'>{$root->path}</style>");

        $this->renderChildren($root->children, '', $console);
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
}
