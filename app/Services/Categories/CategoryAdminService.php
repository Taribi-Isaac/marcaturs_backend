<?php

namespace App\Services\Categories;

use App\Enums\CategoryListingStatus;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategoryAdminService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Category
    {
        $category = new Category;
        $category->fill($this->only($attributes));
        $category->listing_status = CategoryListingStatus::from(
            (string) ($attributes['listing_status'] ?? CategoryListingStatus::Allowed->value),
        );
        $category->is_active = (bool) ($attributes['is_active'] ?? true);
        $category->sort_order = (int) ($attributes['sort_order'] ?? 0);
        $category->slug = $this->uniqueSlug(
            (string) ($attributes['slug'] ?? ''),
            (string) $attributes['name'],
        );
        $category->save();

        return $category->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $category->fill($this->only($attributes));

        if (array_key_exists('listing_status', $attributes)) {
            $category->listing_status = CategoryListingStatus::from((string) $attributes['listing_status']);
        }

        if (array_key_exists('slug', $attributes) || array_key_exists('name', $attributes)) {
            $category->slug = $this->uniqueSlug(
                (string) ($attributes['slug'] ?? $category->slug),
                (string) ($attributes['name'] ?? $category->name),
                $category->id,
            );
        }

        $category->save();

        return $category->refresh();
    }

    /**
     * @return Collection<int, Category>
     */
    public function all(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function visibleToParticipants(): Collection
    {
        return Category::query()
            ->assignable()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function uniqueSlug(string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name);
        if ($base === '') {
            $base = 'category';
        }

        $candidate = $base;
        $suffix = 2;

        while (
            Category::query()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function only(array $attributes): array
    {
        return collect($attributes)->only([
            'name',
            'description',
            'listing_status',
            'is_active',
            'sort_order',
        ])->all();
    }
}
