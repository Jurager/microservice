<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\Tests\TestCase;

/**
 * Reproduces the over-fetching bug reported for PIM's
 * `attribute_values.attribute.enums` include: eager-loading a shared lookup
 * relation (Tag::options, analogous to Attribute::enums) through a nested
 * include must return only the options actually referenced by the loaded
 * "value" rows, not the tag's entire option list — regardless of which
 * resource or how deep the include path is.
 */
class WithEagerIncludesConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_optionable')->default(true);
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('post_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
            $table->unsignedBigInteger('value_integer')->nullable();
        });

        $tagId = TestTag::query()->create()->id;

        // 800 options exist for the tag, but only 2 are actually selected below.
        $optionIds = collect(range(1, 800))->map(fn () => TestOption::query()->create(['tag_id' => $tagId])->id);

        $postA = TestPost::query()->create();
        $postB = TestPost::query()->create();

        TestPostValue::query()->create(['post_id' => $postA->id, 'tag_id' => $tagId, 'value_integer' => $optionIds[3]]);
        TestPostValue::query()->create(['post_id' => $postB->id, 'tag_id' => $tagId, 'value_integer' => $optionIds[41]]);
    }

    public function test_nested_lookup_relation_is_constrained_to_referenced_values(): void
    {
        $request = Request::create('/posts?include=values.tag.options');
        app()->instance('request', $request);

        $posts = TestPost::query()->get();

        TestPostResource::collection($posts);

        $options = $posts->flatMap(fn (TestPost $post) => $post->values)
            ->pluck('tag.options')
            ->flatten()
            ->pluck('id')
            ->unique();

        $this->assertCount(2, $options, 'Only the referenced options should be eager-loaded, not all 800.');
    }

    public function test_direct_browsing_of_the_lookup_relation_is_unconstrained(): void
    {
        // No post_values ancestor in this path -> the full option list is legitimate here.
        $request = Request::create('/tags?include=options');
        app()->instance('request', $request);

        $tags = TestTag::query()->get();

        TestTagResource::collection($tags);

        $this->assertCount(800, $tags->first()->options);
    }

    public function test_constraint_applies_when_the_ancestor_relation_is_pre_loaded(): void
    {
        $request = Request::create('/posts?include=values.tag.options');
        app()->instance('request', $request);

        // Simulate a filter/eager-load pass (e.g. Filterable) that already loaded
        // "values" before the resource layer runs — the deeper constrained
        // relation must still be scoped, not just blindly dot-loaded.
        $posts = TestPost::query()->with('values')->get();

        TestPostResource::collection($posts);

        $options = $posts->flatMap(fn (TestPost $post) => $post->values)
            ->pluck('tag.options')
            ->flatten()
            ->pluck('id')
            ->unique();

        $this->assertCount(2, $options);
    }

    public function test_relation_is_never_queried_for_owners_that_cannot_have_it(): void
    {
        $plainTagId = TestTag::query()->create(['is_optionable' => false])->id;

        $postC = TestPost::query()->create();
        TestPostValue::query()->create(['post_id' => $postC->id, 'tag_id' => $plainTagId, 'value_integer' => null]);

        $request = Request::create('/posts?include=values.tag.options');
        app()->instance('request', $request);

        $optionQueries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$optionQueries) {
            if (str_contains($query->sql, '"options"')) {
                $optionQueries++;
            }
        });

        $posts = TestPost::query()->get();
        TestPostResource::collection($posts);

        $plainTagValue = $posts->firstWhere('id', $postC->id)->values->first();

        $this->assertTrue($plainTagValue->tag->relationLoaded('options'), 'Skipped owners still get the relation initialized, not left lazy.');
        $this->assertCount(0, $plainTagValue->tag->options);
        $this->assertSame(1, $optionQueries, 'Only the optionable tag should ever be queried for options.');
    }
}

class TestTag extends Model
{
    public $timestamps = false;

    protected $table = 'tags';

    protected $guarded = [];

    public function options(): HasMany
    {
        return $this->hasMany(TestOption::class, 'tag_id');
    }

    public static function eagerConstraints(): array
    {
        return [
            'options' => [self::class, 'constrainOptions'],
        ];
    }

    public static function eagerApplicable(): array
    {
        return [
            'options' => [self::class, 'isOptionable'],
        ];
    }

    public static function isOptionable(self $tag): bool
    {
        return (bool) $tag->is_optionable;
    }

    public static function constrainOptions(Relation $query, EloquentCollection $root, string $path): void
    {
        $segments = explode('.', $path);

        if (count($segments) < 3) {
            return;
        }

        $values = $root;

        foreach (array_slice($segments, 0, -2) as $segment) {
            $values = EloquentCollection::make(
                $values
                    ->filter(fn (Model $model) => $model->relationLoaded($segment))
                    ->map(fn (Model $model) => $model->getRelation($segment))
                    ->flatten(1)
                    ->filter()
            );
        }

        if ($values->isEmpty() || ! $values->first() instanceof TestPostValue) {
            return;
        }

        $query->whereIn('id', $values->pluck('value_integer')->filter()->unique());
    }
}

class TestOption extends Model
{
    public $timestamps = false;

    protected $table = 'options';

    protected $guarded = [];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TestTag::class, 'tag_id');
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
