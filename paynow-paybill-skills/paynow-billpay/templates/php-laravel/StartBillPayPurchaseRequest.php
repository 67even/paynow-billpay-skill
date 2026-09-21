<?php

namespace App\Http\Requests;

use App\Models\BillPayProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a purchase against the LOCAL catalogue (never a live ListBillers call):
 * biller + product Enabled, MemberNumberFieldRegex, MinAmount/MaxAmount,
 * AllowSpecifyQuantity and required MetadataFields.
 *
 * Biller and product codes come from your synced catalogue (billpay_billers /
 * billpay_products) — Paynow does not publish them, so don't hard-code guesses.
 */
class StartBillPayPurchaseRequest extends FormRequest
{
    private ?BillPayProduct $product = null;

    public function authorize(): bool
    {
        return true; // put the route behind your auth middleware
    }

    public function rules(): array
    {
        return [
            'biller_code'    => ['required', 'string', 'max:100'],
            'product_code'   => ['required', 'string', 'max:100'],
            'member_number'  => ['required', 'string', 'max:64'],   // meter, account or mobile number
            'amount'         => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'quantity'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{9,15}$/'],
            'metadata'       => ['nullable', 'array'],
            'metadata.*'     => ['string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->member_number)) {
            $this->merge(['member_number' => preg_replace('/[\s\-]/', '', $this->member_number)]);
        }
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $product = BillPayProduct::with('biller')
                ->where('biller_code', $this->input('biller_code'))
                ->where('code', $this->input('product_code'))
                ->first();

            if (! $product || ! $product->enabled || ! $product->biller?->enabled) {
                $v->errors()->add('product_code', 'This product is not available right now.');

                return;
            }
            $biller = $product->biller;

            // MemberNumberFieldRegex comes from BillPay (.NET syntax). If PCRE can't
            // compile it, skip the local check and let AUTH validate the number.
            if ($biller->member_number_regex) {
                $pattern = '~'.str_replace('~', '\~', $biller->member_number_regex).'~';
                if (@preg_match($pattern, $this->input('member_number')) === 0) {
                    $label = $biller->member_number_label ?: 'number';
                    $v->errors()->add('member_number', trim("Please enter a valid {$label}. ".($biller->member_number_desc ?? '')));
                }
            }

            if ((int) ($this->input('quantity') ?? 1) !== 1 && ! $product->allow_specify_quantity) {
                $v->errors()->add('quantity', 'Quantity cannot be changed for this product.');
            }

            if ($product->customerChoosesAmount()) {
                $amount = $this->input('amount');
                if ($amount === null) {
                    $v->errors()->add('amount', 'Please enter an amount.');
                } elseif ($product->min_amount !== null && (float) $amount < (float) $product->min_amount) {
                    $v->errors()->add('amount', "The minimum amount is {$product->min_amount}.");
                } elseif ($product->max_amount !== null && (float) $amount > (float) $product->max_amount) {
                    $v->errors()->add('amount', "The maximum amount is {$product->max_amount}.");
                }
            }

            foreach ($product->metadata_fields ?? [] as $field) {
                if (($field['Required'] ?? false) && blank($this->input('metadata.'.($field['Name'] ?? '')))) {
                    $v->errors()->add('metadata', "{$field['Name']} is required.");
                }
            }

            $this->product = $product;
        }];
    }

    public function product(): BillPayProduct
    {
        return $this->product;
    }
}
