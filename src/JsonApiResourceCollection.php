<?php

declare(strict_types=1);

namespace TiMacDonald\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use stdClass;

class JsonApiResourceCollection extends AnonymousResourceCollection
{
    use Concerns\RelationshipLinks;

    /**
     * @param  (callable(JsonApiResource): JsonApiResource)  $callback
     * @return $this
     */
    public function map(callable $callback)
    {
        $this->collection = $this->collection->map($callback);

        return $this;
    }

    /**
     * @return RelationshipObject
     */
    public function toResourceLink(Request $request)
    {
        return RelationshipObject::toMany($this->resolveResourceIdentifiers($request)->all());
    }

    /**
     * @return Collection<int, ResourceIdentifier>
     */
    private function resolveResourceIdentifiers(Request $request)
    {
        return $this->collection
            ->uniqueStrict(fn (JsonApiResource $resource): array => $resource->uniqueKey($request))
            ->map(fn (JsonApiResource $resource): ResourceIdentifier => $resource->resolveResourceIdentifier($request));
    }

    /**
     * @param  Request  $request
     * @return array{included?: array<int, JsonApiResource>, jsonapi?: ServerImplementation}
     */
    public function with($request)
    {
        $primaryKeys = $this->collection
            ->map(fn (JsonApiResource $resource): string => self::resourceKey($resource, $request))
            ->flip();

        $included = self::mergeDuplicateResources(
            $this->collection
                ->flatMap(fn (JsonApiResource $resource): Collection => $resource->included($request))
                ->reject(fn (JsonApiResource $resource): bool => $primaryKeys->has(self::resourceKey($resource, $request))),
            $request,
        );

        return [
            ...$included === [] ? [] : ['included' => $included],
            ...($implementation = $this->collects::toServerImplementation($request))
                ? ['jsonapi' => $implementation] : [],
        ];
    }

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

    /**
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toResponse($request)
    {
        return tap(parent::toResponse($request)->header('Content-type', 'application/vnd.api+json'), $this->flush(...));
    }

    /**
     * @param  array<array-key, mixed>  $paginated
     * @param  array{links: array<string, ?string>}  $default
     * @return array{links: array<string, string>}
     */
    public function paginationInformation(Request $request, array $paginated, array $default)
    {
        if (isset($default['links'])) {
            $default['links'] = array_filter($default['links'], fn (?string $link): bool => $link !== null);
        }

        if (isset($default['meta']['links'])) {
            $default['meta']['links'] = array_map(
                function (array $link): array {
                    $link['label'] = (string) $link['label'];

                    return $link;
                },
                $default['meta']['links']
            );
        }

        return $default;
    }

    /**
     * @internal
     *
     * @return $this
     */
    public function withIncludePrefix(string $prefix)
    {
        $this->collection->each(fn (JsonApiResource $resource): JsonApiResource => $resource->withIncludePrefix($prefix));

        return $this;
    }

    /**
     * @internal
     *
     * @return Collection<int, Collection<int, JsonApiResource>>
     */
    public function included(Request $request)
    {
        return $this->collection->map(fn (JsonApiResource $resource): Collection => $resource->included($request));
    }

    /**
     * @internal
     *
     * @return Collection<int, JsonApiResource>
     */
    public function includable()
    {
        return $this->collection;
    }

    /**
     * @internal
     *
     * @infection-ignore-all
     *
     * @return void
     */
    public function flush()
    {
        $this->collection->each(fn (JsonApiResource $resource) => $resource->flush());
    }
}
