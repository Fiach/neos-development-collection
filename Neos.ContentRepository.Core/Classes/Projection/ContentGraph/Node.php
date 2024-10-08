<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Main read model of the {@see ContentSubgraphInterface}.
 *
 * Immutable, Read Only. In case you want to modify it, you need
 * to create Commands and send them to ContentRepository::handle.
 *
 * ## Identity of a Node
 *
 * The node's "Read Model" identity is summarized here {@see NodeAddress}, consisting of:
 *
 * - {@see ContentRepositoryId}
 * - {@see WorkspaceName}
 * - {@see DimensionSpacePoint}
 * - {@see NodeAggregateId}
 *
 * The node address can be constructed via {@see NodeAddress::fromNode()} and serialized.
 *
 * ## Traversing the graph
 *
 * The node does not have structure information, i.e. no infos
 * about its children. To f.e. fetch children, you need to fetch
 * the subgraph and use findChildNodes on the subgraph:
 *
 *     $subgraph = $contentRepository->getContentGraph($node->workspaceName)->getSubgraph(
 *         $node->dimensionSpacePoint,
 *         $node->visibilityConstraints
 *     );
 *     $childNodes = $subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create());
 *
 * ## A note about the {@see DimensionSpacePoint} and the {@see OriginDimensionSpacePoint}
 *
 * The {@see Node::dimensionSpacePoint} is the DimensionSpacePoint this node has been accessed in,
 * and NOT the DimensionSpacePoint where the node is "at home".
 * The DimensionSpacePoint where the node is (at home) is called the ORIGIN DimensionSpacePoint,
 * and this can be accessed using {@see Node::originDimensionSpacePoint}. If in doubt, you'll
 * usually need the DimensionSpacePoint instead of the OriginDimensionSpacePoint;
 * you'll only need the OriginDimensionSpacePoint when constructing commands on the write side.
 *
 * @api Note: The constructor is not part of the public API
 */
final readonly class Node
{
    /**
     * @param ContentRepositoryId $contentRepositoryId The content-repository this Node belongs to
     * @param WorkspaceName $workspaceName The workspace of this Node
     * @param DimensionSpacePoint $dimensionSpacePoint DimensionSpacePoint a node has been accessed in
     * @param NodeAggregateId $aggregateId NodeAggregateId (identifier) of this node. This is part of the node's "Read Model" identity, which is defined in {@see NodeAddress}
     * @param OriginDimensionSpacePoint $originDimensionSpacePoint The DimensionSpacePoint the node originates in. Usually needed to address a Node in a NodeAggregate in order to update it.
     * @param NodeAggregateClassification $classification The classification (regular, root, tethered) of this node
     * @param NodeTypeName $nodeTypeName The node's node type name; always set, even if unknown to the NodeTypeManager
     * @param PropertyCollection $properties All properties of this node. References are NOT part of this API; To access references, {@see ContentSubgraphInterface::findReferences()} can be used; To read the serialized properties use {@see PropertyCollection::serialized()}.
     * @param NodeName|null $name The optional name of the node, describing its relation to its parent
     * @param NodeTags $tags explicit and inherited SubtreeTags of this node
     * @param Timestamps $timestamps Creation and modification timestamps of this node
     * @param VisibilityConstraints $visibilityConstraints Information which subgraph filter was used to access this node
     */
    private function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public WorkspaceName $workspaceName,
        public DimensionSpacePoint $dimensionSpacePoint,
        public NodeAggregateId $aggregateId,
        public OriginDimensionSpacePoint $originDimensionSpacePoint,
        public NodeAggregateClassification $classification,
        public NodeTypeName $nodeTypeName,
        public PropertyCollection $properties,
        public ?NodeName $name,
        public NodeTags $tags,
        public Timestamps $timestamps,
        public VisibilityConstraints $visibilityConstraints
    ) {
        if ($this->classification->isTethered() && $this->name === null) {
            throw new \InvalidArgumentException('The NodeName must be set if the Node is tethered.', 1695118377);
        }
    }

    /**
     * @internal The signature of this method can change in the future!
     */
    public static function create(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint, NodeAggregateId $aggregateId, OriginDimensionSpacePoint $originDimensionSpacePoint, NodeAggregateClassification $classification, NodeTypeName $nodeTypeName, PropertyCollection $properties, ?NodeName $name, NodeTags $tags, Timestamps $timestamps, VisibilityConstraints $visibilityConstraints): self
    {
        return new self($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $aggregateId, $originDimensionSpacePoint, $classification, $nodeTypeName, $properties, $name, $tags, $timestamps, $visibilityConstraints);
    }

    /**
     * Returns the specified property, or null if it does not exist (or was set to null -> unset)
     *
     * @param PropertyName|string $propertyName Name of the property
     * @return mixed value of the property
     * @api
     */
    public function getProperty(PropertyName|string $propertyName): mixed
    {
        return $this->properties->offsetGet($propertyName instanceof PropertyName ? $propertyName->value : $propertyName);
    }

    /**
     * If this node has a property with the given name. It does not check if the property exists in the current NodeType schema.
     *
     * That means that {@see self::getProperty()} will not be null, except for the rare case the property deserializing returns null.
     *
     * @param PropertyName|string $propertyName Name of the property
     * @return boolean
     * @api
     */
    public function hasProperty(PropertyName|string $propertyName): bool
    {
        return $this->properties->offsetExists($propertyName instanceof PropertyName ? $propertyName->value : $propertyName);
    }

    /**
     * Checks if the node's "Read Model" identity equals with the given one
     */
    public function equals(Node $other): bool
    {
        return $this->contentRepositoryId->equals($other->contentRepositoryId)
            && $this->workspaceName->equals($other->workspaceName)
            && $this->dimensionSpacePoint->equals($other->dimensionSpacePoint)
            && $this->aggregateId->equals($other->aggregateId);
    }
}
