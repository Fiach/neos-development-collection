<?php

namespace Neos\ContentRepository\Domain\Context\Node;

use Neos\ContentRepository\Domain\Context\ContentStream\ContentStreamCommandHandler;
use Neos\ContentRepository\Domain\Context\DimensionSpace\Repository\InterDimensionalFallbackGraph;
use Neos\ContentRepository\Domain\Context\Importing\Command\FinalizeImportingSession;
use Neos\ContentRepository\Domain\Context\Importing\Command\StartImportingSession;
use Neos\ContentRepository\Domain\Context\Importing\Event\ImportingSessionWasFinalized;
use Neos\ContentRepository\Domain\Context\Importing\Event\ImportingSessionWasStarted;
use Neos\ContentRepository\Domain\Context\Node\Command\TranslateNodeInAggregate;
use Neos\ContentRepository\Domain\Context\Node\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Domain\Context\Node\Command\CreateRootNode;
use Neos\ContentRepository\Domain\Context\Importing\Command\ImportNode;
use Neos\ContentRepository\Domain\Context\Importing\Event\NodeWasImported;
use Neos\ContentRepository\Domain\Context\Node\Command\MoveNode;
use Neos\ContentRepository\Domain\Context\Node\Command\MoveNodesInAggregate;
use Neos\ContentRepository\Domain\Context\Node\Command\ChangeNodeName;
use Neos\ContentRepository\Domain\Context\Node\Command\SetNodeProperty;
use Neos\ContentRepository\Domain\Context\Node\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Domain\Context\Node\Event\NodeNameWasChanged;
use Neos\ContentRepository\Domain\Context\Node\Event\NodePropertyWasSet;
use Neos\ContentRepository\Domain\Context\Node\Event\NodesInAggregateWereMoved;
use Neos\ContentRepository\Domain\Context\Node\Event\NodeWasMoved;
use Neos\ContentRepository\Domain\Context\Node\Event\NodeWasTranslatedInAggregate;
use Neos\ContentRepository\Domain\Context\Node\Event\RootNodeWasCreated;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\ValueObject\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\ValueObject\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\ValueObject\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\ValueObject\NodeIdentifier;
use Neos\ContentRepository\Domain\ValueObject\NodeName;
use Neos\ContentRepository\Domain\ValueObject\NodeTypeName;
use Neos\ContentRepository\Domain\ValueObject\PropertyValue;
use Neos\ContentRepository\Exception;
use Neos\ContentRepository\Exception\DimensionSpacePointNotFound;
use Neos\ContentRepository\Exception\NodeNotFoundException;
use Neos\EventSourcing\Event\EventPublisher;
use Neos\EventSourcing\EventStore\ExpectedVersion;
use Neos\Flow\Annotations as Flow;

final class NodeCommandHandler
{

    /**
     * @Flow\Inject
     * @var EventPublisher
     */
    protected $eventPublisher;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var InterDimensionalFallbackGraph
     */
    protected $interDimensionalFallbackGraph;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Projection\Content\ContentGraphInterface
     */
    protected $contentGraph;

    /**
     * @param CreateNodeAggregateWithNode $command
     */
    public function handleCreateNodeAggregateWithNode(CreateNodeAggregateWithNode $command): void
    {
        $events = $this->nodeAggregateWithNodeWasCreatedFromCommand($command);
        $this->eventPublisher->publishMany(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $events);
    }

    /**
     * @param StartImportingSession $command
     */
    public function handleStartImportingSession(StartImportingSession $command): void
    {
        $streamName = 'Neos.ContentRepository:Importing:' . $command->getImportingSessionIdentifier();
        $this->eventPublisher->publish($streamName,
            new ImportingSessionWasStarted($command->getImportingSessionIdentifier()), ExpectedVersion::NO_STREAM);
    }

    /**
     * @param ImportNode $command
     */
    public function handleImportNode(ImportNode $command): void
    {
        $this->validateNodeTypeName($command->getNodeTypeName());

        $streamName = 'Neos.ContentRepository:Importing:' . $command->getImportingSessionIdentifier();
        $this->eventPublisher->publish($streamName, new NodeWasImported(
            $command->getImportingSessionIdentifier(),
            $command->getParentNodeIdentifier(),
            $command->getNodeIdentifier(),
            $command->getNodeName(),
            $command->getNodeTypeName(),
            $command->getDimensionValues(),
            $command->getPropertyValues()
        ));
    }

    /**
     * @param FinalizeImportingSession $command
     */
    public function handleFinalizeImportingSession(FinalizeImportingSession $command): void
    {
        $streamName = 'Neos.ContentRepository:Importing:' . $command->getImportingSessionIdentifier();
        $this->eventPublisher->publish($streamName,
            new ImportingSessionWasFinalized($command->getImportingSessionIdentifier()));
    }

