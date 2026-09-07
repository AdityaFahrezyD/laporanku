<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIncomeRequest;
use App\Http\Requests\UpdateIncomeRequest;
use App\Services\IncomeService;
use Illuminate\Http\JsonResponse;

class IncomeController extends Controller
{
    public function __construct(private readonly IncomeService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Data income berhasil diambil', 'data' => $this->service->getIncomes()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Data income berhasil diambil', 'data' => $this->service->getIncomeById($id)]);
    }

    public function store(StoreIncomeRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Income berhasil dibuat', 'data' => $this->service->createIncome($request->validated())], 201);
    }

    public function update(UpdateIncomeRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Income berhasil diperbarui', 'data' => $this->service->updateIncome($id, $request->validated())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteIncome($id);

        return response()->json(['message' => 'Income berhasil dihapus']);
    }
}
