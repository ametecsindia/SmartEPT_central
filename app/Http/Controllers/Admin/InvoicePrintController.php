<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CheckoutController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;

class InvoicePrintController extends Controller
{
    public function show(Invoice $invoice)
    {
        return view('invoice-print', [
            'invoice' => $invoice->load('tenant', 'order'),
            'company' => [
                'name' => Setting::get('company_name', 'Ametecs India Private Limited'),
                'address' => Setting::get('company_address', ''),
                'gstin' => Setting::get('company_gstin', ''),
                'phone' => Setting::get('company_phone', ''),
                'email' => Setting::get('company_email', ''),
                // Seller state + bank/UPI block for the GST tax invoice.
                'state' => \App\Support\IndianStates::placeOfSupply(Setting::get('seller_state_code', '36')),
                'bank_account_name' => Setting::get('bank_account_name', ''),
                'bank_name' => Setting::get('bank_name', ''),
                'bank_branch' => Setting::get('bank_branch', ''),
                'bank_account_no' => Setting::get('bank_account_no', ''),
                'bank_ifsc' => Setting::get('bank_ifsc', ''),
                'upi_id' => Setting::get('upi_id', ''),
            ],
        ]);
    }

    public function creditNote(OrderPayment $payment)
    {
        abort_unless((float) $payment->amount < 0, 404); // credit notes are refund rows only
        $order = $payment->order()->with('tenant', 'invoice')->firstOrFail();

        return view('credit-note-print', [
            'payment' => $payment,
            'order' => $order,
            'invoice' => $order->invoice,
            'company' => $this->companyBlock(),
        ]);
    }

    private function companyBlock(): array
    {
        return [
            'name' => Setting::get('company_name', 'Ametecs India Private Limited'),
            'address' => Setting::get('company_address', ''),
            'gstin' => Setting::get('company_gstin', ''),
            'phone' => Setting::get('company_phone', ''),
            'email' => Setting::get('company_email', ''),
            'state' => \App\Support\IndianStates::placeOfSupply(Setting::get('seller_state_code', '36')),
        ];
    }

    public function quote(Order $order)
    {
        abort_unless($order->quote_number, 404);

        return view('quote-print', [
            'order' => $order->load('tenant'),
            'payUrl' => url('/pay/' . $order->number . '/' . CheckoutController::token($order)),
            'company' => [
                'name' => Setting::get('company_name', 'Ametecs India Private Limited'),
                'address' => Setting::get('company_address', ''),
                'gstin' => Setting::get('company_gstin', ''),
                'phone' => Setting::get('company_phone', ''),
                'email' => Setting::get('company_email', ''),
                // Seller state + bank/UPI block for the GST tax invoice.
                'state' => \App\Support\IndianStates::placeOfSupply(Setting::get('seller_state_code', '36')),
                'bank_account_name' => Setting::get('bank_account_name', ''),
                'bank_name' => Setting::get('bank_name', ''),
                'bank_branch' => Setting::get('bank_branch', ''),
                'bank_account_no' => Setting::get('bank_account_no', ''),
                'bank_ifsc' => Setting::get('bank_ifsc', ''),
                'upi_id' => Setting::get('upi_id', ''),
            ],
        ]);
    }
}
