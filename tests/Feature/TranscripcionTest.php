<?php

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Models\User;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Http\UploadedFile;

/**
 * Plan B del dictado por voz (CLAUDE.md sección 5.9): grabar y transcribir en
 * el servidor.
 *
 * Está apagado por defecto porque cada llamada se factura al proveedor, así que
 * casi todos estos tests lo encienden a mano: comprueban que el endpoint sigue
 * siendo correcto para el despliegue que decida pagarlo. El último comprueba lo
 * contrario — que apagado no transcribe nada.
 */
beforeEach(function () {
    config(['services.transcripcion.fallback_servidor' => true]);
});

/**
 * Un WAV mínimo pero válido: la validación `mimetypes` mira el contenido real
 * del archivo, no la extensión, así que un archivo falso no serviría.
 */
function audioDePrueba(): UploadedFile
{
    $muestras = str_repeat("\x00\x00", 800);
    $datos = 'data'.pack('V', strlen($muestras)).$muestras;
    $formato = 'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16);
    $cuerpo = 'WAVE'.$formato.$datos;

    $ruta = tempnam(sys_get_temp_dir(), 'tudi').'.wav';
    file_put_contents($ruta, 'RIFF'.pack('V', strlen($cuerpo)).$cuerpo);

    return new UploadedFile($ruta, 'dictado.wav', 'audio/wav', null, true);
}

it('exige sesión iniciada', function () {
    $this->post(route('transcribir'), ['audio' => audioDePrueba()])
        ->assertRedirect(route('login'));
});

it('devuelve el texto dictado', function () {
    $this->mock(TranscripcionAudioProviderInterface::class)
        ->shouldReceive('transcribir')
        ->once()
        ->andReturn('dos huevos y media palta');

    $this->actingAs(User::factory()->create())
        ->postJson(route('transcribir'), ['audio' => audioDePrueba()])
        ->assertOk()
        ->assertExactJson(['texto' => 'dos huevos y media palta']);
});

it('traduce un fallo del proveedor a 422 con mensaje, nunca a un 500', function () {
    $this->mock(TranscripcionAudioProviderInterface::class)
        ->shouldReceive('transcribir')
        ->once()
        ->andThrow(TranscripcionNoDisponibleException::sinVoz());

    $this->actingAs(User::factory()->create())
        ->postJson(route('transcribir'), ['audio' => audioDePrueba()])
        ->assertStatus(422)
        ->assertJsonPath('error', fn (string $error) => str_contains($error, 'No se escuchó nada'));
});

it('rechaza una petición sin audio', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('transcribir'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audio');
});

it('rechaza un archivo que no es audio', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('transcribir'), ['audio' => UploadedFile::fake()->image('foto.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audio');
});

it('no transcribe nada cuando el plan B del servidor está apagado', function () {
    // Es el estado por defecto: el reconocimiento nativo del navegador no
    // cuesta nada y este endpoint sí, así que apagado ni siquiera llama al
    // proveedor (CLAUDE.md sección 5.9).
    config(['services.transcripcion.fallback_servidor' => false]);

    $this->mock(TranscripcionAudioProviderInterface::class)
        ->shouldNotReceive('transcribir');

    $this->actingAs(User::factory()->create())
        ->post(route('transcribir'), ['audio' => audioDePrueba()])
        ->assertStatus(422)
        ->assertJsonPath('error', 'El dictado por voz lo resuelve tu navegador. Si el micrófono no funciona aquí, escríbelo a mano.');
});
