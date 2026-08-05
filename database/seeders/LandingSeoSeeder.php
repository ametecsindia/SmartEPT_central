<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Professional default SEO/SMM copy for the SmartEPT landing page.
 * Only fills a field if it is currently empty — never overwrites owner edits.
 * Account-specific values (analytics IDs, canonical domain, Twitter handle,
 * OG/share image, favicon, logo) are intentionally left blank for the owner.
 */
class LandingSeoSeeder extends Seeder
{
    public function run(): void
    {
        $vals = [
            'seo_title'       => "SmartEPT — Employee Productivity Tracking & Attendance Software",
            'seo_description' => "Track productive vs idle time, attendance, app & website usage, breaks and shift adherence in one transparent dashboard — with Gate-to-PC access control. Client-hosted or managed cloud.",
            'seo_keywords'    => "employee productivity tracking software, employee monitoring software India, attendance tracking software, time tracking software, workforce monitoring, idle time tracking, app and website usage monitoring, screenshot monitoring, productivity analytics, shift adherence, gate-to-PC access control, biometric attendance integration, work from home monitoring, SmartEPT, Ametecs",
            'seo_robots'      => "index, follow",
            'seo_site_name'   => "SmartEPT",
            'thankyou_headline' => "Thank you — we've got your details",
            'thankyou_message'  => "Our team will reach out shortly to set up your SmartEPT demo. Meanwhile, feel free to explore how transparent, policy-based productivity tracking works.",
        ];

        foreach ($vals as $k => $v) {
            if (trim((string) Setting::get($k, '')) === '') {
                Setting::set($k, $v);
            }
        }
    }
}
