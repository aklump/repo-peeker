<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * The symbol and color constants {@see TreeRenderer} uses to render a
 * `status` row, pulled out into one shared place so {@see LegendCommand}
 * can build its key from the exact same values instead of duplicating
 * (and risking drifting from) what `status` actually renders.
 */
final class StatusStyles
{
    /** Badge for a clean/dirty git repo (colored per state) or its default-styled fallback. */
    public const string SYMBOL_REPO = '●';

    /** Badge for a repo in detached HEAD state; rendered with no color (neutral). */
    public const string SYMBOL_DETACHED = '⎇';

    /** Change-count cue shown when a repo has zero pending changes. */
    public const string SYMBOL_CLEAN = '✓';

    /** Prefixes the pending-change count when a repo is dirty, e.g. `+3`. */
    public const string CHANGE_PREFIX = '+';

    /** Prefixes the ahead count relative to upstream, e.g. `↑2`. */
    public const string SYMBOL_AHEAD = '↑';

    /** Prefixes the behind count relative to upstream, e.g. `↓1`. */
    public const string SYMBOL_BEHIND = '↓';

    /** Color used for a clean repo's badge, branch, and change cue. */
    public const string COLOR_CLEAN = 'fg-green';

    /** Color used for a dirty repo's badge, branch, and change cue. */
    public const string COLOR_DIRTY = 'fg-yellow';

    /** Color (+ weight) used for non-zero ahead/behind counts. */
    public const string COLOR_AHEAD_BEHIND = 'fg-cyan bold';

    /** Weight applied to a directory name and to a dirty repo's change count. */
    public const string STYLE_BOLD = 'bold';

    /** Style used for a subtree with no repo beneath it (`--tree` only) and for the trailing remote URL column. */
    public const string STYLE_DIM = 'dim';

    /**
     * Wraps `$text` in Tempest's inline `<style='...'>` console markup.
     */
    public static function styled(string $text, string $style): string
    {
        return "<style='{$style}'>{$text}</style>";
    }
}
