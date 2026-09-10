# Rate limit dan cache transaksi

Konfigurasi tersedia di `config/traffic.php` dan `.env.example`:

| Variabel | Default | Cakupan |
| --- | --- | --- |
| `RATE_LIMIT_LOGIN` | 5/menit | Email (case insensitive) + IP |
| `RATE_LIMIT_LOGIN_IP` | 20/menit | Semua login dari satu IP |
| `RATE_LIMIT_READS` | 120/menit | Seluruh GET/HEAD API per pengguna, atau IP untuk tamu |
| `RATE_LIMIT_WRITES` | 30/menit | Seluruh mutasi resource per pengguna |
| `TRANSACTION_CACHE_TTL` | 60 detik | Cache daftar income, expense, transfer; 0 menonaktifkan |

Request berlebih menerima HTTP 429 beserta `Retry-After`. Cache hit tetap memakai kuota. Login juga mempertahankan perlindungan percobaan autentikasi gagal bawaan. Kuota pengguna berlaku bersama lintas endpoint dalam kelompok read/write dan tidak dapat direset dengan mengganti IP. Aplikasi saat ini menggunakan autentikasi session Sanctum; fitur penerbitan token baru tidak ditambahkan.

Cache menyimpan JSON hasil GET daftar menggunakan cache Laravel sesuai `CACHE_STORE`. Kunci mencakup pengguna, role, nama route, dan query string. Tamu memiliki scope sendiri. Endpoint baca yang sudah publik tetap publik; pemisahan cache bukan pengganti otorisasi atau filter kepemilikan data.

Observer income, expense, transfer, wallet, category, dan attachment mengganti versi namespace cache setelah commit. Semua scope mendapat versi baru, karena transaksi dan wallet saat ini merupakan data bersama. Entri lama tidak lagi digunakan dan habis sesuai TTL; pembacaan lama yang sedang berjalan tidak dapat mengisi versi baru. Perubahan yang rollback tidak menginvalidasi cache. Mutasi saldo tetap memakai transaksi database dan row lock.

Bulk update/delete melalui query builder atau SQL langsung tidak memicu observer Eloquent. Jika menambahkan jalur tersebut, panggil `DB::afterCommit(fn () => TransactionCache::invalidate())` dengan `App\Support\TransactionCache`. Detail resource tidak dicache.

Gunakan `php artisan migrate` untuk menyediakan tabel cache bila memakai database store. Setelah perubahan konfigurasi pada deployment yang memakai config cache, jalankan `php artisan config:cache`. Untuk beberapa instance aplikasi, gunakan cache store bersama (database atau Redis) agar kuota dan invalidasi konsisten. Jika memakai reverse proxy, konfigurasi trusted proxy sesuai infrastrukturnya agar IP klien benar.

Rate limit dan cache membantu mengurangi beban, tetapi tidak menjamin server bebas overload. Daftar masih menggunakan seluruh hasil query; pagination dan pengukuran beban tetap diperlukan untuk volume besar.

Referensi: [Laravel rate limiting](https://laravel.com/docs/13.x/routing#rate-limiting), [cache](https://laravel.com/docs/13.x/cache), dan [observer setelah commit](https://laravel.com/docs/13.x/eloquent#observers-and-database-transactions).
