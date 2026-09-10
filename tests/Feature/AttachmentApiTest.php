<?php

use App\Models\Attachment;
use App\Models\Income;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AttachmentFiles;
use App\Services\AvifProcessor;
use App\Services\IncomeService;
use App\Support\TransactionCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('attachments');
    $this->actingAs(User::factory()->create(['role' => 'admin']));
});

function attachmentTransaction($test, string $kind): string
{
    $wallet = Wallet::create(['name' => 'Bank', 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
    $to = Wallet::create(['name' => 'Cash', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
    $data = ['amount' => '10.00', 'transaction_date' => '10-09-2026 10:00'];
    $data += $kind === 'transfers' ? ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $to->getKey()] : ['wallet_id' => $wallet->getKey()];
    $response = $test->postJson('/api/'.$kind, $data)->assertCreated();

    return '/api/'.$kind.'/'.$response->json('data.'.rtrim($kind, 's').'_id');
}

test('attachments upload list public read and deletion work for every transaction', function (string $kind) {
    $base = attachmentTransaction($this, $kind);
    $this->getJson('/api/'.$kind)->assertJsonCount(0, 'data.0.attachments');
    $balances = Wallet::pluck('balance', 'wallet_id')->all();
    $version = TransactionCache::version();
    $response = $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('receipt.jpg', 2000, 1000)])
        ->assertCreated()->assertJsonPath('data.mime_type', 'image/avif')
        ->assertJsonPath('data.width', 1920)->assertJsonPath('data.height', 960)
        ->assertJsonMissingPath('data.file_path')->assertJsonMissingPath('data.disk');
    expect(TransactionCache::version())->not->toBe($version);
    expect(Wallet::pluck('balance', 'wallet_id')->all())->toBe($balances);
    $url = $response->json('data.url');
    $attachment = Attachment::findOrFail($response->json('data.attachment_id'));
    expect(getimagesizefromstring(Storage::disk('attachments')->get($attachment->file_path))['mime'])->toBe('image/avif');
    $this->getJson('/api/'.$kind)->assertJsonPath('data.0.attachments.0.url', $url);
    $this->getJson($base)->assertJsonPath('data.attachments.0.url', $url);
    auth()->forgetGuards();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/avif');
    $this->postJson($base.'/attachments', [])->assertUnauthorized();
    $this->deleteJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'user']));
    $this->postJson($base.'/attachments', [])->assertForbidden();
    $this->deleteJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $other = attachmentTransaction($this, $kind);
    $this->get($other.'/attachments/'.$attachment->getKey())->assertNotFound();
    $this->deleteJson($other.'/attachments/'.$attachment->getKey())->assertNotFound();
    $version = TransactionCache::version();
    $this->deleteJson($url)->assertNoContent();
    expect(TransactionCache::version())->not->toBe($version);
    Storage::disk('attachments')->assertMissing($attachment->file_path);
    $this->get($url)->assertNotFound();
})->with(['incomes', 'expenses', 'transfers']);

test('deleting a transaction deletes its files after commit', function (string $kind) {
    $base = attachmentTransaction($this, $kind);
    $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('small.png', 32, 16)])->assertCreated();
    $path = Attachment::firstOrFail()->file_path;
    $this->deleteJson($base)->assertOk();
    Storage::disk('attachments')->assertMissing($path);
    $this->assertDatabaseCount('attachments', 0);
})->with(['incomes', 'expenses', 'transfers']);

