<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function getCategories(?string $type = null): Collection
    {
        return Category::when(
            $type !== null,
            fn($query) => $query->where("type", $type)
        )
            ->orderBy("name")
            ->orderBy("category_id")
            ->get();
    }

    public function getCategoryById(string $id): Category
    {
        return Category::findOrFail($id);
    }

    public function createCategory(array $data): Category
    {
        return $this->save(null, $data);
    }

    public function updateCategory(string $id, array $data): Category
    {
        return $this->save($id, $data);
    }

    private function save(?string $id, array $data): Category
    {
        try {
            return DB::transaction(function () use ($id, $data) {
                $category =
                    $id === null
                        ? new Category()
                        : Category::lockForUpdate()->findOrFail($id);
                $merged = array_replace($category->getAttributes(), $data);
                Validator::make($merged, [
                    "name" => [
                        "required",
                        "string",
                        "max:100",
                        Rule::unique("categories", "name")
                            ->where("type", $merged["type"] ?? null)
                            ->ignore($category->getKey(), "category_id"),
                    ],
                    "type" => ["required", "in:income,expense"],
                ])->validate();
                if (
                    $category->exists &&
                    $category->type !== $merged["type"] &&
                    $this->isUsed($category)
                ) {
                    throw ValidationException::withMessages([
                        "type" =>
                            "Jenis kategori yang sudah digunakan tidak dapat diubah.",
                    ]);
                }
                $category->fill($data)->save();

                return $category->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                "name" => "Nama kategori sudah digunakan untuk jenis ini.",
            ]);
        }
    }

    public function deleteCategory(string $id): void
    {
        DB::transaction(function () use ($id) {
            $category = Category::lockForUpdate()->findOrFail($id);
            if ($this->isUsed($category)) {
                throw ValidationException::withMessages([
                    "category_id" =>
                        "Kategori yang sudah digunakan tidak dapat dihapus.",
                ]);
            }
            $category->delete();
        }, 3);
    }

    private function isUsed(Category $category): bool
    {
        return $category->incomes()->exists() ||
            $category->expenses()->exists();
    }
}