    /**
     * Create events for adding a node aggregate with node, including all auto-created child node aggregates with nodes (recursively)
     *
     * @param CreateNodeAggregateWithNode $command
     * @return array
     * @throws DimensionSpacePointNotFound
     */
    private function nodeAggregateWithNodeWasCreatedFromCommand(CreateNodeAggregateWithNode $command): array
    {
        $nodeType = $this->getNodeType($command->getNodeTypeName());

        $propertyDefaultValuesAndTypes = [];
        foreach ($nodeType->getDefaultValuesForProperties() as $propertyName => $propertyValue) {
            $propertyDefaultValuesAndTypes[$propertyName] = new PropertyValue($propertyValue,
                $nodeType->getPropertyType($propertyName));
        }

        $events = [];

        $dimensionSpacePoint = $command->getDimensionSpacePoint();

        // TODO Validate if node with parentNodeIdentifier is visible in the subgraph with contentStreamIdentifier, dimensionSpacePoint

        $visibleDimensionSpacePoints = $this->getVisibleDimensionSpacePoints($dimensionSpacePoint);

        $events[] = new NodeAggregateWithNodeWasCreated(
            $command->getContentStreamIdentifier(),
            $command->getNodeAggregateIdentifier(),
            $command->getNodeTypeName(),
            $dimensionSpacePoint,
            $visibleDimensionSpacePoints,
            $command->getNodeIdentifier(),
            $command->getParentNodeIdentifier(),
            $command->getNodeName(),
            $propertyDefaultValuesAndTypes
        );

        foreach ($nodeType->getAutoCreatedChildNodes() as $childNodeNameStr => $childNodeType) {
            $childNodeName = new NodeName($childNodeNameStr);
            $childNodeAggregateIdentifier = NodeAggregateIdentifier::forAutoCreatedChildNode($childNodeName,
                $command->getNodeAggregateIdentifier());
            $childNodeIdentifier = new NodeIdentifier();
            $childParentNodeIdentifier = $command->getNodeIdentifier();

            $events = array_merge($events,
                $this->nodeAggregateWithNodeWasCreatedFromCommand(new CreateNodeAggregateWithNode(
                    $command->getContentStreamIdentifier(),
                    $childNodeAggregateIdentifier,
                    new NodeTypeName($childNodeType),
                    $dimensionSpacePoint,
                    $childNodeIdentifier,
                    $childParentNodeIdentifier,
                    $childNodeName
                )));
        }

        return $events;
    }

    /**
     * CreateRootNode
     *
     * @param CreateRootNode $command
     */
    public function handleCreateRootNode(CreateRootNode $command): void
    {
        $this->eventPublisher->publish(
            ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            new RootNodeWasCreated(
                $command->getContentStreamIdentifier(),
                $command->getNodeIdentifier(),
                $command->getInitiatingUserIdentifier()
            )
        );
    }

    /**
     * @param SetNodeProperty $command
     */
    public function handleSetNodeProperty(SetNodeProperty $command): void
    {
        // Check if node exists
        $this->getNode($command->getContentStreamIdentifier(), $command->getNodeIdentifier());

        $event = new NodePropertyWasSet(
            $command->getContentStreamIdentifier(),
            $command->getNodeIdentifier(),
            $command->getPropertyName(),
            $command->getValue()
        );

        $this->eventPublisher->publish(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $event);
    }

    /**
     * @param MoveNode $command
     */
    public function handleMoveNode(MoveNode $command): void
    {
        $contentStreamIdentifier = $command->getContentStreamIdentifier();

        /** @var Node $node */
        $node = $this->getNode($contentStreamIdentifier, $command->getNodeIdentifier());

        $contentSubgraph = $this->contentGraph->getSubgraphByIdentifier($contentStreamIdentifier,
            $node->dimensionSpacePoint);
        if ($contentSubgraph === null) {
            throw new Exception(sprintf('Content subgraph not found for content stream %s, %s',
                $contentStreamIdentifier, $node->dimensionSpacePoint), 1506074858);
        }

        $referenceNode = $contentSubgraph->findNodeByIdentifier($command->getReferenceNodeIdentifier());
        if ($referenceNode === null) {
            throw new NodeNotFoundException(sprintf('Reference node %s not found for content stream %s, %s',
                $command->getReferenceNodeIdentifier(), $contentStreamIdentifier, $node->dimensionSpacePoint),
                1506075821, $command->getReferenceNodeIdentifier());
        }

        $event = new NodeWasMoved(
            $command->getContentStreamIdentifier(),
            $command->getNodeIdentifier(),
            $command->getReferencePosition(),
            $command->getReferenceNodeIdentifier()
        );

        $this->eventPublisher->publish(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $event);
    }

    /**
     * @param MoveNodesInAggregate $command
     */
    public function handleMoveNodesInAggregate(MoveNodesInAggregate $command): void
    {
        // TODO Get nodes in nodeAggregateIdentifier

        // TODO Check: foreach node we can find a node in the content subgraph with node.dimensionSpacePoint by referenced aggregated node identifier
        $nodesToReferenceNodes = [];

        $event = new NodesInAggregateWereMoved(
            $command->getContentStreamIdentifier(),
            $command->getNodeAggregateIdentifier(),
            $command->getReferencePosition(),
            $command->getReferenceNodeAggregateIdentifier(),
            $nodesToReferenceNodes
        );

        $this->eventPublisher->publish(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $event);
    }

