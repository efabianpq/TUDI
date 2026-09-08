<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cambio de rol y/o estado de una cuenta desde la consola (CLAUDE.md sección
 * 4.26).
 *
 * La guarda de "no sobre uno mismo" vive aquí y no solo en el controlador
 * porque es una regla de la petición, no del flujo: un administrador que se
 * suspende o se degrada a sí mismo deja el sistema sin consola accesible.
 */
class ActualizarUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rol' => ['nullable', 'in:'.User::ROL_USUARIO.','.User::ROL_ADMIN],
            'estado' => ['nullable', 'in:'.implode(',', [
                User::ESTADO_PENDIENTE,
                User::ESTADO_ACTIVO,
                User::ESTADO_SUSPENDIDO,
            ])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador) {
                /** @var User $objetivo */
                $objetivo = $this->route('usuario');

                if (! $objetivo->is($this->user())) {
                    return;
                }

                if ($this->input('rol') === User::ROL_USUARIO) {
                    $validador->errors()->add('rol', 'No puedes quitarte a ti mismo el rol de administrador.');
                }

                if ($this->filled('estado') && $this->input('estado') !== User::ESTADO_ACTIVO) {
                    $validador->errors()->add('estado', 'No puedes desactivar tu propia cuenta.');
                }
            },
        ];
    }
}
