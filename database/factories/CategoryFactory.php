<?php

namespace Database\Factories;

use App\Enums\CategoryListingStatus;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => 'Administrator-defined marketplace category.',
            'listing_status' => CategoryListingStatus::Allowed,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_status' => CategoryListingStatus::Restricted,
        ]);
    }

    public function prohibited(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_status' => CategoryListingStatus::Prohibited,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
