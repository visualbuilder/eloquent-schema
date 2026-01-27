<?php

declare(strict_types=1);

use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Author;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Comment;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Post;
use Visualbuilder\EloquentSchema\Tests\Fixtures\Models\Tag;

it('discovers models from configured paths', function () {
    $service = app(ModelDiscoveryService::class);

    $models = $service->getModels();

    expect($models)->toContain(Author::class);
    expect($models)->toContain(Post::class);
    expect($models)->toContain(Comment::class);
    expect($models)->toContain(Tag::class);
});

it('filters models by name', function () {
    $service = app(ModelDiscoveryService::class);

    $models = $service->getModels(filter: 'Post');

    expect($models)->toContain(Post::class);
    expect($models)->not->toContain(Author::class);
    expect($models)->not->toContain(Tag::class);
});

it('filters models case-insensitively', function () {
    $service = app(ModelDiscoveryService::class);

    $models = $service->getModels(filter: 'author');

    expect($models)->toContain(Author::class);
});

it('warms cache and returns stats', function () {
    $service = app(ModelDiscoveryService::class);

    $stats = $service->warmCache();

    expect($stats)->toHaveKeys(['app', 'vendor', 'total']);
    expect($stats['total'])->toBeGreaterThanOrEqual(4);
});

it('clears cache without error', function () {
    $service = app(ModelDiscoveryService::class);

    $service->clearCache();

    expect(true)->toBeTrue();
});
