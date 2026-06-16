# Changelog

Semua perubahan penting pada `codewithdiki/payment-module` didokumentasikan di file ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.0.0/),
dan project ini memakai [Semantic Versioning](https://semver.org/lang/id/).
Setiap rilis ditandai dengan git tag `vX.Y.Z` (mis. `v1.3.0`).

## [Unreleased]

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

[Unreleased]: ../../compare/v1.3.0...HEAD
[1.3.0]: ../../compare/v1.2.0...v1.3.0
[1.2.0]: ../../releases/tag/v1.2.0
