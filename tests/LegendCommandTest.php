<?php

declare(strict_types=1);

namespace Tests;

/**
 * @internal
 */
final class LegendCommandTest extends IntegrationTestCase
{
    public function test_legend_documents_the_no_badge_plain_directory_case(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('(no badge)')
            ->assertSee('not a git repository — plain directory');
    }

    public function test_legend_documents_the_clean_and_dirty_repo_badges(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('●')
            ->assertSee('repository is clean')
            ->assertSee('hyperlinked to its local path')
            ->assertSee('repository has changes')
            ->assertContainsFormattedText("\e[92m●\e[39m")
            ->assertContainsFormattedText("\e[93m●\e[39m")
            ->assertContainsFormattedText("\e[92m✓\e[39m")
            ->assertContainsFormattedText("\e[93m\e[1m+N\e[39m\e[22m");
    }

    public function test_legend_documents_the_detached_head_state(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('⎇')
            ->assertSee('repository in detached HEAD')
            ->assertSee('detached HEAD@<sha>');
    }

    public function test_legend_documents_the_current_branch_column(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('<current branch>')
            ->assertSee('clean — current branch name')
            ->assertSee('dirty — current branch name')
            ->assertContainsFormattedText("\e[92m<current branch>\e[39m")
            ->assertContainsFormattedText("\e[93m<current branch>\e[39m");
    }

    public function test_legend_documents_the_ahead_behind_counts(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('↑N / ↓N')
            ->assertSee('ahead / behind upstream')
            ->assertContainsFormattedText("\e[96m\e[1m↑N\e[39m\e[22m")
            ->assertContainsFormattedText("\e[96m\e[1m↓N\e[39m\e[22m");
    }

    public function test_legend_documents_the_remote_url_column(): void
    {
        $this->console
            ->call('legend')
            ->assertSuccess()
            ->assertSee('<remote url>')
            ->assertSee('origin')
            ->assertContainsFormattedText("\e[2m<remote url>\e[22m");
    }
}
