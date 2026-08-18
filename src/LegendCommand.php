<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

/**
 * Prints a static key explaining every symbol/color {@see StatusCommand}
 * uses, built from the same {@see StatusStyles} constants
 * {@see TreeRenderer} renders with, so `legend` can't drift out of sync
 * with what `status` actually shows.
 */
final readonly class LegendCommand {

  use HasConsole;

  #[ConsoleCommand(name: 'legend', description: 'Show what the status symbols and colors mean')]
  public function __invoke(): void {
    $entries = [
      $this->entry(
        StatusStyles::styled('(no badge)', StatusStyles::STYLE_DIM),
        '(no badge)',
        'not a git repository — plain directory',
      ),
      $this->entry(
        StatusStyles::styled(StatusStyles::SYMBOL_REPO, StatusStyles::COLOR_CLEAN) . ' ' . StatusStyles::styled('<name>', StatusStyles::STYLE_BOLD),
        StatusStyles::SYMBOL_REPO . ' <name>',
        'repository is clean — <name> is hyperlinked to its local path when your terminal supports it',
      ),
      $this->entry(
        StatusStyles::styled(StatusStyles::SYMBOL_REPO, StatusStyles::COLOR_DIRTY) . ' ' . StatusStyles::styled('<name>', StatusStyles::STYLE_BOLD),
        StatusStyles::SYMBOL_REPO . ' <name>',
        'repository has changes',
      ),
      $this->entry(
        StatusStyles::SYMBOL_DETACHED . ' ' . StatusStyles::styled('<name>', StatusStyles::STYLE_BOLD),
        StatusStyles::SYMBOL_DETACHED . ' <name>',
        'repository in detached HEAD',
      ),
      $this->entry(
        StatusStyles::styled(StatusStyles::SYMBOL_CLEAN, StatusStyles::COLOR_CLEAN),
        StatusStyles::SYMBOL_CLEAN,
        'zero pending changes',
      ),
      $this->entry(
        StatusStyles::styled(StatusStyles::CHANGE_PREFIX . 'N', StatusStyles::COLOR_DIRTY . ' ' . StatusStyles::STYLE_BOLD),
        StatusStyles::CHANGE_PREFIX . 'N',
        'N files with pending changes (staged, unstaged, untracked, or conflicted)',
      ),
      $this->entry(
        StatusStyles::styled(StatusStyles::SYMBOL_AHEAD . 'N', StatusStyles::COLOR_AHEAD_BEHIND) . ' / ' . StatusStyles::styled(StatusStyles::SYMBOL_BEHIND . 'N', StatusStyles::COLOR_AHEAD_BEHIND),
        StatusStyles::SYMBOL_AHEAD . 'N / ' . StatusStyles::SYMBOL_BEHIND . 'N',
        'commits ahead / behind upstream — omitted when there is no upstream',
      ),
      $this->entry(
        StatusStyles::styled('<current branch>', StatusStyles::COLOR_CLEAN),
        '<current branch>',
        'clean — current branch name',
      ),
      $this->entry(
        StatusStyles::styled('<current branch>', StatusStyles::COLOR_DIRTY),
        '<current branch>',
        'dirty — current branch name',
      ),
      $this->entry(
        '<current branch>',
        '<current branch>',
        'detached HEAD@<sha> — current branch name',
      ),
      $this->entry(
        StatusStyles::styled('<remote url>', StatusStyles::STYLE_DIM),
        '<remote url>',
        '`origin` remote URL — hyperlinked to its HTTPS equivalent when possible',
      ),
    ];

    $width = max(array_map(static fn(array $entry): int => mb_strlen($entry['plain']), $entries));

    $this->writeln();

    foreach ($entries as $entry) {
      $pad = str_repeat(' ', $width - mb_strlen($entry['plain']));

      $this->writeln("  {$entry['styled']}{$pad}  {$entry['description']}");
    }
  }

  /**
   * @return array{styled:string,plain:string,description:string}
   */
  private function entry(string $styled, string $plain, string $description): array {
    return [
      'styled' => $styled,
      'plain' => $plain,
      'description' => $description,
    ];
  }
}
