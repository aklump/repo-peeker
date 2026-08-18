<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\ConsoleCommands;
use Tempest\Console\ConsoleConfig;
use Tempest\Console\Testing\ConsoleTester;
use Tempest\Container\Container;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\Kernel;

abstract class IntegrationTestCase extends TestCase
{
    protected string $root;

    /** @var \Tempest\Core\DiscoveryLocation[] */
    protected array $discoveryLocations = [];

    protected Kernel $kernel;

    protected Container $container;

    protected ConsoleTester $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root ??= __DIR__ . '/../';

        $this->kernel ??= FrameworkKernel::boot(
            root: $this->root,
            discoveryLocations: $this->discoveryLocations,
        );

        $this->container = $this->kernel->container;

        ConsoleCommands::restrictToApp($this->container->get(ConsoleConfig::class));

        $this->console = $this->container->get(ConsoleTester::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->root);
        unset($this->discoveryLocations);
        unset($this->kernel);
        unset($this->container);
        unset($this->console);
    }
}
