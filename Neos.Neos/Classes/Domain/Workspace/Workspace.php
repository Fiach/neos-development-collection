<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\Domain\Workspace;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Dto\NodeIdsToPublishOrDiscard;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Dto\NodeIdToPublishOrDiscard;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\PendingChangesProjection\Change;
use Neos\Neos\PendingChangesProjection\ChangeFinder;

/**
 * Neos' workspace model
 *
 * @api
 */
#[Flow\Proxy(false)]
final readonly class Workspace
{
    public function __construct(
        private ContentStreamId $currentContentStreamId,
        public WorkspaceName $name,
        private ContentRepository $contentRepository,
    ) {
    }

    /**
     * @return int The amount of changes that were published
     */
    public function publishChangesInSite(NodeAggregateId $siteId): int
    {
        $ancestorNodeTypeName = NodeTypeNameFactory::forSite();
        $this->requireNodeToBeOfType(
            $siteId,
            $ancestorNodeTypeName
        );

        $nodeIdsToPublish = $this->resolveNodeIdsToPublishOrDiscard(
            $siteId,
            $ancestorNodeTypeName
        );

        $this->publishNodes($nodeIdsToPublish);

        return count($nodeIdsToPublish);
    }

    /**
     * @return int The amount of changes that were published
     */
    public function publishChangesInDocument(NodeAggregateId $documentId): int
    {
        $ancestorNodeTypeName = NodeTypeNameFactory::forDocument();
        $this->requireNodeToBeOfType(
            $documentId,
            $ancestorNodeTypeName
        );

        $nodeIdsToPublish = $this->resolveNodeIdsToPublishOrDiscard(
            $documentId,
            $ancestorNodeTypeName
        );

        $this->publishNodes($nodeIdsToPublish);

        return count($nodeIdsToPublish);
    }

    /**
     * @return int The amount of changes that were discarded
     */
    public function discardChangesInSite(NodeAggregateId $siteId): int
    {
        $ancestorNodeTypeName = NodeTypeNameFactory::forSite();
        $this->requireNodeToBeOfType(
            $siteId,
            $ancestorNodeTypeName
        );

        $nodeIdsToDiscard = $this->resolveNodeIdsToPublishOrDiscard(
            $siteId,
            NodeTypeNameFactory::forSite()
        );

        $this->discardNodes($nodeIdsToDiscard);

        return count($nodeIdsToDiscard);
    }

    /**
     * @return int The amount of changes that were discarded
     */
    public function discardChangesInDocument(NodeAggregateId $documentId): int
    {
        $ancestorNodeTypeName = NodeTypeNameFactory::forDocument();
        $this->requireNodeToBeOfType(
            $documentId,
            $ancestorNodeTypeName
        );

        $nodeIdsToDiscard = $this->resolveNodeIdsToPublishOrDiscard(
            $documentId,
            $ancestorNodeTypeName
        );

        $this->discardNodes($nodeIdsToDiscard);

        return count($nodeIdsToDiscard);
    }

    private function requireNodeToBeOfType(
        NodeAggregateId $nodeAggregateId,
        NodeTypeName $nodeTypeName,
    ): void {
        $nodeAggregate = $this->contentRepository->getContentGraph()->findNodeAggregateById(
            $this->currentContentStreamId,
            $nodeAggregateId,
        );
        if (!$nodeAggregate instanceof NodeAggregate) {
            throw new NodeAggregateCurrentlyDoesNotExist(
                'Node aggregate ' . $nodeAggregateId->value . ' does currently not exist',
                1710967964
            );
        }

        if (
            !$this->contentRepository->getNodeTypeManager()
                ->getNodeType($nodeAggregate->nodeTypeName)
                ->isOfType($nodeTypeName)
        ) {
            throw new \DomainException(
                'Node aggregate ' . $nodeAggregateId->value . ' is not of expected type ' . $nodeTypeName->value,
                1710968108
            );
        }
    }

    private function publishNodes(
        NodeIdsToPublishOrDiscard $nodeIdsToPublish
    ): void {
        /**
         * TODO: only rebase if necessary!
         * Also, isn't this already included in @see WorkspaceCommandHandler::handlePublishIndividualNodesFromWorkspace ?
         */
        $this->contentRepository->handle(
            RebaseWorkspace::create(
                $this->name
            )
        )->block();

        $this->contentRepository->handle(
            PublishIndividualNodesFromWorkspace::create(
                $this->name,
                $nodeIdsToPublish
            )
        )->block();
    }

    private function discardNodes(
        NodeIdsToPublishOrDiscard $nodeIdsToDiscard
    ): void {
        /**
         * TODO: only rebase if necessary!
         * Also, isn't this already included in @see WorkspaceCommandHandler::handleDiscardIndividualNodesFromWorkspace ?
         */
        $this->contentRepository->handle(
            RebaseWorkspace::create(
                $this->name
            )
        )->block();

        $this->contentRepository->handle(
            DiscardIndividualNodesFromWorkspace::create(
                $this->name,
                $nodeIdsToDiscard
            )
        )->block();
    }

    /**
     * @param NodeAggregateId $ancestorId The id of the ancestor node of all affected nodes
     * @param NodeTypeName $ancestorNodeTypeName The type of the ancestor node of all affected nodes
     */
    private function resolveNodeIdsToPublishOrDiscard(
        NodeAggregateId $ancestorId,
        NodeTypeName $ancestorNodeTypeName
    ): NodeIdsToPublishOrDiscard {
        /** @var ChangeFinder $changeFinder */
        $changeFinder = $this->contentRepository->projectionState(ChangeFinder::class);
        $changes = $changeFinder->findByContentStreamId($this->currentContentStreamId);
        $nodeIdsToPublishOrDiscard = [];
        foreach ($changes as $change) {
            if (
                !$this->isChangePublishableWithinAncestorScope(
                    $change,
                    $ancestorNodeTypeName,
                    $ancestorId
                )
            ) {
                continue;
            }

            $nodeIdsToPublishOrDiscard[] = new NodeIdToPublishOrDiscard(
                $change->nodeAggregateId,
                $change->originDimensionSpacePoint->toDimensionSpacePoint()
            );
        }

        return NodeIdsToPublishOrDiscard::create(...$nodeIdsToPublishOrDiscard);
    }

    private function isChangePublishableWithinAncestorScope(
        Change $change,
        NodeTypeName $ancestorNodeTypeName,
        NodeAggregateId $ancestorId
    ): bool {
        // see method comment for `isChangeWithSelfReferencingRemovalAttachmentPoint`
        // to get explanation for this condition
        if ($this->isChangeWithSelfReferencingRemovalAttachmentPoint($change)) {
            if ($ancestorNodeTypeName->equals(NodeTypeNameFactory::forSite())) {
                return true;
            }
        }

        $subgraph = $this->contentRepository->getContentGraph()->getSubgraph(
            $this->currentContentStreamId,
            $change->originDimensionSpacePoint->toDimensionSpacePoint(),
            VisibilityConstraints::withoutRestrictions()
        );

        // A Change is publishable if the respective node (or the respective
        // removal attachment point) has a closest ancestor that matches our
        // current ancestor scope (Document/Site)
        $actualAncestorNode = $subgraph->findClosestNode(
            $change->removalAttachmentPoint ?? $change->nodeAggregateId,
            FindClosestNodeFilter::create(nodeTypes: $ancestorNodeTypeName->value)
        );

        return $actualAncestorNode?->nodeAggregateId->equals($ancestorId) ?? false;
    }

    /**
     * Before the introduction of the WorkspacePublisher, the UI only ever
     * referenced the closest document node as a removal attachment point.
     *
     * Removed document nodes therefore were referencing themselves.
     *
     * In order to enable publish/discard of removed documents, the removal
     * attachment point of a document MUST refer to an ancestor. The UI now
     * references the site node in those cases.
     *
     * Workspaces that were created before this change was introduced may
     * contain removed documents, for which the site node can longer be
     * located, because we have no reference to their respective site.
     *
     * Every document node that matches that description will be published
     * or discarded by WorkspacePublisher::publishSite, regardless of what
     * the current site is.
     *
     * @deprecated remove once we are sure this check is no longer needed due to
     * * the UI sending proper commands
     * * the ChangeFinder being refactored / rewritten
     * (whatever happens first)
     */
    private function isChangeWithSelfReferencingRemovalAttachmentPoint(Change $change): bool
    {
        return $change->removalAttachmentPoint?->equals($change->nodeAggregateId) ?? false;
    }
}
