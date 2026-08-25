<?php

declare(strict_types=1);

namespace TiMacDonald\JsonApi\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use stdClass;
use TiMacDonald\JsonApi\JsonApiResource;

trait MergesDuplicateResources
{
    private static function resourceKey(JsonApiResource $resource, Request $request): string
    {
        return json_encode($resource->uniqueKey($request), JSON_THROW_ON_ERROR);
    }

    /**
     * The same resource can be reached through several include paths, each requesting different
     * relationships. Collapsing duplicates must union their relationships instead of dropping later
     * copies, otherwise a resource keeps only the relationships from the include path seen first.
     *
     * @param  Collection<int, JsonApiResource>  $included
     * @return array<int, array{id: string, type: string, attributes?: stdClass, relationships?: stdClass, meta?: stdClass, links?: stdClass}>
     */
    private static function mergeDuplicateResources(Collection $included, Request $request): array
    {
        return $included
            ->groupBy(fn (JsonApiResource $resource): string => self::resourceKey($resource, $request))
            ->map(fn (Collection $duplicates): array => self::mergeResourceData($duplicates, $request))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, JsonApiResource>  $duplicates
     * @return array{id: string, type: string, attributes?: stdClass, relationships?: stdClass, meta?: stdClass, links?: stdClass}
     */
    private static function mergeResourceData(Collection $duplicates, Request $request): array
    {
        $resolved = $duplicates->map(fn (JsonApiResource $resource): array => $resource->resolveResourceData($request));

        return Collection::make(['attributes', 'links', 'meta', 'relationships'])
            ->reduce(function (array $resource, string $section) use ($resolved): array {
                $merged = $resolved
                    ->pluck($section)
                    ->filter()
                    ->reduce(fn (array $carry, stdClass $object): array => $carry + (array) $object, []);

                return $merged === [] ? $resource : [...$resource, $section => (object) $merged];
            }, $resolved->first() ?? []);
    }
}
