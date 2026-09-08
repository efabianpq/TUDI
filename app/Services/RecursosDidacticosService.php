<?php

namespace App\Services;

use App\Models\RecursoDidactico;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Material de apoyo que el administrador publica desde la consola (CLAUDE.md
 * sección 5.15): hoy, el video que explica la Calculadora Déficit y la guía en
 * PDF con el detalle de macronutrientes.
 *
 * ── Por qué no es un parámetro maestro ─────────────────────────────────────
 *
 * ParametrosMaestrosService declara umbrales numéricos con mínimo, máximo y
 * unidad, y su catálogo referencia constantes de los servicios que los
 * consumen. Esto es contenido: uno de los dos valores es un archivo subido, y
 * meter `Storage` en aquel catálogo lo convertiría en otra cosa. Misma forma
 * (clave/valor, una fila solo cuando hay algo publicado), tabla y servicio
 * aparte.
 *
 * ── Video incrustado, no alojado ───────────────────────────────────────────
 *
 * El video se publica como URL y se incrusta. Servir un MP4 desde el hosting
 * compartido ocuparía un worker de PHP-FPM por espectador y por reproducción
 * (sección 5.13), que es exactamente lo que el resto de la aplicación evita.
 * El PDF sí se sube: pesa poco y se descarga una vez.
 *
 * ── Coste de lectura ───────────────────────────────────────────────────────
 *
 * Igual que los parámetros maestros: se cachean juntos y para siempre, y
 * guardar invalida la entrada. Si la tabla todavía no existe (despliegue antes
 * de `migrate`), se comporta como si no hubiera nada publicado en vez de tumbar
 * la Calculadora.
 */
class RecursosDidacticosService
{
    public const CACHE_CLAVE = 'recursos_didacticos';

    /**
     * Tamaño máximo del PDF, en kilobytes. Lo consume el Form Request; vive
     * aquí porque es una decisión sobre el recurso, no sobre el formulario.
     */
    public const PDF_MAX_KB = 20480;

    private const DISCO = 'public';

    private const DIRECTORIO = 'recursos';

    /**
     * La única declaración de qué recursos existen.
     *
     * @var array<string, array{grupo: string, etiqueta: string, ayuda: string, tipo: string}>
     */
    public const CATALOGO = [
        'calculadora_video_url' => [
            'grupo' => 'Calculadora Déficit',
            'etiqueta' => 'Video explicativo',
            'ayuda' => 'Se incrusta junto a "Tu objetivo diario". Pega el enlace de YouTube o Vimeo.',
            'tipo' => RecursoDidactico::TIPO_URL,
        ],
        'calculadora_guia_pdf' => [
            'grupo' => 'Calculadora Déficit',
            'etiqueta' => 'Guía en PDF',
            'ayuda' => 'El usuario la descarga desde la Calculadora. Máximo 20 MB.',
            'tipo' => RecursoDidactico::TIPO_ARCHIVO,
        ],
    ];

    /**
     * Todos los recursos publicados, indexados por clave. Las claves del
     * catálogo que nadie ha publicado no aparecen.
     *
     * @return array<string, array{tipo: string, valor: string, nombre_original: string|null, url: string}>
     */
    public function todos(): array
    {
        return Cache::rememberForever(self::CACHE_CLAVE, function (): array {
            try {
                $filas = RecursoDidactico::all();
            } catch (QueryException $e) {
                // La tabla todavía no existe: un despliegue que hizo `git pull`
                // pero aún no `migrate` no puede quedarse sin Calculadora.
                Log::warning('recursos_didacticos no disponible, se sirve sin material de apoyo.', [
                    'excepcion' => $e->getMessage(),
                ]);

                return [];
            }

            $recursos = [];

            foreach ($filas as $fila) {
                if (! array_key_exists($fila->clave, self::CATALOGO)) {
                    continue;
                }

                $recursos[$fila->clave] = [
                    'tipo' => $fila->tipo,
                    'valor' => $fila->valor,
                    'nombre_original' => $fila->nombre_original,
                    'url' => $fila->tipo === RecursoDidactico::TIPO_ARCHIVO
                        ? Storage::disk(self::DISCO)->url($fila->valor)
                        : $fila->valor,
                ];
            }

            return $recursos;
        });
    }

