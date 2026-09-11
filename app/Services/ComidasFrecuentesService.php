<?php

namespace App\Services;

use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Tu desayuno habitual" (CLAUDE.md sección 5.22): las comidas que el usuario
 * repite, ofrecidas como atajo al cerrar esa misma comida.
 *
 * ── Qué problema resuelve ──────────────────────────────────────────────────
 *
 * Casi nadie desayuna algo distinto cada día. Sin esto, quien repite su
 * desayuno seis días de siete vuelve a escribirlo (o a dictarlo) seis veces, y
 * cada una cuesta una llamada facturable al proveedor para obtener los mismos
 * macros que ya se calcularon el lunes. Con esto es un botón: se copian los
 * macros del reporte anterior y no se llama a nadie — ataca el coste de la
 * sección 5.20 y quita trabajo al usuario a la vez.
 *
 * ── Cómo se agrupan ────────────────────────────────────────────────────────
 *
 * Por lo que el usuario ve, que es la nota del reporte (lo que contó que comió)
 * o, si cumplió lo sugerido, la descripción del plan. Se normaliza en PHP
 * —minúsculas, sin espacios de más, sin el detalle que el modelo añade después
 * de un guion— y se cuenta cuántas veces aparece. Nada de esto se hace en SQL:
 * son como mucho unas decenas de filas por usuario y la normalización con
 * acentos no es cosa de una consulta portable entre MySQL y SQLite (sección
 * 5.7, mismo criterio que el promedio móvil).
 *
 * No persiste nada ni inventa una tabla de plantillas: la plantilla ES el
 * reporte anterior, y repetirla es copiar sus macros.
 */
class ComidasFrecuentesService
{
    /**
     * Ventana de historial en la que se busca. Un mes captura lo que alguien
     * come de forma habitual sin arrastrar lo que dejó de comer hace tiempo.
     */
    public const DIAS_HISTORIAL = 30;

    /**
     * Cuántas veces tiene que haberse repetido una comida para ofrecerla. Con
     * una sola vez, la lista sería el historial entero y no un atajo.
     */
    public const REPETICIONES_MINIMAS = 2;

    /** Cuántas se ofrecen por comida. Más de tres ya es una pantalla, no un atajo. */
    public const MAXIMO_SUGERENCIAS = 3;

    /**
     * Las comidas que este usuario repite para ese tipo de comida, de la más
     * repetida a la menos.
     *
     * @return Collection<int, array{comida_real_id: int, etiqueta: string, veces: int, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>
     */
    public function paraComida(User $usuario, string $tipoComida): Collection
    {
        $reportes = $this->historial($usuario, $tipoComida);

        if ($reportes->isEmpty()) {
            return collect();
        }

        return $reportes
            ->groupBy(fn (ComidaReal $comida): string => $this->clave($comida))
            ->reject(fn (Collection $grupo): bool => $grupo->count() < self::REPETICIONES_MINIMAS)
            ->map(function (Collection $grupo): array {
                /** @var ComidaReal $ultima */
                $ultima = $grupo->first();

                return [
                    'comida_real_id' => $ultima->id,
                    'etiqueta' => $this->etiqueta($ultima),
                    'veces' => $grupo->count(),
                    'calorias' => (float) $ultima->calorias_reales,
                    'proteina_g' => (float) $ultima->proteina_g,
                    'grasa_g' => (float) $ultima->grasa_g,
                    'carbohidratos_g' => (float) $ultima->carbohidratos_g,
                ];
            })
            ->sortByDesc('veces')
            ->take(self::MAXIMO_SUGERENCIAS)
            ->values();
    }

    /**
     * Si el texto del campo sigue siendo, palabra por palabra, la comida que se
     * copió desde "Lo que sueles comer".
     *
     * El atajo solo escribe ese texto en el campo: mientras el usuario no lo
     * toque, lo que va a reportar es exactamente la comida de la que ya se
     * calcularon los macros, así que se copian y no se llama a nadie. En cuanto
     * lo edita —"lo mismo pero con dos huevos"— deja de ser la misma comida y
     * manda lo que escribió.
     */
    public function coincideCon(ComidaReal $comida, string $texto): bool
    {
        return $this->normalizar($texto) === $this->clave($comida);
    }

    /**
     * Un reporte concreto del usuario, para copiar sus macros al repetirlo.
     *
     * La comprobación de propiedad vive aquí y no solo en el controlador porque
     * este es el único camino por el que se copia una ComidaReal de un día a
     * otro: sin ella, un id ajeno traería los macros de otra persona.
     */
    public function deUsuario(User $usuario, int $comidaRealId): ?ComidaReal
    {
        return ComidaReal::where('id', $comidaRealId)
            ->whereIn('plan_comida_id', $this->planesDelUsuario($usuario))
            ->first();
    }

    /**
     * Los reportes de ese tipo de comida en la ventana, del más reciente al más
     * antiguo — el orden importa: el primero de cada grupo es el que se copia.
     *
     * @return Collection<int, ComidaReal>
     */
    private function historial(User $usuario, string $tipoComida): Collection
    {
        return ComidaReal::whereIn(
            'plan_comida_id',
            $this->planesDelUsuario($usuario)->where('tipo_comida', $tipoComida),
        )
            ->with('planComida')
            ->where('consumido_en', '>=', now()->subDays(self::DIAS_HISTORIAL)->startOfDay())
            ->orderByDesc('consumido_en')
            ->get();
    }

    /**
     * @return Builder<PlanComida>
     */
    private function planesDelUsuario(User $usuario)
    {
        return PlanComida::select('id')->whereHas(
            'registroDiario',
            fn ($consulta) => $consulta->where('usuario_id', $usuario->id),
        );
    }

    /**
     * Con qué texto se agrupan dos reportes como "la misma comida".
     */
    private function clave(ComidaReal $comida): string
    {
        return $this->normalizar($this->etiqueta($comida));
    }

    /**
     * Con qué forma se compara un texto con otro: en minúsculas, sin signos y
     * sin espacios de más — "arepa con huevo" y "Arepa con huevo." son la misma
     * comida para quien la come.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    /**
     * Cómo se llama esa comida en pantalla: lo que el usuario contó, sin el
     * detalle que el modelo añade detrás de un guion, o la descripción del plan
     * cuando dijo que lo había cumplido.
     */
    public function etiqueta(ComidaReal $comida): string
    {
        $notas = trim((string) $comida->notas);

        if ($notas !== '' && $notas !== __('Cumplí con lo sugerido.')) {
            $notas = trim(explode(' — ', $notas)[0]);
        } else {
            $notas = trim((string) ($comida->planComida?->descripcion ?? ''));
        }

        return mb_substr($notas !== '' ? $notas : __('Comida sin descripción'), 0, 80);
    }
}
