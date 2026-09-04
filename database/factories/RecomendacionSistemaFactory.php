<?php

namespace Database\Factories;

use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecomendacionSistema>
 */
class RecomendacionSistemaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registro_diario_id' => RegistroDiario::factory(),
            'tipo' => 'ajuste_calorico',
            'calorias_objetivo_sugeridas' => fake()->randomFloat(2, 1200, 3000),
            'justificacion' => fake()->sentence(12),
            'estado' => fake()->randomElement(['pendiente', 'confirmada', 'rechazada']),
            'confirmada_en' => fake()->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
