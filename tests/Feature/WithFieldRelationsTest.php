<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;
use Jurager\Microservice\Tests\TestCase;

/**
 * A field that addresses a relation's rows (an EAV code selecting `attribute_values`), reported by the
 * model's optional fieldRelations(), keeps that relation in the response and is not repeated as an
 * attribute.
 */
class WithFieldRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('addressed_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        Schema::create('addressed_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('code');
        });

        $post = AddressedPost::query()->create(['title' => 'Hello']);
        AddressedValue::query()->create(['post_id' => $post->id, 'code' => 'author']);
    }

    /** @return array<string, mixed> */
    private function single(string $query): array
    {
        $request = Request::create("/addressed-posts/1?$query");
        app()->instance('request', $request);

        return json_decode(AddressedPostResource::make(AddressedPost::query()->first())->toResponse($request)->getContent(), true);
    }

    public function test_relation_addressed_by_a_field_is_kept_although_the_fieldset_does_not_name_it(): void
    {
        $document = $this->single('include=values&fields[addressedPost]=author');

        $this->assertArrayHasKey('values', $document['data']['relationships']);
        $this->assertSame('addressedValue', $document['included'][0]['type']);
    }

    public function test_addressing_field_is_not_repeated_as_an_attribute(): void
    {
        $document = $this->single('include=values&fields[addressedPost]=author,title');

        $this->assertSame(['title' => 'Hello'], $document['data']['attributes']);
    }

    public function test_relation_stays_dropped_when_no_field_addresses_it(): void
    {
        $document = $this->single('include=values&fields[addressedPost]=title');

        $this->assertSame(['title' => 'Hello'], $document['data']['attributes']);
        $this->assertArrayNotHasKey('included', $document);
    }

    public function test_addressed_relation_is_loaded_and_unaddressed_one_is_not(): void
    {
        $request = Request::create('/addressed-posts?include=values&fields[addressedPost]=author');
        app()->instance('request', $request);
        $posts = AddressedPost::query()->get();
        AddressedPostResource::collection($posts);

        $this->assertTrue($posts->first()->relationLoaded('values'));

        $request = Request::create('/addressed-posts?include=values&fields[addressedPost]=title');
        app()->instance('request', $request);
        $posts = AddressedPost::query()->get();
        AddressedPostResource::collection($posts);

        $this->assertFalse($posts->first()->relationLoaded('values'));
    }

    public function test_addressing_field_stays_an_attribute_when_its_relation_was_not_requested(): void
    {
        $document = $this->single('fields[addressedPost]=author,title');

        $this->assertSame(['title' => 'Hello', 'author' => 'ann'], $document['data']['attributes']);
    }

    public function test_addressed_relation_is_loaded_once_for_the_attribute_even_when_not_included(): void
    {
        $request = Request::create('/addressed-posts?fields[addressedPost]=author,title');
        app()->instance('request', $request);
        $posts = AddressedPost::query()->get();
        AddressedPostResource::collection($posts);

        $this->assertTrue($posts->first()->relationLoaded('values'));
    }

    public function test_relation_read_lazily_while_serializing_is_loaded_for_the_whole_collection(): void
    {
        AddressedPost::query()->create(['title' => 'Second']);
        AddressedPost::query()->create(['title' => 'Third']);

        $request = Request::create('/addressed-posts');
        app()->instance('request', $request);
        $posts = AddressedPost::query()->get();

        DB::enableQueryLog();
        LazyPostResource::collection($posts)->toResponse($request);
        $valueQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'addressed_values'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $valueQueries);
    }

    public function test_constraint_wins_over_a_copy_of_the_relation_loaded_earlier(): void
    {
        AddressedValue::query()->create(['post_id' => 1, 'code' => 'other']);

        $request = Request::create('/addressed-posts?include=values&fields[addressedPost]=author');
        app()->instance('request', $request);
        $posts = AddressedPost::query()->with('values')->get();

        $this->assertCount(2, $posts->first()->values);

        AddressedPostResource::collection($posts);

        $this->assertSame(['author'], $posts->first()->values->pluck('code')->all());
    }

    public function test_without_a_fieldset_nothing_changes(): void
    {
        $document = $this->single('include=values');

        $this->assertSame(['title' => 'Hello', 'author' => 'ann'], $document['data']['attributes']);
        $this->assertArrayHasKey('values', $document['data']['relationships']);
    }
}

class AddressedValue extends Model
{
    public $timestamps = false;

    protected $table = 'addressed_values';

    protected $guarded = [];
}

class AddressedPost extends Model implements ProvidesEagerLoads
{
    public $timestamps = false;

    protected $table = 'addressed_posts';

    protected $guarded = [];

    public function values(): HasMany
    {
        return $this->hasMany(AddressedValue::class, 'post_id');
    }

    /** @return array<string, \Closure> */
    public function eagerLoads(array $included, ?array $fields = null): array
    {
        if ($fields === null || ! in_array('values', $included, true)) {
            return [];
        }

        return ['values' => fn ($query) => $query->whereIn('code', $fields)];
    }

    /** @param  list<string>  $fields */
    public static function fieldRelations(array $fields): array
    {
        $codes = array_values(array_intersect($fields, ['author']));

        return $codes === [] ? [] : ['values' => $codes];
    }
}

class AddressedValueResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'addressedValue';
    }

    public function toAttributes(Request $request): array
    {
        return ['code' => $this->code];
    }
}

class AddressedPostResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'addressedPost';
    }

    public function toAttributes(Request $request): array
    {
        return ['title' => $this->title, 'author' => 'ann'];
    }

    public function toRelationships(Request $request): array
    {
        return ['values' => AddressedValueResource::class];
    }
}

class LazyPostResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'lazyPost';
    }

    public function toAttributes(Request $request): array
    {
        return ['values' => $this->values->count()];
    }
}
