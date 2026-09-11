# Logo TUDI — variante Premium

Cinco archivos, todos con el mismo isotipo (anillo de progreso con el corte del déficit):

| Archivo | Uso |
| --- | --- |
| `tudi-logo.svg` | Logotipo por defecto, sobre fondo claro (crema `#EFE9DE`). |
| `tudi-logo-premium.svg` | Con el sufijo "Premium" en lima oscuro `#5C6B14`, sobre fondo claro. |
| `tudi-logo-dark.svg` | Por defecto, sobre fondo carbón. |
| `tudi-logo-premium-dark.svg` | Premium, sobre fondo carbón — "Premium" en lima `#C8F03C`. |
| `tudi-mark.svg` | Solo el isotipo (ícono de app, favicon, avatar). |

El sufijo "Premium" sigue el patrón de YouTube: el logotipo no cambia, se le añade la palabra a la derecha en el color de acento. Nunca al revés, nunca en otro color, nunca sin el logotipo.

**Los SVG usan `<text>` con Instrument Sans**, así que la fuente debe estar cargada en la página (ya lo está en el layout). Si necesitas el logo en un contexto sin esa fuente — un correo, un favicon, un PDF externo — conviértelo a trazos antes de usarlo.

## Cómo implementarlo en Laravel

### 1. Copia los SVG

```
public/img/brand/tudi-logo.svg
public/img/brand/tudi-logo-premium.svg
public/img/brand/tudi-logo-dark.svg
public/img/brand/tudi-logo-premium-dark.svg
public/img/brand/tudi-mark.svg
```

### 2. Un componente Blade que decide la variante

`resources/views/components/tudi-logo.blade.php`

```blade
@props(['dark' => false, 'height' => 32])

@php
    $premium = auth()->check() && auth()->user()->isPremium();
    $file = 'tudi-logo'
          . ($premium ? '-premium' : '')
          . ($dark ? '-dark' : '')
          . '.svg';
@endphp

<img src="{{ asset('img/brand/' . $file) }}"
     alt="{{ $premium ? 'tudi Premium' : 'tudi' }}"
     height="{{ $height }}"
     style="height: {{ $height }}px; width: auto; display: block;">
```

Ajusta `isPremium()` al método real del modelo `User`. Si aún no existe, añádelo:

```php
public function isPremium(): bool
{
    return $this->suscripcion?->activa() ?? false;
}
```

Mientras el cobro no esté abierto, ese método puede devolver `false` siempre — el componente ya queda listo para cuando se active.

### 3. Úsalo en la barra superior y la barra lateral

En `resources/views/layouts/navigation.blade.php`, reemplaza el logo actual:

```blade
<a href="{{ route('dashboard') }}">
    <x-tudi-logo :height="32" />
</a>
```

La barra lateral de escritorio usa la misma etiqueta. En paneles carbón:

```blade
<x-tudi-logo dark :height="28" />
```

### 4. Cachear la decisión

`isPremium()` se llama en cada render del layout. Si consulta la base de datos, memoriza el resultado en el modelo:

```php
public function isPremium(): bool
{
    return $this->premiumCache ??= ($this->suscripcion?->activa() ?? false);
}
```

### 5. El cambio debe ser inmediato al actualizar el plan

Cuando el usuario pase a Premium, refresca la sesión y redirige al dashboard con un mensaje. Nada de esperar al siguiente login: el logo es la confirmación visible de que el plan se activó.

## Qué no hacer

- No poner la palabra "Premium" en lima puro sobre crema: no tiene contraste. Sobre claro va `#5C6B14`.
- No añadir corona, estrella, brillo ni degradado. El sufijo es la única señal.
- No usar la variante Premium en la landing pública ni en pantallas de registro.
