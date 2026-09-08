<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Arr;

/**
 * Acepta cifras decimales escritas como las escribe un hispanohablante.
 *
 * Los campos decimales de la aplicación (peso, estatura, factores de macros,
 * kcal) dejaron de ser `<input type="number">`: ese tipo reporta el valor vacío
 * mientras se está escribiendo un decimal y además valida `step` en el
 * navegador, lo que impedía teclear "1.72" o "70,5" desde el móvil. Ahora son
 * `inputmode="decimal"`, así que lo que llega al servidor es texto libre y
 * puede venir con coma decimal ("70,5") o con separador de millares ("1.234,5").
 *
 * Este trait lo normaliza a la notación que entiende la regla `numeric` de
 * Laravel (punto decimal, sin separador de millares) ANTES de validar, para que
 * la validación siga siendo la única que decide si el valor es aceptable — aquí
 * no se recorta ni se rechaza nada, solo se cambia la notación.
 */
trait NormalizaDecimales
{
    /**
     * Reescribe en notación con punto los campos indicados.
     *
     * Acepta notación de punto para campos anidados ("feedback.desayuno.peso").
     *
     * @param  array<int, string>  $campos
     */
    protected function normalizarDecimales(array $campos): void
    {
        $entrada = $this->all();
        $tocado = false;

        foreach ($campos as $campo) {
            if (! Arr::has($entrada, $campo)) {
                continue;
            }

            $valor = Arr::get($entrada, $campo);

            if (! is_string($valor)) {
                continue;
            }

            Arr::set($entrada, $campo, $this->aNotacionConPunto($valor));
            $tocado = true;
        }

        if ($tocado) {
            $this->replace($entrada);
        }
    }

    /**
     * "70,5" → "70.5"; "1.234,5" → "1234.5"; "1.72" → "1.72".
     *
     * La coma solo se interpreta como separador decimal cuando está presente;
     * si no hay coma, los puntos se dejan tal cual, porque en ese caso el punto
     * ya es el separador decimal ("1.72") y no un separador de millares.
     */
    private function aNotacionConPunto(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            return $valor;
        }

        if (str_contains($valor, ',')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        }

        // Espacios finos o duros que algunos teclados móviles insertan como
        // separador de millares.
        return str_replace([' ', "\u{00A0}", "\u{202F}"], '', $valor);
    }
}
