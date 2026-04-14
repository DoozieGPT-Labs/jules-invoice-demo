<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invoice_number' => 'required|string|unique:invoices',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:pending,paid,overdue',
            'due_date' => 'required|date',
            'paid_at' => 'nullable|date',
        ];
    }
}
