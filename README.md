# Payment Module untuk Laravel

Membangun sistem pembayaran dari nol di setiap proyek Laravel itu melelahkan. Kamu harus menulis integrasi Midtrans sendiri, mengelola status transaksi, mengurus webhook, sampai membuat panel admin — semuanya berulang. **Payment Module** hadir untuk menghilangkan pekerjaan itu.

Package ini adalah lapisan abstraksi di atas berbagai payment gateway. Kamu cukup panggil `PaymentModule::createPayment()`, dan semua proses di belakangnya — mulai dari charge ke gateway, menyimpan response, sampai men-dispatch event — ditangani secara otomatis. Arsitektur **event-driven** yang digunakan juga memastikan kamu tetap bisa menyesuaikan perilaku di setiap titik tanpa menyentuh kode inti package.

Saat ini mendukung **Midtrans** (GoPay, ShopeePay, QRIS), **Stripe** (Checkout Session), **Xendit** (Virtual Account, e-wallet, QRIS), dan **Offline** secara bawaan, dengan panel admin berbasis **Filament** yang siap pakai. Selain menerima pembayaran, package ini juga mendukung **disbursement** (kirim dana ke rekening pihak ketiga) via **Midtrans Payouts (Iris)** dan **Xendit Disbursement**.

Setiap payment method bisa dikenakan **fee otomatis** (flat + persentase) yang ditambahkan ke tagihan pelanggan.

