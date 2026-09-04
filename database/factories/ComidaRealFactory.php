<?php

namespace Database\Factories;

use App\Models\ComidaReal;
use App\Models\PlanComida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComidaReal>
 */
class ComidaRealFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_comida_id' => PlanComida::factory(),
            'calorias_reales' => fake()->randomFloat(2, 200, 900),
            'proteina_g' => fake()->randomFloat(2, 10, 60),
            'grasa_g' => fake()->randomFloat(2, 5, 40),
            'carbohidratos_g' => fake()->randomFloat(2, 10, 100),
            'consumido_en' => fake()->dateTimeBetween('-3 months', 'now'),
            'notas' => fake()->optional()->sentence(),
        ];
    }
}
