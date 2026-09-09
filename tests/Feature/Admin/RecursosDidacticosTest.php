<?php

use App\Models\RecursoDidactico;
use App\Models\User;
use App\Services\NutritionCalculatorService;
use App\Services\RecursosDidacticosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Material de apoyo de la Calculadora (CLAUDE.md sección 5.15): lo publica el
 * administrador desde la consola y lo ve el usuario junto a su objetivo diario.
 */
beforeEach(function () {
    Storage::fake('public');
    app(RecursosDidacticosService::class)->olvidarCache();
});

function usuarioConCalculadora(): User
{
    return User::factory()->create([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        'valor_deficit' => 0.2,
        'proteina_factor' => 1.8,
        'grasa_factor' => 0.8,
        'calorias_objetivo' => 2112,
    ]);
}

it('cierra la pantalla de material de apoyo a quien no es administrador', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.recursos.edit'))->assertForbidden();
});

it('deja al administrador entrar y la enlaza desde la portada de la consola', function () {
    $admin = User::factory()->administradora()->create();

    $this->actingAs($admin)->get(route('admin.inicio'))
        ->assertOk()
        ->assertSee('Material de apoyo');

    $this->actingAs($admin)->get(route('admin.recursos.edit'))
        ->assertOk()
        ->assertSee('Video explicativo')
        ->assertSee('Guía en PDF');
});

it('avisa cuando el enlace guardado no se puede incrustar', function () {
    $admin = User::factory()->administradora()->create();

    app(RecursosDidacticosService::class)->guardarUrl('calculadora_video_url', 'https://ejemplo.test/video.mp4');

    $this->actingAs($admin)->get(route('admin.recursos.edit'))
        ->assertOk()
        ->assertSee('no es de YouTube ni de Vimeo');
});

it('rechaza un archivo que no es PDF', function () {
    $this->actingAs(User::factory()->administradora()->create())
        ->post(route('admin.recursos.update'), [
            'calculadora_guia_pdf' => UploadedFile::fake()->image('guia.png'),
        ])
        ->assertSessionHasErrors('calculadora_guia_pdf');
});

it('vaciar el campo del video lo retira', function () {
    $admin = User::factory()->administradora()->create();

    app(RecursosDidacticosService::class)->guardarUrl('calculadora_video_url', 'https://youtu.be/dQw4w9WgXcQ');

    $this->actingAs($admin)->post(route('admin.recursos.update'), ['calculadora_video_url' => '']);

    expect(RecursoDidactico::where('clave', 'calculadora_video_url')->count())->toBe(0);
});

it('publica el video como URL y la guía como archivo', function () {
    $admin = User::factory()->administradora()->create();

    $this->actingAs($admin)->post(route('admin.recursos.update'), [
        'calculadora_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'calculadora_guia_pdf' => UploadedFile::fake()->create('guia-tudi.pdf', 200, 'application/pdf'),
    ])->assertRedirect(route('admin.recursos.edit'))
        ->assertSessionHas('status', 'recursos-guardados');

    $video = RecursoDidactico::where('clave', 'calculadora_video_url')->first();
    $pdf = RecursoDidactico::where('clave', 'calculadora_guia_pdf')->first();

    expect($video->tipo)->toBe(RecursoDidactico::TIPO_URL)
        ->and($pdf->tipo)->toBe(RecursoDidactico::TIPO_ARCHIVO)
        ->and($pdf->nombre_original)->toBe('guia-tudi.pdf');

    Storage::disk('public')->assertExists($pdf->valor);
});

it('traduce el enlace de YouTube a una URL incrustable', function () {
    $servicio = app(RecursosDidacticosService::class);

    $servicio->guardarUrl('calculadora_video_url', 'https://youtu.be/dQw4w9WgXcQ');

    expect($servicio->urlIncrustable('calculadora_video_url'))
        ->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

it('no incrusta un enlace que no es de YouTube ni de Vimeo', function () {
    $servicio = app(RecursosDidacticosService::class);

    $servicio->guardarUrl('calculadora_video_url', 'https://ejemplo.test/video.mp4');

    expect($servicio->urlIncrustable('calculadora_video_url'))->toBeNull();
});

it('reemplazar la guía borra el archivo anterior del disco', function () {
    $servicio = app(RecursosDidacticosService::class);

    $servicio->guardarArchivo('calculadora_guia_pdf', UploadedFile::fake()->create('vieja.pdf', 10, 'application/pdf'));
    $rutaVieja = RecursoDidactico::where('clave', 'calculadora_guia_pdf')->value('valor');

    $servicio->guardarArchivo('calculadora_guia_pdf', UploadedFile::fake()->create('nueva.pdf', 10, 'application/pdf'));

    Storage::disk('public')->assertMissing($rutaVieja);
    Storage::disk('public')->assertExists(RecursoDidactico::where('clave', 'calculadora_guia_pdf')->value('valor'));
});

it('retirar un recurso borra su fila y su archivo', function () {
    $admin = User::factory()->administradora()->create();
    $servicio = app(RecursosDidacticosService::class);

    $servicio->guardarArchivo('calculadora_guia_pdf', UploadedFile::fake()->create('guia.pdf', 10, 'application/pdf'));
    $ruta = RecursoDidactico::where('clave', 'calculadora_guia_pdf')->value('valor');

    $this->actingAs($admin)
        ->delete(route('admin.recursos.destroy', 'calculadora_guia_pdf'))
        ->assertSessionHas('status', 'recurso-retirado');

    Storage::disk('public')->assertMissing($ruta);
    expect(RecursoDidactico::where('clave', 'calculadora_guia_pdf')->count())->toBe(0);
});

it('rechaza una clave de recurso que no está en el catálogo', function () {
    $this->actingAs(User::factory()->administradora()->create())
        ->delete(route('admin.recursos.destroy', 'inventada'))
        ->assertNotFound();
});

it('la calculadora enseña el video y la guía cuando están publicados', function () {
    $servicio = app(RecursosDidacticosService::class);
    $servicio->guardarUrl('calculadora_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    $servicio->guardarArchivo('calculadora_guia_pdf', UploadedFile::fake()->create('guia.pdf', 10, 'application/pdf'));

    $this->actingAs(usuarioConCalculadora())->get(route('calculadora.edit'))
        ->assertOk()
        // Dos botones dentro del panel del objetivo, no una tarjeta que
        // reordene la pantalla (CLAUDE.md sección 5.15).
        ->assertSee('Ver el video')
        ->assertSee('Guía en PDF')
        ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', escape: false)
        // El ancho de la Calculadora no cambia por publicar material.
        ->assertSee('mx-auto max-w-2xl space-y-5', escape: false);
});

it('la calculadora se ve igual que siempre si no hay nada publicado', function () {
    $this->actingAs(usuarioConCalculadora())->get(route('calculadora.edit'))
        ->assertOk()
        ->assertSee('Tu objetivo diario')
        ->assertDontSee('Ver el video')
        ->assertDontSee('Guía en PDF');
});
