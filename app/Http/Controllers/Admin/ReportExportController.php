<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\Request;

/**
 * Accountant CSV exports (Ejaz 20-Jul): GST register (GSTR-1 style), collections
 * (money received) and outstanding (credit balances). Read-only, super/sales.
 * Downloads open as normal GET links (session-authenticated) — no JSON layer.
 */
class ReportExportController extends Controller
{
    /** GET /admin/api/reports/gst-register?from=&to= — one row per invoice with the tax split. */
    public function gstRegister(Request $request)
    {
        [$from, $to] = $this->range($request);

        $rows = Invoice::with('tenant:id,company_name')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(fn (Invoice $i) => [
                optional($i->date)->toDateString(),
                $i->number,
                $i->tenant?->company_name,
                $i->buyer_gstin,
                $i->place_of_supply,
                $i->sac_code,
                $this->money($i->subtotal),
                $this->money($i->cgst),
                $this->money($i->sgst),
                $this->money($i->igst),
                $this->money($i->gst_amount),
                $this->money($i->total),
                $i->currency,
                strtoupper((string) $i->status),
            ]);

        return $this->csv("gst-register_{$from}_to_{$to}.csv", [
            'Date', 'Invoice', 'Client', 'Buyer GSTIN', 'Place of supply', 'SAC',
            'Taxable value', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice total', 'Currency', 'Status',
        ], $rows);
    }

    /** GET /admin/api/reports/collections?from=&to= — every payment/refund received. */
    public function collections(Request $request)
    {
        [$from, $to] = $this->range($request);

        $rows = OrderPayment::with(['order:id,number,currency,tenant_id', 'order.tenant:id,company_name'])
            ->whereBetween('paid_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('paid_at')
            ->get()
            ->map(fn (OrderPayment $p) => [
                optional($p->paid_at)->toDateTimeString(),
                $p->order?->tenant?->company_name,
                $p->order?->number,
                $p->method ?: $p->gateway,
                $p->reference ?: $p->gateway_payment_id,
                $p->credit_note_number,
                $this->money($p->amount),
                $p->order?->currency,
            ]);

        return $this->csv("collections_{$from}_to_{$to}.csv", [
            'Paid at', 'Client', 'Order', 'Method', 'Reference', 'Credit note', 'Amount', 'Currency',
        ], $rows);
    }

    /** GET /admin/api/reports/outstanding — provisioned orders with a balance still due. */
    public function outstanding(Request $request)
    {
        $rows = Order::with(['tenant:id,company_name,phone', 'invoice:id,order_id,number'])
            ->withSum('payments as received', 'amount')
            ->whereNotNull('provisioned_at')
            ->where('status', '!=', 'paid')
            ->orderByRaw('credit_due_date IS NULL, credit_due_date')
            ->get()
            ->map(function (Order $o) {
                $received = round((float) ($o->received ?? 0), 2);
                $balance = round(max(0, (float) $o->total - $received), 2);

                return [
                    $o->tenant?->company_name,
                    $o->tenant?->phone,
                    $o->number,
                    $o->invoice?->number,
                    $this->money($o->total),
                    $this->money($received),
                    $this->money($balance),
                    optional($o->credit_due_date)->toDateString(),
                    ($o->credit_due_date && $o->credit_due_date->isPast()) ? 'OVERDUE' : '',
                    $o->currency,
                ];
            })
            ->filter(fn ($r) => (float) $r[6] > 0)   // only rows with a real balance
            ->values();

        $today = now()->toDateString();

        return $this->csv("outstanding_{$today}.csv", [
            'Client', 'Phone', 'Order', 'Invoice', 'Order total', 'Received', 'Balance', 'Due date', 'Flag', 'Currency',
        ], $rows);
    }

    // ---------------------------------------------------------------------

    private function range(Request $request): array
    {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to   = $request->query('to') ?: now()->toDateString();

        return [$from, $to];
    }

    private function money($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function csv(string $filename, array $header, iterable $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
