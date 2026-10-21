<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;
use Jurager\Microservice\Tests\TestCase;

class RequestedIncludesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('include_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
        });
        Schema::create('include_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->string('code');
        });

        $parent = IncludeEntity::query()->create(['name' => 'Parent']);
        IncludeValue::query()->create(['entity_id' => $parent->id, 'code' => 'name']);
        IncludeValue::query()->create(['entity_id' => $parent->id, 'code' => 'unused']);
        IncludeEntity::query()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    }

    protected function tearDown(): void
    {
        IncludeEntity::$expanded = false;
        parent::tearDown();
    }

    private function response(string $query): array
    {
        $request = Request::create('/entities?'.$query);
        app()->instance('request', $request);

        return IncludeEntityResource::collection(IncludeEntity::query()->whereNotNull('parent_id')->get())
            ->toResponse($request)->getData(true);
    }

    public function test_internal_eager_loads_do_not_expand_the_included_document(): void
    {
        $body = $this->response('include=parent');

        $this->assertSame(['includeEntity'], array_column($body['included'], 'type'));
        $this->assertArrayNotHasKey('relationships', $body['included'][0]);
    }

    public function test_explicit_nested_include_is_still_returned(): void
    {
        $body = $this->response('include=parent.values');

        $this->assertCount(3, $body['included']);
        $this->assertCount(2, $body['included'][0]['relationships']['values']['data']);
    }

    public function test_declared_eager_relation_expansions_are_still_public(): void
    {
        IncludeEntity::$expanded = true;
        $body = $this->response('include=parent');

        $this->assertCount(3, $body['included']);
        $this->assertCount(2, $body['included'][0]['relationships']['values']['data']);
    }

    public function test_partially_preloaded_relationships_are_completed_for_the_whole_batch(): void
    {
        IncludeEntity::query()->create(['name' => 'Second child', 'parent_id' => 1]);
        $models = IncludeEntity::query()->whereNotNull('parent_id')->get();
        $models->first()->load('parent');
        $this->assertFalse($models->last()->relationLoaded('parent'));
        app()->instance('request', Request::create('/entities?include=parent'));

        IncludeEntityResource::collection($models);

        $this->assertTrue($models->last()->relationLoaded('parent'));
        $this->assertSame(1, (int) $models->last()->parent->id);
    }

    public function test_different_related_resource_types_receive_different_fieldsets(): void
    {
        $this->response('include=parent.values&fields[includeEntity]=name&fields[includeValue]=code');

        $this->assertSame(['name'], IncludeEntity::$lastFields);
        $this->assertSame(['code'], IncludeValue::$lastFields);
    }

    public function test_nested_model_receives_its_own_sparse_fieldset(): void
    {
        $body = $this->response('include=parent.values&fields[includeEntity]=name');

        $this->assertSame(['name'], IncludeEntity::$lastFields);
        $values = array_values(array_filter($body['included'], fn ($item) => $item['type'] === 'includeValue'));
        $this->assertSame(['name'], array_column(array_column($values, 'attributes'), 'code'));
    }
}

class IncludeEntity extends Model implements ProvidesEagerLoads
{
    public $timestamps = false;

    protected $table = 'include_entities';

    protected $guarded = [];

    public static ?array $lastFields = null;

    public static bool $expanded = false;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(IncludeValue::class, 'entity_id');
    }

    public static function eagerRelations(): array
    {
        return self::$expanded ? ['parent' => ['parent.values']] : [];
    }

    public function eagerLoads(array $included, ?array $fields = null): array
    {
        self::$lastFields = $fields;

        return $fields === null ? ['values'] : ['values' => fn ($query) => $query->whereIn('code', $fields)];
    }
}

class IncludeValue extends Model implements ProvidesEagerLoads
{
    public static ?array $lastFields = null;

    public function eagerLoads(array $included, ?array $fields = null): array
    {
        self::$lastFields = $fields;

        return [];
    }

    public $timestamps = false;

    protected $table = 'include_values';

    protected $guarded = [];
}

class IncludeEntityResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'includeEntity';
    }

    public function toAttributes(Request $request): array
    {
        return ['name' => $this->name];
    }

    public function toRelationships(Request $request): array
    {
        return ['parent' => self::class, 'values' => IncludeValueResource::class];
    }
}

class IncludeValueResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'includeValue';
    }

    public function toAttributes(Request $request): array
    {
        return ['code' => $this->code];
    }
}
