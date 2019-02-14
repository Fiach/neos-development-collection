<?php

declare(strict_types=1);

namespace Neos\ContentRepository\DimensionSpace\DimensionSpace;

/*
 * This file is part of the Neos.ContentRepository.DimensionSpace package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Cache\CacheAwareInterface;
use Neos\ContentRepository\DimensionSpace\Dimension;
use Neos\Utility\Arrays;

/**
 * A point in the dimension space with coordinates DimensionName => DimensionValue.
 * E.g.: ["language" => "es", "country" => "ar"]
 *
 * Implements CacheAwareInterface because of Fusion Runtime caching and Routing
 *
 * @Flow\Proxy(false)
 */
final class DimensionSpacePoint implements \JsonSerializable, CacheAwareInterface, ProtectedContextAwareInterface
{
    /**
     * @var array
     */
    private $coordinates;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @param array $coordinates
     */
    public function __construct(array $coordinates)
    {
        foreach ($coordinates as $dimensionName => $dimensionValue) {
            if (!is_string($dimensionValue)) {
                throw new \InvalidArgumentException(sprintf('Dimension value for %s is not a string', $dimensionName), 1506076562);
            }
            if ($dimensionValue === '') {
                throw new \InvalidArgumentException('Dimension value must not be empty', 1506076563);
            }
        }

        $this->coordinates = $coordinates;
        $identityComponents = $coordinates;
        Arrays::sortKeysRecursively($identityComponents);

        $this->hash = md5(json_encode($identityComponents));
    }

    /**
     * @param string $jsonString A JSON string representation, see jsonSerialize
     * @return DimensionSpacePoint
     */
    public static function fromJsonString(string $jsonString): DimensionSpacePoint
    {
        return new DimensionSpacePoint(json_decode($jsonString, true));
    }

    /**
     * @param array $legacyDimensionValues Array from dimension name to dimension values
     * @return static
     */
    public static function fromLegacyDimensionArray(array $legacyDimensionValues): DimensionSpacePoint
    {
        $coordinates = [];
        foreach ($legacyDimensionValues as $dimensionName => $rawDimensionValues) {
            $coordinates[$dimensionName] = reset($rawDimensionValues);
        }

        return new DimensionSpacePoint($coordinates);
    }

    /**
     * @param Dimension\ContentDimensionIdentifier $dimensionIdentifier
     * @param string $value
     * @return static
     */
    public function vary(Dimension\ContentDimensionIdentifier $dimensionIdentifier, string $value): DimensionSpacePoint
    {
        $variedCoordinates = $this->coordinates;
        $variedCoordinates[(string)$dimensionIdentifier] = $value;

        return new DimensionSpacePoint($variedCoordinates);
    }

    /**
     * A variant VarA is a "Direct Variant in Dimension Dim" of another variant VarB, if VarA and VarB are sharing all dimension values except in "Dim",
     * AND they have differing dimension values in "Dim". Thus, VarA and VarB only vary in the given "Dim".
     * It does not say anything about how VarA and VarB relate (if it is specialization, lateral shift/translation or generalization).
     *
     * @param DimensionSpacePoint $otherDimensionSpacePoint
     * @param Dimension\ContentDimensionIdentifier $contentDimensionIdentifier
     * @return bool
     */
    public function isDirectVariantInDimension(DimensionSpacePoint $otherDimensionSpacePoint, Dimension\ContentDimensionIdentifier $contentDimensionIdentifier): bool
    {
        if (!$this->hasCoordinate($contentDimensionIdentifier) || !$otherDimensionSpacePoint->hasCoordinate($contentDimensionIdentifier)) {
            return false;
        }
        if ($this->coordinates[(string)$contentDimensionIdentifier] === $otherDimensionSpacePoint->getCoordinates()[(string)$contentDimensionIdentifier]) {
            return false;
        }

        $theseCoordinates = $this->coordinates;
        $otherCoordinates = $otherDimensionSpacePoint->getCoordinates();
        unset($theseCoordinates[(string)$contentDimensionIdentifier]);
        unset($otherCoordinates[(string)$contentDimensionIdentifier]);

        return $theseCoordinates === $otherCoordinates;
    }

    /**
     * @return array
     */
    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    /**
     * @param Dimension\ContentDimensionIdentifier $dimensionIdentifier
     * @return bool
     */
    public function hasCoordinate(Dimension\ContentDimensionIdentifier $dimensionIdentifier): bool
    {
        return isset($this->coordinates[(string)$dimensionIdentifier]);
    }

    /**
     * @param Dimension\ContentDimensionIdentifier $dimensionIdentifier
     * @return null|string
     */
    public function getCoordinate(Dimension\ContentDimensionIdentifier $dimensionIdentifier): ?string
    {
        return $this->coordinates[(string)$dimensionIdentifier] ?? null;
    }

    /**
     * @param DimensionSpacePoint $otherDimensionSpacePoint
     * @return bool
     */
    public function equals(DimensionSpacePoint $otherDimensionSpacePoint): bool
    {
        return $this->coordinates === $otherDimensionSpacePoint->getCoordinates();
    }

    /**
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return array
     */
    public function toLegacyDimensionArray(): array
    {
        $legacyDimensions = [];
        foreach ($this->coordinates as $dimensionName => $dimensionValue) {
            $legacyDimensions[$dimensionName] = [$dimensionValue];
        }

        return $legacyDimensions;
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->coordinates;
    }

    /**
     * serialize to URI
     *
     * @return string
     */
    public function serializeForUri(): string
    {
        return base64_encode(json_encode($this->coordinates));
    }

    /**
     * @param string $encoded
     * @return DimensionSpacePoint
     */
    public static function fromUriRepresentation(string $encoded): DimensionSpacePoint
    {
        return new DimensionSpacePoint(json_decode(base64_decode($encoded), true));
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return 'dimension space point:' . json_encode($this->coordinates);
    }

    /**
     * @return string
     */
    public function getCacheEntryIdentifier(): string
    {
        return $this->getHash();
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
