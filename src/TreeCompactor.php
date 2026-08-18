<?php

declare(strict_types=1);

namespace RepoPeeker;

/**
 * Transforms a walked {@see Node} tree into a compact {@see DisplayNode}
 * tree that surfaces where git repos actually live, rather than the literal
 * filesystem structure surrounding them:
 *
 * - Prune: any subtree with no git repo anywhere beneath it is dropped
 *   entirely.
 * - Collapse: a plain (non-repo) directory left with exactly one remaining
 *   "interesting" child after pruning is merged into that child's line,
 *   joined by `/`, repeating transitively until a repo node or a node with
 *   more than one remaining child is reached.
 *
 * The root node is exempt from collapsing — it always renders on its own
 * line — but its children are pruned/collapsed like any other subtree.
 */
final class TreeCompactor
{
    public function compact(Node $root): DisplayNode
    {
        $display = new DisplayNode($root->path, $root->isGitRepo);
        $display->children = $this->compactChildren($root->children);

        return $display;
    }

    /**
     * @param Node[] $nodes
     *
     * @return DisplayNode[]
     */
    private function compactChildren(array $nodes): array
    {
        $compacted = [];

        foreach ($nodes as $node) {
            $result = $this->compactNode($node);

            if ($result !== null) {
                $compacted[] = $result;
            }
        }

        return $compacted;
    }

    private function compactNode(Node $node): ?DisplayNode
    {
        if (! $node->isGitRepo && ! $node->hasRepoDescendant()) {
            return null; // prune: nothing of interest anywhere beneath this node
        }

        $children = $this->compactChildren($node->children);

        if (! $node->isGitRepo && count($children) === 1) {
            // Collapse into the one remaining interesting child's line. The
            // child's own label may already be a merged chain (e.g.
            // `b/repo`), which is how a run of several plain directories in
            // a row ends up joined into a single line (e.g. `a/b/repo`).
            $onlyChild = $children[0];

            $merged = new DisplayNode("{$node->name()}/{$onlyChild->label}", $onlyChild->isGitRepo);
            $merged->children = $onlyChild->children;

            return $merged;
        }

        $display = new DisplayNode($node->name(), $node->isGitRepo);
        $display->children = $children;

        return $display;
    }
}
