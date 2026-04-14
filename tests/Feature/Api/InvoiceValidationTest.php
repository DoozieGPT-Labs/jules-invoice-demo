<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class InvoiceValidationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_invalid_data_returns_422_with_validation_errors(): void
    {
        $response = $this->postJson('/api/invoices', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_number', 'customer_name', 'customer_email', 'amount', 'status', 'due_date']);
    }

    public function test_duplicate_invoice_number_is_rejected(): void
    {
        $existingInvoice = Invoice::factory()->create(['invoice_number' => 'INV-001']);

        $data = [
            'invoice_number' => 'INV-001',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 100.00,
            'status' => 'pending',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_number']);
    }

    public function test_invalid_email_format_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-002',
            'customer_name' => 'John Doe',
            'customer_email' => 'invalid-email',
            'amount' => 100.00,
            'status' => 'pending',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_email']);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-003',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => -10.00,
            'status' => 'pending',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-003',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 0.00,
            'status' => 'pending',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_past_due_date_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-004',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 100.00,
            'status' => 'pending',
            'due_date' => now()->subDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_today_due_date_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-005',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 100.00,
            'status' => 'pending',
            'due_date' => now()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $data = [
            'invoice_number' => 'INV-006',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 100.00,
            'status' => 'invalid',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
