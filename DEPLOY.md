# Despliegue en Hostinger

Paso a paso concreto para publicar TUDéficit Inteligente en un hosting compartido/Business de Hostinger. Referencia cruzada: `CLAUDE.md` sección 10 ("Despliegue (Hostinger)").

## 1. Requisitos del plan de hosting

- PHP 8.3+ (el proyecto fija `"php": "^8.3"` en `composer.json`).
- MySQL 8.x / MariaDB 10.6+ (mínimo asumido en el código: MySQL 5.7 / MariaDB 10.1 — ver `CLAUDE.md` sección 4.7; ninguna consulta usa funciones de ventana ni CTEs).
- Acceso SSH o al menos un terminal/gestor de archivos con permiso para ejecutar `composer` y `php artisan` (Hostinger Business lo ofrece vía hPanel → "Avanzado → SSH Access").
- Un único cron job disponible (ver sección 4).
- **No se asume Node/npm en el servidor.** Los assets de Vite (`public/build/`) se compilan en local (`npm run build`) y se commitean al repo — `public/build` **no** está en `.gitignore` por esta razón. Cada vez que cambie CSS/JS (`resources/css`, `resources/js`, `tailwind.config.js`) hay que correr `npm run build` antes de hacer commit; de lo contrario el despliegue falla con `ViteManifestNotFoundException` al no existir `public/build/manifest.json`.

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
# APP_ENV=production, APP_DEBUG=false, APP_URL=https://tu-dominio.
#
# APP_TIMEZONE=America/Bogota (GMT-5). De esto depende dónde cae la medianoche
# que decide "hoy" en todo el dominio: el registro diario, el cierre automático
# de las 00:15 y los promedios móviles (CLAUDE.md sección 7).
#
# SESSION_DRIVER=database — NUNCA `file` en producción: ver la sección 8.
#
# GEMINI_API_KEY: clave de la API de Gemini (aistudio.google.com).
# La usa "Generar distribución" y el cierre del día (CLAUDE.md sección 4.12),
# y también el dictado por voz en Safari de iOS (sección 4.21).
# Sin ella el resto de la aplicación funciona igual y esos botones muestran un
# mensaje pidiendo configurarla. Requiere salida HTTPS a
# generativelanguage.googleapis.com: si el plan de hosting bloquea las
# conexiones salientes, hay que habilitarla (lo detecta `tudi:diagnostico`).
#
# MAIL_*: el alta de cuentas envía correo al usuario y a los administradores
# (sección 4.26). Sin SMTP configurado, esos correos no salen y la única vía de
# entregar el código de activación es la consola /admin.

php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Primer administrador: la consola exige ya serlo, así que el primero se crea
# aquí (CLAUDE.md sección 4.26). Regístrate antes desde la web con ese correo.
php artisan tudi:hacer-admin tu-correo@dominio.com

# Comprobación final de todo lo anterior (sección 8).
php artisan tudi:diagnostico
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
7. **`php artisan tudi:diagnostico`** no reporta ningún fallo crítico (sección 8). Cubre de golpe los puntos 4 y 6 y añade los que no se ven desde el navegador: salida HTTPS al proveedor de IA, driver de sesión y trabajos encolados.
8. **El alta de una cuenta funciona de punta a punta:** regístrate con un correo real, comprueba que llega el aviso, que la cuenta aparece en `/admin` con su código, y que ese código la activa (`CLAUDE.md` sección 4.26). Si el correo no llega pero la cuenta sí aparece en la consola, el problema es SMTP, no la aplicación.

## 7. Actualizaciones posteriores (no el primer despliegue)

```bash
git pull                                    # o subir los archivos actualizados
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan tudi:diagnostico                # ver sección 8
```

No hace falta repetir `storage:link` ni `key:generate` en actualizaciones — son operaciones de una sola vez.

**Importante:** `config:cache` congela los valores de `.env` en un archivo compilado. Si cambias `.env` después, hay que volver a ejecutar `php artisan config:cache` (o `config:clear`) o el cambio no surtirá efecto.

