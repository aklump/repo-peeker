<?php

declare(strict_types=1);

namespace Tests;

/**
 * @internal
 */
final class ConsoleOverviewTest extends IntegrationTestCase
{
    public function test_overview_lists_only_this_apps_own_commands(): void
    {
        $this->console
            ->call('')
            ->assertSuccess()
            ->assertSee('status')
            ->assertSee('legend')
            ->assertNotSee('Shows insights about the application')
            ->assertNotSee('cache:clear')
            ->assertNotSee('container:show')
            ->assertNotSee('discovery:status')
            ->assertNotSee('make:command')
            ->assertNotSee('schedule:run')
            ->assertNotSee('tail:debug');
    }
}
