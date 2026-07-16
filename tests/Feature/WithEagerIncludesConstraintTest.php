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
use Jurager\Microservice\Exceptions\UnknownIncludeException;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
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

class TestPost extends Model
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
