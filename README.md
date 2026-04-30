# Payment Module untuk Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/codewithdiki/payment-module.svg?style=flat-square)](https://packagist.org/packages/codewithdiki/payment-module)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/codewithdiki/payment-module/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/codewithdiki/payment-module/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/codewithdiki/payment-module/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/codewithdiki/payment-module/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/codewithdiki/payment-module.svg?style=flat-square)](https://packagist.org/packages/codewithdiki/payment-module)

Membangun sistem pembayaran dari nol di setiap proyek Laravel itu melelahkan. Kamu harus menulis integrasi Midtrans sendiri, mengelola status transaksi, mengurus webhook, sampai membuat panel admin — semuanya berulang. **Payment Module** hadir untuk menghilangkan pekerjaan itu.

Package ini adalah lapisan abstraksi di atas berbagai payment gateway. Kamu cukup panggil `PaymentModule::createPayment()`, dan semua proses di belakangnya — mulai dari charge ke gateway, menyimpan response, sampai men-dispatch event — ditangani secara otomatis. Arsitektur **event-driven** yang digunakan juga memastikan kamu tetap bisa menyesuaikan perilaku di setiap titik tanpa menyentuh kode inti package.

Saat ini mendukung **Midtrans** (GoPay, ShopeePay, QRIS) dan **Offline** secara bawaan, dengan panel admin berbasis **Filament** yang siap pakai.

---

## Daftar Isi

