<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final readonly class StatusCommand
{
    use HasConsole;

    public function __construct(
        private DirectoryWalker $walker,
        private TreeRenderer $renderer,
        private TreeCompactor $compactor,
        private GitInspector $gitInspector,
    ) {}

    #[ConsoleCommand(name: 'status', aliases: ['st'], description: 'Show git status for a folder of projects')]
    public function __invoke(
        string $path = '.',
        #[ConsoleArgument(
            description: 'Limit output to N levels of depth (like tree -L)',
            aliases: ['-L'],
        )]
        ?int $depth = 2,
        #[ConsoleArgument(
            description: 'Also descend into repos to look for nested/submodule repos',
            aliases: ['--nested'],
        )]
        bool $nested = false,
        #[ConsoleArgument(
            description: 'Show the full filesystem tree, uncollapsed',
            aliases: ['--full'],
        )]
        bool $full = false,
    ): void {
        $walkedTree = $this->walker->walk($path, $depth, $nested);
        $this->gitInspector->hydrateSummaries($walkedTree);

        if ($full) {
            $this->renderer->render($walkedTree, $this->console);

            return;
        }

        $compactTree = $this->compactor->compact($walkedTree);

        if (! $compactTree->isGitRepo && $compactTree->children === []) {
            $this->console->writeln('No git repositories found');
            $this->renderer->render($walkedTree, $this->console);

            return;
        }

        $this->renderer->renderCompact($compactTree, $this->console);
    }
}
