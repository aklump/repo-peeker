<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\Console;

/**
 * Renders either a raw {@see Node} tree or a compacted {@see DisplayNode}
 * tree as colored `tree`-style output.
 *
 * Every git repo row (whether from the raw or compact tree) renders the full
 * status line hydrated by {@see GitInspector::hydrateSummaries()}, in column
 * order: a green/yellow `●` (or neutral `⎇` for detached HEAD), the name, a
 * bold `+N` pending change count (or a plain `✓` when clean), bold cyan
 * `↑N`/`↓N` ahead/behind counts when non-zero, the branch (or `detached
 * @<sha>`), and a dim `origin` remote URL when one is configured, wrapped in
 * a terminal hyperlink escape pointing at a best-effort HTTPS equivalent
 * (see `webUrl()`) so SSH-style remotes are clickable too. Plain directories
 * never carry a {@see GitSummary} and render as a bare label.
 *
 * The raw-tree renderer (`render()`) keeps the Phase 1 dim/bold distinction
 * (dim for a subtree with no repo anywhere beneath it) since it is used for
 * `--tree`, the only view where "no repo anywhere beneath it" directories
 * still render at all. The compact-tree renderer (`renderCompact()`) never
 * dims: every surviving {@see DisplayNode} is either a repo itself or leads
 * to one, since {@see TreeCompactor} prunes everything else.
 *
 * The name/changes/ahead-behind columns line up at a fixed position across
 * the *entire* rendered tree, regardless of nesting depth or how long any
 * individual name happens to be (branch is rendered last and needs no
 * padding). Rendering therefore happens in two passes: first collect every
 * row's plain-text pieces, then measure the widest of each column across all
 * repo rows before padding and writing each line.
 */
final class TreeRenderer
{
    /** Badge shown before a git repository's name. */
    public const string REPO_BADGE = "<style='fg-green'>●</style>";

    public function render(Node $root, Console $console): void
    {
        $rows = [$this->makeRow('', $root->path, dim: false, isRepo: $root->isGitRepo, summary: $root->summary)];
        $this->collectRawChildren($root->children, '', $rows);

        $this->writeRows($rows, $console);
    }

    public function renderCompact(DisplayNode $root, Console $console): void
    {
        $rows = [$this->makeRow('', $root->label, dim: false, isRepo: $root->isGitRepo, summary: $root->summary)];
        $this->collectCompactChildren($root->children, '', $rows);

        $this->writeRows($rows, $console);
    }

    /**
     * @param Node[] $children
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary}> $rows
     */
    private function collectRawChildren(array $children, string $prefix, array &$rows): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;
            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $dim = ! $child->isGitRepo && ! $child->hasRepoDescendant();

            $rows[] = $this->makeRow($prefix . $branch, $child->name(), $dim, $child->isGitRepo, $child->summary);

