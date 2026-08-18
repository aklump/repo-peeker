<?php

declare(strict_types=1);

namespace RepoPeeker;

use Tempest\Console\ConsoleConfig;

/**
 * Tempest's own discovery unconditionally registers its built-in commands
 * (`about`, `cache:*`, `container:show`, `discovery:*`, `make:*`,
 * `schedule:*`, `tail:*`, `install`, ...) onto {@see ConsoleConfig} alongside
 * this app's own. Since `gitpeek` is a standalone single-purpose tool, not a
 * full Tempest project, its help/overview and command resolution should only
 * ever expose commands this app actually declares.
 */
final class ConsoleCommands
{
    public static function restrictToApp(ConsoleConfig $consoleConfig, string $namespace = __NAMESPACE__ . '\\'): void
    {
        $consoleConfig->commands = array_filter(
            $consoleConfig->commands,
            static fn ($command) => str_starts_with($command->handler->getDeclaringClass()->getName(), $namespace),
        );
    }
}
