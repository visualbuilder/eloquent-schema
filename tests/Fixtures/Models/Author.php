<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $fillable = [
        'name',
        'email',
        'bio',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => $this->name);
    }
}
