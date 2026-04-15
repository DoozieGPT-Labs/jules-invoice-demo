<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'invoice_number' => 'INV-' . $this->faker->unique()->numberBetween(1000, 999999),
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'status' => $this->faker->randomElement(['pending', 'paid', 'overdue']),
            'due_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'paid_at' => null,
        ];
    }
}
