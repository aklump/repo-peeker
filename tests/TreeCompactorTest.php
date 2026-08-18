<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\Node;
use RepoPeeker\TreeCompactor;

/**
 * @internal
 */
final class TreeCompactorTest extends TestCase
{
    private TreeCompactor $compactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compactor = new TreeCompactor();
    }

    public function test_a_plain_directory_with_one_interesting_child_collapses_and_drops_its_uninteresting_siblings(): void
    {
        $root = new Node('/root', isGitRepo: false);
        $directio = new Node('/root/directio', isGitRepo: false);
        $idea = new Node('/root/directio/.idea', isGitRepo: false);
        $reproduceIssue = new Node('/root/directio/reproduce_issue', isGitRepo: false);
        $app = new Node('/root/directio/app', isGitRepo: true);

        $directio->children = [$idea, $reproduceIssue, $app];
        $root->children = [$directio];

        $display = $this->compactor->compact($root);

        $this->assertCount(1, $display->children, 'The uninteresting siblings must be dropped, leaving a single merged line.');

        $merged = $display->children[0];
        $this->assertSame('directio/app', $merged->label);
        $this->assertTrue($merged->isGitRepo);
        $this->assertSame([], $merged->children);
        $this->assertSame('/root/directio/app', $merged->path, 'A merged row must link to its innermost real directory, not the outer one its label starts with.');
    }

    public function test_a_plain_directory_with_two_or_more_interesting_children_is_not_collapsed(): void
    {
        $root = new Node('/root', isGitRepo: false);
        $packages = new Node('/root/packages', isGitRepo: false);
        $php = new Node('/root/packages/php', isGitRepo: true);
        $js = new Node('/root/packages/js', isGitRepo: true);

        $packages->children = [$php, $js];
        $root->children = [$packages];

        $display = $this->compactor->compact($root);

        $this->assertCount(1, $display->children);

        $packagesDisplay = $display->children[0];
        $this->assertSame('packages', $packagesDisplay->label, 'A container with more than one interesting branch keeps its own row.');
        $this->assertFalse($packagesDisplay->isGitRepo);
        $this->assertCount(2, $packagesDisplay->children);
    }

    public function test_a_repo_is_never_merged_into_its_parents_line_and_keeps_its_status_across_a_merge_chain(): void
    {
        $root = new Node('/root', isGitRepo: false);
        $a = new Node('/root/a', isGitRepo: false);
        $b = new Node('/root/a/b', isGitRepo: false);
        $repo = new Node('/root/a/b/repo', isGitRepo: true);

        $b->children = [$repo];
        $a->children = [$b];
        $root->children = [$a];

        $display = $this->compactor->compact($root);

        $this->assertCount(1, $display->children);

        $merged = $display->children[0];
        $this->assertSame('a/b/repo', $merged->label, 'A chain of single-child plain directories must fold into one joined line.');
        $this->assertTrue($merged->isGitRepo, 'The repo status must survive being folded through a chain of merges.');
        $this->assertSame('/root/a/b/repo', $merged->path, 'The path must survive being folded through a chain of merges, pointing at the innermost directory.');
    }

    public function test_root_is_never_merged_into_a_child_even_with_exactly_one_interesting_child(): void
    {
        $root = new Node('/root/only-project', isGitRepo: false);
        $repo = new Node('/root/only-project/repo', isGitRepo: true);

        $root->children = [$repo];

        $display = $this->compactor->compact($root);

        $this->assertSame('/root/only-project', $display->label, 'Root must always render on its own line, never merged into a child.');
        $this->assertSame('/root/only-project', $display->path);
        $this->assertCount(1, $display->children);
        $this->assertSame('repo', $display->children[0]->label);
        $this->assertTrue($display->children[0]->isGitRepo);
        $this->assertSame('/root/only-project/repo', $display->children[0]->path);
    }

    public function test_a_subtree_with_no_git_repo_anywhere_beneath_it_is_pruned_entirely(): void
    {
        $root = new Node('/root', isGitRepo: false);
        $plain = new Node('/root/plain', isGitRepo: false);
        $child = new Node('/root/plain/child', isGitRepo: false);

        $plain->children = [$child];
        $root->children = [$plain];

        $display = $this->compactor->compact($root);

        $this->assertSame([], $display->children, 'A subtree with no repo anywhere beneath it must be dropped entirely.');
    }
}
