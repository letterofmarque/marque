<?php

declare(strict_types=1);

namespace Marque\Parley\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Marque\Parley\Models\Category;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = 'Category '.Str::random(6);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'position' => 0,
        ];
    }

    public function at(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
