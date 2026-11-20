<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\JsonApi\Contracts\PreparesModels;
use Jurager\Microservice\Tests\TestCase;

/** `prepareModels()` lets a resource load what it reads from the request, a level of the document at a time. */
class PreparesModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shelves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable();
        });

        Schema::create('shelf_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id');
        });

        Schema::create('shelf_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id');
        });

        ShelfResource::$calls = [];
        ShelfResource::$scope = null;
        ShelfNoteResource::$calls = [];
        ShelfLabelResource::$calls = [];
    }

    /**
     * Shelves with the given numbers of shelves under them, each shelf holding one note and one label.
     *
     * @return list<Shelf>
     */
    private function shelves(int ...$children): array
    {
        $shelves = [];

        foreach ($children as $count) {
            $shelves[] = $shelf = $this->shelf();

            for ($i = 0; $i < $count; $i++) {
                $this->shelf($shelf->id);
            }
        }

        return $shelves;
    }

    private function shelf(?int $parentId = null): Shelf
    {
        $shelf = Shelf::query()->create(['parent_id' => $parentId]);

        ShelfNote::query()->create(['shelf_id' => $shelf->id]);
        ShelfLabel::query()->create(['shelf_id' => $shelf->id]);

        return $shelf;
    }

    private function request(string $uri): Request
    {
        $request = Request::create($uri);
        app()->instance('request', $request);

        return $request;
    }

    /** @return Collection<int, Shelf> */
    private function top(): Collection
    {
        return Shelf::query()->whereNull('parent_id')->orderBy('id')->get();
    }

    /**
     * @param  list<list<Model>>  $calls
     * @return list<list<int>>
     */
    private function ids(array $calls): array
    {
        return array_map(static fn (array $models): array => array_map(static fn (Model $model): int => $model->getKey(), $models), $calls);
    }

    public function test_a_collection_is_prepared_in_one_call_when_nothing_is_included(): void
    {
        [$first, $second, $third] = $this->shelves(0, 0, 0);
        $request = $this->request('/shelves?scope=7');

        $body = ShelfResource::collection($this->top())->toResponse($request)->getData(true);

        $this->assertSame([[$first->id, $second->id, $third->id]], $this->ids(ShelfResource::$calls));
        $this->assertSame('7', ShelfResource::$scope, 'The resource should be handed the request of the document.');
        $this->assertSame([1, 1, 1], array_column(array_column($body['data'], 'attributes'), 'notes'));
    }

    public function test_a_single_resource_is_prepared(): void
    {
        [$shelf] = $this->shelves(0);
        $request = $this->request('/shelves/1');

        $body = ShelfResource::make($shelf)->toResponse($request)->getData(true);

        $this->assertSame([[$shelf->id]], $this->ids(ShelfResource::$calls));
        $this->assertSame(1, $body['data']['attributes']['notes']);
    }

    public function test_each_level_of_the_document_is_prepared_in_one_call(): void
    {
        [$first, $second] = $this->shelves(2, 1);
        $request = $this->request('/shelves?include=children');

        $body = ShelfResource::collection($this->top())->toResponse($request)->getData(true);

        $nested = Shelf::query()->whereNotNull('parent_id')->orderBy('id')->pluck('id')->all();

        $this->assertSame([[$first->id, $second->id], $nested], $this->ids(ShelfResource::$calls));
        $this->assertSame([1, 1, 1], array_column(array_column($body['included'], 'attributes'), 'notes'));
    }

    public function test_a_model_reached_by_two_include_paths_is_prepared_once(): void
    {
        $parent = $this->shelf();
        $shelf = $this->shelf($parent->id);
        $child = $this->shelf($shelf->id);

        // The same parent stands on two paths, each with includes of its own.
        $shelf->setRelation('parent', $parent)->setRelation('children', new Collection([$child]));
        $child->setRelation('parent', $parent);

        $request = $this->request('/shelves?include=parent.children,children.parent');

        ShelfResource::collection(new Collection([$shelf]))->toResponse($request);

        $prepared = array_map(spl_object_id(...), array_merge(...ShelfResource::$calls));

        $this->assertSame(array_unique($prepared), $prepared, 'No model should be prepared by the same resource twice.');
        $this->assertContains(spl_object_id($parent), $prepared);
    }

    public function test_a_relationship_declared_by_a_closure_is_prepared_by_the_resource_it_resolves_to(): void
    {
        $this->shelves(0, 0);
        $request = $this->request('/shelves?include=notes.shelf');

        ShelfResource::collection($this->top())->toResponse($request);

        $this->assertSame([ShelfNote::query()->orderBy('id')->pluck('id')->all()], $this->ids(ShelfNoteResource::$calls));
    }

    public function test_a_relationship_resolved_while_rendering_is_prepared_by_the_resource_of_its_model(): void
    {
        $this->shelves(0, 0);
        $request = $this->request('/shelves?include=labels');

        ShelfResource::collection($this->top())->toResponse($request);

        $this->assertSame([ShelfLabel::query()->orderBy('id')->pluck('id')->all()], $this->ids(ShelfLabelResource::$calls));
    }

    public function test_a_resource_that_prepares_nothing_is_left_alone(): void
    {
        [$shelf] = $this->shelves(2);
        $request = $this->request('/shelves?include=children');

        $body = PlainShelfResource::collection($this->top())->toResponse($request)->getData(true);

        $this->assertSame([$shelf->children()->orderBy('id')->pluck('id')->all()], $this->ids(ShelfResource::$calls));
        $this->assertSame((string) $shelf->id, $body['data'][0]['id']);
        $this->assertArrayNotHasKey('notes', $body['data'][0]['attributes'] ?? []);
        $this->assertSame([1, 1], array_column(array_column($body['included'], 'attributes'), 'notes'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_models(): void
    {
        $this->shelves(2, 2, 2);

        $queries = function (Collection $shelves): int {
            $request = $this->request('/shelves?include=children');

            DB::flushQueryLog();
            DB::enableQueryLog();

            ShelfResource::collection($shelves)->toResponse($request);

            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $forOne = $queries($this->top()->take(1));

        $this->assertGreaterThan(0, $forOne);
        $this->assertSame($forOne, $queries($this->top()));
    }
}

class Shelf extends Model
{
    public $timestamps = false;

    protected $table = 'shelves';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ShelfNote::class, 'shelf_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ShelfLabel::class, 'shelf_id');
    }
}

class ShelfNote extends Model
{
    public $timestamps = false;

    protected $table = 'shelf_notes';

    protected $guarded = [];

    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'shelf_id');
    }
}

