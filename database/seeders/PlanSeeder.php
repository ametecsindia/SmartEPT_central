<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'core', 'name' => 'SmartEPT Core', 'sort' => 1,
                'inr_annual' => 29, 'inr_monthly' => 39, 'usd_annual' => 0.75, 'usd_monthly' => 1.00,
                'perpetual_device_inr' => 699, 'perpetual_server_inr' => 15000, 'min_devices' => 10,
                'features' => [
                    'attendance' => true, 'activity' => true, 'screenshots' => true,
                    'reports' => true, 'manager_accounts' => 1,
                    'live_status' => false, 'live_screen' => false, 'restrictions' => false,
                    'scoring' => 'basic', 'multi_office' => false, 'api' => false,
                    'screen_recording' => false, 'camera_presence' => false, 'usb_controls' => false,
                    'sso' => false, 'gate_to_pc' => false,
                ],
            ],
            [
                'code' => 'professional', 'name' => 'SmartEPT Professional', 'sort' => 2,
                'inr_annual' => 59, 'inr_monthly' => 79, 'usd_annual' => 1.50, 'usd_monthly' => 2.00,
                'perpetual_device_inr' => 1199, 'perpetual_server_inr' => 25000, 'min_devices' => 10,
                'features' => [
                    'attendance' => true, 'activity' => true, 'screenshots' => true,
                    'reports' => true, 'manager_accounts' => 10,
                    'live_status' => true, 'live_screen' => true, 'restrictions' => true,
                    'scoring' => 'full', 'multi_office' => true, 'api' => true,
                    'screen_recording' => 'addon', 'camera_presence' => 'addon', 'usb_controls' => 'addon',
                    'sso' => 'addon', 'gate_to_pc' => true,
                ],
            ],
            [
                'code' => 'enterprise', 'name' => 'SmartEPT Enterprise', 'sort' => 3,
                'inr_annual' => 99, 'inr_monthly' => 129, 'usd_annual' => 2.50, 'usd_monthly' => 3.25,
                'perpetual_device_inr' => 1999, 'perpetual_server_inr' => 50000, 'min_devices' => 25,
                'features' => [
                    'attendance' => true, 'activity' => true, 'screenshots' => true,
                    'reports' => true, 'manager_accounts' => -1,
                    'live_status' => true, 'live_screen' => true, 'restrictions' => true,
                    'scoring' => 'advanced', 'multi_office' => true, 'api' => true,
                    'screen_recording' => true, 'camera_presence' => true, 'usb_controls' => true,
                    'sso' => true, 'gate_to_pc' => true,
                ],
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['code' => $data['code']], $data);
        }

        // Volume tiers for Professional (annual, client-hosted base rates).
        $pro = Plan::where('code', 'professional')->first();
        $tiers = [
            [10, 25, 59], [26, 100, 49], [101, 250, 39],
            [251, 500, 29], [501, 1000, 24], [1001, null, 20],
        ];
        $pro->volumeTiers()->delete();
        foreach ($tiers as [$min, $max, $rate]) {
            $pro->volumeTiers()->create([
                'min_devices' => $min, 'max_devices' => $max, 'rate_inr_annual' => $rate,
            ]);
        }
    }
}