> **Versi:** project ini mengikuti [Semantic Versioning](https://semver.org/lang/id/) dan ditandai lewat git tag `vX.Y.Z`. Dukungan Xendit & sistem fee diperkenalkan di **v1.3.0** — lihat [CHANGELOG](CHANGELOG.md) dan [Upgrade dari 1.2.x ke 1.3.0](#upgrade-dari-12x-ke-130).

---

## Daftar Isi

- [Persyaratan](#persyaratan)
- [Instalasi](#instalasi)
- [Upgrade dari 1.2.x ke 1.3.0](#upgrade-dari-12x-ke-130)
- [Konfigurasi](#konfigurasi)
- [Setup Payment Method](#setup-payment-method)
- [Fee Payment Method](#fee-payment-method)
- [Membuat Pembayaran](#membuat-pembayaran)
- [Menangani Status Pembayaran](#menangani-status-pembayaran)
- [Webhook Midtrans](#webhook-midtrans)
- [Webhook Stripe](#webhook-stripe)
- [Webhook Xendit](#webhook-xendit)
- [Disbursement (Payout)](#disbursement-payout)
- [Keamanan Webhook & Disbursement](#keamanan-webhook--disbursement)
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
Sebelum menginstal Payment Module, kamu harus membuat akun terlebih dahulu di : [sini](https://dikiakbarasyidiq.dev/auth/register). Setelah membuat akun buka halaman (Dashboard → Account) untuk melihat license key kamu.

Copy license key kamu lalu jalankan command ini :

```bash
composer config bearer.dikiakbarasyidiq.dev <license_key>
```

Setelah menjalankan command diatas, tambahkan repository berikut di file composer.json. (Jika Belum Ada)
```
{
"repositories": [
        {
            "type" : "composer",
            "url" : "https://dikiakbarasyidiq.dev"
        }
    ]
}
```

Setelah menambahkan repository, update composer terlebih dahulu:

```bash
composer update
```

Lalu kamu akan bisa melakukan installasi via composer di project kamu dengan command :

```bash
composer require codewithdiki/payment-module
```

Publish dan jalankan migrasi:

```bash
php artisan vendor:publish --tag="payment-module-migrations"
php artisan vendor:publish --provider="Spatie\WebhookClient\WebhookClientServiceProvider" --tag="webhook-client-migrations"
php artisan migrate
```

Empat tabel akan dibuat: `payment_method_groups`, `payment_methods`, `payments`, `disbursements`, plus tabel `webhook_calls` dari [spatie/laravel-webhook-client](https://github.com/spatie/laravel-webhook-client) yang dipakai untuk menyimpan setiap webhook yang masuk.

Publish file konfigurasi:

```bash
php artisan vendor:publish --tag="payment-module-config"
```

---

## Upgrade dari 1.2.x ke 1.3.0

Versi **1.3.0** menambah beberapa kolom baru ke skema database:

| Tabel | Kolom baru | Keterangan |
|-------|------------|------------|
| `payment_methods` | `fee_flat`, `fee_percentage` | Konfigurasi fee per metode pembayaran (default `0`) |
| `payments` | `fee`, `total_amount` | Fee yang dihitung & total yang ditagih ke pelanggan |
| `disbursements` | `created_by`, `approved_by` | Audit maker-approver (default `null`) |

**Instalasi baru:** tidak ada langkah tambahan — migrasi bawaan sudah menyertakan kolom di atas.

**Instalasi lama (sudah pernah migrate di 1.2.x):** kolom tidak ditambahkan otomatis. Buat satu migration untuk meng-`ALTER` tabel yang sudah ada:

```bash
php artisan make:migration upgrade_payment_module_to_1_3
```

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->double('fee_flat')->default(0)->after('image_url');
            $table->double('fee_percentage')->default(0)->after('fee_flat');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->double('fee')->default(0)->after('amount');
            $table->double('total_amount')->default(0)->after('fee');
        });

        Schema::table('disbursements', function (Blueprint $table) {
            $table->string('created_by')->nullable()->after('error_message');
            $table->string('approved_by')->nullable()->after('created_by');
        });

        // Isi total_amount untuk record lama agar verifikasi nominal webhook tetap akurat
        \DB::table('payments')->whereNull('total_amount')->orWhere('total_amount', 0)
            ->update(['total_amount' => \DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', fn (Blueprint $t) => $t->dropColumn(['fee_flat', 'fee_percentage']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['fee', 'total_amount']));
        Schema::table('disbursements', fn (Blueprint $t) => $t->dropColumn(['created_by', 'approved_by']));
    }
};
```

```bash
php artisan migrate
```

> `Payment::billableAmount()` jatuh kembali ke `amount` bila `total_amount` masih `0`, jadi pembayaran lama tetap berfungsi meski belum di-backfill — tapi backfill di atas tetap disarankan agar verifikasi nominal webhook konsisten.

Terakhir, tambahkan kredensial Xendit ke `.env` bila ingin memakainya (lihat [Konfigurasi](#konfigurasi)).

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

    // Stripe
    'stripe_secret_key'      => env('STRIPE_SECRET_KEY', ''),
    'stripe_publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
    'stripe_webhook_secret'  => env('STRIPE_WEBHOOK_SECRET', ''),
    'stripe_currency'        => env('STRIPE_CURRENCY', 'usd'),
    // {payment_code} akan diganti dengan payment code transaksi
    'stripe_success_url'     => env('STRIPE_SUCCESS_URL', ''),
    'stripe_cancel_url'      => env('STRIPE_CANCEL_URL', ''),

    // Xendit
    'xendit_secret_key'           => env('XENDIT_SECRET_KEY', ''),
    // Verification token callback dari dashboard Xendit (header x-callback-token)
    'xendit_webhook_token'        => env('XENDIT_WEBHOOK_TOKEN', ''),
    'xendit_is_production'        => env('XENDIT_IS_PRODUCTION', false),
    // {payment_code} akan diganti dengan payment code transaksi (untuk e-wallet)
    'xendit_success_redirect_url' => env('XENDIT_SUCCESS_REDIRECT_URL', ''),
    'xendit_failure_redirect_url' => env('XENDIT_FAILURE_REDIRECT_URL', ''),

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

STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_PUBLISHABLE_KEY=pk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_CURRENCY=usd
STRIPE_SUCCESS_URL="https://domain-kamu.com/payments/{payment_code}/success"
STRIPE_CANCEL_URL="https://domain-kamu.com/payments/{payment_code}/cancel"

XENDIT_SECRET_KEY=xnd_development_xxx
XENDIT_WEBHOOK_TOKEN=your-callback-verification-token
XENDIT_IS_PRODUCTION=false
XENDIT_SUCCESS_REDIRECT_URL="https://domain-kamu.com/payments/{payment_code}/success"
XENDIT_FAILURE_REDIRECT_URL="https://domain-kamu.com/payments/{payment_code}/failure"
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
    image_url: 'https://cdn.example.com/dompet-digital.png', // opsional
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
    image_url: 'https://cdn.example.com/gopay.png', // opsional
    description: 'Bayar dengan GoPay',              // opsional
    meta_data: null,                                 // opsional, disimpan sebagai JSON
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
| `Midtrans` | `gopay`, `shopee_pay`, `qris`, `permata`, `bca`, `bni`, `bri`, `bsi`, `mandiri` |
| `Stripe` | `card`, `link`, `alipay`, `wechat_pay` |
| `Xendit` | `BCA`, `BNI`, `BRI`, `MANDIRI`, `PERMATA`, `BSI` (Virtual Account); `ID_OVO`, `ID_DANA`, `ID_LINKAJA`, `ID_SHOPEEPAY` (e-wallet); `QRIS` |
| `Offline` | `bank_transfer`, `cstore`, `offline`, `offline_qris` |

> Channel `permata`, `bca`, `bni`, `bri`, `bsi`, dan `mandiri` diproses sebagai **bank transfer** via Midtrans.
>
> Untuk **Xendit**, channel `QRIS` membuat QR dinamis, channel `ID_*` membuat e-wallet charge, dan sisanya (kode bank) membuat **closed Virtual Account**.

---

## Fee Payment Method

Setiap payment method bisa dikenakan **fee** yang otomatis ditambahkan ke tagihan pelanggan. Fee terdiri dari dua komponen yang bisa dikombinasikan:

- `fee_flat` — nominal tetap (mis. `4400` untuk biaya VA)
- `fee_percentage` — persentase dari `amount` (mis. `0.7` untuk QRIS, `2.9` untuk kartu)

```php
PaymentModule::createPaymentMethod(new PaymentMethodData(
    name: 'Virtual Account BCA',
    vendor: PaymentVendor::Xendit,
    channel: 'BCA',
    is_active: true,
    fee_flat: 4400,       // Rp4.400 biaya tetap
    fee_percentage: 0,    // tanpa komponen persentase
));
```

Saat pembayaran dibuat, fee dihitung otomatis dan disimpan ke kolom `fee`, lalu `total_amount = amount + fee` adalah jumlah yang **benar-benar ditagih** ke pelanggan oleh gateway:

```
fee          = fee_flat + (amount × fee_percentage / 100)
total_amount = amount + fee
```

Contoh: `amount` `100000` dengan `fee_flat: 4400` dan `fee_percentage: 0` → `fee` `4400`, `total_amount` `104400`. Nilai inilah yang dikirim ke Midtrans/Stripe/Xendit dan yang diverifikasi saat webhook masuk.

```php
$payment->amount;            // 100000 (nominal pokok)
$payment->fee;               // 4400
$payment->total_amount;      // 104400
$payment->billableAmount();  // 104400 (fallback ke amount bila total_amount belum terisi)

// Hitung fee tanpa membuat payment:
$paymentMethod->calculateFee(100000); // 4400.0
```

> Fee dihitung di sisi server dari konfigurasi payment method — bukan input dari pemanggil — sehingga tidak bisa dimanipulasi lewat request.

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

Begitu `createPayment()` dipanggil, transaksi disimpan ke database lalu event `PaymentCreated` langsung di-dispatch. Listener `ProcessingPaymentGateway` yang berjalan **secara asinkron via queue** (mengimplementasikan `ShouldQueue`) akan memilih processor yang tepat berdasarkan vendor dan memanggil `processPayment()`.

### Mengambil QR Code URL (khusus QRIS via Midtrans)

Setelah payment diproses, kamu bisa langsung ambil URL gambar QR Code untuk ditampilkan ke pengguna:

```php
$qrCodeUrl = $payment->getQrCodeUrl();
// Mengembalikan URL string jika Midtrans merespons dengan status_code 201, atau null
```

### Mengambil Nomor Virtual Account (khusus Bank Transfer via Midtrans)

Untuk metode bank transfer, kamu bisa mengambil nomor virtual account yang di-generate Midtrans:

```php
$vaNumber = $payment->getMidtransVirtualAccountNumber();
// Mengembalikan nomor VA string jika Midtrans merespons dengan status_code 201, atau null
// Channel yang digunakan (bca, bni, bri, dll.) dideteksi otomatis dari payment method
```

### Mengambil URL Checkout (khusus Stripe)

Untuk pembayaran via Stripe, processor membuat **Checkout Session** (halaman pembayaran hosted Stripe). Setelah payment diproses, redirect pelanggan ke URL checkout:

```php
$checkoutUrl = $payment->getStripeCheckoutUrl();
// Mengembalikan URL hosted checkout Stripe, atau null jika vendor bukan Stripe

return redirect()->away($checkoutUrl);
```

> Nominal yang ditagih adalah `total_amount` (amount + fee), otomatis dikonversi ke satuan terkecil currency (dikali 100, kecuali currency zero-decimal seperti `jpy`, `krw`, `vnd`).

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

Semua webhook masuk ditangani oleh **[spatie/laravel-webhook-client](https://github.com/spatie/laravel-webhook-client)**:

1. **Signature diverifikasi** lebih dulu (signature tidak valid → respons `500`, payload tidak disimpan).
2. Webhook valid **disimpan ke tabel `webhook_calls`** sebagai jejak audit, dan gateway langsung menerima `200 {"message":"ok"}`.
3. Pemrosesan sebenarnya (update status + dispatch event) berjalan **di queue** lewat job khusus per vendor — pastikan **queue worker berjalan** (`php artisan queue:work`), atau gunakan `QUEUE_CONNECTION=sync` agar diproses langsung.

Route webhook didaftarkan **secara otomatis** oleh package saat service provider di-load — tidak perlu memanggil apapun di `AppServiceProvider`.

Dengan konfigurasi default, endpoint yang tersedia adalah:

```
POST https://domain-kamu.com/webhooks/midtrans
```

Masukkan URL tersebut di dashboard Midtrans. Job `ProcessMidtransWebhookJob` akan secara otomatis:
1. Memetakan status Midtrans ke `PaymentStatus` (`settlement`/`capture` → `PAID`, `deny`/`expire`/`cancel` → `FAILED`; status lain diabaikan)
2. Memanggil `setPaymentStatus()` yang men-dispatch event terkait

> Validasi signature SHA-512 (`sha512(order_id + status_code + gross_amount + server_key)`) dilakukan oleh `MidtransSignatureValidator` sebelum payload disimpan.

---

## Webhook Stripe

Sama seperti Midtrans, route webhook Stripe didaftarkan otomatis. Dengan konfigurasi default, endpoint-nya adalah:

```
POST https://domain-kamu.com/webhooks/stripe
```

Daftarkan URL tersebut di [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks), lalu salin **signing secret**-nya ke env `STRIPE_WEBHOOK_SECRET`. Event yang perlu diaktifkan:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Alur penanganannya sama dengan webhook Midtrans (simpan ke `webhook_calls` → proses di queue). `StripeSignatureValidator` memverifikasi header `Stripe-Signature`, lalu job `ProcessStripeWebhookJob` akan:
1. Memetakan event ke `PaymentStatus` (`completed`/`async_payment_succeeded` → `PAID`, `expired`/`async_payment_failed` → `FAILED`)
2. Mencari transaksi via `client_reference_id` (berisi `payment_code`)
3. Memanggil `setPaymentStatus()` yang men-dispatch event terkait

Event Stripe lain yang tidak relevan tetap dibalas `200` (tersimpan di `webhook_calls`, tapi diabaikan oleh job) agar tidak di-retry oleh Stripe.

Untuk pengujian lokal, gunakan Stripe CLI:

```bash
stripe listen --forward-to localhost:8000/webhooks/stripe
```

### Kustomisasi Webhook

Ubah prefix atau hapus middleware CSRF via config:

```php
'webhook' => [
    'prefix'             => 'api/payment-webhooks',
    'without_middleware' => [],
],
```

Package mendaftarkan lima profil webhook-client secara otomatis: `payment-module-midtrans`, `payment-module-midtrans-payout`, `payment-module-stripe`, `payment-module-xendit`, dan `payment-module-xendit-disbursement`. Profil milik aplikasimu sendiri di `config/webhook-client.php` tetap dipertahankan (profil tanpa `process_webhook_job` diabaikan karena tidak valid). Webhook lama otomatis dibersihkan oleh webhook-client setelah 30 hari (atur via config `webhook-client.delete_after_days` + jadwalkan `php artisan model:prune`).

---

## Webhook Xendit

Route webhook Xendit juga didaftarkan otomatis. Dengan konfigurasi default, endpoint pembayaran-nya:

```
POST https://domain-kamu.com/webhooks/xendit
```

Daftarkan URL tersebut di [Xendit Dashboard → Settings → Webhooks](https://dashboard.xendit.co/settings/developers#webhooks) untuk channel yang dipakai (Virtual Account, e-wallet, QR). Salin **Webhook Verification Token** dari dashboard ke env `XENDIT_WEBHOOK_TOKEN`.

Xendit mengautentikasi callback lewat header **`x-callback-token`** (token statis, bukan HMAC). `XenditSignatureValidator` mencocokkan header ini dengan `XENDIT_WEBHOOK_TOKEN` secara timing-safe; bila token belum diisi, semua callback ditolak. Lalu `ProcessXenditWebhookJob`:

1. Menormalkan payload lintas produk (Virtual Account, e-wallet, QR) — `reference_id`/`external_id` berisi `payment_code`.
2. Memetakan status ke `PaymentStatus` (`PAID`/`SUCCEEDED`/`COMPLETED` → `PAID`, `FAILED`/`EXPIRED`/`VOIDED` → `FAILED`; callback VA tertutup yang membawa `payment_id` dianggap `PAID`).
3. **Memverifikasi nominal** callback terhadap `total_amount` sebelum menandai `PAID`.
4. Memanggil `setPaymentStatus()`.

---

## Disbursement (Payout)

Selain menerima pembayaran, package ini bisa **mengirim dana keluar** ke rekening bank atau e-wallet pihak ketiga (misalnya untuk withdrawal seller, refund manual, atau pembayaran mitra) melalui **Midtrans Payouts API (Iris)** atau **Xendit Disbursement API**.

> **Vendor yang didukung untuk disbursement:** `Midtrans` (maker-approver) dan `Xendit` (single-step / auto-process). Stripe memiliki produk serupa (Global Payouts) tetapi saat ini hanya untuk akun pengirim di **US/GB** sehingga belum diimplementasikan. Vendor yang tidak mendukung disbursement akan melempar `DisbursementNotSupportedException`.

### Konfigurasi

Payouts/Iris memakai kredensial **terpisah** dari payment gateway Midtrans biasa. Ambil dari dashboard Midtrans (menu Payouts/Iris), lalu tambahkan ke `.env`:

```env
MIDTRANS_IRIS_CREATOR_KEY=your-creator-api-key
MIDTRANS_IRIS_APPROVER_KEY=your-approver-api-key
MIDTRANS_IRIS_MERCHANT_KEY=your-iris-merchant-key
```

- **Creator key** — dipakai untuk membuat payout.
- **Approver key** — dipakai untuk approve/reject payout.
- **Merchant key** — dipakai untuk memverifikasi signature webhook (`Iris-Signature`).

Environment (sandbox/production) mengikuti `MIDTRANS_IS_PRODUCTION` yang sudah ada.

### Membuat Disbursement

```php
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Data\DisbursementData;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;

$disbursement = PaymentModule::createDisbursement(new DisbursementData(
    vendor: PaymentVendor::Midtrans,
    disbursement_code: 'DISB-2026-0001', // unik, dipakai juga sebagai idempotency key
    amount: 150000,
    beneficiary_name: 'Budi Santoso',
    beneficiary_account: '1234567890',   // no. rekening, atau no. HP (08xx) untuk e-wallet
    beneficiary_bank: 'bca',             // kode bank (bca, bni, mandiri, ...) atau e-wallet (gopay, ovo)
    beneficiary_email: 'budi@email.com', // opsional
    notes: 'Withdrawal saldo seller',    // opsional, maks 100 karakter
    disbursable: $withdrawal,            // opsional, model apapun (polymorphic)
));
```

Sama seperti pembayaran, alurnya **event-driven**: `createDisbursement()` menyimpan record berstatus `PENDING` dan men-dispatch `DisbursementCreated`. Listener `ProcessingDisbursementGateway` (queued) lalu mengirim payout ke Midtrans — jika berhasil, `reference_no` tersimpan dan status menjadi `QUEUED`.

### Alur Maker–Approver

Midtrans Payouts memakai pemisahan wewenang: payout yang dibuat (status `queued`) harus **di-approve** dulu sebelum diproses. Approve/reject bisa dilakukan via dashboard Midtrans, atau langsung dari aplikasi:

```php
PaymentModule::approveDisbursement($disbursement);            // POST /payouts/approve
PaymentModule::rejectDisbursement($disbursement, 'alasan');   // POST /payouts/reject
```

> Jika fitur approval dimatikan di dashboard Midtrans, payout langsung diproses tanpa perlu approve.

### Siklus Status

| Status | Arti |
|--------|------|
| `pending` | Dibuat lokal, belum terkirim ke gateway |
| `queued` | Diterima Midtrans, menunggu approval |
| `approved` / `rejected` | Hasil keputusan approver |
| `processed` | Sedang diproses bank |
| `completed` | Dana terkirim (`completed_at` terisi, dispatch `DisbursementCompleted`) |
| `failed` | Gagal (`error_code`/`error_message` terisi, dispatch `DisbursementFailed`) |

### Webhook Payout

Daftarkan URL berikut di dashboard Midtrans (Payouts → Notification URL):

```
POST https://domain-kamu.com/webhooks/midtrans/payout
```

Alur penanganannya sama dengan webhook lain (simpan ke `webhook_calls` → proses di queue). `MidtransPayoutSignatureValidator` memverifikasi header `Iris-Signature` (`SHA512(rawBody + merchantKey)`), lalu job `ProcessMidtransPayoutWebhookJob` mencari disbursement via `reference_no` dan memanggil `setDisbursementStatus()` yang men-dispatch event terkait. Status yang tidak dikenal tetap dibalas `200` dan diabaikan.

### Disbursement via Xendit

Xendit memproses payout **langsung (single-step)** — tidak ada tahap maker-approver. Cukup panggil `createDisbursement()` dengan `vendor: PaymentVendor::Xendit`; payout langsung dikirim, `reference_no` (id Xendit) tersimpan, dan status menjadi `processed`.

```php
$disbursement = PaymentModule::createDisbursement(new DisbursementData(
    vendor: PaymentVendor::Xendit,
    disbursement_code: 'DISB-2026-0001', // unik, dipakai sebagai idempotency key
    amount: 150000,
    beneficiary_name: 'Budi Santoso',
    beneficiary_account: '1234567890',
    beneficiary_bank: 'BCA',             // kode bank Xendit (BCA, BNI, MANDIRI, ...)
    beneficiary_email: 'budi@email.com', // opsional
    notes: 'Withdrawal saldo seller',    // opsional
));
```

- Memakai kredensial yang sama dengan payment: `XENDIT_SECRET_KEY`.
- `approveDisbursement()` / `rejectDisbursement()` **tidak berlaku** untuk Xendit (melempar `BadMethodCallException`) karena payout sudah diproses otomatis.
- Daftarkan webhook callback disbursement di dashboard Xendit ke endpoint:

```
POST https://domain-kamu.com/webhooks/xendit/disbursement
```

  Diverifikasi dengan header `x-callback-token` (`XENDIT_WEBHOOK_TOKEN`). `ProcessXenditDisbursementWebhookJob` memetakan status `COMPLETED` → `completed` dan `FAILED` → `failed`, mencari disbursement via `id` (reference_no) atau `external_id` (disbursement_code).

### Helper & Event

```php
PaymentModule::getDisbursementByCode('DISB-2026-0001');
PaymentModule::getDisbursementByReferenceNo('REF-123');

Disbursement::isPending()->get();
Disbursement::isCompleted()->get();
Disbursement::isFailed()->get();
```

Event yang tersedia untuk di-listen: `DisbursementCreated`, `DisbursementGatewayProcessed`, `DisbursementCompleted`, `DisbursementFailed`.

> **Catatan enum kustom:** jika kamu memakai enum vendor kustom (lihat [Menambah Vendor Baru](#menambah-vendor-baru-dengan-enum-kustom)), tambahkan juga method `getDisbursementProcessorClass(): ?string` di enum-mu — kembalikan `null` untuk vendor yang tidak mendukung disbursement.

---

## Keamanan Webhook & Disbursement

Karena package ini menangani uang, beberapa proteksi diterapkan secara default (sejak v1.3.0):

- **Signing secret wajib terisi.** Semua signature validator (Midtrans, Midtrans Payout, Stripe, Xendit) menolak callback bila secret/token-nya belum dikonfigurasi — mencegah pemalsuan webhook saat env masih kosong. Pastikan `MIDTRANS_SERVER_KEY`, `MIDTRANS_IRIS_MERCHANT_KEY`, `STRIPE_WEBHOOK_SECRET`, dan `XENDIT_WEBHOOK_TOKEN` terisi di production.
- **Verifikasi nominal.** Notifikasi pembayaran `PAID` hanya diproses jika nominal yang dilaporkan gateway cocok dengan `total_amount` yang diharapkan. Nominal yang tidak cocok dicatat ke log dan diabaikan.
- **Proteksi replay/idempoten.** Pembayaran/disbursement yang sudah berada di status terminal (`paid`/`failed`, `completed`/`failed`/`rejected`) tidak diproses ulang — webhook yang diulang tidak men-dispatch event ganda.
- **Maker-approver (separation of duties).** Pengguna yang membuat disbursement tidak bisa menyetujui payout-nya sendiri; percobaan demikian melempar `DisbursementApprovalDeniedException`. Kolom `created_by`/`approved_by` mencatat jejaknya (terisi otomatis dari `auth()->id()` bila ada konteks autentikasi).
- **Pesan error tidak bocor.** Aksi Approve/Reject di Filament menampilkan pesan generik ke operator dan mencatat detail exception ke log, alih-alih menampilkan pesan mentah dari gateway.

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

Empat resource langsung tersedia di panel admin:
- **Payments** — Daftar & detail transaksi (read-only)
- **Payment Methods** — CRUD metode pembayaran
- **Payment Method Groups** — CRUD grup metode pembayaran
- **Disbursements** — Daftar & detail payout, dengan aksi **Approve**/**Reject** (muncul saat status `queued`) yang langsung memanggil API Midtrans

---

## Menambah Vendor Baru dengan Enum Kustom

Ini adalah fitur paling fleksibel dari package ini. Kamu bisa menambahkan integrasi ke payment gateway manapun — Doku, Flip, iPaymu, dll. — tanpa mengubah satu baris pun dari kode inti package. Caranya adalah dengan membuat **enum PHP kustom** dan **model `PaymentMethod` kustom**, lalu mengarahkan config ke keduanya.

> **Catatan:** Midtrans, Stripe, dan **Xendit** kini sudah menjadi vendor **bawaan** (tidak perlu langkah di bawah). Contoh `Xendit` berikut tetap dipakai sebagai ilustrasi pola umum — terapkan pola yang sama untuk gateway lain yang belum didukung.

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
        │       ├── Dispatch PaymentGatewayProcessed
        │       ├── getQrCodeUrl()              → URL QR Code (QRIS, status_code 201)
        │       └── getMidtransVirtualAccountNumber() → Nomor VA (bank transfer, status_code 201)
        │
        ├── PaymentVendor::Stripe → Stripe::processPayment()
        │       ├── Buat Checkout Session via Stripe API
        │       ├── Simpan response ke payment record
        │       ├── Dispatch PaymentGatewayProcessed
        │       └── getStripeCheckoutUrl() → URL hosted checkout untuk redirect
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
| `getActivePaymentMethodGroups()` | `Collection` | Ambil semua group yang aktif beserta payment method-nya |
| `getPaymentMethodsByGroupId(int $group_id)` | `Collection` | Ambil payment method aktif dalam grup tertentu |
| `getPaymentFromPaymentable(string $type, int $id)` | `?Payment` | Cari pembayaran berdasarkan model yang terkait |
| `createDisbursement(DisbursementData $data)` | `Disbursement` | Buat payout & dispatch `DisbursementCreated` |
| `setDisbursementStatus(Disbursement $d, DisbursementStatus $s)` | `Disbursement` | Update status payout & dispatch event terkait |
| `approveDisbursement(Disbursement $d)` | `Disbursement` | Approve payout via API approver |
| `rejectDisbursement(Disbursement $d, ?string $reason)` | `Disbursement` | Reject payout via API approver |
| `getDisbursementByCode(string $code)` | `?Disbursement` | Cari payout berdasarkan disbursement code |
| `getDisbursementByReferenceNo(string $ref)` | `?Disbursement` | Cari payout berdasarkan reference number Midtrans |

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
| `payment_headers` | `?string` | — | Header HTTP yang dikirim ke gateway (disimpan sebagai JSON) |
| `payment_payload` | `?string` | — | Payload request yang dikirim ke gateway (disimpan sebagai JSON) |
| `payment_response` | `?string` | — | Response mentah dari gateway (disimpan sebagai JSON) |

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
