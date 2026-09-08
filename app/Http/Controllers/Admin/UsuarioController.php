<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarUsuarioRequest;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Gestión de usuarios de la consola de administración (CLAUDE.md sección 4.26).
 *
 * Controlador delgado, como el resto (sección 3): busca, pagina y delega en
 * CuentaService, que es quien sabe activar, suspender y regenerar códigos.
 */
class UsuarioController extends Controller
{
    private const USUARIOS_POR_PAGINA = 25;

    public function __construct(
        private readonly CuentaService $cuentas,
    ) {}

    /**
     * Portada de la consola: lo que hay pendiente de atender.
     */
    public function inicio(): View
    {
        return view('admin.inicio', [
            'pendientes' => User::where('estado', User::ESTADO_PENDIENTE)
                ->orderByDesc('created_at')
                ->get(),
            'totales' => [
                'usuarios' => User::count(),
                'activos' => User::where('estado', User::ESTADO_ACTIVO)->count(),
                'pendientes' => User::where('estado', User::ESTADO_PENDIENTE)->count(),
                'suspendidos' => User::where('estado', User::ESTADO_SUSPENDIDO)->count(),
                'administradores' => User::where('rol', User::ROL_ADMIN)->count(),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        return view('admin.usuarios.index', [
            'usuarios' => $this->listado($request),
            'busqueda' => (string) $request->query('q', ''),
            'estado' => (string) $request->query('estado', ''),
        ]);
    }

    /**
     * Cambia rol y/o estado. Las tres transiciones pasan por CuentaService para
     * que el correo de activación y el quemado del código no se queden fuera si
     * algún día se cambia el flujo.
     */
    public function update(ActualizarUsuarioRequest $request, User $usuario): RedirectResponse
    {
        $datos = $request->validated();

        if (isset($datos['rol'])) {
            $usuario->forceFill(['rol' => $datos['rol']])->save();
        }

        if (isset($datos['estado']) && $datos['estado'] !== $usuario->estado) {
            match ($datos['estado']) {
                User::ESTADO_ACTIVO => $this->cuentas->activar($usuario),
                User::ESTADO_SUSPENDIDO => $this->cuentas->suspender($usuario),
                User::ESTADO_PENDIENTE => $this->cuentas->regenerarCodigo($usuario),
            };
        }

        return Redirect::back()->with('status', 'usuario-actualizado');
    }

    /**
     * Genera un código nuevo: el anterior deja de servir.
     */
    public function regenerarCodigo(Request $request, User $usuario): RedirectResponse
    {
        $this->noSobreUnoMismo($request, $usuario);

        $codigo = $this->cuentas->regenerarCodigo($usuario);

        return Redirect::back()
            ->with('status', 'codigo-regenerado')
            ->with('codigo-generado', $codigo);
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        $this->noSobreUnoMismo($request, $usuario);

        // Las FKs de todo el dominio son cascade (sección 4): borrar al usuario
        // se lleva sus registros diarios, planes, comidas y métricas.
        $usuario->delete();

        return Redirect::route('admin.usuarios.index')->with('status', 'usuario-eliminado');
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function listado(Request $request): LengthAwarePaginator
    {
        return User::query()
            ->when($request->filled('q'), function ($consulta) use ($request) {
                $termino = '%'.$request->query('q').'%';

                $consulta->where(fn ($sub) => $sub->where('name', 'like', $termino)
                    ->orWhere('email', 'like', $termino)
                    ->orWhere('codigo_activacion', 'like', $termino));
            })
            ->when($request->filled('estado'), fn ($consulta) => $consulta->where('estado', $request->query('estado')))
            // Lo pendiente primero: es lo que el administrador viene a resolver.
            ->orderByRaw("case when estado = 'pendiente' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(self::USUARIOS_POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Un administrador no puede suspenderse, degradarse ni borrarse a sí mismo:
     * es la forma más fácil de quedarse sin ninguna consola accesible.
     */
    private function noSobreUnoMismo(Request $request, User $usuario): void
    {
        abort_if($usuario->is($request->user()), 403, 'No puedes hacer eso sobre tu propia cuenta.');
    }
}
