<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly CuentaService $cuentas,
    ) {}

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        /*
         * La cuenta entra directa y con su prueba de Premium ya corriendo
         * (CLAUDE.md secciones 5.1 y 5.18). No hay pantalla intermedia de
         * "elige tu plan": los días de prueba (config/planes.php) son el
         * comportamiento por defecto del registro, no una elección aparte, y el
         * botón "Probar X días gratis" de la landing es solo el refuerzo visual
         * de lo que ya va a pasar.
         *
         * Va a la Calculadora y no al panel de inicio porque es el primer paso
         * del flujo (sección 5.2): sin objetivo calórico no hay nada que el
         * panel pueda enseñarle todavía.
         */
        $user = $this->cuentas->registrar([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('calculadora.edit', absolute: false))->with('status', 'prueba-iniciada');
    }
}
