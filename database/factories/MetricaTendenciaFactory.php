<?php

namespace Database\Factories;

use App\Models\MetricaTendencia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricaTendencia>
 */
class MetricaTendenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'fecha' => fake()->unique()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'promedio_movil_peso_kg' => fake()->randomFloat(2, 50, 120),
            'promedio_movil_calorias' => fake()->randomFloat(2, 1200, 3000),
            'porcentaje_perdida_semanal' => fake()->randomFloat(2, -2, 2),
            'tendencia' => fake()->randomElement(['perdida_lenta', 'perdida_adecuada', 'perdida_rapida', 'estable', 'ganancia']),
        ];
    }
}