            $this->collectRawChildren($child->children, $childPrefix, $rows);
        }
    }

    /**
     * @param DisplayNode[] $children
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary}> $rows
     */
    private function collectCompactChildren(array $children, string $prefix, array &$rows): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;
            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');

            $rows[] = $this->makeRow($prefix . $branch, $child->label, dim: false, isRepo: $child->isGitRepo, summary: $child->summary);

            $this->collectCompactChildren($child->children, $childPrefix, $rows);
        }
    }

    /**
     * @return array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary}
     */
    private function makeRow(string $prefix, string $label, bool $dim, bool $isRepo, ?GitSummary $summary): array
    {
        return ['prefix' => $prefix, 'label' => $label, 'dim' => $dim, 'isRepo' => $isRepo, 'summary' => $summary];
    }

    /**
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary}> $rows
     */
    private function writeRows(array $rows, Console $console): void
    {
        [$nameWidth, $changesWidth, $abWidth, $branchWidth] = $this->columnWidths($rows);

        foreach ($rows as $row) {
            $console->writeln($this->renderRow($row, $nameWidth, $changesWidth, $abWidth, $branchWidth));
        }
    }

    /**
     * Measures the widest name/changes/ahead-behind/branch column across
     * every repo row, since only repo rows have columns that need to line up
     * (the trailing remote URL is rendered last and needs no padding).
     *
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary}> $rows
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function columnWidths(array $rows): array
    {
        $nameWidth = 0;
        $changesWidth = 0;
        $abWidth = 0;
        $branchWidth = 0;

        foreach ($rows as $row) {
            if (! $row['isRepo']) {
                continue;
            }

            $nameWidth = max($nameWidth, mb_strlen($row['prefix'] . $row['label']));

            if ($row['summary'] === null) {
                continue;
            }

            $segments = $this->repoSegments($row['label'], $row['summary']);

            $changesWidth = max($changesWidth, mb_strlen($segments['changesPlain']));
            $abWidth = max($abWidth, mb_strlen($segments['abPlain']));
            $branchWidth = max($branchWidth, mb_strlen($segments['branchPlain']));
        }

        return [$nameWidth, $changesWidth, $abWidth, $branchWidth];
    }

    /**
     * @param array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary} $row
     */
    private function renderRow(array $row, int $nameWidth, int $changesWidth, int $abWidth, int $branchWidth): string
    {
        if (! $row['isRepo']) {
            $labelStyle = $row['dim'] ? 'dim' : 'bold';

            return $row['prefix'] . "<style='{$labelStyle}'>{$row['label']}</style>";
        }

        $segments = $this->repoSegments($row['label'], $row['summary']);

        $line = $row['prefix']
            . $segments['badgeStyled'] . ' '
            . $segments['nameStyled'] . $this->gap($nameWidth - mb_strlen($row['prefix'] . $row['label']));

        if ($row['summary'] === null) {
            return $line;
        }

        $line .= '  ' . $this->gap($changesWidth - mb_strlen($segments['changesPlain'])) . $segments['changesStyled'];

        if ($abWidth > 0) {
            $abField = $segments['abStyled'] === ''
                ? $this->gap($abWidth)
                : $segments['abStyled'] . $this->gap($abWidth - mb_strlen($segments['abPlain']));

            $line .= '  ' . $abField;
        }

        $line .= '  ' . $segments['branchStyled'];

        $remoteUrl = $row['summary']->remoteUrl;

        if ($remoteUrl === null) {
            return $line;
        }

        $display = "<style='dim'>{$remoteUrl}</style>";
        $webUrl = $this->webUrl($remoteUrl);
        $remoteField = $webUrl === null ? $display : $this->hyperlink($display, $webUrl);

        return $line . $this->gap($branchWidth - mb_strlen($segments['branchPlain'])) . '  ' . $remoteField;
    }

    /**
     * Best-effort conversion of a git remote URL into a browsable web URL,
     * so the trailing remote column is a clickable link (via a terminal
     * hyperlink escape) even for SSH-style remotes, which most terminals
     * don't auto-linkify. Returns `null` for anything that isn't
     * confidently a host-and-path remote (e.g. a local filesystem path),
     * rather than guessing at a link that might not resolve.
     */
    private function webUrl(string $remoteUrl): ?string
    {
        if (preg_match('#^https?://#', $remoteUrl)) {
            return rtrim(preg_replace('/\.git$/', '', $remoteUrl), '/');
        }

        // `ssh://[user@]host[:port]/path` and `git://host/path`.
        if (preg_match('#^(?:ssh|git)://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+)$#', $remoteUrl, $matches)) {
            return "https://{$matches[1]}/" . preg_replace('/\.git$/', '', $matches[2]);
        }

        // scp-like syntax: `user@host:path`.
        if (preg_match('#^[\w.-]+@([\w.-]+):(.+)$#', $remoteUrl, $matches)) {
            return "https://{$matches[1]}/" . preg_replace('/\.git$/', '', $matches[2]);
        }

        return null;
    }

    private function hyperlink(string $text, string $url): string
    {
        return "\e]8;;{$url}\e\\{$text}\e]8;;\e\\";
    }

    private function gap(int $width): string
    {
        return $width > 0 ? str_repeat(' ', $width) : '';
    }

    /**
     * Builds every plain/styled text piece needed to render a git repo row,
     * keyed by column.
     *
     * @return array{badgePlain:string,badgeStyled:string,nameStyled:string,abPlain:string,abStyled:string,changesPlain:string,changesStyled:string,branchPlain:string,branchStyled:string}
     */
    private function repoSegments(string $label, ?GitSummary $summary): array
    {
        if ($summary === null) {
            // Defensive fallback: hydration didn't run for this node.
            return [
                'badgePlain' => '●',
                'badgeStyled' => self::REPO_BADGE,
                'nameStyled' => "<style='bold'>{$label}</style>",
                'abPlain' => '',
                'abStyled' => '',
                'changesPlain' => '',
                'changesStyled' => '',
                'branchPlain' => '',
                'branchStyled' => '',
            ];
        }

        $statusColor = $summary->isClean() ? 'fg-green' : 'fg-yellow';

        $abPlain = $this->plainAheadBehind($summary);
        $branchPlain = $summary->isDetached ? "detached @{$summary->headSha}" : $summary->branch;

        return [
            'badgePlain' => $summary->isDetached ? '⎇' : '●',
            'badgeStyled' => $summary->isDetached ? '⎇' : "<style='{$statusColor}'>●</style>",
            'nameStyled' => "<style='bold'>{$label}</style>",
            'abPlain' => $abPlain,
            'abStyled' => $abPlain === '' ? '' : $this->styledAheadBehind($summary),
            'changesPlain' => $summary->isClean() ? '✓' : "+{$summary->changeCount}",
            'changesStyled' => $summary->isClean()
                ? "<style='fg-green'>✓</style>"
                : "<style='fg-yellow bold'>+{$summary->changeCount}</style>",
            'branchPlain' => $branchPlain,
            'branchStyled' => $summary->isDetached
                ? $branchPlain
                : "<style='{$statusColor}'>{$branchPlain}</style>",
        ];
    }

    private function plainAheadBehind(GitSummary $summary): string
    {
        $parts = [];

        if ($summary->aheadCount !== null && $summary->aheadCount > 0) {
            $parts[] = "↑{$summary->aheadCount}";
        }

        if ($summary->behindCount !== null && $summary->behindCount > 0) {
            $parts[] = "↓{$summary->behindCount}";
        }

        return implode('  ', $parts);
    }

    private function styledAheadBehind(GitSummary $summary): string
    {
        $parts = [];

        if ($summary->aheadCount !== null && $summary->aheadCount > 0) {
            $parts[] = "<style='fg-cyan bold'>↑{$summary->aheadCount}</style>";
        }

        if ($summary->behindCount !== null && $summary->behindCount > 0) {
            $parts[] = "<style='fg-cyan bold'>↓{$summary->behindCount}</style>";
        }

        return implode('  ', $parts);
    }
}
