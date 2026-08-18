<?php

declare(strict_types=1);

namespace Tests;

/**
 * @internal
 */
final class StatusCommandTest extends IntegrationTestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir() . '/gitpeek-status-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_status_renders_a_tree_with_repo_and_plain_directory_badges(): void
    {
        $this->makeDirs([
            'plain-project',
            'git-project/.git',
        ]);

        $this->console
            ->call("status {$this->fixtureRoot} -L 2")
            ->assertSuccess()
            ->assertSee($this->fixtureRoot)
            ->assertSee('plain-project')
            ->assertSee('git-project')
            ->assertSee('●');
    }

    public function test_status_dims_plain_directories_and_bolds_git_repos(): void
    {
        $this->makeDirs(['plain-project', 'git-project/.git']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 2")
            ->assertSuccess()
            ->assertContainsFormattedText("\e[2mplain-project\e[22m")
            ->assertContainsFormattedText("\e[1mgit-project\e[22m");
    }

    public function test_status_bolds_a_plain_container_directory_that_holds_a_repo(): void
    {
        $this->makeDirs(['container/repo-project/.git', 'lonely-dir']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3")
            ->assertSuccess()
            ->assertContainsFormattedText("\e[1mcontainer\e[22m")
            ->assertContainsFormattedText("\e[2mlonely-dir\e[22m");
    }

    public function test_status_respects_the_depth_cap(): void
    {
        $this->makeDirs(['level-1/level-2/level-3']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 1")
            ->assertSuccess()
            ->assertSee('level-1')
            ->assertNotSee('level-2');
    }

    public function test_status_default_depth_is_two_when_l_is_omitted(): void
    {
        $this->makeDirs(['level-1/level-2/level-3']);

        $this->console
            ->call("status {$this->fixtureRoot}")
            ->assertSuccess()
            ->assertSee('level-1')
            ->assertSee('level-2')
            ->assertNotSee('level-3');
    }

    public function test_status_hides_a_nested_repo_by_default(): void
    {
        $this->makeDirs(['outer-repo/.git', 'outer-repo/inner-repo/.git']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3")
            ->assertSuccess()
            ->assertSee('outer-repo')
            ->assertNotSee('inner-repo');
    }

    public function test_status_reveals_a_nested_repo_with_the_nested_flag(): void
    {
        $this->makeDirs(['outer-repo/.git', 'outer-repo/inner-repo/.git']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3 --nested")
            ->assertSuccess()
            ->assertSee('outer-repo')
            ->assertSee('inner-repo');
    }

    /**
     * @param string[] $relativeDirs
     */
    private function makeDirs(array $relativeDirs): void
    {
        foreach ($relativeDirs as $relativeDir) {
            mkdir($this->fixtureRoot . '/' . $relativeDir, recursive: true);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
