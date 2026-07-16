<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'gst_rate' => '18',
            'invoice_prefix' => 'EPT',
            'quote_prefix' => 'EPT-Q',
            'order_prefix' => 'SEPT-ORD',
            'company_name' => 'Ametecs India Private Limited',
            'company_address' => 'Modern Profound Techpark, Ground Floor, Hive Space, opp. Google, Whitefields, Kondapur, Hyderabad, Telangana 500084',
            'company_gstin' => '36AAHCT0971F1ZB',
            'company_phone' => '+91 96666 12424',
            'company_email' => 'sales@ametecsindia.com',
            'whatsapp_number' => '919000098877',
            // GST invoice identity (Release-1): seller state drives CGST/SGST vs IGST,
            // SAC 997331 = licensing of software / SaaS services.
            'seller_state_code' => '36',
            'sac_code' => '997331',
            // Bank/UPI block printed on tax invoices — Ejaz fills the real account
            // number/IFSC/UPI in the Settings screen; blank rows are hidden on print.
            'bank_account_name' => 'Ametecs India Private Limited',
            'bank_name' => 'HDFC Bank',
            'bank_branch' => 'Kondapur, Hyderabad',
            'bank_account_no' => '',
            'bank_ifsc' => '',
            'upi_id' => '',
            // Gateways: TEST MODE — Ejaz pastes real keys in Settings screen, never in chat.
            'razorpay_key_id' => '',
            'razorpay_key_secret' => '',
            'razorpay_webhook_secret' => '',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
