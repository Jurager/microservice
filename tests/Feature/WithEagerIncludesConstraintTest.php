<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\Exceptions\UnknownIncludeException;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\JsonApi\Contracts\DefinesEagerRelations;
use Jurager\Microservice\JsonApi\ResourceCollection;
use Jurager\Microservice\Tests\TestCase;

/** `eagerRelations()` overrides must still apply when the top segment of the path arrives already loaded. */
class WithEagerIncludesConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tag_labels', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_label_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('post_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
        });

        $labelId = TestTagLabel::query()->create()->id;
        $tagId = TestTag::query()->create(['tag_label_id' => $labelId])->id;

        $post = TestPost::query()->create();
        TestPostValue::query()->create(['post_id' => $post->id, 'tag_id' => $tagId]);
    }

    public function test_eager_relations_override_applies_on_a_fresh_load(): void
    {
        // Client only asks for "values.tag" — TestPost::eagerRelations() forces
        // "tag.label" to load alongside it too.
        $request = Request::create('/posts?include=values.tag');
        app()->instance('request', $request);

        $posts = TestPost::query()->get();

        TestPostResource::collection($posts);

        $tag = $posts->first()->values->first()->tag;

        $this->assertTrue($tag->relationLoaded('label'), 'eagerRelations() override should force-load "label" alongside "tag".');
    }

    public function test_eager_relations_override_still_applies_when_the_top_segment_is_preloaded(): void
    {
        $request = Request::create('/posts?include=values.tag');
        app()->instance('request', $request);

        // Simulate a filter query (e.g. Filterable) that already loaded
        // "values" before the resource layer runs.
        $posts = TestPost::query()->with('values')->get();

        TestPostResource::collection($posts);

        $tag = $posts->first()->values->first()->tag;

        $this->assertTrue(
            $tag->relationLoaded('label'),
            'The override must still force-load "label" even though "values" itself arrived pre-loaded.'
        );
    }

    public function test_unknown_include_throws(): void
    {
        $request = Request::create('/posts?include=bogus');
        app()->instance('request', $request);

        $this->expectException(UnknownIncludeException::class);

        TestPostResource::collection(TestPost::query()->get());
    }

    public function test_unknown_nested_include_throws_with_full_path(): void
    {
        $request = Request::create('/posts?include=values.bogus');
        app()->instance('request', $request);

        $this->expectException(UnknownIncludeException::class);
        $this->expectExceptionMessage('Unknown include: "values.bogus".');

        TestPostResource::collection(TestPost::query()->get());
    }

    /** A document of posts and tags: only posts have values. */
    private function postsAndTags(string $include): array
    {
        $request = Request::create('/items?include='.$include);
        app()->instance('request', $request);

        $resources = new Collection([
            new TestPostResource(TestPost::query()->firstOrFail()),
            new TestTagResource(TestTag::query()->firstOrFail()),
        ]);

        return (new ResourceCollection($resources, JsonApiResource::class))->toResponse($request)->getData(true);
    }

    public function test_an_include_applies_to_the_primary_resources_that_have_the_relation(): void
    {
        $body = $this->postsAndTags('values,label');

        $this->assertCount(1, $body['data'][0]['relationships']['values']['data']);
        $this->assertArrayNotHasKey('label', $body['data'][0]['relationships']);
        $this->assertArrayNotHasKey('values', $body['data'][1]['relationships']);
        $this->assertNotNull($body['data'][1]['relationships']['label']['data']);
    }

    public function test_nested_includes_follow_the_primary_resources_that_have_the_relation(): void
    {
        $body = $this->postsAndTags('values.tag');

        $this->assertCount(1, $body['data'][0]['relationships']['values']['data']);
        $this->assertArrayNotHasKey('relationships', $body['data'][1]);
    }

    public function test_an_include_no_primary_resource_has_throws(): void
    {
        $this->expectException(UnknownIncludeException::class);
        $this->expectExceptionMessage('Unknown include: "bogus".');

        $this->postsAndTags('values,bogus');
    }

    /** A page of posts and tags that holds tags alone. */
    private function tagsOfPostsAndTags(string $include): array
    {
        $request = Request::create('/items?include='.$include);
        app()->instance('request', $request);

        $resources = new Collection([new TestTagResource(TestTag::query()->firstOrFail())]);

        return (new ResourceCollection($resources, JsonApiResource::class, [TestPost::class, TestTag::class]))
            ->toResponse($request)
            ->getData(true);
    }

    public function test_an_include_of_a_model_the_page_lacks_is_not_rejected(): void
    {
        $body = $this->tagsOfPostsAndTags('values,label');

        $this->assertArrayNotHasKey('values', $body['data'][0]['relationships']);
        $this->assertNotNull($body['data'][0]['relationships']['label']['data']);
    }

    public function test_an_include_no_model_of_the_collection_has_throws(): void
    {
        $this->expectException(UnknownIncludeException::class);
        $this->expectExceptionMessage('Unknown include: "bogus".');

        $this->tagsOfPostsAndTags('values,bogus');
    }
}

class TestTagLabel extends Model
{
    public $timestamps = false;

    protected $table = 'tag_labels';

    protected $guarded = [];
}

class TestTag extends Model
{
    public $timestamps = false;

    protected $table = 'tags';

    protected $guarded = [];

    public function label(): BelongsTo
    {
        return $this->belongsTo(TestTagLabel::class, 'tag_label_id');
    }
}

class TestPost extends Model implements DefinesEagerRelations
{
    public $timestamps = false;

    protected $table = 'posts';

    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(TestPostValue::class, 'post_id');
    }

    public static function eagerRelations(): array
    {
        return [
            'values' => ['values.tag.label'],
        ];
    }
}

class TestPostValue extends Model
{
    public $timestamps = false;

    protected $table = 'post_values';

    protected $guarded = [];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TestTag::class, 'tag_id');
    }
}

class TestPostResource extends JsonApiResource
{
    use WithEagerIncludes;
}

class TestTagResource extends JsonApiResource
{
    use WithEagerIncludes;
}
