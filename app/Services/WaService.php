<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WaTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp sending via Interakt for SmartEPT Central (platform-level).
 * Config lives in Settings (interakt_api_key / _api_url / _status — Settings →
 * WhatsApp API). Only APPROVED template messages are supported (WhatsApp requires
 * business-initiated messages to use an approved template with matching variables).
 * Every attempt is logged to wa_log. Fail-soft: NEVER throws into callers.
 */
class WaService
{
    public const PURPOSES = ['welcome', 'payment', 'renewal', 'lead', 'otp', 'custom'];

    /** Interakt credentials from Settings, else env fallback. Null when unconfigured. */
    public static function config(): ?array
    {
        try {
            $key = Setting::get('interakt_api_key');
            $status = strtolower((string) Setting::get('interakt_status', 'active'));
            if ($key && ! in_array($status, ['inactive', 'disabled', 'off', '0'], true)) {
                return ['key' => $key, 'url' => Setting::get('interakt_api_url') ?: 'https://api.interakt.ai/v1/public/message/'];
            }
        } catch (\Throwable $e) {
            // fall through to env
        }
        if (env('INTERAKT_API_KEY')) {
            return ['key' => env('INTERAKT_API_KEY'), 'url' => env('INTERAKT_API_URL') ?: 'https://api.interakt.ai/v1/public/message/'];
        }

        return null;
    }

    /** Resolve the live Interakt template NAME for a purpose from approved rows. */
    public static function templateNameFor(string $purpose): string
    {
        $defaults = [
            'welcome' => 'smartept_welcome', 'payment' => 'smartept_payment',
            'renewal' => 'smartept_renewal', 'lead' => 'smartept_lead', 'otp' => 'smartept_otp',
        ];
        try {
            if (Schema::hasTable('wa_templates')) {
                $name = WaTemplate::where('purpose', $purpose)->where('status', 'approved')->orderBy('id')->value('name');
                if ($name) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // fall through to defaults
        }

        return $defaults[$purpose] ?? $purpose;
    }

    /**
     * Send an APPROVED template message. $opts: mobile, purpose? or template?,
     * bodyValues (array matching the template), lang?, countryCode?, kind?.
     */
    public static function sendTemplate(array $opts): bool
    {
        $ok = false;
        $error = null;
        $digits = preg_replace('/\D+/', '', (string) ($opts['mobile'] ?? ''));
        $phone = substr($digits, -10);
        $template = $opts['template'] ?? self::templateNameFor($opts['purpose'] ?? 'custom');

        try {
            $cfg = self::config();
            if (! $cfg) {
                throw new \RuntimeException('WhatsApp (Interakt) is not configured — add the API key in Settings → WhatsApp API.');
            }
            if (strlen($phone) < 10) {
                throw new \RuntimeException('No valid mobile number to send to.');
            }
            $resp = Http::timeout(15)->withHeaders(['Authorization' => 'Basic ' . $cfg['key']])
                ->post($cfg['url'], [
                    'countryCode' => $opts['countryCode'] ?? '+91',
                    'phoneNumber' => $phone,
                    'type' => 'Template',
                    'template' => [
                        'name' => $template,
                        'languageCode' => $opts['lang'] ?? 'en',
                        'bodyValues' => array_values(array_map('strval', $opts['bodyValues'] ?? [])),
                    ],
                ]);
            $ok = $resp->successful();
            if (! $ok) {
                $error = 'HTTP ' . $resp->status() . ': ' . substr($resp->body(), 0, 400);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        try {
            if (Schema::hasTable('wa_log')) {
                DB::table('wa_log')->insert([
                    'mobile' => $phone ?: null,
                    'template' => $template,
                    'body_values' => json_encode(array_values($opts['bodyValues'] ?? [])),
                    'kind' => $opts['kind'] ?? ($opts['purpose'] ?? null),
                    'status' => $ok ? 'sent' : 'failed',
                    'error' => $error,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // logging is best-effort
        }

        return $ok;
    }
}
