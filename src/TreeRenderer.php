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
 * Every row's name — repo or plain directory alike — is itself wrapped in a
 * terminal hyperlink escape pointing at a `file://` URI for its real local
 * path (see `fileUrl()`), so clicking it opens that directory the same way
 * clicking any other path in the terminal would.
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
    public function render(Node $root, Console $console): void
    {
        $rows = [$this->makeRow('', $root->path, dim: false, isRepo: $root->isGitRepo, summary: $root->summary, path: $root->path)];
        $this->collectRawChildren($root->children, '', $rows);

        $this->writeRows($rows, $console);
    }

    public function renderCompact(DisplayNode $root, Console $console): void
    {
        $rows = [$this->makeRow('', $root->label, dim: false, isRepo: $root->isGitRepo, summary: $root->summary, path: $root->path)];
        $this->collectCompactChildren($root->children, '', $rows);

        $this->writeRows($rows, $console);
    }

    /**
     * @param Node[] $children
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string}> $rows
     */
    private function collectRawChildren(array $children, string $prefix, array &$rows): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;
            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $dim = ! $child->isGitRepo && ! $child->hasRepoDescendant();

            $rows[] = $this->makeRow($prefix . $branch, $child->name(), $dim, $child->isGitRepo, $child->summary, $child->path);

            $this->collectRawChildren($child->children, $childPrefix, $rows);
        }
    }

    /**
     * @param DisplayNode[] $children
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string}> $rows
     */
    private function collectCompactChildren(array $children, string $prefix, array &$rows): void
    {
        $lastIndex = count($children) - 1;

        foreach ($children as $index => $child) {
            $isLast = $index === $lastIndex;
            $branch = $isLast ? '└── ' : '├── ';
            $childPrefix = $prefix . ($isLast ? '    ' : '│   ');

            $rows[] = $this->makeRow($prefix . $branch, $child->label, dim: false, isRepo: $child->isGitRepo, summary: $child->summary, path: $child->path);

            $this->collectCompactChildren($child->children, $childPrefix, $rows);
        }
    }

    /**
     * @return array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string}
     */
    private function makeRow(string $prefix, string $label, bool $dim, bool $isRepo, ?GitSummary $summary, string $path): array
    {
        return ['prefix' => $prefix, 'label' => $label, 'dim' => $dim, 'isRepo' => $isRepo, 'summary' => $summary, 'path' => $path];
    }

    /**
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string}> $rows
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
     * @param list<array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string}> $rows
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
     * @param array{prefix:string,label:string,dim:bool,isRepo:bool,summary:?GitSummary,path:string} $row
     */
    private function renderRow(array $row, int $nameWidth, int $changesWidth, int $abWidth, int $branchWidth): string
    {
        if (! $row['isRepo']) {
            $labelStyle = $row['dim'] ? StatusStyles::STYLE_DIM : StatusStyles::STYLE_BOLD;
            $name = $this->hyperlink(StatusStyles::styled($row['label'], $labelStyle), $this->fileUrl($row['path']));

            return $row['prefix'] . $name;
        }

        $segments = $this->repoSegments($row['label'], $row['summary']);
        $name = $this->hyperlink($segments['nameStyled'], $this->fileUrl($row['path']));

        $line = $row['prefix']
            . $segments['badgeStyled'] . ' '
            . $name . $this->gap($nameWidth - mb_strlen($row['prefix'] . $row['label']));

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

        $display = StatusStyles::styled($remoteUrl, StatusStyles::STYLE_DIM);
        $webUrl = $this->webUrl($remoteUrl);
        $remoteField = $webUrl === null ? $display : $this->hyperlink($display, $webUrl);

        return $line . $this->gap($branchWidth - mb_strlen($segments['branchPlain'])) . '  ' . $remoteField;
    }

    /**
     * Resolves `$path` to a `file://` URI so the name column is clickable in
     * terminals that support hyperlink escapes — jumping to the row's real
     * local directory (Finder/Explorer/file manager, whatever the OS opens a
     * directory with), the same way `webUrl()` makes the remote column
     * clickable. `$path` may be relative to the invoking cwd (e.g. when
     * `status` was run against `.`), so it's resolved to an absolute path
     * via `realpath()` first — the displayed label is untouched, only the
     * link target changes.
     */
    private function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', realpath($path) ?: $path);

        // Windows drive-letter paths (e.g. `C:/Users/...`) need a leading
        // slash after the `file://` authority and an unencoded drive colon.
        if (preg_match('#^([A-Za-z]):/(.*)$#', $normalized, $matches)) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $matches[2])));

            return "file:///{$matches[1]}:/{$encoded}";
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));

        return "file://{$encoded}";
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
                'badgePlain' => StatusStyles::SYMBOL_REPO,
                'badgeStyled' => StatusStyles::styled(StatusStyles::SYMBOL_REPO, StatusStyles::COLOR_CLEAN),
                'nameStyled' => StatusStyles::styled($label, StatusStyles::STYLE_BOLD),
                'abPlain' => '',
                'abStyled' => '',
                'changesPlain' => '',
                'changesStyled' => '',
                'branchPlain' => '',
                'branchStyled' => '',
            ];
        }

        $statusColor = $summary->isClean() ? StatusStyles::COLOR_CLEAN : StatusStyles::COLOR_DIRTY;

        $abPlain = $this->plainAheadBehind($summary);
        $branchPlain = $summary->isDetached ? "detached @{$summary->headSha}" : $summary->branch;

        return [
            'badgePlain' => $summary->isDetached ? StatusStyles::SYMBOL_DETACHED : StatusStyles::SYMBOL_REPO,
            'badgeStyled' => $summary->isDetached
                ? StatusStyles::SYMBOL_DETACHED
                : StatusStyles::styled(StatusStyles::SYMBOL_REPO, $statusColor),
            'nameStyled' => StatusStyles::styled($label, StatusStyles::STYLE_BOLD),
            'abPlain' => $abPlain,
            'abStyled' => $abPlain === '' ? '' : $this->styledAheadBehind($summary),
            'changesPlain' => $summary->isClean() ? StatusStyles::SYMBOL_CLEAN : StatusStyles::CHANGE_PREFIX . $summary->changeCount,
            'changesStyled' => $summary->isClean()
                ? StatusStyles::styled(StatusStyles::SYMBOL_CLEAN, StatusStyles::COLOR_CLEAN)
                : StatusStyles::styled(
                    StatusStyles::CHANGE_PREFIX . $summary->changeCount,
                    StatusStyles::COLOR_DIRTY . ' ' . StatusStyles::STYLE_BOLD,
                ),
            'branchPlain' => $branchPlain,
            'branchStyled' => $summary->isDetached
                ? $branchPlain
                : StatusStyles::styled($branchPlain, $statusColor),
        ];
    }

    private function plainAheadBehind(GitSummary $summary): string
    {
        $parts = [];

        if ($summary->aheadCount !== null && $summary->aheadCount > 0) {
            $parts[] = StatusStyles::SYMBOL_AHEAD . $summary->aheadCount;
        }

        if ($summary->behindCount !== null && $summary->behindCount > 0) {
            $parts[] = StatusStyles::SYMBOL_BEHIND . $summary->behindCount;
        }

        return implode('  ', $parts);
    }

    private function styledAheadBehind(GitSummary $summary): string
    {
        $parts = [];

        if ($summary->aheadCount !== null && $summary->aheadCount > 0) {
            $parts[] = StatusStyles::styled(StatusStyles::SYMBOL_AHEAD . $summary->aheadCount, StatusStyles::COLOR_AHEAD_BEHIND);
        }

        if ($summary->behindCount !== null && $summary->behindCount > 0) {
            $parts[] = StatusStyles::styled(StatusStyles::SYMBOL_BEHIND . $summary->behindCount, StatusStyles::COLOR_AHEAD_BEHIND);
        }

        return implode('  ', $parts);
    }
}
