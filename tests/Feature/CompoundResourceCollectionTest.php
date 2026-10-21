<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\Tests\TestCase;

class CompoundResourceCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CompoundDefinitionResource::$resolutions = 0;
        CompoundTranslationResource::$resolutions = 0;
    }

    private function models(): Collection
    {
        $definitions = new Collection;
        foreach ([1, 2] as $id) {
            $locale = new CompoundLocale(['id' => 1]);
            $locale->setRelation('pivot', new Pivot(['id' => 100 + $id, 'label' => 'Label '.$id]));
            $definitions->push((new CompoundDefinition(['id' => $id]))->setRelation('translations', new Collection([$locale])));
        }

        return new Collection(array_map(function (int $id) use ($definitions) {
            $values = $definitions->map(fn ($definition) => (new CompoundValue(['id' => $id * 10 + $definition->id]))->setRelation('definition', $definition));

            return (new CompoundOwner(['id' => $id]))->setRelation('values', $values);
        }, range(1, 15)));
    }

    private function response(Collection $models, bool $native = false, string $include = 'values.definition.translations', bool $paginate = false): array
    {
        $request = Request::create('/owners?include='.$include);
        app()->instance('request', $request);
        $models = $paginate ? new LengthAwarePaginator($models, 45, 15, 1) : $models;
        $resource = $native
            ? new AnonymousResourceCollection($models, CompoundOwnerResource::class)
            : CompoundOwnerResource::collection($models);

        return $resource->toResponse($request)->getData(true);
    }

    public function test_shared_included_models_are_serialized_once_for_the_document(): void
    {
        $body = $this->response($this->models());

        $this->assertCount(15, $body['data']);
        $this->assertSame(2, CompoundDefinitionResource::$resolutions);
        $this->assertSame(2, CompoundTranslationResource::$resolutions);
        $this->assertCount(34, $body['included']);
    }

    public function test_distinct_pivot_translations_are_preserved(): void
    {
        $body = $this->response($this->models());
        $translations = array_values(array_filter($body['included'], fn ($r) => $r['type'] === 'compoundTranslation'));

        $this->assertSame(['101', '102'], array_column($translations, 'id'));
        $this->assertSame(['Label 1', 'Label 2'], array_column(array_column($translations, 'attributes'), 'label'));
    }

    public function test_unique_resource_content_matches_laravels_collection(): void
    {
        $native = $this->response($this->models(), native: true);
        $optimized = $this->response($this->models());
        $index = fn (array $body) => collect($body['included'])->keyBy(fn ($r) => $r['type'].':'.$r['id'])->sortKeys()->all();

        $this->assertSame($native['data'], $optimized['data']);
        $this->assertSame($index($native), $index($optimized));
    }

    public function test_distinct_include_paths_keep_laravels_per_root_behavior(): void
    {
        $models = $this->models();
        foreach ($models as $model) {
            $model->setRelation('featuredDefinition', $model->values->first()->definition);
        }
        $include = 'featuredDefinition,values.definition.translations';

        $this->assertSame($this->response($models, native: true, include: $include), $this->response($models, include: $include));
    }

    public function test_distinct_resource_classes_keep_laravels_per_root_behavior(): void
    {
        $models = $this->models();
        foreach ($models as $model) {
            $model->setRelation('featuredDefinition', $model->values->first()->definition);
            $model->setRelation('alternateDefinition', $model->values->first()->definition);
        }
        $include = 'featuredDefinition.translations,alternateDefinition.translations';

        $this->assertSame($this->response($models, native: true, include: $include), $this->response($models, include: $include));
    }

    public function test_primary_resource_cycles_keep_laravels_per_root_behavior(): void
    {
        $models = $this->models()->take(2);
        $models[0]->setRelation('parent', $models[1]);
        $models[1]->setRelation('parent', $models[0]);

        $this->assertSame($this->response($models, native: true, include: 'parent.parent'), $this->response($models, include: 'parent.parent'));
    }

    public function test_the_same_model_can_be_included_under_different_resource_types(): void
    {
        $models = $this->models();
        foreach ($models as $model) {
            $model->setRelation('featuredDefinition', $model->values->first()->definition);
            $model->setRelation('otherDefinition', $model->values->first()->definition);
        }
        $body = $this->response($models, include: 'featuredDefinition.translations,otherDefinition.translations');
        $types = array_count_values(array_column($body['included'], 'type'));

        $this->assertSame(1, $types['compoundDefinition']);
        $this->assertSame(1, $types['compoundOtherDefinition']);
        $this->assertSame(1, $types['compoundTranslation']);
    }

    public function test_pagination_information_is_preserved(): void
    {
        $native = $this->response($this->models(), native: true, paginate: true);
        $optimized = $this->response($this->models(), paginate: true);

        $this->assertSame($native['data'], $optimized['data']);
        $this->assertSame($native['links'], $optimized['links']);
        $this->assertSame($native['meta'], $optimized['meta']);
    }

    public function test_repeated_serialization_does_not_accumulate_relationship_maps(): void
    {
        $request = Request::create('/owners?include=values.definition.translations');
        app()->instance('request', $request);
        $resource = CompoundOwnerResource::collection($this->models());
        $first = $resource->toResponse($request)->getData(true);
        $map = $resource->collection->first()->loadedRelationshipsMap;

        $this->assertSame($first, $resource->toResponse($request)->getData(true));
        $this->assertSame($map, $resource->collection->first()->loadedRelationshipsMap);
    }

    public function test_empty_collections_do_not_add_included_resources(): void
    {
        $this->assertSame(['data' => []], $this->response(new Collection));
    }
}

