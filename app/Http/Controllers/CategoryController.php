<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['sometimes', 'required', 'in:income,expense']]);

        return response()->json(['message' => 'Data kategori berhasil diambil', 'data' => $this->service->getCategories($data['type'] ?? null)]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Data kategori berhasil diambil', 'data' => $this->service->getCategoryById($id)]);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Kategori berhasil dibuat', 'data' => $this->service->createCategory($request->validated())], 201);
    }

    public function update(CategoryRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Kategori berhasil diperbarui', 'data' => $this->service->updateCategory($id, $request->validated())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteCategory($id);

        return response()->json(['message' => 'Kategori berhasil dihapus']);
    }
}
