<?php

declare(strict_types=1);

use Visualbuilder\EloquentSchema\Services\ModelSchemaService;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Author;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Post;

it('returns schema for a valid model', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class);

    expect($schema)->not->toBeNull();
    expect($schema['model'])->toBe(Author::class);
    expect($schema['table'])->toBe('authors');
});

it('returns null for invalid model class', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema('NonExistentModel');

    expect($schema)->toBeNull();
});

it('discovers database columns', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class);

    expect($schema['columns'])->toHaveKeys(['id', 'name', 'email', 'bio', 'created_at', 'updated_at']);
});

it('identifies column types', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class);

    expect($schema['columns']['id']['type'])->toBe('integer');
    expect($schema['columns']['name']['type'])->toBeIn(['string', 'varchar']);
    expect($schema['columns']['bio']['type'])->toBe('text');
});

it('discovers accessors', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class);

    expect($schema['columns'])->toHaveKey('full_name');
    expect($schema['columns']['full_name']['is_accessor'])->toBeTrue();
});

it('discovers hasMany relationships', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class, maxDepth: 1);

    expect($schema['relationships'])->toHaveKey('posts');
    expect($schema['relationships']['posts']['type'])->toBe('HasMany');
    expect($schema['relationships']['posts']['is_collection'])->toBeTrue();
});

it('discovers belongsTo relationships', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Post::class, maxDepth: 1);

    expect($schema['relationships'])->toHaveKey('author');
    expect($schema['relationships']['author']['type'])->toBe('BelongsTo');
    expect($schema['relationships']['author']['is_collection'])->toBeFalse();
});

it('discovers belongsToMany relationships', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Post::class, maxDepth: 1);

    expect($schema['relationships'])->toHaveKey('tags');
    expect($schema['relationships']['tags']['type'])->toBe('BelongsToMany');
    expect($schema['relationships']['tags']['is_collection'])->toBeTrue();
});

it('respects max depth for nested relationships', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class, maxDepth: 0);

    expect($schema['relationships'])->toBeEmpty();
});

it('includes related model schema when depth allows', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Author::class, maxDepth: 2);

    $relatedModel = $schema['relationships']['posts']['related_model'];
    expect($schema['definitions'])->toHaveKey($relatedModel);
    expect($schema['definitions'][$relatedModel]['table'])->toBe('posts');
});

it('detects circular references', function () {
    $service = app(ModelSchemaService::class);

    // Author -> posts -> author (circular)
    $schema = $service->getSchema(Author::class, maxDepth: 3);

    $postModelClass = $schema['relationships']['posts']['related_model'];
    $postsSchema = $schema['definitions'][$postModelClass] ?? [];
    $authorRelation = $postsSchema['relationships']['author'] ?? [];

    expect($authorRelation['circular'] ?? false)->toBeTrue();
});

it('returns flat field list', function () {
    $service = app(ModelSchemaService::class);

    $fields = $service->getFlatFieldList(Author::class, maxDepth: 1);

    expect($fields)->toHaveKey('id');
    expect($fields)->toHaveKey('name');
    expect($fields)->toHaveKey('posts.id');
    expect($fields)->toHaveKey('posts.title');
});

it('detects enum casts and includes options', function () {
    $service = app(ModelSchemaService::class);

    $schema = $service->getSchema(Post::class);

    expect($schema['columns'])->toHaveKey('status');
    expect($schema['columns']['status']['type'])->toBe('enum:draft|published|archived');
    expect($schema['columns']['status']['enum_class'])->toBe(\Visualbuilder\EloquentSchema\Tests\Fixtures\Enums\PostStatus::class);
});