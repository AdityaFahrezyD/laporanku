<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferRequest;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Data transfer berhasil diambil', 'data' => $this->service->getTransfers()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Data transfer berhasil diambil', 'data' => $this->service->getTransferById($id)]);
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Transfer berhasil dibuat', 'data' => $this->service->createTransfer($request->validated())], 201);
    }

    public function update(UpdateTransferRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Transfer berhasil diperbarui', 'data' => $this->service->updateTransfer($id, $request->validated())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteTransfer($id);

        return response()->json(['message' => 'Transfer berhasil dihapus']);
    }
}
