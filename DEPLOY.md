# Despliegue en Hostinger

Paso a paso concreto para publicar TUDéficit Inteligente en un hosting compartido/Business de Hostinger. Referencia cruzada: `CLAUDE.md` sección 10 ("Despliegue (Hostinger)").

## 1. Requisitos del plan de hosting

- PHP 8.3+ (el proyecto fija `"php": "^8.3"` en `composer.json`).
- MySQL 8.x / MariaDB 10.6+ (mínimo asumido en el código: MySQL 5.7 / MariaDB 10.1 — ver `CLAUDE.md` sección 4.7; ninguna consulta usa funciones de ventana ni CTEs).
- Acceso SSH o al menos un terminal/gestor de archivos con permiso para ejecutar `composer` y `php artisan` (Hostinger Business lo ofrece vía hPanel → "Avanzado → SSH Access").
- Un único cron job disponible (ver sección 4).

## 2. Estructura de carpetas en el servidor

Hostinger sirve el contenido de `public_html/` directamente; Laravel espera servir solo el contenido de `public/`. Dos formas válidas de resolverlo (elige una y sé consistente):

- **Opción A (recomendada):** sube todo el proyecto a una carpeta **fuera** de `public_html` (por ejemplo `~/tudeficit-app/`) y copia/enlaza únicamente el contenido de `public/` dentro de `public_html/`, ajustando en `public_html/index.php` las rutas `require` hacia `../tudeficit-app/vendor/autoload.php` y `../tudeficit-app/bootstrap/app.php`.
- **Opción B (más simple, aceptable en shared hosting):** sube todo el proyecto directamente a `public_html/` tal cual. Es menos prolijo (el código de la aplicación queda accesible por FTP junto al document root) pero no requiere editar `index.php`. Es la opción asumida en el resto de esta guía por simplicidad; si se usa la Opción A, ajusta las rutas de los comandos de la sección 3 en consecuencia.

En cualquier caso, `.env` **nunca** debe quedar dentro de una carpeta servida públicamente sin protección — con la Opción B, Laravel ya deniega el acceso directo a `.env` vía las reglas del `.htaccess` de la raíz del framework (no de `public/`), pero conviene confirmarlo (paso 6).

## 3. Primer despliegue: comandos a ejecutar tras subir el código

Desde SSH, en la raíz del proyecto (`public_html/` o donde corresponda según la sección 2):

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env          # solo la primera vez; luego edita los valores reales
php artisan key:generate

# Editar .env con los datos reales de MySQL (ver hPanel → Bases de datos),
# APP_ENV=production, APP_DEBUG=false, APP_URL=https://tu-dominio,
# y APP_TIMEZONE con la zona horaria del mercado objetivo (ver CLAUDE.md sección 7).
#
# ANTHROPIC_API_KEY: clave de la API de Claude (console.anthropic.com).
# La usa "Generar distribución" en "Plan de hoy" (CLAUDE.md sección 4.12).
# Sin ella el resto de la aplicación funciona igual y ese botón muestra un
# mensaje pidiendo configurarla. Requiere salida HTTPS a api.anthropic.com:
# si el plan de hosting bloquea las conexiones salientes, hay que habilitarla.

php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Por qué `--force` en `migrate`:** sin él, Laravel pide confirmación interactiva, que no existe en un pipeline no supervisado; `--force` es explícito y seguro porque no hay otra forma de correr migraciones en producción de todos modos.

**Por qué cachear config/route/view:** en hosting compartido cada request ya paga el costo de arrancar PHP desde cero (sin OPcache persistente garantizado); cachear evita releer y reparsear `config/*.php` y las rutas en cada petición.

## 4. Cron job: la única tarea programada del servidor

Hostinger permite cron jobs, pero el proyecto asume que solo se puede depender de **uno** (ver `CLAUDE.md` sección 10) — toda la automatización diaria real (cierre automático a las 00:15, cálculo de tendencias a las 00:30) vive en el Scheduler de Laravel (`routes/console.php`), no en cron jobs independientes.

Configurar en hPanel → Avanzado → Cron Jobs, con periodicidad **cada minuto**:

```
* * * * * php /home/USER/domains/TU-DOMINIO/public_html/artisan schedule:run >> /dev/null 2>&1
```

Sustituye `USER` y `TU-DOMINIO` por los reales (visibles en hPanel). Si se usó la Opción A de la sección 2, la ruta a `artisan` es la de la carpeta del proyecto, no la de `public_html/`.

## 5. Límite de subida de imágenes (evidencia de comidas)

`ComidaRealRequest` valida `imagen` con `max:4096` (4 MB) — ver `CLAUDE.md` sección 4.3. Ese límite de **aplicación** es el que efectivamente gobierna la subida; para que no sea el propio PHP quien la rechace antes de llegar a la validación de Laravel, confirma en hPanel → Avanzado → Configuración de PHP que:

- `upload_max_filesize` ≥ 4M (recomendado 8M, para dejar margen al overhead de multipart/form-data)
- `post_max_size` ≥ `upload_max_filesize` (recomendado también 8M o más)

Si el plan contratado trae valores por debajo de 4M, subirlos desde el mismo panel (Hostinger permite ajustar `php.ini` por dominio sin acceso root) antes de considerar el despliegue completo — de lo contrario toda subida de imagen de evidencia fallará con un error de PHP anterior a cualquier mensaje de validación de la aplicación.

## 6. Verificación post-despliegue

Tras el primer despliegue (o cualquier actualización posterior), confirma en este orden:

1. **La app responde:** `https://tu-dominio/` carga sin error 500.
2. **`.env` no es accesible públicamente:** `https://tu-dominio/.env` debe devolver 403/404, nunca el contenido del archivo.
3. **Registro y login funcionan** de punta a punta (crea un usuario de prueba real, no solo revises que la página carga).
4. **El storage de imágenes está enlazado:** sube una comida real con imagen de evidencia (sección 4.3 de `CLAUDE.md`) y confirma que la URL pública devuelta carga la imagen — si `php artisan storage:link` no se ejecutó o el hosting no soporta symlinks, esto fallará con 404 aunque el resto de la app funcione.
5. **El cron corre de verdad:** espera a la medianoche siguiente (hora de `APP_TIMEZONE`) y confirma en la tabla `registros_diarios` que los registros de "ayer" quedaron con `cerrado = true` y `cerrado_en` poblado (cierre automático), y en `metricas_tendencia` que existe una fila nueva para cada usuario con historial (cálculo de tendencias). Si no aparece nada al día siguiente, revisa que el cron job de la sección 4 esté realmente activo en hPanel y que la ruta al `artisan` sea correcta.
6. **`php artisan about`** (por SSH) muestra `Environment: production`, `Debug Mode: OFF` y la `Timezone` esperada — un despliegue con `APP_DEBUG=true` en producción expone trazas de error con detalles internos a cualquier visitante.

## 7. Actualizaciones posteriores (no el primer despliegue)

```bash
git pull                                    # o subir los archivos actualizados
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

No hace falta repetir `storage:link` ni `key:generate` en actualizaciones — son operaciones de una sola vez.
