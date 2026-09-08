<?php

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Models\User;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Http\UploadedFile;

/**
 * Dictado por voz con transcripción en el servidor (CLAUDE.md sección 4.21):
 * el plan B para Safari de iOS, donde la Web Speech API pide el micrófono y
 * nunca emite un resultado.
 */

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
