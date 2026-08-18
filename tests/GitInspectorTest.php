<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RepoPeeker\GitInspector;
use RepoPeeker\Node;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class GitInspectorTest extends TestCase
{
    private string $fixtureRoot;

    private GitInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir() . '/gitpeek-inspector-test-' . uniqid();
        mkdir($this->fixtureRoot, recursive: true);

        $this->inspector = new GitInspector();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_hydrate_summaries_attaches_a_clean_summary_on_a_named_branch(): void
    {
        $repoPath = $this->makeRepo('clean-repo');

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertNotNull($summary);
        $this->assertSame('main', $summary->branch);
        $this->assertFalse($summary->isDetached);
        $this->assertNull($summary->headSha);
        $this->assertSame(0, $summary->changeCount);
        $this->assertTrue($summary->isClean());
        $this->assertNull($summary->aheadCount);
        $this->assertNull($summary->behindCount);
    }

    public function test_hydrate_summaries_counts_a_modified_tracked_file_as_a_pending_change(): void
    {
        $repoPath = $this->makeRepo('dirty-repo');
        file_put_contents($repoPath . '/file.txt', "modified\n");

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertSame(1, $summary->changeCount);
        $this->assertFalse($summary->isClean());
    }

    public function test_hydrate_summaries_counts_an_untracked_file_as_a_pending_change(): void
    {
        $repoPath = $this->makeRepo('untracked-repo');
        file_put_contents($repoPath . '/new-file.txt', "new\n");

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $this->assertSame(1, $node->summary->changeCount);
    }

    public function test_hydrate_summaries_detects_a_detached_head(): void
    {
        $repoPath = $this->makeRepo('detached-repo');
        $sha = $this->gitOutput($repoPath, ['rev-parse', 'HEAD']);
        $this->git($repoPath, ['checkout', '--detach', $sha]);

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertTrue($summary->isDetached);
        $this->assertNull($summary->branch);
        $this->assertNotNull($summary->headSha);
        $this->assertStringStartsWith($summary->headSha, $sha);
    }

    public function test_hydrate_summaries_reports_commits_ahead_of_upstream(): void
    {
        $repoPath = $this->makeRepo('ahead-repo');
        $baseSha = $this->gitOutput($repoPath, ['rev-parse', 'HEAD']);
        $this->git($repoPath, ['remote', 'add', 'origin', '/nonexistent-remote']);
        $this->git($repoPath, ['update-ref', 'refs/remotes/origin/main', $baseSha]);
        $this->git($repoPath, ['branch', '--set-upstream-to=origin/main', 'main']);

        file_put_contents($repoPath . '/file.txt', "second\n");
        $this->git($repoPath, ['add', '.']);
        $this->git($repoPath, ['commit', '-m', 'second commit']);

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertSame(1, $summary->aheadCount);
        $this->assertSame(0, $summary->behindCount);
    }

    public function test_hydrate_summaries_reports_commits_behind_upstream(): void
    {
        $repoPath = $this->makeRepo('behind-repo');

        // Build a commit that is reachable only via the simulated upstream
        // ref, never via local HEAD, to produce a "behind" count.
        $this->git($repoPath, ['checkout', '-b', 'temp-remote-branch']);
        file_put_contents($repoPath . '/file.txt', "remote change\n");
        $this->git($repoPath, ['add', '.']);
        $this->git($repoPath, ['commit', '-m', 'remote-only commit']);
        $remoteSha = $this->gitOutput($repoPath, ['rev-parse', 'HEAD']);

        $this->git($repoPath, ['remote', 'add', 'origin', '/nonexistent-remote']);
        $this->git($repoPath, ['update-ref', 'refs/remotes/origin/main', $remoteSha]);
        $this->git($repoPath, ['checkout', 'main']);
        $this->git($repoPath, ['branch', '-D', 'temp-remote-branch']);
        $this->git($repoPath, ['branch', '--set-upstream-to=origin/main', 'main']);

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertSame(0, $summary->aheadCount);
        $this->assertSame(1, $summary->behindCount);
    }

    public function test_hydrate_summaries_leaves_ahead_behind_null_when_there_is_no_upstream(): void
    {
        $repoPath = $this->makeRepo('no-upstream-repo');

        $node = new Node($repoPath, isGitRepo: true);
        $this->inspector->hydrateSummaries($node);

        $summary = $node->summary;
        $this->assertNull($summary->aheadCount);
        $this->assertNull($summary->behindCount);
    }

    public function test_hydrate_summaries_only_touches_repo_nodes_and_recurses_into_children(): void
    {
        $outerRepoPath = $this->makeRepo('outer-repo');

        $plainPath = $outerRepoPath . '/plain';
        mkdir($plainPath, recursive: true);

        $innerRepoPath = $outerRepoPath . '/inner-repo';
        $this->initRepoAt($innerRepoPath);

        $root = new Node($outerRepoPath, isGitRepo: true);
        $plain = new Node($plainPath, isGitRepo: false);
        $inner = new Node($innerRepoPath, isGitRepo: true);
        $root->children = [$plain, $inner];

        $this->inspector->hydrateSummaries($root);

        $this->assertNotNull($root->summary);
        $this->assertNull($plain->summary);
        $this->assertNotNull($inner->summary);
    }

    private function makeRepo(string $name): string
    {
        $repoPath = $this->fixtureRoot . '/' . $name;
        $this->initRepoAt($repoPath);

        return $repoPath;
    }

    private function initRepoAt(string $repoPath): void
    {
        mkdir($repoPath, recursive: true);

        $this->git($repoPath, ['init', '-b', 'main']);
        $this->git($repoPath, ['config', 'user.email', 'test@example.com']);
        $this->git($repoPath, ['config', 'user.name', 'Test']);

        file_put_contents($repoPath . '/file.txt', "hello\n");
        $this->git($repoPath, ['add', '.']);
        $this->git($repoPath, ['commit', '-m', 'initial commit']);
    }

    /**
     * @param string[] $arguments
     */
    private function git(string $repoPath, array $arguments): void
    {
        (new Process(['git', '-C', $repoPath, ...$arguments]))->mustRun();
    }

    /**
     * @param string[] $arguments
     */
    private function gitOutput(string $repoPath, array $arguments): string
    {
        $process = new Process(['git', '-C', $repoPath, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
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
