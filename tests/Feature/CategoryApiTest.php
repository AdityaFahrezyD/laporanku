<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

test('category reads are public and writes require admin', function () {
    $category = Category::create(['name' => 'Gaji', 'type' => 'income']);
    $url = '/api/categories/'.$category->getKey();
    foreach (['GET', 'HEAD'] as $method) {
        $this->json($method, '/api/categories')->assertOk();
        $this->json($method, $url)->assertOk();
        $this->json($method, '/api/categories/'.Str::uuid())->assertNotFound();
    }
    $actions = [['POST', '/api/categories'], ['PUT', $url], ['PATCH', $url], ['DELETE', $url]];
    foreach ($actions as [$method, $path]) {
        $this->json($method, $path, ['name' => 'Baru', 'type' => 'expense'])->assertUnauthorized();
    }
    $this->actingAs(User::factory()->create());
    $this->getJson($url)->assertOk();
    foreach ($actions as [$method, $path]) {
        $this->json($method, $path, ['name' => 'Baru', 'type' => 'expense'])->assertForbidden();
    }
    expect($category->fresh()->name)->toBe('Gaji');
    expect(Category::count())->toBe(1);
});

test('admin manages categories with type scoped names and sorted filters', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $a = $this->postJson('/api/categories', ['name' => 'Zeta', 'type' => 'income'])->assertCreated()->json('data.category_id');
    $this->postJson('/api/categories', ['name' => 'Alpha', 'type' => 'expense'])->assertCreated();
    $this->postJson('/api/categories', ['name' => 'Zeta', 'type' => 'income'])->assertUnprocessable();
    $b = $this->postJson('/api/categories', ['name' => 'Zeta', 'type' => 'expense'])->assertCreated()->json('data.category_id');
    $this->getJson('/api/categories')->assertJsonCount(3, 'data')->assertJsonPath('data.0.name', 'Alpha');
    $this->getJson('/api/categories?type=income')->assertJsonCount(1, 'data')->assertJsonPath('data.0.category_id', $a);
    $this->getJson('/api/categories?type=expense')->assertJsonCount(2, 'data');
    $this->getJson('/api/categories?type=transfer')->assertUnprocessable();
    $this->patchJson("/api/categories/$b", ['type' => 'income'])->assertUnprocessable();
    $this->putJson("/api/categories/$b", ['name' => 'Bonus'])->assertUnprocessable();
    $this->putJson("/api/categories/$b", ['name' => 'Bonus', 'type' => 'income'])->assertOk();
    $this->patchJson("/api/categories/$b", ['name' => 'Bonus'])->assertOk();
    $this->deleteJson("/api/categories/$b")->assertOk();
    $this->deleteJson("/api/categories/$b")->assertNotFound();
    foreach ([['name' => '', 'type' => 'income'], ['name' => str_repeat('a', 101), 'type' => 'income'], ['name' => 'Other', 'type' => 'transfer']] as $invalid) {
        $this->postJson('/api/categories', $invalid)->assertUnprocessable();
    }
});

test('income and expense categories are optional typed and independently editable', function (string $kind) {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $type = $kind === 'incomes' ? 'income' : 'expense';
    $wallet = financeWallet();
    $payload = financePayload($kind, $wallet);
    $category = Category::create(['name' => 'First', 'type' => $type]);
    $other = Category::create(['name' => 'Second', 'type' => $type]);
    $wrong = Category::create(['name' => 'Wrong', 'type' => $type === 'income' ? 'expense' : 'income']);
    $id = $this->postJson("/api/$kind", $payload)->assertCreated()->assertJsonPath('data.category_id', null)->assertJsonPath('data.category', null)->json("data.{$type}_id");
    $url = "/api/$kind/$id";
    $balance = $wallet->fresh()->balance;
    foreach ([$wrong->getKey(), (string) Str::uuid(), 'invalid'] as $invalid) {
        $this->patchJson($url, ['category_id' => $invalid])->assertUnprocessable();
        $this->postJson("/api/$kind", [...$payload, 'category_id' => $invalid])->assertUnprocessable();
    }
    $this->patchJson($url, ['category_id' => $category->getKey()])->assertOk()->assertJsonPath('data.category.name', 'First');
    $this->patchJson($url, ['description' => 'Keep'])->assertOk()->assertJsonPath('data.category_id', $category->getKey());
    $this->patchJson($url, ['category_id' => $other->getKey()])->assertOk()->assertJsonPath('data.category.name', 'Second');
    $this->deleteJson('/api/categories/'.$other->getKey())->assertUnprocessable();
    $this->patchJson('/api/categories/'.$other->getKey(), ['type' => $wrong->type])->assertUnprocessable();
    $this->patchJson('/api/categories/'.$other->getKey(), ['name' => 'Renamed'])->assertOk();
    $this->getJson($url)->assertJsonPath('data.category.name', 'Renamed');
    $this->getJson("/api/$kind")->assertJsonPath('data.0.category.name', 'Renamed');
    $this->patchJson($url, ['category_id' => null])->assertOk()->assertJsonPath('data.category', null);
    $this->patchJson($url, ['category_id' => $category->getKey()])->assertOk();
    $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.category_id', null)->assertJsonPath('data.category', null);
    expect($wallet->fresh()->balance)->toBe($balance);
    $this->patchJson($url, ['category_id' => $category->getKey()])->assertOk();
    $this->deleteJson($url)->assertOk();
    expect($category->fresh())->not->toBeNull();
    $this->deleteJson('/api/categories/'.$category->getKey())->assertOk();
    $this->postJson("/api/$kind", [...$payload, 'category_id' => $other->getKey()])->assertCreated()->assertJsonPath('data.category.name', 'Renamed');
})->with(['incomes', 'expenses']);