## 8. Diagnóstico y error 504 (Gateway Time-out)

```bash
php artisan tudi:diagnostico
```

Comprueba en segundos, y solo leyendo, las causas que de verdad producen un 504 en hosting compartido: entorno y timezone, límites de PHP, latencia y tablas de la base de datos, driver de sesión, cola de trabajos, permisos de escritura, `public/build/manifest.json` y la salida HTTPS al proveedor de IA. Marca `✗` lo crítico y `!` lo que conviene revisar. `--sin-red` omite la última comprobación.

### Qué significa un 504 y cómo acotarlo

Un `504 Gateway Time-out` de nginx significa **una sola cosa**: PHP no contestó dentro del plazo (`fastcgi_read_timeout`, típicamente 30–60 s en Hostinger). No dice por qué. Para acotarlo, en este orden:

1. **`https://tu-dominio/up`** — la ruta de salud del framework, que no toca sesión ni la mayoría del arranque.
   - Si `/up` **también** da 504 → el problema es de infraestructura: PHP-FPM caído, pool agotado, o la base de datos no responde. Sigue por el punto 2.
   - Si `/up` responde y `/dashboard` da 504 → el problema es de la aplicación: sigue por el punto 3.
2. **Pool de PHP-FPM.** En hosting compartido `pm.max_children` suele estar entre 5 y 15. **Cada petición en curso ocupa un proceso entero**, y ese proceso no atiende a nadie más mientras espera. Si todos están ocupados, las peticiones nuevas se encolan y acaban en 504 aunque no hagan nada pesado. Es el mecanismo por el que un puñado de usuarios simultáneos tumba el sitio entero.
3. **Lo que hace esperar a un worker en esta aplicación**, de mayor a menor riesgo:
   - **La llamada al proveedor de IA** ("Generar distribución" y "Cerrar mi día"). Acotada con `GEMINI_TIMEOUT` (20 s por defecto) y `GEMINI_CONNECT_TIMEOUT` (5 s). **`GEMINI_TIMEOUT` debe quedar por debajo del `fastcgi_read_timeout` del servidor**, para que corte la aplicación —con un mensaje al usuario— y no el gateway. Si el hosting **bloquea la salida HTTPS**, cada intento consume el timeout entero: `tudi:diagnostico` lo detecta explícitamente.
   - **El envío de correo del alta de cuenta.** Va en cola precisamente para no bloquear el registro esperando al SMTP. Requiere que el cron del scheduler esté corriendo (sección 4); si `tudi:diagnostico` muestra trabajos acumulados en cola, el cron no está activo.
   - **La base de datos.** `tudi:diagnostico` mide la latencia de conexión; por encima de ~500 ms el hosting está saturado o la instancia de MySQL está mal dimensionada.
4. **Driver de sesión.** `SESSION_DRIVER` **no debe ser `file`**. Con el driver de archivo, cada petición bloquea el archivo de sesión hasta terminar, así que dos peticiones del mismo usuario se serializan: con llamadas a la IA de varios segundos, dos pestañas abiertas bastan para provocar un 504. El valor correcto en este proyecto es `database` (requiere la tabla `sessions`, que crea la migración inicial).
5. **`APP_DEBUG=true` en producción.** Además de exponer trazas internas, hace que Laravel acumule en memoria todas las consultas ejecutadas. Debe ser `false`.

### Ajustes recomendados en hPanel

- **Configuración de PHP** → `max_execution_time` por encima de `GEMINI_TIMEOUT` + 10 s (30 s como mínimo). Si está por debajo, PHP corta la petición a mitad de una llamada al proveedor y el usuario ve un 500 en vez de un mensaje.
- Si el plan lo permite, subir `pm.max_children`: es lo que determina cuántos usuarios simultáneos aguanta el sitio.
- Confirmar que la salida HTTPS a `generativelanguage.googleapis.com` está permitida.
