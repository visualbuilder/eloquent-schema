<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Tests\Fixtures\Enums;

enum PostStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
