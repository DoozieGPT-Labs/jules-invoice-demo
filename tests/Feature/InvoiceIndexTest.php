<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the invoice listing page loads and displays data.
     */
    public function test_invoice_index_page_loads_and_displays_data(): void
    {
        $invoice = Invoice::factory()->create([
            'invoice_number' => 'INV-001',
            'customer_name' => 'John Doe',
        ]);

        $response = $this->get('/invoices');

        $response->assertStatus(200);
        $response->assertSee('INV-001');
        $response->assertSee('John Doe');
    }

    /**
     * Test that pagination works on the invoice listing page.
     */
    public function test_invoice_index_pagination_works(): void
    {
        Invoice::factory()->count(15)->create();

        $response = $this->get('/invoices');

        $response->assertStatus(200);
        // Since we paginate by 10, we should see pagination links
        $response->assertSee('Next');
    }
}
