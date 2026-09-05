<?php

namespace Database\Factories;

use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroDiario>
 */
class RegistroDiarioFactory extends Factory
{
    /**
     * An open day: the closure figures are filled in by DailyClosureService when
     * the user closes the day, so they stay null here. A closed day is a frozen
     * snapshot that rejects new records — use the cerrado() state for it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_id' => User::factory(),
            'fecha' => fake()->unique()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'calorias_objetivo_dia' => null,
            'calorias_consumidas' => null,
            'calorias_actividad_ajustada' => null,
            'deficit_diario' => null,
            'proteina_objetivo_g' => null,
            'proteina_consumida_g' => null,
            'cerrado' => false,
            'cerrado_en' => null,
        ];
    }

    /**
     * A day already closed, with plausible closure figures.
     */
    public function cerrado(): static
    {
        return $this->state(function () {
            $caloriasObjetivo = fake()->randomFloat(2, 1200, 3000);
            $caloriasConsumidas = fake()->randomFloat(2, 1000, 3200);
            $caloriasActividad = fake()->randomFloat(2, 0, 600);
            $proteinaObjetivo = fake()->randomFloat(2, 100, 200);

            return [
                'calorias_objetivo_dia' => $caloriasObjetivo,
                'calorias_consumidas' => $caloriasConsumidas,
                'calorias_actividad_ajustada' => $caloriasActividad,
                'deficit_diario' => round($caloriasObjetivo - $caloriasConsumidas + $caloriasActividad, 2),
                'proteina_objetivo_g' => $proteinaObjetivo,
                'proteina_consumida_g' => fake()->randomFloat(2, 50, $proteinaObjetivo),
                'cerrado' => true,
                'cerrado_en' => now(),
            ];
        });
    }
}
