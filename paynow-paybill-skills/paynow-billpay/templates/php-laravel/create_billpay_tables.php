<?php

// database/migrations/2026_01_01_000000_create_billpay_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------- Vendor: local catalogue (never query ListBillers per request) ----
        Schema::create('billpay_billers', function (Blueprint $t) {
            $t->string('code')->primary();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('enabled')->default(false);
            $t->string('member_number_label')->nullable();
            $t->string('member_number_desc')->nullable();
            $t->string('member_number_regex')->nullable();
            $t->boolean('allow_multiple_products')->default(false);
            $t->boolean('vendor_must_invoice')->default(false);
            $t->string('icon_url')->nullable();
            $t->string('logo_url')->nullable();
            $t->json('raw_json')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('billpay_products', function (Blueprint $t) {
            $t->id();
            $t->string('biller_code')->index();
            $t->string('code');
            $t->string('name');
            $t->decimal('price', 14, 2)->nullable();
            $t->text('description')->nullable();
            $t->string('department')->nullable();
            $t->string('amount_field_label')->nullable();
            $t->string('quantity_field_label')->nullable();
            $t->text('pre_purchase_instructions')->nullable();
            $t->text('post_purchase_instructions')->nullable();
            $t->boolean('requires_forex')->nullable();        // null = AUTH decides
            $t->boolean('auth_amount_mandated')->nullable();  // null = you set price; true = full; false = part allowed
            $t->decimal('min_amount', 14, 2)->nullable();
            $t->decimal('max_amount', 14, 2)->nullable();
            $t->boolean('returns_vouchers')->default(false);
            $t->boolean('allow_specify_quantity')->default(false);
            $t->json('metadata_fields')->nullable();
            $t->boolean('enabled')->default(false);
            $t->json('raw_json')->nullable();
            $t->timestamps();
            $t->unique(['biller_code', 'code']);
        });

        // ---------------- Vendor: payments ---------------------------------------------
        Schema::create('billpay_transactions', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();             // same for AUTH, PAY, STATUS
            $t->foreignId('user_id')->nullable()->index(); // your customer (optional)
            $t->string('customer_phone')->nullable();      // where receipt SMSes go
            $t->string('biller_code');
            $t->string('product_code')->nullable();
            $t->string('member_number');
            $t->json('auth_request');
            $t->json('payer_details')->nullable();
            $t->boolean('auth_returns_price')->default(false);
            $t->boolean('vendor_must_invoice')->default(false);
            $t->decimal('total_amount', 14, 2)->nullable();
            $t->decimal('amount_due', 14, 2)->nullable();  // what the customer is charged
            $t->string('currency', 3)->nullable();
            $t->string('state', 32)->index();
            $t->string('billpay_status', 32)->nullable();
            $t->string('billpay_reference')->nullable();
            $t->string('biller_payment_reference')->nullable();
            $t->string('member_name')->nullable();
            $t->string('member_address')->nullable();
            $t->json('auth_response')->nullable();
            $t->json('pay_response')->nullable();
            $t->text('narration')->nullable();           // customer-facing
            $t->text('technical_narration')->nullable(); // internal only
            $t->decimal('wallet_balance_after', 14, 2)->nullable();
            $t->unsignedInteger('status_checks')->default(0);
            $t->timestamp('customer_charged_at')->nullable();
            $t->timestamp('customer_refunded_at')->nullable();
            $t->timestamp('receipts_delivered_at')->nullable();
            $t->string('fiscal_invoice_reference')->nullable();
            $t->text('fiscal_signature')->nullable();
            $t->text('fiscal_metadata')->nullable();
            $t->timestamps();
        });

        // ---------------- Biller: payments received via webhook -------------------------
        Schema::create('billpay_received_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('payment_id')->primary(); // idempotency key
            $t->string('billpay_reference');
            $t->string('bank_reference')->nullable();
            $t->string('paid_date');
            $t->string('member_number')->index();
            $t->string('member_name');
            $t->string('product_code');
            $t->decimal('product_price', 14, 2);
            $t->string('product_department')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billpay_received_payments');
        Schema::dropIfExists('billpay_transactions');
        Schema::dropIfExists('billpay_products');
        Schema::dropIfExists('billpay_billers');
    }
};
