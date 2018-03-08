<?php
namespace Neos\Neos\TypeConverter;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Property\PropertyMappingConfigurationInterface;
use Neos\Flow\Property\TypeConverter\AbstractTypeConverter;
use Neos\Neos\Domain\Context\Content\ContentQuery;

/**
 * An Object Converter for content queries which can be used for routing (but also for other
 * purposes) as a plugin for the Property Mapper.
 *
 * @Flow\Scope("singleton")
 */
class ContentQueryToStringConverter extends AbstractTypeConverter
{
    /**
     * @var array
     */
    protected $sourceTypes = [ContentQuery::class];

    /**
     * @var string
     */
    protected $targetType = 'string';

    /**
     * @var int
     */
    protected $priority = 1;


    /**
     * @param ContentQuery $source
     * @param string $targetType
     * @param array $convertedChildProperties
     * @param PropertyMappingConfigurationInterface|null $configuration
     * @return string
     */
    public function convertFrom($source, $targetType, array $convertedChildProperties = [], PropertyMappingConfigurationInterface $configuration = null): string
    {
        return json_encode($source);
    }
}