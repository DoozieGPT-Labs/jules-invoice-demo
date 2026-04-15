<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_invoice_with_all_fields_populated(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertNotNull($invoice->user_id);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->customer_name);
        $this->assertNotNull($invoice->customer_email);
        $this->assertNotNull($invoice->amount);
        $this->assertNotNull($invoice->status);
        $this->assertNotNull($invoice->due_date);
    }

    public function test_invoice_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $invoice->user);
        $this->assertEquals($user->id, $invoice->user->id);
    }

    public function test_scope_pending_returns_only_pending_invoices(): void
    {
        Invoice::factory()->create(['status' => 'pending']);
        Invoice::factory()->create(['status' => 'paid']);
        Invoice::factory()->create(['status' => 'overdue']);

        $pendingInvoices = Invoice::pending()->get();

        $this->assertCount(1, $pendingInvoices);
        $this->assertEquals('pending', $pendingInvoices->first()->status);
    }

    public function test_scope_paid_returns_only_paid_invoices(): void
    {
        Invoice::factory()->create(['status' => 'pending']);
        Invoice::factory()->create(['status' => 'paid']);
        Invoice::factory()->create(['status' => 'overdue']);

        $paidInvoices = Invoice::paid()->get();

        $this->assertCount(1, $paidInvoices);
        $this->assertEquals('paid', $paidInvoices->first()->status);
    }

    public function test_scope_overdue_returns_only_overdue_invoices(): void
    {
        // Not overdue (pending but future)
        Invoice::factory()->create(['status' => 'pending', 'due_date' => now()->addDay()]);
        // Overdue (pending and past)
        Invoice::factory()->create(['status' => 'pending', 'due_date' => now()->subDay()]);
        // Not overdue (paid and past)
        Invoice::factory()->create(['status' => 'paid', 'due_date' => now()->subDay()]);

        $overdueInvoices = Invoice::overdue()->get();

        $this->assertCount(1, $overdueInvoices);
        $this->assertEquals('pending', $overdueInvoices->first()->status);
        $this->assertTrue($overdueInvoices->first()->due_date->isPast());
    }

    public function test_is_overdue_returns_true_for_past_due_date(): void
    {
        $invoice = Invoice::factory()->make([
            'status' => 'pending',
            'due_date' => now()->subDay(),
        ]);

        $this->assertTrue($invoice->isOverdue());
    }

    public function test_is_overdue_returns_false_for_future_due_date(): void
    {
        $invoice = Invoice::factory()->make([
            'status' => 'pending',
            'due_date' => now()->addDay(),
        ]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_is_overdue_returns_false_if_paid_even_if_past_due_date(): void
    {
        $invoice = Invoice::factory()->make([
            'status' => 'paid',
            'due_date' => now()->subDay(),
        ]);

        $this->assertFalse($invoice->isOverdue());
    }
}
