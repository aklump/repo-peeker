<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\ConsoleCommands;
use RepoPeeker\StatusCommand;
use Tempest\Console\Commands\AboutCommand;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ConsoleConfig;
use Tempest\Reflection\MethodReflector;

/**
 * @internal
 */
final class ConsoleCommandsTest extends TestCase
{
    public function test_restrict_to_app_keeps_only_commands_declared_in_the_given_namespace(): void
    {
        $ours = (new ConsoleCommand(name: 'status'))
            ->setHandler(MethodReflector::fromParts(StatusCommand::class, '__invoke'));

        $foreign = (new ConsoleCommand(name: 'about'))
            ->setHandler(MethodReflector::fromParts(AboutCommand::class, '__invoke'));

        $consoleConfig = new ConsoleConfig(commands: [
            'status' => $ours,
            'about' => $foreign,
        ]);

        ConsoleCommands::restrictToApp($consoleConfig, namespace: 'RepoPeeker\\');

        $this->assertSame(['status' => $ours], $consoleConfig->commands);
    }
}
