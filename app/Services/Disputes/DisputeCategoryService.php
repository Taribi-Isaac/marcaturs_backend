<?php

namespace App\Services\Disputes;

use App\Models\DisputeCategory;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DisputeCategoryService
{
    /**
     * @return Collection<int, DisputeCategory>
     */
    public function listActive(): Collection
    {
        return DisputeCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, DisputeCategory>
     */
    public function listAll(): Collection
    {
        return DisputeCategory::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DisputeCategory
    {
        $category = new DisputeCategory;
        $category->code = $this->normalizeCode((string) ($attributes['code'] ?? $attributes['name']));
        $category->name = trim((string) $attributes['name']);
        $category->description = $this->optionalText($attributes['description'] ?? null);
        $category->is_active = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : true;
        $category->sort_order = (int) ($attributes['sort_order'] ?? 0);
        $category->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DisputeCategory $category, array $attributes): DisputeCategory
    {
        if (array_key_exists('name', $attributes)) {
            $category->name = trim((string) $attributes['name']);
        }
        if (array_key_exists('description', $attributes)) {
            $category->description = $this->optionalText($attributes['description']);
        }
        if (array_key_exists('is_active', $attributes)) {
            $category->is_active = (bool) $attributes['is_active'];
        }
        if (array_key_exists('sort_order', $attributes)) {
            $category->sort_order = (int) $attributes['sort_order'];
        }
        if (array_key_exists('code', $attributes)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Dispute category codes cannot be changed after creation.',
                422,
            ));
        }

        $category->save();

        return $category;
    }

    public function requireActive(int $categoryId): DisputeCategory
    {
        $category = DisputeCategory::query()->whereKey($categoryId)->first();

        if ($category === null || ! $category->is_active) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'The selected dispute category is invalid or inactive.',
                422,
            ));
        }

        return $category;
    }

    private function normalizeCode(string $value): string
    {
        $code = Str::slug($value, '_');

        if ($code === '') {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'A valid category code is required.',
                400,
            ));
        }

        return Str::limit($code, 64, '');
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
