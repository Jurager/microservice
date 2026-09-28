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

/** A sparse fieldset narrows the resource's own fields; relationships the client included are never cut by it. */
class WithSparseRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sparse_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        Schema::create('sparse_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('body');
            $table->string('author');
        });

        $post = SparsePost::query()->create(['title' => 'Hello']);
        SparseComment::query()->create(['post_id' => $post->id, 'body' => 'Nice', 'author' => 'ann']);
    }

    /** @return array<string, mixed> */
    private function single(string $query): array
    {
        $request = Request::create("/sparse-posts/1?$query");
        app()->instance('request', $request);

        return json_decode(SparsePostResource::make(SparsePost::query()->first())->toResponse($request)->getContent(), true);
    }

    public function test_without_fieldset_every_requested_relationship_is_returned(): void
    {
        $document = $this->single('include=comments');

        $this->assertArrayHasKey('comments', $document['data']['relationships']);
        $this->assertCount(1, $document['included']);
    }

    public function test_fieldset_narrows_attributes_but_keeps_the_included_relationship(): void
    {
        $document = $this->single('include=comments&fields[sparsePost]=title');

        $this->assertSame(['title' => 'Hello'], $document['data']['attributes']);
        $this->assertArrayHasKey('comments', $document['data']['relationships']);
        $this->assertSame('comment', $document['included'][0]['type']);
    }

    public function test_included_resource_is_limited_by_its_own_fieldset(): void
    {
        $document = $this->single('include=comments&fields[sparsePost]=title&fields[comment]=body');

        $this->assertSame(['body' => 'Nice'], $document['included'][0]['attributes']);
    }

    public function test_included_relation_is_loaded_under_a_fieldset(): void
    {
        $request = Request::create('/sparse-posts?include=comments&fields[sparsePost]=title');
        app()->instance('request', $request);

        $posts = SparsePost::query()->get();
        SparsePostResource::collection($posts);

        $this->assertTrue($posts->first()->relationLoaded('comments'));
    }

    public function test_collection_response_keeps_the_included_relationship(): void
    {
        $request = Request::create('/sparse-posts?include=comments&fields[sparsePost]=title');
        app()->instance('request', $request);

        $document = SparsePostResource::collection(SparsePost::query()->get())->toResponse($request)->getData(true);

        $this->assertSame(['title' => 'Hello'], $document['data'][0]['attributes']);
        $this->assertArrayHasKey('comments', $document['data'][0]['relationships']);
        $this->assertCount(1, $document['included']);
    }
}

class SparseComment extends Model
{
    public $timestamps = false;

    protected $table = 'sparse_comments';

    protected $guarded = [];
}

class SparsePost extends Model
{
    public $timestamps = false;

    protected $table = 'sparse_posts';

    protected $guarded = [];

    public function comments(): HasMany
    {
        return $this->hasMany(SparseComment::class, 'post_id');
    }
}

class SparseCommentResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'comment';
    }

    public function toAttributes(Request $request): array
    {
        return ['body' => $this->body, 'author' => $this->author];
    }
}

class SparsePostResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toType(Request $request): string
    {
        return 'sparsePost';
    }

    public function toAttributes(Request $request): array
    {
        return ['title' => $this->title];
    }

    public function toRelationships(Request $request): array
    {
        return ['comments' => SparseCommentResource::class];
    }
}
