<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceModelVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_model_casts_work_correctly(): void
    {
        $invoice = new Invoice([
            'invoice_number' => 'INV-001',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => 100.50,
            'status' => 'pending',
            'due_date' => '2024-12-31',
            'paid_at' => '2024-01-15 10:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $invoice->due_date);
        $this->assertInstanceOf(Carbon::class, $invoice->paid_at);

        $this->assertEquals('2024-12-31', $invoice->due_date->format('Y-m-d'));
        $this->assertEquals('2024-01-15 10:00:00', $invoice->paid_at->format('Y-m-d H:i:s'));
    }

    public function test_invoice_model_can_be_created_with_factory(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }
}
