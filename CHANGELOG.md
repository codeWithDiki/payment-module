# Changelog

Semua perubahan penting pada `codewithdiki/payment-module` didokumentasikan di file ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.0.0/),
dan project ini memakai [Semantic Versioning](https://semver.org/lang/id/).
Setiap rilis ditandai dengan git tag `vX.Y.Z` (mis. `v1.3.0`).

## [Unreleased]

### Added

- Aksi **Confirm Payment** di resource Payments untuk menyelesaikan pembayaran offline secara manual, lengkap dengan konfirmasi dan notifikasi. Hanya muncul untuk payment `pending` bervendor `Offline`.
- `Payment::canBeConfirmedManually()` — predikat kelayakan konfirmasi manual, ditaruh di model agar bisa dipakai ulang di luar Filament.
- Trait `Resources\Concerns\HasDefaultTableSort` — semua tabel Filament package kini diurutkan descending (terbaru di atas), dengan `$defaultSortColumn` yang bisa di-override per tabel.

### Changed

- **BREAKING:** pembayaran bervendor `Offline` **tidak lagi otomatis ditandai `paid`** saat dibuat. `Offline::processPayment()` sekarang no-op dan payment tetap `pending` sampai operator mengonfirmasinya. Tidak ada gateway yang bisa membuktikan dana offline sudah masuk, jadi konfirmasi otomatis menyelesaikan order yang belum tentu dibayar. Bila kamu bergantung pada perilaku lama, panggil `PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID)` sendiri setelah verifikasi.

### Fixed

- Kolom `paid_at` pada `payments` kini benar-benar terisi. Sebelumnya kolom itu ada dan sudah di-cast, tapi tidak pernah ditulis oleh kode mana pun; `setPaymentStatus()` sekarang mengisinya saat status menjadi `paid`, sejalan dengan `completed_at` pada disbursement.

## [1.4.0] - 2026-08-02

Rilis ini menambahkan **DOKU** sebagai vendor bawaan, mencakup pembayaran lewat **SNAP Direct API**
dan payout lewat **Kirim DOKU**.

> ⚠️ **Rilis ini mengubah skema database.** Lihat [Upgrade dari 1.3.x ke 1.4.0](README.md#upgrade-dari-13x-ke-140) di README untuk langkah migrasi.

### Added

- **DOKU — Payment** (SNAP Direct API): Virtual Account (11 bank), e-wallet (OVO/DANA/ShopeePay), dan QRIS. Vendor baru `PaymentVendor::Doku`. Channel memakai kode channel DOKU sendiri (mis. `VIRTUAL_ACCOUNT_BCA`, `EMONEY_OVO`), dan prefix VA per bank disimpan di `meta_data.partner_service_id` pada payment method.
- **DOKU — Disbursement/Payout** (Kirim DOKU): payout single-step yang menjalankan Account Inquiry lebih dulu karena `sessionId` hasil inquiry wajib ada di `transfer-bank`. Butuh approval regulator ASPI sebelum live.
- Webhook DOKU otomatis terdaftar di `POST /webhooks/doku` dan `POST /webhooks/doku/disbursement`, diverifikasi lewat header `X-SIGNATURE` (HMAC-SHA512).
- `Supports\Doku\SnapClient`: access token B2B (asimetris SHA256withRSA, di-cache selama masa berlakunya) dan penandatanganan request simetris HMAC-SHA512, dipakai bersama oleh sisi payment dan disbursement.
- Kolom `beneficiary_phone` pada `disbursements`, wajib oleh Kirim DOKU dan diabaikan vendor lain; tersedia juga sebagai field opsional di `DisbursementData`.
- Accessor `Payment::getDokuVirtualAccountNumber()` dan `Payment::getDokuQrString()`.
- Konfigurasi baru: `doku_client_id`, `doku_client_secret`, `doku_private_key`, `doku_is_production`, `doku_sender_name`, `doku_sender_phone`, `doku_sender_personal_id`, `doku_sender_personal_id_type`, `doku_sender_country_code`.

### Changed

- Semua gateway (Midtrans, Stripe, Xendit, DOKU) menagih **`total_amount`** (amount + fee), bukan `amount` saja.
- Helper test DOKU dipindah ke `tests/Pest.php` agar tersedia lintas file test, karena PHPUnit dijalankan dengan urutan acak.

### Security

- **Webhook ditolak bila signing secret belum dikonfigurasi** — kini termasuk DOKU (`doku_client_secret`).
- **Verifikasi nominal webhook** juga diterapkan pada notifikasi DOKU sebelum menandai pembayaran `paid`.

## [1.3.0] - 2026-06-16

Rilis ini menambahkan **Xendit** sebagai vendor bawaan, **sistem fee otomatis** per
payment method, dan sejumlah **perbaikan keamanan** pada alur webhook & disbursement.

> ⚠️ **Rilis ini mengubah skema database.** Lihat [Upgrade dari 1.2.x ke 1.3.0](README.md#upgrade-dari-12x-ke-130) di README untuk langkah migrasi.

### Added

- **Xendit — Payment** (direct channel API): Virtual Account, e-wallet (OVO/DANA/LinkAja/ShopeePay), dan QRIS. Vendor baru `PaymentVendor::Xendit`.
- **Xendit — Disbursement/Payout**: payout single-step (auto-process, tanpa maker-approver) via Xendit Disbursement API.
- Webhook Xendit otomatis terdaftar di `POST /webhooks/xendit` dan `POST /webhooks/xendit/disbursement`, diverifikasi lewat header `x-callback-token`.
- **Sistem fee payment method**: kolom `fee_flat` & `fee_percentage` pada `payment_methods`. Saat pembayaran dibuat, fee dihitung otomatis (`fee_flat + amount × fee_percentage%`) dan **ditambahkan ke tagihan customer**.
- Kolom `fee` & `total_amount` pada `payments`; helper `PaymentMethod::calculateFee()` dan `Payment::billableAmount()`.
- Kolom `created_by` & `approved_by` pada `disbursements` untuk audit maker-approver.
- Konfigurasi baru: `xendit_secret_key`, `xendit_webhook_token`, `xendit_is_production`, `xendit_success_redirect_url`, `xendit_failure_redirect_url`.
- Annotasi `@property` pada model `Payment`, `PaymentMethod`, `Disbursement` (IDE/PHPStan).

### Changed

- Semua gateway (Midtrans, Stripe, Xendit) kini menagih **`total_amount`** (amount + fee), bukan `amount` saja.
- `DisbursementStatus` & `PaymentStatus` mendapat helper `isTerminal()`.

### Security

- **Webhook ditolak bila signing secret belum dikonfigurasi** (Midtrans, Midtrans Payout, Stripe, Xendit) — mencegah pemalsuan webhook saat env belum diisi.
- **Verifikasi nominal webhook**: notifikasi PAID hanya diproses bila nominal dari gateway cocok dengan `total_amount` yang diharapkan.
- **Proteksi replay/idempoten**: status terminal (PAID/FAILED, COMPLETED/FAILED/REJECTED) tidak diproses ulang sehingga event tidak ter-dispatch ganda.
- **Maker-approver separation of duties**: pembuat disbursement tidak dapat menyetujui payout-nya sendiri (`DisbursementApprovalDeniedException`).
- Pesan exception mentah tidak lagi ditampilkan di UI Filament (di-log, pesan generik ke operator).

## [1.2.0] - 2026-06-11

Baseline sebelum changelog ini mulai dicatat. Mendukung Midtrans (GoPay, ShopeePay, QRIS,
bank transfer), Stripe (Checkout Session), Offline, disbursement via Midtrans Payouts (Iris),
dan panel admin Filament. Riwayat rilis lama tersedia di [git tags](../../tags) (`v1.1.x`–`v1.2.0`).

[Unreleased]: ../../compare/v1.4.0...HEAD
[1.4.0]: ../../compare/v1.3.0...v1.4.0
[1.3.0]: ../../compare/v1.2.0...v1.3.0
[1.2.0]: ../../releases/tag/v1.2.0