#[UseResource(ShelfLabelResource::class)]
class ShelfLabel extends Model
{
    public $timestamps = false;

    protected $table = 'shelf_labels';

    protected $guarded = [];
}

class ShelfResource extends JsonApiResource implements PreparesModels
{
    use WithEagerIncludes;

    /** @var list<list<Model>> */
    public static array $calls = [];

    public static ?string $scope = null;

    public static function prepareModels(Collection $models, Request $request): void
    {
        static::$calls[] = $models->all();
        static::$scope = $request->query('scope');

        $models->loadCount('notes');
    }

    public function toType(Request $request): string
    {
        return 'shelf';
    }

    public function toAttributes(Request $request): array
    {
        return ['notes' => $this->notes_count];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'parent' => self::class,
            'children' => self::class,
            'notes' => fn () => new AnonymousResourceCollection($this->notes, ShelfNoteResource::class),
            'labels' => fn () => new AnonymousResourceCollection($this->labels, ShelfLabelResource::class),
        ];
    }
}

class PlainShelfResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'shelf';
    }

    public function toRelationships(Request $request): array
    {
        return ['children' => ShelfResource::class];
    }
}

class ShelfNoteResource extends JsonApiResource implements PreparesModels
{
    use WithEagerIncludes;

    /** @var list<list<Model>> */
    public static array $calls = [];

    public static function prepareModels(Collection $models, Request $request): void
    {
        static::$calls[] = $models->all();
    }

    public function toType(Request $request): string
    {
        return 'shelfNote';
    }

    public function toRelationships(Request $request): array
    {
        return ['shelf' => ShelfResource::class];
    }
}

class ShelfLabelResource extends JsonApiResource implements PreparesModels
{
    use WithEagerIncludes;

    /** @var list<list<Model>> */
    public static array $calls = [];

    public static function prepareModels(Collection $models, Request $request): void
    {
        static::$calls[] = $models->all();
    }

    public function toType(Request $request): string
    {
        return 'shelfLabel';
    }
}
