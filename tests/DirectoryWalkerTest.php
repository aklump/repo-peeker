<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\DirectoryWalker;
use RepoPeeker\GitInspector;
use RepoPeeker\Node;

/**
 * @internal
 */
final class DirectoryWalkerTest extends TestCase
{
    private string $fixtureRoot;

    private DirectoryWalker $walker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir() . '/gitpeek-walker-test-' . uniqid();

        $this->walker = new DirectoryWalker(new GitInspector());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->fixtureRoot);
    }

    public function test_walk_respects_depth_cap_on_plain_nesting(): void
    {
        $this->makeDirs([
            'level-1',
            'level-1/level-2',
            'level-1/level-2/level-3',
        ]);

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 2);

        $level1 = $this->findChild($tree, 'level-1');
        $this->assertNotNull($level1);
        $this->assertFalse($level1->isGitRepo);

        $level2 = $this->findChild($level1, 'level-2');
        $this->assertNotNull($level2);

        // Depth cap of 2 means level-2's own children (level-3) are not walked.
        $this->assertSame([], $level2->children);
    }

    public function test_walk_stops_descending_into_a_directory_containing_a_git_directory(): void
    {
        $this->makeDirs(['repo/.git', 'repo/src']);

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 5);

        $repo = $this->findChild($tree, 'repo');
        $this->assertNotNull($repo);
        $this->assertTrue($repo->isGitRepo);
        $this->assertSame([], $repo->children, 'Walker must not descend past a git repo boundary by default.');
    }

    public function test_walk_detects_a_dot_git_file_as_a_repository_worktree_case(): void
    {
        $this->makeDirs(['worktree-repo']);
        file_put_contents($this->fixtureRoot . '/worktree-repo/.git', 'gitdir: /somewhere/.git/worktrees/worktree-repo');

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 5);

        $worktreeRepo = $this->findChild($tree, 'worktree-repo');
        $this->assertNotNull($worktreeRepo);
        $this->assertTrue($worktreeRepo->isGitRepo);
    }

    public function test_default_nested_false_stops_at_outer_repo_and_never_sees_the_nested_repo(): void
    {
        $this->makeDirs(['outer-repo/.git', 'outer-repo/inner-repo/.git']);

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 5, nested: false);

        $outerRepo = $this->findChild($tree, 'outer-repo');
        $this->assertNotNull($outerRepo);
        $this->assertTrue($outerRepo->isGitRepo);
        $this->assertSame([], $outerRepo->children, 'Nested repo must be invisible when --nested is not passed.');
    }

    public function test_nested_true_descends_past_the_boundary_and_finds_the_nested_repo(): void
    {
        $this->makeDirs(['outer-repo/.git', 'outer-repo/inner-repo/.git']);

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 5, nested: true);

        $outerRepo = $this->findChild($tree, 'outer-repo');
        $this->assertNotNull($outerRepo);
        $this->assertTrue($outerRepo->isGitRepo);

        $innerRepo = $this->findChild($outerRepo, 'inner-repo');
        $this->assertNotNull($innerRepo, '--nested must surface a repo nested inside another repo.');
        $this->assertTrue($innerRepo->isGitRepo);
    }

    public function test_nested_true_still_respects_the_depth_cap(): void
    {
        $this->makeDirs(['outer-repo/.git', 'outer-repo/inner-repo/.git']);

        // maxDepth of 1 means only outer-repo itself is walked; inner-repo,
        // one level deeper, must not appear even with --nested.
        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 1, nested: true);

        $outerRepo = $this->findChild($tree, 'outer-repo');
        $this->assertNotNull($outerRepo);
        $this->assertSame([], $outerRepo->children);
    }

    public function test_walk_never_lists_dot_git_itself_as_a_child_node(): void
    {
        $this->makeDirs(['repo/.git/objects']);

        $tree = $this->walker->walk($this->fixtureRoot, maxDepth: 5, nested: true);

        $repo = $this->findChild($tree, 'repo');
        $this->assertNotNull($repo);
        $this->assertNull($this->findChild($repo, '.git'), '.git must never be walked into as a plain child directory.');
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

    private function findChild(Node $parent, string $name): ?Node
    {
        foreach ($parent->children as $child) {
            if ($child->name() === $name) {
                return $child;
            }
        }

        return null;
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
