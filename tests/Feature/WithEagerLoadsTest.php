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

/** `eagerLoads()` lets a model batch-load relations the client never named, e.g. a variant's parent values. */
class WithEagerLoadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('variant_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable();
        });

        Schema::create('variant_post_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
        });

        $parent = VariantPost::query()->create();
        VariantPostValue::query()->create(['post_id' => $parent->id]);

        $child = VariantPost::query()->create(['parent_id' => $parent->id]);
        VariantPostValue::query()->create(['post_id' => $child->id]);
    }

    public function test_eager_loads_are_batch_loaded_alongside_the_requested_includes(): void
    {
        $request = Request::create('/posts?include=values');
        app()->instance('request', $request);

        $posts = VariantPost::query()->get();

        VariantPostResource::collection($posts);

        $child = $posts->firstWhere('parent_id', '!=', null);

        $this->assertNotNull($child);
        $this->assertTrue(
            $child->parent->relationLoaded('values'),
            'eagerLoads() should batch-load the parent values a variant reads, without naming them in the request.'
        );
    }

    public function test_eager_loads_receive_the_requested_include_keys(): void
    {
        $request = Request::create('/posts?include=values');
        app()->instance('request', $request);

        VariantPostResource::collection(VariantPost::query()->get());

        $this->assertSame(['values'], VariantPost::$lastIncluded, 'The hook should see the top-level include keys.');
    }
}

class VariantPost extends Model implements ProvidesEagerLoads
{
    public $timestamps = false;

    /** @var list<string>|null */
    public static ?array $lastIncluded = null;

    protected $table = 'variant_posts';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(VariantPostValue::class, 'post_id');
    }

    /**
     * @param list<string> $included
     * @return list<string>
     */
    public function eagerLoads(array $included): array
    {
        static::$lastIncluded = $included;

        return in_array('values', $included, true) ? ['parent.values'] : [];
    }
}

class VariantPostValue extends Model
{
    public $timestamps = false;

    protected $table = 'variant_post_values';

    protected $guarded = [];
}

class VariantPostResource extends JsonApiResource
{
    use WithEagerIncludes;
}