    /**
     * Un recurso publicado, o null si no lo está.
     *
     * @return array{tipo: string, valor: string, nombre_original: string|null, url: string}|null
     */
    public function recurso(string $clave): ?array
    {
        $this->exigirDelCatalogo($clave);

        return $this->todos()[$clave] ?? null;
    }

    /**
     * URL para incrustar un video de YouTube o Vimeo en un iframe, o null si la
     * URL publicada no es de ninguno de los dos.
     *
     * Se traduce aquí y no en la vista porque es lógica, no presentación: qué
     * forma tiene la URL de reproducción de cada proveedor.
     */
    public function urlIncrustable(string $clave): ?string
    {
        $recurso = $this->recurso($clave);

        if ($recurso === null || $recurso['tipo'] !== RecursoDidactico::TIPO_URL) {
            return null;
        }

        $url = $recurso['url'];

        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $coincidencias) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$coincidencias[1];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $coincidencias) === 1) {
            return 'https://player.vimeo.com/video/'.$coincidencias[1];
        }

        return null;
    }

    /**
     * Publica una URL. Guardar una cadena vacía retira el recurso.
     */
    public function guardarUrl(string $clave, ?string $url, ?int $usuarioId = null): void
    {
        $this->exigirTipo($clave, RecursoDidactico::TIPO_URL);

        $url = trim((string) $url);

        if ($url === '') {
            $this->retirar($clave);

            return;
        }

        RecursoDidactico::updateOrCreate(
            ['clave' => $clave],
            [
                'tipo' => RecursoDidactico::TIPO_URL,
                'valor' => $url,
                'nombre_original' => null,
                'actualizado_por' => $usuarioId,
            ],
        );

        $this->olvidarCache();
    }

    /**
     * Publica un archivo, reemplazando —y borrando del disco— el anterior.
     */
    public function guardarArchivo(string $clave, UploadedFile $archivo, ?int $usuarioId = null): void
    {
        $this->exigirTipo($clave, RecursoDidactico::TIPO_ARCHIVO);

        $anterior = RecursoDidactico::where('clave', $clave)->first();

        $ruta = $archivo->store(self::DIRECTORIO, self::DISCO);

        RecursoDidactico::updateOrCreate(
            ['clave' => $clave],
            [
                'tipo' => RecursoDidactico::TIPO_ARCHIVO,
                'valor' => $ruta,
                'nombre_original' => $archivo->getClientOriginalName(),
                'actualizado_por' => $usuarioId,
            ],
        );

        if ($anterior?->tipo === RecursoDidactico::TIPO_ARCHIVO && $anterior->valor !== $ruta) {
            Storage::disk(self::DISCO)->delete($anterior->valor);
        }

        $this->olvidarCache();
    }

    /**
     * Retira un recurso: borra su fila y, si era un archivo, el archivo.
     */
    public function retirar(string $clave): void
    {
        $this->exigirDelCatalogo($clave);

        $recurso = RecursoDidactico::where('clave', $clave)->first();

        if ($recurso === null) {
            return;
        }

        if ($recurso->tipo === RecursoDidactico::TIPO_ARCHIVO) {
            Storage::disk(self::DISCO)->delete($recurso->valor);
        }

        $recurso->delete();

        $this->olvidarCache();
    }

    public function olvidarCache(): void
    {
        Cache::forget(self::CACHE_CLAVE);
    }

    private function exigirDelCatalogo(string $clave): void
    {
        if (! array_key_exists($clave, self::CATALOGO)) {
            throw new InvalidArgumentException("Recurso didáctico desconocido: {$clave}");
        }
    }

    private function exigirTipo(string $clave, string $tipo): void
    {
        $this->exigirDelCatalogo($clave);

        if (self::CATALOGO[$clave]['tipo'] !== $tipo) {
            throw new InvalidArgumentException("El recurso {$clave} no es de tipo {$tipo}.");
        }
    }
}
