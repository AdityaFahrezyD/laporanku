<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Data expense berhasil diambil', 'data' => $this->service->getExpenses()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Data expense berhasil diambil', 'data' => $this->service->getExpenseById($id)]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Expense berhasil dibuat', 'data' => $this->service->createExpense($request->validated())], 201);
    }

    public function update(UpdateExpenseRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Expense berhasil diperbarui', 'data' => $this->service->updateExpense($id, $request->validated())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteExpense($id);

        return response()->json(['message' => 'Expense berhasil dihapus']);
    }
}
