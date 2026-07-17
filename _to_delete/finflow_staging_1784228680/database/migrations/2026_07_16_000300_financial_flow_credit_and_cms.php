<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B Financial Flow master-prompt pass (16-Jul-2026, Ejaz's SmartPRS blueprint):
 *
 * 1. order_payments — the PAYMENTS LEDGER. Partial/credit state is COMPUTED
 *    from this ledger (sum vs order total), never stored as an enum. This is
 *    the rev186 lesson: computed state can never disagree with the money.
 * 2. orders.provisioned_at — set the FIRST time a payment (even ₹0 "Due"
 *    credit) provisions the licence. Decouples "workspace live" from "fully paid".
 * 3. orders.credit_due_date — the commercial promise date for any balance.
 * 4. invoices.due_date — printed on credit invoices; invoice stays `issued`
 *    (displayed as DUE) until the ledger covers the total, then flips to paid.
 * 5. coupons.exclusive_email — exclusive-offer catch: a coupon sent to one
 *    email auto-applies when that email types itself into the signup form.
 * 6. tenants.terms_accepted_at — timestamped Terms + Refund-policy consent.
 * 7. tenant_users.must_set_password — temp password is a backup only; first
 *    login forces a create-your-own-password screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_payments')) {
            Schema::create('order_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('order_id')->constrained()->cascadeOnDelete();
                $t->decimal('amount', 12, 2);
                $t->string('gateway')->default('manual');           // manual | razorpay | stripe
                $t->string('method')->nullable();                   // NEFT / UPI / cheque / cash / other
                $t->string('reference')->nullable();                // UTR / cheque no.
                $t->string('gateway_payment_id')->nullable()->unique('order_payments_gpid_unique');
                $t->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $t->string('note')->nullable();
                $t->timestamp('paid_at');
                $t->timestamps();
                $t->index('order_id', 'order_payments_order_idx');
            });
        }

        if (! Schema::hasColumn('orders', 'provisioned_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->timestamp('provisioned_at')->nullable()->after('paid_at')
                    ->comment('When the licence/workspace was provisioned (may precede full payment on credit)');
                $t->date('credit_due_date')->nullable()->after('provisioned_at')
                    ->comment('Balance payable by — commercial credit promise; manual follow-up only, never auto-lock');
            });
        }

        if (! Schema::hasColumn('invoices', 'due_date')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->date('due_date')->nullable()->after('date')
                    ->comment('Credit due date for invoices issued before full payment');
            });
        }

        if (! Schema::hasColumn('coupons', 'exclusive_email')) {
            Schema::table('coupons', function (Blueprint $t) {
                $t->string('exclusive_email')->nullable()->after('min_devices')
                    ->comment('Exclusive-to-one-email coupon; auto-applied when that email appears at signup');
            });
        }

        if (! Schema::hasColumn('tenants', 'terms_accepted_at')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->timestamp('terms_accepted_at')->nullable()->after('setup_fee_paid')
                    ->comment('Timestamped Terms + Refund-policy consent from self-service signup');
            });
        }

        if (! Schema::hasColumn('tenant_users', 'must_set_password')) {
            Schema::table('tenant_users', function (Blueprint $t) {
                $t->boolean('must_set_password')->default(false)->after('active')
                    ->comment('Forced create-your-own-password on first portal login (temp password is backup only)');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['provisioned_at', 'credit_due_date']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('due_date'));
        Schema::table('coupons', fn (Blueprint $t) => $t->dropColumn('exclusive_email'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('terms_accepted_at'));
        Schema::table('tenant_users', fn (Blueprint $t) => $t->dropColumn('must_set_password'));
    }
};
