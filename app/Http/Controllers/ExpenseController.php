<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Services\ExpenseService;
use App\Services\TransactionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->has('paginated')) {
            return response()->json(app(TransactionQuery::class)->page('expenses', $request));
        }

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
