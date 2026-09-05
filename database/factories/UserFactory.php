<?php

namespace Database\Factories;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\User;
use App\Services\NutritionCalculatorService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pesoKg = fake()->randomFloat(2, 50, 120);
        $nivelActividad = fake()->randomFloat(3, 1.2, 1.725);
        $tipoDeficit = fake()->randomElement(['porcentaje', 'fijo']);
        $proteinaFactor = fake()->randomFloat(2, 1.6, 2.2);
        $grasaFactor = fake()->randomFloat(2, 0.6, 1.0);

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'peso_kg' => $pesoKg,
            'estatura_m' => fake()->randomFloat(2, 1.50, 2.00),
            'edad' => fake()->numberBetween(18, 65),
            'sexo' => fake()->randomElement(['masculino', 'femenino']),
            'nivel_actividad' => $nivelActividad,
            'tipo_deficit' => $tipoDeficit,
            'valor_deficit' => $tipoDeficit === 'porcentaje'
                ? fake()->randomFloat(2, 0.1, 0.25)
                : fake()->randomFloat(2, 200, 500),
            'proteina_factor' => $proteinaFactor,
            'grasa_factor' => $grasaFactor,
            'calorias_objetivo' => fn (array $atributos) => $this->objetivoCoherenteCon($atributos),
        ];
    }

    /**
     * calorias_objetivo derivado de los propios parámetros del usuario en vez de
     * un número aleatorio suelto: es el objetivo vigente que usan el plan y el
     * cierre (CLAUDE.md sección 4.10), así que un fixture cuyo objetivo no cuadre
     * con su peso y su déficit produciría planes que no corresponden al perfil.
     *
     * Se evalúa como closure para que vea también los atributos sobrescritos en
     * la llamada — `factory()->create(['peso_kg' => 80, ...])` debe derivar el
     * objetivo de esos 80 kg, no del peso aleatorio de la definición.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function objetivoCoherenteCon(array $atributos): ?float
    {
        try {
            return round(app(NutritionCalculatorService::class)->calculatePlan(
                (float) ($atributos['peso_kg'] ?? 0),
                (float) ($atributos['nivel_actividad'] ?? 0),
                (string) ($atributos['tipo_deficit'] ?? ''),
                (float) ($atributos['valor_deficit'] ?? 0),
                (float) ($atributos['proteina_factor'] ?? 0),
                (float) ($atributos['grasa_factor'] ?? 0),
            )['calorias_objetivo'], 2);
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException) {
            // Perfil sin objetivo calculable (parámetros nulos, o macros que no
            // caben en el objetivo): el mismo estado en el que queda un usuario
            // recién registrado que todavía no completó su perfil.
            return null;
        }
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