test('invalid oversized and animated images are rejected without storing files', function () {
    $base = attachmentTransaction($this, 'incomes');
    $png = UploadedFile::fake()->image('image.png', 8, 8);
    $bytes = file_get_contents($png->getPathname());
    $animated = substr($bytes, 0, 33).pack('N', 8).'acTL'.pack('NN', 2, 0).pack('N', 0).substr($bytes, 33);
    foreach ([
        UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
        UploadedFile::fake()->image('large.jpg', 2001, 2000),
        UploadedFile::fake()->image('large.png')->size(5121),
        UploadedFile::fake()->createWithContent('animated.png', $animated),
        UploadedFile::fake()->image('animated.gif'),
    ] as $file) {
        $this->postJson($base.'/attachments', ['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
    }
    $this->assertDatabaseCount('attachments', 0);
    expect(Storage::disk('attachments')->allFiles())->toBe([]);
});

test('all supported static formats encode and small images are not enlarged', function (string $format) {
    $gd = imagecreatetruecolor(30, 20);
    ob_start();
    match ($format) {
        'webp' => imagewebp($gd), 'avif' => imageavif($gd), 'png' => imagepng($gd), 'jpg' => imagejpeg($gd)
    };
    $bytes = ob_get_clean();
    $result = app(AvifProcessor::class)->process(UploadedFile::fake()->createWithContent('image.'.$format, $bytes));
    expect($result['width'])->toBe(30)->and($result['height'])->toBe(20);
})->with(['jpg', 'png', 'webp', 'avif']);

test('jpeg EXIF orientation is applied before resizing', function () {
    $file = UploadedFile::fake()->image('portrait.jpg', 30, 20);
    $jpeg = file_get_contents($file->getPathname());
    $exif = "Exif\0\0".'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
    $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $result = app(AvifProcessor::class)->process(UploadedFile::fake()->createWithContent('rotated.jpg', $jpeg));
    expect($result['width'])->toBe(20)->and($result['height'])->toBe(30);
});

test('database failure removes the written file and returns a safe error', function () {
    $base = attachmentTransaction($this, 'incomes');
    DB::statement("CREATE TRIGGER fail_attachment BEFORE INSERT ON attachments BEGIN SELECT RAISE(ABORT, 'private database details'); END");
    $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])
        ->assertStatus(500)->assertExactJson(['message' => 'Gambar gagal diproses atau disimpan. Silakan coba kembali.']);
    expect(Storage::disk('attachments')->allFiles())->toBe([]);
    $this->assertDatabaseCount('attachment_file_cleanup', 0);
});

test('cleanup retries failures but preserves referenced and recent files', function () {
    $files = app(AttachmentFiles::class);
    $id = $files->track('attachments', 'old.avif');
    Storage::disk('attachments')->put('old.avif', 'bytes');
    $this->artisan('attachments:cleanup')->assertSuccessful();
    Storage::disk('attachments')->assertExists('old.avif');
    $disk = Storage::disk('attachments');
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);
    // Unknown disk is retained as a durable failure for an operator to fix.
    $bad = $files->track('missing-disk', 'failed.avif');
    expect($files->cleanup($bad))->toBeFalse();
    $this->assertDatabaseHas('attachment_file_cleanup', ['id' => $bad]);
    DB::table('attachment_file_cleanup')->where('id', $bad)->delete();
    $this->travel(61)->minutes();
    $this->artisan('attachments:cleanup')->assertSuccessful();
    expect($disk->exists('old.avif'))->toBeFalse();
    $this->assertDatabaseMissing('attachment_file_cleanup', ['id' => $id]);
});

test('rollback preserves both attachment row and file', function () {
    $base = attachmentTransaction($this, 'incomes');
    $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])->assertCreated();
    $attachment = Attachment::firstOrFail();
    DB::beginTransaction();
    app(AttachmentFiles::class)->removeAfterCommit($attachment);
    $attachment->delete();
    Storage::disk('attachments')->assertExists($attachment->file_path);
    DB::rollBack();
    Storage::disk('attachments')->assertExists($attachment->file_path);
    $this->assertDatabaseHas('attachments', ['attachment_id' => $attachment->getKey()]);
});

test('storage failure does not publish attachment metadata', function () {
    $base = attachmentTransaction($this, 'incomes');
    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('Private storage path'));
    $disk->shouldReceive('exists')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);
    $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])
        ->assertStatus(500)->assertJsonMissingPath('exception');
    $this->assertDatabaseCount('attachments', 0);
});