class CompoundOwner extends Model
{
    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(CompoundValue::class, 'owner_id');
    }

    public function featuredDefinition(): BelongsTo
    {
        return $this->belongsTo(CompoundDefinition::class, 'definition_id');
    }

    public function alternateDefinition(): BelongsTo
    {
        return $this->belongsTo(CompoundDefinition::class, 'alternate_definition_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function otherDefinition(): BelongsTo
    {
        return $this->belongsTo(CompoundDefinition::class, 'other_definition_id');
    }
}

class CompoundValue extends Model
{
    protected $guarded = [];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CompoundDefinition::class, 'definition_id');
    }
}

class CompoundDefinition extends Model
{
    protected $guarded = [];

    public function translations(): BelongsToMany
    {
        return $this->belongsToMany(CompoundLocale::class, 'compound_translations');
    }
}

class CompoundLocale extends Model
{
    protected $guarded = [];
}

class CompoundOwnerResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'compoundOwner';
    }

    public function toAttributes(Request $request): array
    {
        return ['id' => $this->id];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'values' => CompoundValueResource::class,
            'featuredDefinition' => CompoundDefinitionResource::class,
            'alternateDefinition' => CompoundAlternateDefinitionResource::class,
            'otherDefinition' => CompoundOtherDefinitionResource::class,
            'parent' => self::class,
        ];
    }
}

class CompoundValueResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'compoundValue';
    }

    public function toAttributes(Request $request): array
    {
        return ['id' => $this->id];
    }

    public function toRelationships(Request $request): array
    {
        return ['definition' => CompoundDefinitionResource::class];
    }
}

class CompoundDefinitionResource extends JsonApiResource
{
    use WithEagerIncludes;

    public static int $resolutions = 0;

    public function toType(Request $request): string
    {
        return 'compoundDefinition';
    }

    public function toAttributes(Request $request): array
    {
        self::$resolutions++;

        return ['id' => $this->id];
    }

    public function toRelationships(Request $request): array
    {
        return ['translations' => CompoundTranslationResource::class];
    }
}

class CompoundTranslationResource extends JsonApiResource
{
    use WithEagerIncludes;

    public static int $resolutions = 0;

    public function toType(Request $request): string
    {
        return 'compoundTranslation';
    }

    public function toId(Request $request): string
    {
        return (string) $this->pivot->id;
    }

    public function toAttributes(Request $request): array
    {
        self::$resolutions++;

        return ['label' => $this->pivot->label];
    }
}

class CompoundAlternateDefinitionResource extends CompoundDefinitionResource
{
    public function toAttributes(Request $request): array
    {
        return ['variant' => true, ...parent::toAttributes($request)];
    }
}

class CompoundOtherDefinitionResource extends CompoundDefinitionResource
{
    public function toType(Request $request): string
    {
        return 'compoundOtherDefinition';
    }
}
