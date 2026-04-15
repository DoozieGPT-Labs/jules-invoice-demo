<?php

namespace Tests\Unit;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the invoice factory generates valid data.
     */
    public function test_invoice_factory_creates_valid_data(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->customer_name);
        $this->assertNotNull($invoice->customer_email);
        $this->assertNotNull($invoice->amount);
        $this->assertNotNull($invoice->status);
        $this->assertNotNull($invoice->due_date);
    }

    /**
     * Test the invoice factory states.
     */
    public function test_invoice_factory_states(): void
    {
        $paidInvoice = Invoice::factory()->paid()->create();
        $this->assertEquals('paid', $paidInvoice->status);
        $this->assertNotNull($paidInvoice->paid_at);

        $overdueInvoice = Invoice::factory()->overdue()->create();
        $this->assertEquals('overdue', $overdueInvoice->status);
        $this->assertTrue($overdueInvoice->due_date->isPast());
    }

    /**
     * Test model attribute casting.
     */
    public function test_invoice_model_casts(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => '123.45',
            'due_date' => '2024-12-31',
            'paid_at' => '2024-01-01 12:00:00',
        ]);

        $this->assertSame('123.45', $invoice->amount); // decimal:2 cast to string in Eloquent
        $this->assertInstanceOf(Carbon::class, $invoice->due_date);
        $this->assertInstanceOf(Carbon::class, $invoice->paid_at);
        $this->assertEquals('2024-12-31', $invoice->due_date->format('Y-m-d'));
    }

    /**
     * Test helper methods.
     */
    public function test_invoice_helper_methods(): void
    {
        $paidInvoice = Invoice::factory()->paid()->make();
        $this->assertTrue($paidInvoice->isPaid());
        $this->assertFalse($paidInvoice->isOverdue());

        $overdueInvoice = Invoice::factory()->overdue()->make();
        $this->assertTrue($overdueInvoice->isOverdue());
        $this->assertFalse($overdueInvoice->isPaid());
    }

    /**
     * Test query scopes.
     */
    public function test_invoice_query_scopes(): void
    {
        Invoice::factory()->count(2)->paid()->create();
        Invoice::factory()->count(3)->overdue()->create();
        Invoice::factory()->count(1)->create(['status' => 'pending']);

        $this->assertEquals(2, Invoice::paid()->count());
        $this->assertEquals(3, Invoice::overdue()->count());
        $this->assertEquals(1, Invoice::pending()->count());
    }

    /**
     * Test soft deletes.
     */
    public function test_invoice_soft_deletes(): void
    {
        $invoice = Invoice::factory()->create();

        $invoice->delete();

        $this->assertSoftDeleted($invoice);
        $this->assertNotNull($invoice->deleted_at);
        $this->assertEquals(0, Invoice::count());
        $this->assertEquals(1, Invoice::withTrashed()->count());
    }
}