test('transaction removed during conversion cannot receive an attachment', function () {
    $base = attachmentTransaction($this, 'incomes');
    $this->mock(AvifProcessor::class, function ($mock) use ($base) {
        $mock->shouldReceive('process')->once()->andReturnUsing(function ($file) use ($base) {
            $result = (new AvifProcessor)->process($file);
            app(IncomeService::class)->deleteIncome(basename($base));

            return $result;
        });
    });
    $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])->assertNotFound();
    expect(Storage::disk('attachments')->allFiles())->toBe([]);
    $this->assertDatabaseCount('attachments', 0);
});

test('failed deletion is retried and referenced files are protected', function () {
    $base = attachmentTransaction($this, 'incomes');
    $response = $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])->assertCreated();
    $attachment = Attachment::firstOrFail();
    $files = app(AttachmentFiles::class);
    $ticket = $files->track($attachment->disk, $attachment->file_path);
    expect($files->cleanup($ticket))->toBeFalse();
    Storage::disk('attachments')->assertExists($attachment->file_path);
    DB::table('attachment_file_cleanup')->where('id', $ticket)->delete();
    $real = Storage::disk('attachments');
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('delete')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $real->delete($path));
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);
    $this->deleteJson($response->json('data.url'))->assertNoContent();
    expect($real->exists($attachment->file_path))->toBeTrue();
    $this->assertDatabaseCount('attachment_file_cleanup', 1);
    $this->travel(61)->minutes();
    $this->artisan('attachments:cleanup')->assertSuccessful();
    expect($real->exists($attachment->file_path))->toBeFalse();
});

test('attachment routes use shared read and write quotas', function () {
    $base = attachmentTransaction($this, 'incomes');
    config(['traffic.writes_per_minute' => 2, 'traffic.reads_per_minute' => 1]);
    $response = $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])->assertCreated();
    $this->deleteJson($response->json('data.url'))->assertStatus(429);
    $this->get($response->json('data.url'))->assertOk();
    $this->getJson($base)->assertStatus(429);
});

test('legacy records remain readable as metadata and missing files return 404', function () {
    $base = attachmentTransaction($this, 'incomes');
    $income = Income::findOrFail(basename($base));
    $legacy = $income->attachments()->create(['file_path' => 'old/receipt.pdf']);
    $this->getJson($base)->assertOk()->assertJsonPath('data.attachments.0.url', null);
    $this->get($base.'/attachments/'.$legacy->getKey())->assertNotFound();
    $this->deleteJson($base.'/attachments/'.$legacy->getKey())->assertNoContent();
    $response = $this->postJson($base.'/attachments', ['image' => UploadedFile::fake()->image('image.png')])->assertCreated();
    Storage::disk('attachments')->delete(Attachment::firstOrFail()->file_path);
    $this->get($response->json('data.url'))->assertNotFound();
});

test('animated WebP and AVIF containers are rejected', function (string $format) {
    $gd = imagecreatetruecolor(10, 10);
    ob_start();
    $format === 'webp' ? imagewebp($gd) : imageavif($gd);
    $bytes = ob_get_clean();
    if ($format === 'webp') {
        $chunk = 'ANIM'.pack('V', 6).str_repeat("\0", 6);
        $bytes = substr($bytes, 0, 4).pack('V', strlen($bytes) - 8 + strlen($chunk)).substr($bytes, 8, 4).$chunk.substr($bytes, 12);
    } else {
        // Add the sequence brand to the compatible brands in ftyp.
        $size = unpack('N', substr($bytes, 0, 4))[1];
        $bytes = pack('N', $size + 4).substr($bytes, 4, $size - 4).'avis'.substr($bytes, $size);
    }
    expect(fn () => app(AvifProcessor::class)->process(UploadedFile::fake()->createWithContent('animated.'.$format, $bytes)))
        ->toThrow(ValidationException::class);
})->with(['webp', 'avif']);
