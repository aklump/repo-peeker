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

    public function test_status_tree_flag_renders_a_tree_with_repo_and_plain_directory_badges(): void
    {
        $this->makeDirs([
            'plain-project',
            'git-project/.git',
        ]);

        $this->console
            ->call("status {$this->fixtureRoot} -L 2 --tree")
            ->assertSuccess()
            ->assertSee($this->fixtureRoot)
            ->assertSee('plain-project')
            ->assertSee('git-project')
            ->assertSee('●');
    }

    public function test_status_tree_flag_dims_plain_directories_and_bolds_git_repos(): void
    {
        $this->makeDirs(['plain-project', 'git-project/.git']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 2 --tree")
            ->assertSuccess()
            ->assertContainsFormattedText("\e[2mplain-project\e[22m")
            ->assertContainsFormattedText("\e[1mgit-project\e[22m");
    }

    public function test_status_tree_flag_bolds_a_plain_container_directory_that_holds_a_repo(): void
    {
        $this->makeDirs(['container/repo-project/.git', 'lonely-dir']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3 --tree")
            ->assertSuccess()
            ->assertContainsFormattedText("\e[1mcontainer\e[22m")
            ->assertContainsFormattedText("\e[2mlonely-dir\e[22m");
    }

    public function test_status_tree_flag_respects_the_depth_cap(): void
    {
        $this->makeDirs(['level-1/level-2/level-3']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 1 --tree")
            ->assertSuccess()
            ->assertSee('level-1')
            ->assertNotSee('level-2');
    }

    public function test_status_tree_flag_default_depth_is_two_when_l_is_omitted(): void
    {
        $this->makeDirs(['level-1/level-2/level-3']);

        $this->console
            ->call("status {$this->fixtureRoot} --tree")
            ->assertSuccess()
            ->assertSee('level-1')
            ->assertSee('level-2')
            ->assertNotSee('level-3');
    }

    public function test_status_default_compact_view_prunes_uninteresting_directories_and_collapses_pass_through_paths(): void
    {
        $this->makeDirs([
            'directio/.idea',
            'directio/reproduce_issue',
            'directio/app/.git',
        ]);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3")
            ->assertSuccess()
            ->assertSee($this->fixtureRoot)
            ->assertSee('directio/app')
            ->assertNotSee('.idea')
            ->assertNotSee('reproduce_issue');
    }

    public function test_status_default_compact_view_keeps_its_own_row_for_a_container_with_multiple_interesting_branches(): void
    {
        $this->makeDirs([
            'packages/php/repo/.git',
            'packages/js/repo/.git',
        ]);

        $this->console
            ->call("status {$this->fixtureRoot} -L 4")
            ->assertSuccess()
            ->assertSee('packages')
            ->assertSee('php/repo')
            ->assertSee('js/repo');
    }

    public function test_status_tree_flag_disables_pruning_and_shows_uninteresting_directories(): void
    {
        $this->makeDirs([
            'directio/.idea',
            'directio/app/.git',
        ]);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3 --tree")
            ->assertSuccess()
            ->assertSee('directio')
            ->assertSee('.idea')
            ->assertSee('app');
    }

    public function test_status_prints_no_repos_found_and_falls_back_to_the_full_tree_when_none_exist(): void
    {
        $this->makeDirs(['plain-a', 'plain-b/plain-c']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 3")
            ->assertSuccess()
            ->assertSee('No git repositories found')
            ->assertSee('plain-a')
            ->assertSee('plain-c');
    }

    public function test_status_does_not_report_no_repos_found_when_the_root_itself_is_a_repo(): void
    {
        $this->makeDirs(['.git']);

        $this->console
            ->call("status {$this->fixtureRoot} -L 2")
            ->assertSuccess()
            ->assertNotSee('No git repositories found')
            ->assertSee('●');
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
