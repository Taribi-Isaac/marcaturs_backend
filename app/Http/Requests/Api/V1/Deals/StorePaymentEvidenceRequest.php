<?php

namespace App\Http\Requests\Api\V1\Deals;

use App\Enums\PaymentEvidenceKind;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentEvidenceRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('deals.max_payment_evidence_kilobytes');
        $mimes = implode(',', config('deals.allowed_payment_evidence_mimes'));

        return [
            'kind' => ['required', Rule::enum(PaymentEvidenceKind::class)],
            'file' => ['sometimes', 'file', 'max:'.$maxKb, 'mimes:'.$mimes],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'paid_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'business_id' => ['prohibited'],
            'business_user_id' => ['prohibited'],
            'ambassador_id' => ['prohibited'],
            'ambassador_user_id' => ['prohibited'],
            'campaign_id' => ['prohibited'],
            'campaign_version_id' => ['prohibited'],
            'deal_id' => ['prohibited'],
            'status' => ['prohibited'],
            'commission_type' => ['prohibited'],
            'commission_rate' => ['prohibited'],
            'commission_amount' => ['prohibited'],
            'reviewer_user_id' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'customer_user_id' => ['prohibited'],
            'customer_email' => ['prohibited'],
            'customer_phone' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
            'cvc' => ['prohibited'],
            'pin' => ['prohibited'],
            'otp' => ['prohibited'],
            'password' => ['prohibited'],
            'account_password' => ['prohibited'],
            'bvn' => ['prohibited'],
            'nin' => ['prohibited'],
            'payment_account_identifier' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = PaymentEvidenceKind::tryFrom((string) $this->input('kind'));

            if ($kind === null) {
                return;
            }

            $hasFile = $this->file('file') !== null;
            $reference = trim((string) $this->input('reference_number', ''));

            if ($kind->requiresFile() && ! $hasFile && $kind !== PaymentEvidenceKind::Other) {
                $validator->errors()->add('file', 'A receipt or screenshot file is required for this evidence type.');
            }

            if ($kind->requiresReference() && $reference === '') {
                $validator->errors()->add('reference_number', 'A payment reference is required for transaction-reference evidence.');
            }

            if ($kind === PaymentEvidenceKind::Other && ! $hasFile && $reference === '') {
                $validator->errors()->add('file', 'Other evidence must include a file or a payment reference.');
            }
        });
    }
}
