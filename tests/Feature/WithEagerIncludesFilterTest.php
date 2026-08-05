<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\Tests\TestCase;

/**
 * `WithEagerIncludes` must apply `filter[included.relation.column]` constraints
 * via a model's `loadIncludedRelations()` when present — duck-typed, so this
 * works for any package (e.g. jurager/filterable) without a hard dependency.
 */
class WithEagerIncludesFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('filter_posts', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('filter_post_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->unsignedInteger('tag_id');
        });
    }

    public function test_included_relation_is_filtered_when_model_supports_load_included_relations(): void
    {
        $post = TestFilterablePost::query()->create();
        TestFilterablePostValue::query()->create(['post_id' => $post->id, 'tag_id' => 1]);
        TestFilterablePostValue::query()->create(['post_id' => $post->id, 'tag_id' => 2]);

        $request = Request::create('/filter-posts?include=values&filter[included.values.tag_id]=1');
        app()->instance('request', $request);

        $posts = TestFilterablePost::query()->get();
        TestFilterablePostResource::collection($posts);

        $this->assertTrue($posts->first()->relationLoaded('values'));
        $this->assertSame([1], $posts->first()->values->pluck('tag_id')->all());
    }

    public function test_included_relation_loads_unfiltered_without_filter_param(): void
    {
        $post = TestFilterablePost::query()->create();
        TestFilterablePostValue::query()->create(['post_id' => $post->id, 'tag_id' => 1]);
        TestFilterablePostValue::query()->create(['post_id' => $post->id, 'tag_id' => 2]);

        $request = Request::create('/filter-posts?include=values');
        app()->instance('request', $request);

        $posts = TestFilterablePost::query()->get();
        TestFilterablePostResource::collection($posts);

        $this->assertSame([1, 2], $posts->first()->values->pluck('tag_id')->sort()->values()->all());
    }

    public function test_models_without_load_included_relations_are_unaffected(): void
    {
        $post = TestPlainPost::query()->create();
        TestPlainPostValue::query()->create(['post_id' => $post->id, 'tag_id' => 1]);

        $request = Request::create('/filter-posts?include=values&filter[included.values.tag_id]=999');
        app()->instance('request', $request);

        $posts = TestPlainPost::query()->get();
        TestPlainPostResource::collection($posts);

        $this->assertTrue($posts->first()->relationLoaded('values'), 'Plain models without loadIncludedRelations() must still eager-load normally.');
        $this->assertSame([1], $posts->first()->values->pluck('tag_id')->all());
    }
}

class TestFilterablePostValue extends Model
{
    public $timestamps = false;

    protected $table = 'filter_post_values';

    protected $guarded = [];
}

class TestFilterablePost extends Model
{
    public $timestamps = false;

    protected $table = 'filter_posts';

    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(TestFilterablePostValue::class, 'post_id');
    }

    /** Minimal stand-in for Jurager\Filterable\Concerns\HasFilterable::loadIncludedRelations(). */
    public function loadIncludedRelations(array $filter): static
    {
        $tagId = $filter['included.values.tag_id'] ?? null;

        if ($tagId !== null) {
            $this->setRelation('values', $this->values()->where('tag_id', $tagId)->get());
        }

        return $this;
    }
}

class TestFilterablePostResource extends JsonApiResource
{
    use WithEagerIncludes;
}

class TestPlainPostValue extends Model
{
    public $timestamps = false;

    protected $table = 'filter_post_values';

    protected $guarded = [];
}

class TestPlainPost extends Model
{
    public $timestamps = false;

    protected $table = 'filter_posts';

    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(TestPlainPostValue::class, 'post_id');
    }
}

class TestPlainPostResource extends JsonApiResource
{
    use WithEagerIncludes;
}
