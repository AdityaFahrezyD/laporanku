<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWalletRequest;
use App\Http\Requests\UpdateWalletRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Data wallet berhasil diambil', 'data' => $this->service->getWallets()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Data wallet berhasil diambil', 'data' => $this->service->getWalletById($id)]);
    }

    public function store(StoreWalletRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Wallet berhasil dibuat', 'data' => $this->service->createWallet($request->validated())], 201);
    }

    public function update(UpdateWalletRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Wallet berhasil diperbarui', 'data' => $this->service->updateWallet($id, $request->validated())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteWallet($id);

        return response()->json(['message' => 'Wallet berhasil dihapus']);
    }
}
