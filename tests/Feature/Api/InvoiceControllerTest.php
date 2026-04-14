<?php

namespace Tests\Feature\Api;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_can_list_invoices(): void
    {
        Invoice::factory()->count(5)->create();

        $response = $this->getJson('/api/invoices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'invoice_number',
                        'customer_name',
                        'customer_email',
                        'amount',
                        'status',
                        'due_date',
                        'paid_at',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
                'links',
            ]);
    }

    public function test_can_show_invoice(): void
    {
        $invoice = Invoice::factory()->create();

        $response = $this->getJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);
    }

    public function test_can_create_invoice(): void
    {
        $data = [
            'invoice_number' => 'INV-2024-001',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => '150.50',
            'status' => 'pending',
            'due_date' => now()->addMonth()->startOfDay()->toISOString(),
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(201);

        $response->assertJsonFragment([
            'invoice_number' => 'INV-2024-001',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'amount' => '150.50',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('invoices', [
            'invoice_number' => 'INV-2024-001',
        ]);
    }

    public function test_create_invoice_validation_fails(): void
    {
        $data = [
            'invoice_number' => '', // Empty
            'customer_name' => '', // Empty
            'customer_email' => 'invalid-email', // Invalid email
            'amount' => -10, // Invalid amount
            'status' => 'invalid-status', // Invalid status
            'due_date' => 'not-a-date', // Invalid date
        ];

        $response = $this->postJson('/api/invoices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'invoice_number',
                'customer_name',
                'customer_email',
                'amount',
                'status',
                'due_date',
            ]);
    }

    public function test_can_update_invoice(): void
    {
        $invoice = Invoice::factory()->create();

        $data = [
            'customer_name' => 'Jane Doe Updated',
            'amount' => '200.00',
        ];

        $response = $this->putJson("/api/invoices/{$invoice->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment($data);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_name' => 'Jane Doe Updated',
            'amount' => 200.00,
        ]);
    }

    public function test_can_soft_delete_invoice(): void
    {
        $invoice = Invoice::factory()->create();

        $response = $this->deleteJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('invoices', [
            'id' => $invoice->id,
        ]);
    }
}
