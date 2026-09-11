# Ejecución local — TUDéficit Inteligente

Cómo levantar la aplicación en el equipo de desarrollo (Windows + Laragon) y qué
revisar cuando "no abre". El despliegue en Hostinger es otra cosa y vive en
`DEPLOY.md`.

Resumen operativo: **limpiar cachés → `migrate` → `php artisan serve`**. Todo lo
demás de este documento es diagnóstico.

---

## 1. Cómo se sirve la aplicación en local

`.env` trae `APP_URL=http://localhost:8000`, así que el camino normal es el
servidor de desarrollo de Laravel (`php artisan serve`), **no** la URL de
Laragon. De Laragon solo hace falta **MySQL**.

Si quisieras servirla por Apache en el puerto 80, antes hay que liberar ese
puerto o cambiárselo a Apache: en este equipo lo tiene tomado el proceso
`System` (http.sys/IIS), que es la razón por la que el Apache de Laragon no
levanta. No es necesario para trabajar.

---

## 2. Paso a paso

Abre una terminal en `C:\laragon\www\TUDeficit Inteligente TUDI`.

**1. Servicios de Laragon.** Abre Laragon y confirma que **MySQL está en verde**.

**2. Dependencias** — solo tras un `git pull` o si borraste carpetas:

```
composer install
npm install
```

**3. Revisa el `.env`.** Que `APP_KEY` no esté vacío y que los datos de MySQL
sean los tuyos. Si falta la clave:

```
php artisan key:generate
```

**4. Limpia las cachés.** El paso que más fallos silenciosos evita: una
configuración cacheada ignora los cambios del `.env` y de `config/`.

```
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

**5. Base de datos al día:**

```
php artisan migrate
```

**6. Enlace de imágenes** (evidencias de comidas y PDF del material de apoyo):

```
php artisan storage:link
```

Si responde *"The link already exists"* pero las imágenes no cargan, el enlace
quedó colgado. En **PowerShell como administrador**:

```
Remove-Item "public\storage" -Force
php artisan storage:link
```

**7. Compila el front** solo si tocaste `resources/css`, `resources/js` o
`tailwind.config.js`:

```
npm run build
```

`public/build/` está commiteado al repo, así que normalmente este paso sobra.

**8. Levanta el servidor** — deja esta ventana abierta; se apaga con `Ctrl+C`:

```
php artisan serve --port=8000
```

Entra a **http://localhost:8000**.

**9. Segunda terminal: la cola de correos.** Con `QUEUE_CONNECTION=database`,
los correos del alta se quedan en la tabla `jobs` hasta que alguien los procesa:

```
php artisan queue:work --stop-when-empty
```

**10. Tercera terminal, opcional: las tareas diarias** (cierre 00:15, tendencias
00:30, vencimiento de pruebas 00:45). Solo si vas a probarlas:

```
php artisan schedule:work
```

**11. Verifica:**

```
php artisan tudi:diagnostico
```

Tiene que terminar en *"Sin fallos críticos"*.

---

## 3. Si algo falla, en este orden

| Síntoma | Qué mirar |
|---|---|
| Pantalla en blanco o error 500 | `storage\logs\laravel.log`, últimas líneas |
| Sale sin estilos | Falta `public\build\manifest.json` → `npm run build` |
| Error 419 al enviar un formulario | Sesión caducada: borra las cookies de localhost y confirma que existe la tabla `sessions` |
| La interfaz sigue en inglés | No se limpió la caché de configuración → paso 4 |
| "Esos datos no coinciden con ninguna cuenta" | No tienes usuario local: `php artisan tudi:demo` siembra seis cuentas de prueba, o `php artisan tudi:hacer-admin tu@correo.com` sobre una cuenta ya registrada |
| `Address already in use` al hacer `serve` | Otra instancia quedó viva: `php artisan serve --port=8001` y actualiza `APP_URL` |
| No conecta a la base de datos | MySQL apagado en Laragon, o `DB_DATABASE`/`DB_PASSWORD` equivocados |
| El sitio no responde en ninguna URL | Comprueba que haya algo escuchando: `netstat -ano | findstr :8000` |

---

## 4. Qué comprueba `tudi:diagnostico`

Sirve como revisión completa en segundos y no modifica nada: entorno y zona
horaria, límites de PHP, latencia y tablas de la base de datos, migraciones
pendientes, driver de sesión, cola, permisos de escritura,
`public/build/manifest.json` y si hay salida HTTPS hacia OpenAI. Devuelve un
código de salida distinto de cero si algo crítico falla.

Lectura de referencia de un entorno sano (revisión del 10/09/2026):

```
Base de datos     ✓ Conexión 21 ms · mysql · tudeficit
                  ✓ Tablas users, sessions, cache, jobs, registros_diarios,
                    parametros_maestros
                  ✓ Migraciones pendientes: ninguna
Sesiones y cola   ✓ SESSION_DRIVER database
                  · QUEUE_CONNECTION database
Almacenamiento    ✓ storage/framework, storage/logs y bootstrap/cache escribibles
                  ✓ public/build/manifest.json presente
Proveedor de IA   ✓ OPENAI_API_KEY configurada · gpt-4.1
                  ✓ Salida HTTPS a api.openai.com (HTTP 200)
```

Aquel día el único fallo real era que **nadie escuchaba en el puerto 8000**: la
aplicación estaba sana y lo que faltaba era el paso 8.