    /**
     * @param ChangeNodeName $command
     * @throws Exception\NodeException
     */
    public function handleChangeNodeName(ChangeNodeName $command)
    {
        $contentStreamIdentifier = $command->getContentStreamIdentifier();
        /** @var Node $node */
        $node = $this->getNode($contentStreamIdentifier, $command->getNodeIdentifier());

        if ($node->getNodeType()->getName() === 'Neos.ContentRepository:Root') {
            throw new Exception\NodeException('The root node cannot be renamed.', 1346778388);
        }

        $event = new NodeNameWasChanged(
            $contentStreamIdentifier,
            $command->getNodeIdentifier(),
            $command->getNewNodeName()
        );

        $this->eventPublisher->publish(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $event);
    }

    /**
     * @param TranslateNodeInAggregate $command
     */
    public function handleTranslateNodeInAggregate(TranslateNodeInAggregate $command)
    {
        $sourceNodeIdentifier = $command->getSourceNodeIdentifier();
        $sourceNode = $this->getNode($command->getContentStreamIdentifier(), $sourceNodeIdentifier);
        $sourceContentSubgraph = $this->contentGraph->getSubgraphByIdentifier($command->getContentStreamIdentifier(), $sourceNode->dimensionSpacePoint);
        /** @var Node $sourceParentNode */
        $sourceParentNode = $sourceContentSubgraph->findParentNode($sourceNodeIdentifier);
        if ($sourceParentNode === null) {
            throw new Exception\NodeException(sprintf('Parent node for %s in %s not found',
                $sourceNodeIdentifier, $sourceNode->dimensionSpacePoint), 1506354274);
        }

        $parentNodeAggregateIdentifier = $sourceParentNode->aggregateIdentifier;
        $destinationContentSubgraph = $this->contentGraph->getSubgraphByIdentifier($command->getContentStreamIdentifier(), $command->getDimensionSpacePoint());
        /** @var Node $destinationParentNode */
        $destinationParentNode = $destinationContentSubgraph->findNodeByNodeAggregateIdentifier($parentNodeAggregateIdentifier);
        if ($destinationParentNode === null) {
            throw new Exception\NodeException(sprintf('Could not find suitable parent node for %s in %s',
                $sourceNodeIdentifier, $destinationContentSubgraph->getDimensionSpacePoint()), 1506354275);
        }

        $dimensionSpacePointSet = $this->getVisibleDimensionSpacePoints($command->getDimensionSpacePoint());

        $event = new NodeWasTranslatedInAggregate(
            $command->getContentStreamIdentifier(),
            $sourceNodeIdentifier,
            $command->getDestinationNodeIdentifier(),
            $destinationParentNode->identifier,
            $command->getDimensionSpacePoint(),
            $dimensionSpacePointSet
        );

        $this->eventPublisher->publish(ContentStreamCommandHandler::getStreamNameForContentStream($command->getContentStreamIdentifier()),
            $event);
    }


    /**
     * @param NodeTypeName $nodeTypeName
     * @return NodeType
     */
    private function getNodeType(NodeTypeName $nodeTypeName): NodeType
    {
        $this->validateNodeTypeName($nodeTypeName);

        $nodeType = $this->nodeTypeManager->getNodeType((string)$nodeTypeName);
        return $nodeType;
    }

    /**
     * @param NodeTypeName $nodeTypeName
     */
    private function validateNodeTypeName(NodeTypeName $nodeTypeName): void
    {
        if (!$this->nodeTypeManager->hasNodeType((string)$nodeTypeName)) {
            throw new \InvalidArgumentException('TODO: Node type ' . $nodeTypeName . ' not found.');
        }
    }

    /**
     * @param $dimensionSpacePoint
     * @return DimensionSpacePointSet
     * @throws DimensionSpacePointNotFound
     */
    private function getVisibleDimensionSpacePoints($dimensionSpacePoint): DimensionSpacePointSet
    {
        return $this->interDimensionalFallbackGraph->getSpecializationSet($dimensionSpacePoint);
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeIdentifier $nodeIdentifier
     * @return Node
     * @throws NodeNotFoundException
     */
    private function getNode(ContentStreamIdentifier $contentStreamIdentifier, NodeIdentifier $nodeIdentifier): Node
    {
        /** @var Node $node */
        $node = $this->contentGraph->findNodeByIdentifierInContentStream($contentStreamIdentifier, $nodeIdentifier);
        if ($node === null) {
            throw new NodeNotFoundException(sprintf('Node %s not found', $nodeIdentifier), 1506074496, $nodeIdentifier);
        }
        return $node;
    }

}