- [Persyaratan](#persyaratan)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Setup Payment Method](#setup-payment-method)
- [Membuat Pembayaran](#membuat-pembayaran)
- [Menangani Status Pembayaran](#menangani-status-pembayaran)
- [Webhook Midtrans](#webhook-midtrans)
- [Integrasi Filament](#integrasi-filament)
- [Menambah Vendor Baru dengan Enum Kustom](#menambah-vendor-baru-dengan-enum-kustom)
- [Kustomisasi Lanjutan](#kustomisasi-lanjutan)
- [Arsitektur & Alur Kerja](#arsitektur--alur-kerja)
- [Referensi API](#referensi-api)
- [Testing](#testing)
- [Changelog](#changelog)
- [Lisensi](#lisensi)

---

## Persyaratan

- PHP ^8.4
- Laravel ^11.0, ^12.0, atau ^13.0
- (Opsional) Filament ^3.0 untuk panel admin

---

## Instalasi

```bash
composer require codewithdiki/payment-module
```

Publish dan jalankan migrasi:

```bash
php artisan vendor:publish --tag="payment-module-migrations"
php artisan migrate
```

Tiga tabel akan dibuat: `payment_method_groups`, `payment_methods`, dan `payments`.

Publish file konfigurasi:

```bash
php artisan vendor:publish --tag="payment-module-config"
```

---

## Konfigurasi

Setelah publish, buka `config/payment-module.php`. Di sinilah kamu mengatur semua hal — dari class model yang digunakan, kredensial Midtrans, sampai listener event:

```php
use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;

return [
    // Model — ganti dengan class kustom jika diperlukan
    'payment_method_class'       => \CodeWithDiki\PaymentModule\Models\PaymentMethod::class,
    'payment_method_group_class' => \CodeWithDiki\PaymentModule\Models\PaymentMethodGroup::class,
    'payment_class'              => \CodeWithDiki\PaymentModule\Models\Payment::class,

    // Enum vendor — ganti jika kamu membuat enum kustom (lihat bagian Menambah Vendor Baru)
    'vendor_enum_class'          => \CodeWithDiki\PaymentModule\Enums\PaymentVendor::class,

    // Midtrans
    'midtrans_server_key'    => env('MIDTRANS_SERVER_KEY', ''),
    'midtrans_client_key'    => env('MIDTRANS_CLIENT_KEY', ''),
    'midtrans_is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'midtrans_is_sanitized'  => env('MIDTRANS_IS_SANITIZED', true),
    'midtrans_is_3ds'        => env('MIDTRANS_IS_3DS', false),

    // Webhook
    'webhook' => [
        'prefix'             => 'webhooks',
        'without_middleware' => [\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class],
    ],

    // Event listeners — mendukung multiple listener per event
    'listeners' => [
        PaymentCreated::class => [
            ProcessingPaymentGateway::class,
        ],
    ],
];
```

Tambahkan ke `.env`:

```env
MIDTRANS_SERVER_KEY=your-server-key
MIDTRANS_CLIENT_KEY=your-client-key
MIDTRANS_IS_PRODUCTION=false
```

---

## Setup Payment Method

Sebelum bisa memproses pembayaran, kamu perlu membuat data **Payment Method Group** dan **Payment Method** di database — biasanya dilakukan lewat seeder.

### Membuat Payment Method Group

Group berguna untuk mengelompokkan metode pembayaran, misalnya "Dompet Digital" atau "Transfer Bank".

```php
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Data\PaymentMethodGroupData;

PaymentModule::createPaymentMethodGroup(new PaymentMethodGroupData(
    name: 'Dompet Digital',
    slug: 'dompet-digital',
    is_active: true,
));
```

### Membuat Payment Method

```php
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Data\PaymentMethodData;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;

PaymentModule::createPaymentMethod(new PaymentMethodData(
    name: 'GoPay',
    vendor: PaymentVendor::Midtrans,
    channel: 'gopay',
    is_active: true,
));

PaymentModule::createPaymentMethod(new PaymentMethodData(
    name: 'QRIS',
    vendor: PaymentVendor::Midtrans,
    channel: 'qris',
    is_active: true,
));

PaymentModule::createPaymentMethod(new PaymentMethodData(
    name: 'Transfer Bank',
    vendor: PaymentVendor::Offline,
    channel: 'bank_transfer',
    is_active: true,
));
```

**Channel yang tersedia per vendor bawaan:**

| Vendor | Channel |
|--------|---------|
| `Midtrans` | `gopay`, `shopee_pay`, `qris` |
| `Offline` | `bank_transfer`, `cstore`, `offline`, `offline_qris` |

---

## Membuat Pembayaran

Package menggunakan **polymorphic relationship** sehingga pembayaran bisa dikaitkan ke model apapun — `Order`, `Invoice`, `Booking`, dan lain-lain — tanpa perubahan pada skema database.

```php
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;

$order = Order::find(1);

$payment = PaymentModule::createPayment(new PaymentData(
    paymentable: $order,
    payment_method_id: 1,
    payment_code: 'INV-2026-0001',
    amount: 150000,
    status: PaymentStatus::PENDING,
    customer_name: 'Budi Santoso',
    customer_email: 'budi@email.com',
    customer_phone: '081234567890',
    customer_address: 'Jl. Merdeka No. 1', // opsional
));
```

Begitu `createPayment()` dipanggil, transaksi disimpan ke database lalu event `PaymentCreated` langsung di-dispatch. Listener `ProcessingPaymentGateway` yang berjalan secara sinkron akan memilih processor yang tepat berdasarkan vendor dan memanggil `processPayment()`.

### Mengambil QR Code URL (khusus QRIS via Midtrans)

Setelah payment diproses, kamu bisa langsung ambil URL gambar QR Code untuk ditampilkan ke pengguna:

```php
$qrCodeUrl = $payment->getQrCodeUrl();
// Mengembalikan URL string jika Midtrans merespons dengan status_code 201, atau null
```

---

## Menangani Status Pembayaran

Untuk mengubah status pembayaran secara manual (misalnya dari webhook pihak ketiga atau konfirmasi admin):

```php
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;

PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID);    // dispatch PaymentPaid
PaymentModule::setPaymentStatus($payment, PaymentStatus::FAILED);  // dispatch PaymentFailed
PaymentModule::setPaymentStatus($payment, PaymentStatus::PENDING); // tidak dispatch event
```

### Mendengarkan Event

Daftarkan listener di `AppServiceProvider` atau `EventServiceProvider` untuk bereaksi atas perubahan status:

```php
use CodeWithDiki\PaymentModule\Events\PaymentPaid;
use CodeWithDiki\PaymentModule\Events\PaymentFailed;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;

protected $listen = [
    PaymentPaid::class => [
        \App\Listeners\SendPaymentConfirmationEmail::class,
        \App\Listeners\UpdateOrderStatus::class,
    ],
    PaymentFailed::class => [
        \App\Listeners\NotifyPaymentFailed::class,
    ],
    PaymentGatewayProcessed::class => [
        \App\Listeners\LogGatewayResponse::class,
    ],
];
```

### Query Scope

Model `Payment` sudah dilengkapi scope bawaan untuk memfilter berdasarkan status:

```php
use CodeWithDiki\PaymentModule\Models\Payment;

Payment::isPaid()->get();
Payment::isPending()->get();
Payment::isFailed()->get();
```

---

## Webhook Midtrans

Route webhook didaftarkan **secara otomatis** oleh package saat service provider di-load — tidak perlu memanggil apapun di `AppServiceProvider`.

Dengan konfigurasi default, endpoint yang tersedia adalah:

```
POST https://domain-kamu.com/webhooks/midtrans
```

Masukkan URL tersebut di dashboard Midtrans. Webhook handler bawaan akan secara otomatis:
1. Memvalidasi signature SHA-512 dari payload
2. Memetakan status Midtrans ke `PaymentStatus` (`settlement`/`capture` → `PAID`, `deny`/`expire`/`cancel` → `FAILED`)
3. Memanggil `setPaymentStatus()` yang men-dispatch event terkait

### Kustomisasi Webhook

Ubah prefix atau hapus middleware CSRF via config:

```php
'webhook' => [
    'prefix'             => 'api/payment-webhooks',
    'without_middleware' => [],
],
```

---

## Integrasi Filament

Jika aplikasimu menggunakan Filament, tambahkan plugin ke konfigurasi panel:

```php
use CodeWithDiki\PaymentModule\PaymentModuleFilament;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            PaymentModuleFilament::make(),
        ]);
}
```

Tiga resource langsung tersedia di panel admin:
- **Payments** — Daftar & detail transaksi (read-only)
- **Payment Methods** — CRUD metode pembayaran
- **Payment Method Groups** — CRUD grup metode pembayaran

---

## Menambah Vendor Baru dengan Enum Kustom

Ini adalah fitur paling fleksibel dari package ini. Kamu bisa menambahkan integrasi ke payment gateway manapun — Xendit, Doku, Flip, dll. — tanpa mengubah satu baris pun dari kode inti package. Caranya adalah dengan membuat **enum PHP kustom** dan **model `PaymentMethod` kustom**, lalu mengarahkan config ke keduanya.

Kunci dari mekanisme ini ada di sini: model `PaymentMethod` bawaan membaca `vendor_enum_class` dari config untuk menentukan enum mana yang dipakai sebagai cast kolom `vendor`. Ini berarti kamu bisa mengganti enum-nya tanpa menyentuh package sama sekali.

```php
// src/Models/PaymentMethod.php (kode package — sudah ditangani secara internal)
protected function casts(): array
{
    return [
        'vendor' => config('payment-module.vendor_enum_class', PaymentVendor::class),
    ];
}
```

Ikuti langkah berikut untuk mengintegrasikan vendor baru secara penuh.

### Langkah 1 — Buat class Processor

Processor adalah tempat logika integrasi dengan gateway hidup. Implementasikan interface `PaymentProcessor`:

```php
// app/Payments/XenditProcessor.php

namespace App\Payments;

use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Contracts\PaymentProcessor;
use Illuminate\Support\Collection;

class XenditProcessor implements PaymentProcessor
{
    public function processPayment(Payment $payment): void
    {
        // Panggil Xendit API di sini
        // Simpan response ke payment_response
        // Dispatch PaymentGatewayProcessed setelah selesai

        $response = \Http::post('https://api.xendit.co/...', [
            'external_id'  => $payment->payment_code,
            'amount'       => $payment->amount,
        ]);

        $payment->update(['payment_response' => $response->json()]);

        \CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed::dispatch($payment);
    }

    public function getChannels(): Collection
    {
        return collect([
            'va_bca' => 'Virtual Account BCA',
            'va_bni' => 'Virtual Account BNI',
            'va_bri' => 'Virtual Account BRI',
        ]);
    }
}
```

### Langkah 2 — Buat Enum Vendor Kustom

Enum ini harus mengimplementasikan method `getPaymentProcessorClass()` yang mengembalikan class processor. Kamu wajib menyertakan kembali semua vendor bawaan yang masih ingin kamu gunakan:

```php
// app/Enums/PaymentVendor.php

namespace App\Enums;

enum PaymentVendor: string
{
    // Vendor bawaan — pertahankan jika masih dipakai
    case Offline  = 'Offline';
    case Midtrans = 'Midtrans';

    // Vendor baru
    case Xendit = 'Xendit';

    public function getPaymentProcessorClass(): string
    {
        return match ($this) {
            self::Offline  => \CodeWithDiki\PaymentModule\Supports\PaymentMethod\Offline::class,
            self::Midtrans => \CodeWithDiki\PaymentModule\Supports\PaymentMethod\Midtrans::class,
            self::Xendit   => \App\Payments\XenditProcessor::class,
        };
    }
}
```

> **Penting:** Nilai string tiap case (misalnya `'Xendit'`) harus cocok dengan nilai yang tersimpan di kolom `vendor` di tabel `payment_methods`. Jangan ubah nilai string untuk case yang sudah ada di database.

### Langkah 3 — Override Model PaymentMethod

Buat model `PaymentMethod` kustom yang meng-extend model bawaan. Tidak perlu mengubah apapun di dalamnya — cukup buat file-nya:

```php
// app/Models/PaymentMethod.php

namespace App\Models;

class PaymentMethod extends \CodeWithDiki\PaymentModule\Models\PaymentMethod
{
    // Model kamu sekarang otomatis menggunakan enum dari config.
    // Tambahkan relasi atau accessor tambahan di sini jika perlu.
}
```

### Langkah 4 — Daftarkan di Config

Hubungkan semua potongan di atas lewat `config/payment-module.php`:

```php
return [
    // Arahkan ke model kustom
    'payment_method_class' => \App\Models\PaymentMethod::class,

    // Arahkan ke enum kustom — ini yang mengubah casting vendor secara dinamis
    'vendor_enum_class'    => \App\Enums\PaymentVendor::class,

    // ... konfigurasi lainnya
];
```

### Langkah 5 — Daftarkan Payment Method Baru

Dengan enum kustom sudah terpasang, kamu bisa membuat payment method Xendit seperti biasa:

```php
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Data\PaymentMethodData;
use App\Enums\PaymentVendor;

PaymentModule::createPaymentMethod(new PaymentMethodData(
    name: 'Virtual Account BCA',
    vendor: PaymentVendor::Xendit,
    channel: 'va_bca',
    is_active: true,
));
```

Mulai sekarang, setiap pembayaran yang menggunakan payment method ini akan otomatis diproses oleh `XenditProcessor` tanpa perubahan apapun pada alur kerja utama.

---

## Kustomisasi Lanjutan

### Override Model Payment atau PaymentMethodGroup

Semua model bisa diganti lewat config. Model kustom cukup meng-extend model bawaan:

```php
// app/Models/Payment.php

namespace App\Models;

class Payment extends \CodeWithDiki\PaymentModule\Models\Payment
{
    // Tambahkan relasi, scope, atau accessor tambahan di sini
}
```

```php
// config/payment-module.php
'payment_class' => \App\Models\Payment::class,
```

Semua relasi antar model (`Payment`, `PaymentMethod`, `PaymentMethodGroup`) secara otomatis mengikuti class yang terdaftar di config.

### Menambah atau Mengganti Listener

Key `listeners` di config menerima peta `EventClass => [ListenerClass, ...]`. Kamu bisa menambah listener baru tanpa menghapus yang bawaan, atau menggantinya sepenuhnya:

```php
'listeners' => [
    // Tambah listener tambahan ke event yang sudah ada
    PaymentCreated::class => [
        ProcessingPaymentGateway::class,
        \App\Listeners\LogPaymentCreated::class,
    ],

    // Dengarkan event dari package di listener milikmu
    \CodeWithDiki\PaymentModule\Events\PaymentPaid::class => [
        \App\Listeners\UpdateOrderStatus::class,
        \App\Listeners\SendInvoiceEmail::class,
    ],

    \CodeWithDiki\PaymentModule\Events\PaymentFailed::class => [
        \App\Listeners\NotifyAdminOnFailure::class,
    ],
],
```

---

## Arsitektur & Alur Kerja

Memahami alur kerja internal akan membantumu men-debug dan meng-extend package dengan lebih mudah.

```
PaymentModule::createPayment(PaymentData)
        │
        ▼
Payment disimpan ke database (menggunakan config payment_class)
        │
        ▼
Event PaymentCreated di-dispatch
        │
        ▼ (synchronous)
ProcessingPaymentGateway::handle()
        │
        ▼
$payment->paymentMethod->vendor->getPaymentProcessorClass()
        │   ↑
        │   cast oleh enum dari config vendor_enum_class
        │
        ├── PaymentVendor::Midtrans → Midtrans::processPayment()
        │       ├── CoreApi::charge() ke Midtrans
        │       ├── Simpan response ke payment record
        │       └── Dispatch PaymentGatewayProcessed
        │
        ├── PaymentVendor::Offline → Offline::processPayment()
        │       └── setPaymentStatus(PAID) → Dispatch PaymentPaid
        │
        └── VendorKustom::Xendit → XenditProcessor::processPayment()
                └── (logika kustom kamu)
```

**Binding penting:**

| Abstract | Concrete |
|----------|----------|
| `\CodeWithDiki\PaymentModule\PaymentModule` | Bound langsung (bukan via interface) |
| `Facade PaymentModule` | Resolves `PaymentModule::class` dari container |

---

## Referensi API

### `PaymentModule` Facade

| Method | Return | Deskripsi |
|--------|--------|-----------|
| `createPayment(PaymentData $data)` | `Payment` | Buat pembayaran & dispatch `PaymentCreated` |
| `setPaymentStatus(Payment $payment, PaymentStatus $status)` | `Payment` | Update status & dispatch event terkait |
| `createPaymentMethod(PaymentMethodData $data)` | `PaymentMethod` | Buat metode pembayaran baru |
| `createPaymentMethodGroup(PaymentMethodGroupData $data)` | `PaymentMethodGroup` | Buat grup metode pembayaran baru |
| `getPaymentByCode(string $code)` | `?Payment` | Cari pembayaran berdasarkan payment code |
| `getPaymentMethodById(int $id)` | `?PaymentMethod` | Cari payment method berdasarkan ID |
| `getActivePaymentMethods()` | `Collection` | Ambil semua payment method yang aktif |
| `getPaymentMethodsByGroupId(int $group_id)` | `Collection` | Ambil payment method aktif dalam grup tertentu |
| `getPaymentFromPaymentable(string $type, int $id)` | `?Payment` | Cari pembayaran berdasarkan model yang terkait |

### `PaymentData` — Parameter Pembayaran

| Property | Type | Wajib | Deskripsi |
|----------|------|-------|-----------|
| `paymentable` | `Model` | Ya | Model yang dikaitkan ke pembayaran |
| `payment_method_id` | `int` | Ya | ID dari PaymentMethod yang digunakan |
| `payment_code` | `string` | Ya | Kode unik transaksi (digunakan sebagai order_id) |
| `amount` | `int` | Ya | Nominal pembayaran dalam satuan terkecil (misal: Rupiah) |
| `status` | `PaymentStatus` | Ya | Status awal pembayaran (biasanya `PENDING`) |
| `customer_name` | `?string` | — | Nama pelanggan |
| `customer_email` | `?string` | — | Email pelanggan |
| `customer_phone` | `?string` | — | Nomor telepon pelanggan |
| `customer_address` | `?string` | — | Alamat pelanggan |
| `customer_custom_data` | `?array` | — | Data tambahan pelanggan (disimpan sebagai JSON) |

---

## Testing

```bash
composer test
```

---

## Changelog

Lihat [CHANGELOG](CHANGELOG.md) untuk informasi perubahan terbaru.

## Kontribusi

Lihat [CONTRIBUTING](CONTRIBUTING.md) untuk panduan berkontribusi.

## Keamanan

Untuk melaporkan celah keamanan, silakan baca [kebijakan keamanan](../../security/policy) kami.

## Credits

- [Diki Akbar Asyidiq](https://github.com/codewithdiki)
- [All Contributors](../../contributors)

## Lisensi

The MIT License (MIT). Lihat [License File](LICENSE.md) untuk informasi lebih lanjut.
