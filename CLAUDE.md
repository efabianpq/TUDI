# CLAUDE.md — TUDéficit Inteligente

Memoria de proyecto para Claude Code. Describe el **estado actual** de la aplicación, no su historia — al terminar una tarea, actualiza el bloque correspondiente en vez de añadir una entrada nueva de "qué cambió". Detalle que solo importa una vez (bugs ya corregidos, migraciones ya aplicadas, rediseños ya completados) no vive aquí.

## 1. Qué es este proyecto

**TUDéficit Inteligente** es una aplicación web de gestión inteligente de pérdida de peso: calcula el objetivo calórico del usuario, genera planes de comida a partir de lo que dice tener disponible (con IA), registra consumo real y actividad física, calcula el balance energético diario, y detecta tendencias de 7 días (nunca un valor diario aislado) para sugerir ajustes al plan.

Referencia funcional completa: `Arquitectura_TUDeficit_Inteligente.docx` (si está en el repo).

## 2. Stack tecnológico (fijo, no proponer alternativas sin pedirlo)

- **Backend:** Laravel 13.x (PHP 8.3+), monolito — sin API REST separada.
- **IA generativa:** **Gemini** (Google, `gemini-flash-latest`) vía `generateContent`, con el cliente HTTP de Laravel (`Http`), sin SDK de Composer. Resuelve dos cosas detrás de dos interfaces distintas: distribución de comidas (`MealDistributionProviderInterface`, sección 5.3) y transcripción de audio (`TranscripcionAudioProviderInterface`, sección 5.9). Opcional: sin `GEMINI_API_KEY` la app funciona y solo se desactivan esas dos cosas, con un mensaje — nunca un 500.
- **Frontend:** Blade (server-rendered), mobile-first, con el sistema visual TUDI (sección 5.12) + Alpine.js (vía Breeze) + Chart.js para el gráfico del dashboard. Sin SPA. Chart.js se carga desde CDN (`cdn.jsdelivr.net`), no está en `package.json`; el CSS/JS propio (Tailwind + Alpine, vía Vite) sí requiere build. El hosting no tiene Node/npm: `public/build/` se compila en local con `npm run build` y **se commitea al repo**. Correr `npm run build` antes de cada commit que toque `resources/css`, `resources/js` o `tailwind.config.js`.
- **Base de datos:** MySQL 8.x / MariaDB 10.6+. Versión mínima asumida: MySQL 5.7 / MariaDB 10.1 (ninguna consulta usa funciones de ventana ni CTEs — sección 5.7 explica por qué). Tests sobre SQLite en memoria.
- **Sesiones:** `SESSION_DRIVER=database`. **Nunca `file` en producción**: ese driver serializa las peticiones de una misma sesión y, con llamadas a la IA de varios segundos, dos pestañas bastan para provocar un 504 (sección 5.13).
- **Tareas programadas:** Laravel Task Scheduling vía un único cron de Hostinger (`schedule:run`). Sin Redis ni colas externas: la cola de correos usa el driver `database` y se vacía con `queue:work --stop-when-empty` desde ese mismo cron.
- **Timezone:** `America/Bogota` (GMT-5) por defecto — de ahí depende dónde cae la medianoche que decide "hoy" en todo el dominio. La suite de tests corre en la misma zona.
- **Instalable como app:** manifest + metas de Apple + iconos del isotipo (sección 5.12). Sin service worker ni funcionamiento offline.
- **Almacenamiento de imágenes:** disco local vía `Storage` facade (`storage/app/public`, con `storage:link`). Nunca rutas hardcodeadas.
- **Testing:** Pest sobre PHPUnit.
- **Despliegue:** hosting compartido/Business de Hostinger, ver `DEPLOY.md`.

## 3. Estructura de carpetas por dominio

| Dominio | Ubicación |
|---|---|
| Usuarios y perfil | `app/Models/User.php`, `app/Http/Controllers/ProfileController.php` |
| Ciclo de vida de la cuenta | `app/Services/CuentaService.php`, `app/Http/Controllers/ActivacionController.php`, `app/Http/Middleware/EnsureCuentaActiva.php`, `app/Notifications/*` |
| Cálculo nutricional | `app/Services/NutritionCalculatorService.php` (fuente de verdad, sección 7) |
| Calculadora Déficit | `app/Http/Controllers/ProfileParametersController.php` (rutas `/calculadora`) |
| Planes diarios (hub del día) | `app/Http/Controllers/PlanComidaController.php`, compone `MealDistributionService` + `ActivitySuggestionService` + `DailyClosureService` |
| Ciclo de vida de un plan diario | `app/Services/PlanDiarioService.php` (reiniciar / eliminar el día) |
| Reparto entre comidas | `app/Services/RepartoComidasService.php` |
| Distribución de comidas con IA | `app/Services/MealDistributionService.php`, `app/Services/AI/MealDistributionProviderInterface.php` + `GeminiMealDistributionProvider.php` (vigente) |
| Registro de comida real | `app/Services/ComidaRealService.php`, `app/Models/PlanComida.php`, `app/Models/ComidaReal.php` |
| Actividad física | `app/Services/ActivitySuggestionService.php`, `app/Services/ActivityCorrectionService.php`, `app/Models/ActividadFisica.php` |
| Cierre diario | `app/Services/DailyClosureService.php`, `app/Services/CierreFeedbackService.php`, `app/Console/Commands/RunDailyClosure.php` (`dailyAt('00:15')`) |
| Motor de recomendaciones | `app/Services/RulesEngineService.php`, `app/Http/Controllers/RecomendacionSistemaController.php` |
| Analítica de tendencias | `app/Services/TrendAnalyticsService.php`, `app/Services/SeguimientoService.php`, `app/Console/Commands/CalculateTrends.php` (`dailyAt('00:30')`) |
| Inicio (dashboard) | `app/Http/Controllers/DashboardController.php` |
| Dictado por voz | `resources/js/tudi/dictado.js` (reconocimiento nativo, camino normal); plan B apagado por defecto: `app/Services/AI/TranscripcionAudioProviderInterface.php` + `GeminiTranscripcionProvider.php`, `app/Http/Controllers/TranscripcionController.php` |
| Consola de administración | `app/Http/Controllers/Admin/UsuarioController.php` + `ParametroMaestroController.php` + `RecursoDidacticoController.php`, `app/Http/Middleware/EnsureEsAdministrador.php` |
| Parámetros maestros | `app/Services/ParametrosMaestrosService.php`, `app/Models/ParametroMaestro.php` |
| Material de apoyo (video, PDF) | `app/Services/RecursosDidacticosService.php`, `app/Models/RecursoDidactico.php` |
| Diagnóstico y administración de despliegue | `app/Console/Commands/Diagnostico.php` (`tudi:diagnostico`), `app/Console/Commands/HacerAdministrador.php` (`tudi:hacer-admin`) |
| Datos de demostración | `database/seeders/DemoSeeder.php`, `app/Console/Commands/SembrarDemo.php` (`tudi:demo`), guion en `PRESENTACION.md` |

**Regla no negociable:** los controladores son delgados (reciben, validan con Form Requests, delegan). Toda la lógica de negocio vive en `app/Services`. Nada de lógica de negocio en modelos Eloquent ni en controladores.

## 4. Modelo de datos

`Usuario` 1—N `RegistroDiario` 1—N `PlanComida` 1—1 `ComidaReal`
`RegistroDiario` 1—N `ActividadFisica`, `RegistroDiario` 1—N `RecomendacionSistema`
`Usuario` 1—N `MetricaTendencia`

- **Nombres de tabla explícitos** (`protected $table`) porque el pluralizador de Laravel no acierta con compuestos en español: `registros_diarios`, `planes_comida`, `comidas_reales`, `actividades_fisicas`, `metricas_tendencia`, `recomendaciones_sistema`, `ingredientes_disponibles` (sin usar, sección 6), `parametros_maestros`.
- **`users`**: perfil nutricional (`peso_kg`, `estatura_m`, `edad`, `sexo`, `nivel_actividad`, `tipo_deficit`, `valor_deficit`, `proteina_factor`, `grasa_factor`, `calorias_objetivo` — todas nullable hasta completar la Calculadora), `reparto_comidas` (json nullable, el reparto habitual — sección 5.14) + administración (`rol` enum(usuario,admin), `estado` enum(pendiente,activo,suspendido) default `activo`, `codigo_activacion`, `activado_en` — sección 5.1).
- **`registros_diarios`**: `usuario_id` + `fecha` (único), `peso_kg` del día, el snapshot del cierre (`calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, y objetivo/consumido de los tres macros: `proteina_objetivo_g`/`proteina_consumida_g`, `grasa_objetivo_g`/`grasa_consumida_g`, `carbohidratos_objetivo_g`/`carbohidratos_consumidos_g`), `cerrado` + `cerrado_en`, `ingredientes_desayuno`/`ingredientes_almuerzo`/`ingredientes_cena` (texto libre por comida) y `reparto_comidas` (json nullable, el reparto de ese día).
- **`planes_comida`**: `tipo_comida` enum(desayuno,almuerzo,cena,snack), macros estimados, `descripcion` + `preparacion` + `notas_ia`, `ingredientes_detalle` (json, snapshot denormalizado a propósito — sigue siendo legible aunque se editen los ingredientes de origen).
- **`comidas_reales`**: `plan_comida_id` único (relación 1—1, nunca se sobrescribe el plan), macros reales, `consumido_en`, `notas`, `imagen_evidencia`.
- **`actividades_fisicas`**: `calorias_dispositivo`, `factor_correccion` (0.8–0.9), `calorias_ajustadas`, `pasos`, `fuente` enum(manual,dispositivo).
- **`metricas_tendencia`**: promedios móviles de 7 días (`promedio_movil_peso_kg`, `promedio_movil_calorias`, `promedio_movil_deficit_kcal`), `indice_consistencia_pct`, `dias_con_datos`, `porcentaje_perdida_semanal`, `tendencia` enum.
- **`recomendaciones_sistema`**: `estado` enum(pendiente,confirmada,rechazada) default `pendiente` — nunca se aplica un ajuste sin confirmación (sección 8).
- **`parametros_maestros`**: `clave` única, `valor` (texto), `actualizado_por` — solo guarda lo que el administrador cambió; una clave ausente significa "el valor de fábrica".
- **`recursos_didacticos`**: `clave` única, `tipo` (url|archivo), `valor`, `nombre_original`, `actualizado_por` — misma forma que la anterior pero para contenido, no umbrales (sección 5.15).
- **`onDelete`: cascade en todas las FKs** — no hay catálogo compartido en el modelo de datos.

`ComidaReal` es una entidad separada de `PlanComida` a propósito: preserva el historial "planificado vs. ejecutado". No cambiar este diseño sin discutirlo.

## 5. Funcionalidades de la plataforma

### 5.1 Cuentas: registro, activación y ciclo de vida

Cualquiera puede registrarse (`RegisteredUserController` → `CuentaService::registrar()`), pero la cuenta nace **`pendiente`** con un código de activación de 8 caracteres (alfabeto sin 0/O/1/I/L) que **nunca viaja en el correo del usuario** — se lo entrega el administrador por fuera de la aplicación. El correo al usuario (`CuentaPendienteDeActivacion`, en cola) solo le dice que lo pida; el correo a los administradores (`NuevoUsuarioPendiente`) sí lo lleva.

- **Middleware `cuenta.activa`** (`EnsureCuentaActiva`): una cuenta `pendiente` va a `GET/POST /activacion` sin perder la sesión; una `suspendida` pierde la sesión y vuelve al login con el motivo. No se aplica a las rutas de autenticación ni a la propia activación (evitaría un bucle).
- **`ActivacionController`** canjea el código contra la cuenta con sesión iniciada (`hash_equals`, tiempo constante); al activar se quema el código y se avisa por correo (`CuentaActivada`).
- **`CuentaService`** es el único punto con lógica de ciclo de vida: `registrar()`, `activarConCodigo()`, `activar()` (desde consola), `suspender()` (no borra datos), `regenerarCodigo()`.
- Todas las notificaciones son `ShouldQueue` — esperar al SMTP en la petición web ocupa un worker de PHP-FPM (sección 5.13).
- `php artisan tudi:hacer-admin {email}` crea el primer administrador (activa la cuenta de paso, porque la consola exige ya serlo).
- Auth estándar de Breeze (registro, login, logout, recuperación de contraseña) en `routes/auth.php`. `GET /` redirige a `dashboard` o `login` según sesión; no hay landing pública.

### 5.2 Calculadora Déficit (`/calculadora`)

Primer paso del flujo, dimensiona todo lo demás. `ProfileParametersController` (rutas `calculadora.edit`/`calculadora.update`; la clase conserva su nombre de la época en que la ruta se llamaba `/profile/parametros`).

- **Orientada a objetivo, no a factores.** Pregunta sexo, peso, estatura, edad, **"¿qué tan activo eres?"** (cuatro escalones con explicación visible bajo el control — excepción documentada a la regla 8 de la sección 13) y **"tu objetivo"** (Mantener/−10%/−20%/−30%, también explicado). Proteína y grasa se **derivan** del objetivo elegido (cuanto más agresivo el déficit, más proteína) y quedan en "Ajustes avanzados", editables a mano si se necesita.
- **Material de apoyo** (sección 5.15): si el administrador publicó video o guía en PDF, aparecen como **dos botones** al pie del panel "Tu objetivo diario" — el video se abre en una capa sobre la página (con `x-if`, para no cargar el iframe hasta que se pulsa), el PDF se descarga. Son una ayuda, no reordenan la pantalla: el ancho de la Calculadora no cambia. Sin nada publicado, se ve exactamente igual que sin la funcionalidad.
- El resultado se muestra **arriba** del formulario, recalculado en vivo por un espejo en JS de la fórmula de la sección 7 (`calculadoraDeficit()` en la vista) — la cifra que se persiste la calcula siempre `NutritionCalculatorService` en el servidor, al guardar.
- `ProfileParametersController@update` calcula y persiste `users.calorias_objetivo`, que es **el objetivo calórico vigente**: lo consumen `MealDistributionService` y `DailyClosureService` en vez de recalcular desde la fórmula cruda, y solo lo mueve `RulesEngineService::confirmar()` (sección 5.6) o una edición explícita de parámetros. Si el cálculo lanza `NegativeCarbohydrateException`/`InvalidNutritionParameterException` no se persiste nada.
- Se ve en toda la plataforma junto al nombre del usuario en la navegación.
- Campos decimales (peso, estatura, proteína, grasa) son `type="text" inputmode="decimal"`, nunca `type="number"` — ver sección 9.

### 5.3 Planes diarios: comidas y distribución con IA (`/planes`)

**"Planes diarios" es el menú; un plan diario es un `RegistroDiario` con todo su día dentro.** `PlanComidaController`: listado paginado (`GET /planes`), crear el de hoy (`POST /planes`), detalle-hub (`GET /planes/{registroDiario}`) con tres secciones: cálculo alimenticio, actividad física, cierre. 403 si el plan no es del usuario.

**Cálculo alimenticio.** El usuario escribe (o dicta) un párrafo por comida y pulsa **un único** "Generar distribución" — las tres comidas viajan juntas en una sola llamada al proveedor, porque el reparto del día es un solo problema de asignación. `MealDistributionService::distribuirDia()` clasifica cada comida:

| Clase | Cuándo | Presupuesto |
|---|---|---|
| fija | ya resuelta y texto sin cambios, o ya tiene `ComidaReal` | se descuenta del día, no se toca |
| a generar | texto nuevo/cambiado, o pedida con `rehacer` | recibe su parte proporcional del 25/40/35 |
| reservada | sin texto todavía | se aparta su parte, no se resuelve |

- `MealPlanGeneratorService::DISTRIBUCION_COMIDAS` (`desayuno 0.25 / almuerzo 0.40 / cena 0.35`) declara qué comidas hay y el reparto **de fábrica**; cuál rige en cada día lo resuelve `RepartoComidasService` (sección 5.14).
- "Rehacer solo el X" fuerza a regenerar una comida sin texto nuevo; una comida con `ComidaReal` nunca se regenera.
- Los presupuestos por comida y los totales de cada plan los calcula **PHP**, nunca el modelo (regla 7, sección 13).
- **`GeminiMealDistributionProvider`** (vigente): `generateContent` con salida estructurada (`responseSchema`, tipos en mayúsculas), `temperature = 0.1`, sin razonamiento extendido (`thinkingBudget: 0`), `GEMINI_MODEL=gemini-flash-latest` (alias flotante — Google retira versiones numeradas con frecuencia). Timeout 20 s / connect 5 s (sección 5.13). Ningún fallo produce 500: `MealDistributionUnavailableException` cubre sin clave, fallo del proveedor, bloqueo de seguridad, corte por `MAX_TOKENS`, respuesta ininterpretable o "nada que distribuir". El texto del usuario se guarda aunque la generación falle. `ClaudeMealDistributionProvider` sigue en el repo sin bindear (proveedor anterior, por si hiciera falta volver atrás).
- **Dictado por voz** en los textareas de ingredientes — ver sección 5.9.
- **Registro de lo que se comió** ya no tiene botón propio por comida: se pregunta en el cierre (sección 5.5). `ComidaRealController` (`/plan/{planComida}/comida-real`) sigue existiendo sin enlazar desde la interfaz, para corregir macros a mano si hace falta.
- **Peso del día** (`POST /planes/{registroDiario}/peso`) vive en el panel de objetivo del plan diario, no en pantalla propia; no toca `users.peso_kg` (el de perfil).
- **Guardado sin recargar:** los formularios `data-fetch` (distribución, peso) se envían por `fetch` y reemplazan `#tudi-avisos`/`#panel-objetivo`/`#lista-comidas`/`#seccion-actividad`/`#seccion-cierre` con la respuesta; sin JS se envían normal.

### 5.4 Actividad física

`ActivitySuggestionService::sugerir()` propone actividad para el día (no persiste nada, no toca `calorias_objetivo`): objetivo = una parte del déficit dietético (mantenimiento − objetivo vigente), acotado entre un suelo y un techo — ambos ajustables desde parámetros maestros (sección 5.11) —, con duraciones por la fórmula MET estándar. `ActividadFisicaController@store` (`POST /planes/{registroDiario}/actividades`) registra lo real y aplica `ActivityCorrectionService::FACTORES_POR_TIPO` (0.80–0.85 según tipo, case-insensitive; un factor fuera de 0.8–0.9 se rechaza, nunca se recorta en silencio). Ambas viven agrupadas en una sola sección desplegable del plan diario, con la cabecera resumiendo lo hecho contra el objetivo sin necesidad de abrirla.

### 5.5 Cierre diario

`DailyClosureService`: `resumen()` (vista previa si el día está abierto, snapshot congelado si está cerrado), `cerrar()` (calcula, persiste y congela; lanza `DayAlreadyClosedException` si ya estaba cerrado — no es idempotente a propósito), `reabrir()` (acción explícita, no-op si ya estaba abierto), `diagnosticoRecomendaciones()` (abajo). Las cifras se recalculan siempre desde `ComidaReal`/`ActividadFisica`, nunca desde acumuladores.

El snapshot congela **objetivo y consumido de los tres macros**, no solo de la proteína: la tarjeta "Resultado real del día" los muestra los cuatro, y para un día cerrado tienen que salir del snapshot y no del perfil actual. Los días cerrados antes de que existieran esas columnas devuelven `null` y se pintan como "—", nunca como cero.

Antes de cerrar, `CierreFeedbackService` pregunta comida a comida **"¿Cumpliste con lo sugerido?"**: un interruptor ("sí, lo cumplí" — crea la `ComidaReal` con los macros del plan, sin llamar al proveedor) o un texto ("contar qué comí" — las comidas descritas viajan en una sola llamada a `estimarConsumoReal()`). El texto manda sobre el interruptor. **La foto de evidencia se adjunta aquí**, junto a la respuesta de cada comida (no hay ya un botón "Registrar" por comida, que preguntaba lo mismo). Una imagen sola, sin interruptor ni texto, no crea ninguna `ComidaReal`. Un fallo del proveedor deja el día sin cerrar y sin nada a medias.

**Lo respondido se ve y se puede cambiar.** El cierre lista comida a comida lo que se contestó (macros reales, notas, foto). Con el día abierto, cada respuesta lleva un **"Cambiar mi respuesta"** → `DELETE /plan/{planComida}/comida-real` (`ComidaRealService::eliminar()`, borra la `ComidaReal` y su imagen y recalcula `calorias_consumidas`), que devuelve esa comida al estado "planificada" y con ella la pregunta. Es lo que hace que reabrir un día sirva de algo: `reabrir()` no borra las `ComidaReal` a propósito, así que sin esta acción el cierre daba por buena la respuesta anterior y no volvía a preguntar nada.

Un día cerrado es inmutable: `ComidaReal`/`ActividadFisica` nuevas se rechazan, y borrarlas también; escribir texto de ingredientes, generar distribución, cambiar el reparto y registrar el peso sí se permiten (no alteran cifras del cierre). `CierreDiarioController` (`POST /planes/{registroDiario}/cierre|reabrir`). Automatizado: `app:run-daily-closure` cierra los `RegistroDiario` de ayer sin cerrar, `dailyAt('00:15')`.

### 5.6 Motor de recomendaciones

`RulesEngineService` decide si corresponde sugerir un ajuste de `calorias_objetivo` o alertar de un estancamiento, y es el **único** camino por el que una recomendación confirmada modifica de verdad el objetivo del usuario — nunca automáticamente (sección 8).

- **Ajuste calórico:** pérdida semanal < umbral lento (0.5% de fábrica) → `reducir`; > umbral rápido (1.0%) → `aumentar`; en medio, nada. Ajuste fijo (150 kcal de fábrica), no un rango. No genera nada si el objetivo vigente es `null` o ≤ 0.
- **Estancamiento:** semanas consecutivas (3 de fábrica) con variación de peso por debajo de un umbral (0.2 kg de fábrica) → alerta informativa, sin cifra sugerida ni acción automática.
- Los cinco umbrales son ajustables desde la consola sin desplegar (sección 5.11): el servicio los lee por método (`umbralPerdidaLentaPct()`, etc.), no por constante.
- `confirmar()`/`rechazar()` transicionan `estado`; solo confirmar un `ajuste_calorico` con cifra no nula mueve `calorias_objetivo`. No son idempotentes (`RecomendacionYaProcesadaException` en un segundo intento).
- `RecomendacionSistemaController` (`POST /recomendaciones/{recomendacion}/confirmar|rechazar`); se listan en el cierre y en Inicio, `Redirect::back()` vuelve a donde se pulsó.
- Disparado desde `DailyClosureService::generarRecomendaciones()` dentro de la transacción de cierre, con los insumos de `TrendAnalyticsService` (abajo).
- **El vacío explica por qué está vacío.** En las primeras semanas no hay nada que sugerir, y "sin recomendaciones" no distinguía "todavía no hay historial" de "tu ritmo es correcto". `DailyClosureService::diagnosticoRecomendaciones()` devuelve el avance hacia los requisitos (días con plan de 7, pesajes en cada una de las dos ventanas) y la vista lo pinta como una lista de checks, más un "¿Qué es esto?" en el cierre y en Inicio. **No se pesa a diario:** basta un pesaje en cada ventana de 7 días, porque el promedio móvil ignora los días sin peso (sección 5.7).

### 5.7 Analítica de tendencias

`TrendAnalyticsService::calcular()`: promedios móviles de una ventana de 7 días naturales (peso, calorías consumidas, déficit), índice de consistencia (`días cerrados / 7 * 100` — se cuenta el cierre, no la existencia del registro), `porcentaje_perdida_semanal` (ventana actual vs. la de hace 7 días) y `tendencia`. Nunca lanza por falta de historial: con menos de 7 días, `datos_suficientes => false`; los días sin dato se ignoran, no cuentan como cero. `serieHistorica()` alimenta el gráfico con una sola consulta. `variacionesSemanalesPesoKg()` es el insumo de la detección de estancamiento.

**El promedio se calcula en PHP, no con `AVG() OVER (...)`** — la versión de MySQL en Hostinger no está garantizada y la suite corre sobre SQLite. Si algún día se fija la versión del servidor, este es el único sitio a tocar.

`app:calculate-trends` recorre todos los usuarios y persiste una fila por usuario+fecha, `dailyAt('00:30')` (15 min después del cierre). `SeguimientoService` solo lee y agrega lo que ya persistieron el cierre y el motor de reglas — no calcula ninguna fórmula nueva.

### 5.8 Inicio (`/dashboard`)

`DashboardController`, tres bloques:

1. **Hoy** — anillo de déficit (`--pct` = consumidas / (objetivo + actividad), acotado 0–100; un superávit se pinta en crema, no en lima, con la etiqueta "kcal por encima"), estado de las tres comidas, botón para abrir/crear el plan de hoy.
2. **Tu tendencia** — promedio móvil de peso + % semanal, déficit promedio, índice de consistencia, gráfico Chart.js (si el CDN no carga, las cifras server-rendered siguen ahí).
3. **Tu seguimiento** — seis semanas con adherencia/comidas/peso medio/variación/déficit, e historial de recomendaciones con sus botones Confirmar/Rechazar.

`/progreso` es un `Route::redirect` a `/dashboard` (pantalla ya fusionada, se conserva el enlace).

### 5.9 Dictado por voz

**Reconocimiento nativo del navegador, sin coste.** El único camino normal es la Web Speech API: quien reconoce la voz es el sistema operativo (Windows, Android, macOS e iOS lo traen), el audio no sale del dispositivo y no cuesta ninguna llamada al proveedor.

- **iOS entra por el mismo camino.** Safari soporta `webkitSpeechRecognition` pero ignora `continuous = true`: corta la sesión sola en cada pausa. La solución nativa es reconocer **por tramos y reengancharlos** (`continuous = false` + arrancar otra sesión en `end` mientras el usuario no pulse "Listo"), acumulando el texto definitivo entre tramos. `TRAMOS_MUDOS_MAXIMOS` evita reenganchar para siempre con un micrófono callado.
- **El plan B —grabar y transcribir en el servidor— está apagado por defecto.** `POST /transcribir` (único endpoint JSON, `throttle:30,1`, `GeminiTranscripcionProvider`) sigue implementado y probado, pero solo responde con `TRANSCRIPCION_FALLBACK_SERVIDOR=true`: cada dictado sería una llamada facturable y ocuparía un worker de PHP-FPM (sección 5.13). Apagado, el layout ni siquiera emite la meta `ruta-transcribir`, así que el JS no tiene a dónde mandar audio.

Si el navegador no puede reconocer voz y el plan B está apagado, el botón del micrófono queda oculto y se escribe a mano. Cubre los textareas de ingredientes y el texto del feedback de cierre, con un popup de grabación (tiempo, transcripción en vivo, "Listo"/"Cancelar").

### 5.10 Consola de administración (`/admin`)

Rutas bajo `auth` + `cuenta.activa` + `admin` (`EnsureEsAdministrador`, **403 y no redirect**).

- `GET /admin` — cifras y cola de activación con el código de cada pendiente a la vista, más los accesos a parámetros maestros y material de apoyo.
- `GET /admin/usuarios` — búsqueda por nombre/correo/código, filtro por estado, pendientes primero; activar/suspender/promover/degradar/regenerar código/eliminar (con confirmación, cascade se lleva todo el historial).
- **Un administrador no puede degradarse, suspenderse ni borrarse a sí mismo** (`ActualizarUsuarioRequest::after()` + `abort_if`).
- "Administración" es un ítem más de la barra lateral **solo para administradores**, y está en el menú del avatar; no entra en la barra inferior de móvil (esos tres destinos son el flujo diario del usuario).

### 5.11 Parámetros maestros (`/admin/parametros`)

`ParametrosMaestrosService::CATALOGO` es la **única declaración** de qué parámetros existen, tipo, límites y explicación; sus valores de fábrica referencian las constantes públicas de los servicios que los consumen (no una copia). Nueve parámetros: cinco del motor de recomendaciones (sección 5.6) y cuatro de la sugerencia de actividad (sección 5.4). La tabla solo guarda lo que cambió; los valores se cachean juntos y para siempre, invalidados al guardar. Si la tabla no existe todavía (deploy antes de `migrate`), cae a los valores de fábrica sin tumbar la aplicación.

**Qué NO entra, a propósito:** el reparto entre comidas (no es un umbral del administrador sino una preferencia del usuario que cambia por día — sección 5.14), el material de apoyo (es contenido y uno de sus valores es un archivo subido — sección 5.15), las fórmulas de la sección 7 (son la definición del producto), el modelo/timeout del proveedor de IA (configuración de despliegue, vive en `.env`).

### 5.12 Identidad visual, mobile-first y shell instalable

- **`resources/css/tudi-tokens.css`** es la única fuente de verdad de color, tipografía, radio y espaciado; se importa antes de las directivas de Tailwind. `tailwind.config.js` refleja los mismos valores — si un token cambia, se cambia en el CSS y se copia allí, nunca al revés. Fondo crema siempre, un panel carbón por pantalla con la cifra protagonista, **lima solo para progreso** (sobre crema el primario es carbón), ámbar para avisos (no hay rojo en la paleta).
- **Navegación:** barra lateral de 232px en escritorio; en móvil, **dos barras fijas** — la superior (`x-tudi.barra-superior`: marca, fecha y menú de la cuenta) y la inferior de tres destinos (Inicio/Calculadora/Planes), con `aria-current="page"` en el activo. Las dos viven en el layout, no en cada vista: antes cada pantalla montaba su cabecera y solo algunas incluían el menú del avatar, así que desde el plan diario no había forma de llegar a "Mi cuenta" ni de cerrar sesión. En escritorio la barra superior se oculta porque la lateral ya trae marca, tarjeta de usuario y logout. Objetivos táctiles de 44px mínimo. Componentes compartidos (`x-text-input`, `x-primary-button`) fijan 48px de alto y 16px de tipografía (evita el zoom automático de Safari).
- **Safe area, arriba y abajo.** Con `viewport-fit=cover` + `apple-mobile-web-app-status-bar-style: black-translucent`, instalada como app en iOS la página empieza **debajo del reloj y la señal**. `main` descuenta `env(safe-area-inset-top)` en móvil (`pt-[calc(env(safe-area-inset-top)+1.25rem)]`, `sm:pt-8`) y el layout de invitado hace lo mismo arriba y abajo; sin eso, la cabecera —y con ella el menú de la cuenta— quedaba solapada con la barra del sistema y no se podía pulsar. En el navegador, sin instalar, el inset es cero y el espaciado es el de siempre.
- **Macros: palabra completa donde cabe, icono donde no.** En los paneles carbón (Objetivo del día, Tu objetivo diario) van "Proteína / Grasas / Carbohidratos" enteros. En los chips compactos sobre crema va el icono ilustrado del branding (`public/icons/macros/*.png`, generados de `resources/branding/`), vía `<x-tudi.macro tipo valor variante>`. Nunca la inicial suelta: "P/G/C" no dice nada a quien empieza. El arte trae su propio fondo crema, así que se recorta en círculo y no se usa sobre oscuro.
- **Acordeón de comidas** en el plan diario: `<details>` nativos con un solo abierto a la vez (listener en captura, `toggle` no burbujea).
- **Instalable como app:** `public/manifest.webmanifest` + metas de Apple + iconos generados del isotipo (`public/icons/`), en los dos layouts. `min-h-[100dvh]` (no `100vh`) para que el alto útil sea idéntico en todas las pantallas. `x-tudi.instalar` ofrece el instalador nativo en Android y explica el gesto en iOS. **Límite honesto:** sin instalar, ninguna web puede ocultar la barra de URL de Safari.
- **En Hostinger (Opción A, proyecto fuera de `public_html`): cualquier archivo nuevo en `public/` necesita enlazarse a mano en `public_html`** tras el `git pull` — ver `DEPLOY.md` sección 7, paso de sincronización obligatorio.
- **Overlay de "procesando"** (`x-tudi.cargando`, `resources/js/tudi/cargando.js`): cualquier formulario que dependa del proveedor de IA declara `data-cargando="mensaje"` y opcionalmente `data-cargando-pistas="a|b|c"` (rotan cada 3.5 s). Los botones se bloquean en el siguiente tick, no dentro del propio `submit` (si no, se pierde el `name`/`value` del botón pulsado).
- Campos decimales: `type="text" inputmode="decimal"`, nunca `type="number"` — sección 9.

### 5.13 Infraestructura: concurrencia, timeouts y diagnóstico

**El mecanismo del 504 en hosting compartido:** cada petición en curso ocupa un proceso entero de PHP-FPM (pool típico de 5–15 en Hostinger); una llamada a la IA de 30 s ocupa un worker 30 s, y un puñado de llamadas simultáneas agota el pool y tumba **todas** las peticiones, no solo las que tocan IA.

- `GEMINI_TIMEOUT=20` / `GEMINI_CONNECT_TIMEOUT=5` — deben quedar por debajo del `fastcgi_read_timeout` del servidor para que corte la aplicación (con mensaje) y no el gateway.
- Correos en cola (sección 5.1), no en la petición web.
- `php artisan tudi:diagnostico`: comprueba en segundos y solo leyendo entorno/timezone, límites de PHP, latencia y tablas de la base de datos, migraciones pendientes, driver de sesión, cola, permisos, `public/build/manifest.json`, y si el hosting bloquea la salida HTTPS a Gemini. Código de salida distinto de cero si algo crítico falla.
- `DEPLOY.md` sección 8 documenta el triaje completo de un 504 (`/up`, pool de PHP-FPM, por qué `SESSION_DRIVER=file` lo provoca).

### 5.14 Reparto de calorías entre comidas

`RepartoComidasService` resuelve, en este orden, cuál rige: **el del día** (`registros_diarios.reparto_comidas`) → **el habitual del usuario** (`users.reparto_comidas`) → **el de fábrica** (`MealPlanGeneratorService::DISTRIBUCION_COMIDAS`, 25/40/35). `null` en las dos columnas significa "el de fábrica", así que cambiar el valor de fábrica alcanza a quien nunca lo personalizó.

- **Se ajusta desde el plan diario** (`POST /planes/{registroDiario}/reparto`, desplegable "Reparto del día"): tres porcentajes enteros que deben sumar 100, mínimo 5% por comida, con una casilla **"guardar como mi reparto habitual"** que además lo adopta en el perfil para los días nuevos.
- **No recalcula nada ya generado.** Los macros de un `PlanComida` están persistidos; el reparto dimensiona los objetivos que se muestran y el presupuesto de lo que queda por generar. Cuando solo faltan dos comidas, lo disponible se reparte con los pesos del reparto vigente, no a partes iguales.
- La validación vive en el servicio (`desdePorcentajes()` lanza `InvalidArgumentException`), no solo en `RepartoComidasRequest`: el reparto se consume fuera de HTTP. Un reparto persistido que no suma 1.0 (una fila tocada a mano) se ignora y se cae al siguiente escalón.
- Las **claves** siguen saliendo de `DISTRIBUCION_COMIDAS` porque son nombres de columna (`ingredientes_*`) y de campo de formulario; lo único que varía es el porcentaje.

### 5.15 Material de apoyo (`/admin/recursos`)

`RecursosDidacticosService::CATALOGO` declara los dos recursos que existen: `calculadora_video_url` (tipo `url`) y `calculadora_guia_pdf` (tipo `archivo`). El administrador los publica desde la consola y el usuario los ve en la Calculadora (sección 5.2).

- **El video se incrusta, no se aloja.** `urlIncrustable()` traduce un enlace de YouTube o Vimeo a su URL de reproducción (`youtube-nocookie.com/embed/…`); cualquier otra URL no se incrusta y la consola lo avisa. Servir un MP4 desde el hosting compartido ocuparía un worker de PHP-FPM por reproducción (sección 5.13). **El PDF sí se sube**, a `storage/app/public/recursos` (máximo 20 MB); reemplazarlo borra el anterior del disco.
- Tabla y servicio propios, no `parametros_maestros`: aquello son umbrales numéricos con mínimo/máximo cuyo catálogo referencia constantes de servicios; esto es contenido con un archivo detrás. Misma mecánica de caché (para siempre, invalidada al guardar) y la misma tolerancia a que la tabla no exista todavía.
- Sin nada publicado, la Calculadora se ve exactamente igual que antes de que existiera la funcionalidad.

### 5.16 Reiniciar y eliminar un plan diario

`PlanDiarioService`, dos acciones destructivas que el usuario pide explícitamente y que van detrás de una confirmación en línea (nunca `confirm()` de JavaScript):

- **`resetear()`** (`POST /planes/{registroDiario}/resetear`, botón "Reiniciar este día" al final del plan): deja el día como recién creado — sin planes de comida, sin lo registrado, sin actividades, sin recomendaciones, sin textos de ingredientes, sin peso, sin las cifras del cierre y abierto. Conserva la fecha y el reparto del día.
- **`eliminar()`** (`DELETE /planes/{registroDiario}`, desde el listado y desde el propio plan): borra el `RegistroDiario`; las FK `cascade` se llevan el resto.

Las dos llaman antes a `ComidaRealService::borrarImagenesDelDia()`: la cascada de la base de datos se lleva las filas, pero no los archivos del disco.

**Por qué el reset también borra el peso:** "volver a empezar" incluye el peso, y dejarlo suelto en un día del que no queda nada sería un dato huérfano. No rompe la ventana de 7 días — el promedio móvil ignora los días sin peso en vez de contarlos como cero (sección 5.7) y el índice de consistencia mide días cerrados, no pesajes. El seguimiento no depende solo del peso: nadie se pesa a diario.

### 5.17 Datos de demostración (`tudi:demo`)

`DemoSeeder` + `php artisan tudi:demo` siembran seis cuentas, cada una parada en un punto distinto del recorrido, para poder enseñar la plataforma sin esperar tres semanas a que alguien acumule historial. Guion de la demostración y material de publicidad en `PRESENTACION.md`.

- **El historial no se inventa:** se crean los `PlanComida`/`ComidaReal`/`ActividadFisica` de cada día y se llama a `DailyClosureService::cerrar()`, así que el déficit y las recomendaciones salen de la lógica de dominio. **No llama al proveedor de IA**: los planes se escriben desde un catálogo de comidas de ejemplo (sembrar 21 días × 6 cuentas costaría cientos de llamadas facturables).
- **Escenarios cubiertos:** administradora, cuenta pendiente con código, cuenta activa sin Calculadora, y tres perfiles con 21 días de historial cuya pendiente de peso los sitúa en ritmo correcto (sin recomendación), demasiado lento (propone reducir) y demasiado rápido (propone aumentar). El día de hoy queda **abierto y a medias** a propósito.
- **Idempotente y acotado:** cada cuenta se busca por correo y su historial se rehace; `--limpiar` borra solo las cuentas cuyo correo termina en `@demo.tudeficitinteligente.online`, así que es seguro correrlo sobre producción. Cubierto por `tests/Feature/DemoSeederTest.php`, que fija el escenario de cada cuenta: si "baja-lento" dejara de generar su recomendación, la demo enseñaría una pantalla vacía.
- Las cuentas usan una contraseña conocida y una es administradora: **retirarlas al terminar**.

## 6. Rutas y código sin usar, conservados a propósito

No son deuda técnica olvidada — cada uno se conserva por una razón concreta y está cubierto por tests:

- **Ingredientes estructurados** (`IngredienteDisponibleController`, rutas `/ingredientes`): el camino normal es el texto libre del plan diario (sección 5.3); estas rutas siguen siendo la entrada de `MealPlanGeneratorService`.
- **`MealPlanGeneratorService` + `POST /planes/{registroDiario}/generar`**: heurística de reparto por macro (proteína → grasa → carbohidratos) sobre ingredientes estructurados. Sustituida por la distribución con IA (sección 5.3) como camino del usuario, pero sigue funcionando y probada. Su constante `DISTRIBUCION_COMIDAS` no es código muerto: es la declaración de qué comidas hay y del reparto de fábrica (sección 5.14).
- **`NutritionAiProviderInterface` + `RuleBasedNutritionProvider`**: desacopla "quién decide" la selección de ingredientes sobre inventario estructurado (usada por `MealPlanGeneratorService`) de una futura IA generativa para ese mismo problema — distinto del problema que resuelve `MealDistributionProviderInterface` (interpretar lenguaje natural).
- **`ComidaRealController@create`/`@store`** (`/plan/{planComida}/comida-real`, "Registrar con detalle"): el botón por comida desapareció del plan diario (sección 5.3); sigue disponible para corregir macros exactos a mano. `@destroy` del mismo controlador **sí** está enlazado — es el "Cambiar mi respuesta" del cierre (sección 5.5).
- **`ClaudeMealDistributionProvider`**: proveedor de IA anterior a Gemini, sin bindear, por si hiciera falta volver atrás.

## 7. Algoritmo de cálculo nutricional (fuente de verdad)

`app/Services/NutritionCalculatorService.php` implementa esto exactamente; ningún otro punto del código debe reimplementar estas fórmulas.

```
Entradas: peso_kg, estatura_m, edad, sexo, nivel_actividad (1.2–1.725),
          tipo_deficit ("porcentaje"|"fijo"), valor_deficit,
          proteina_factor (1.6–2.2), grasa_factor (0.6–1.0)

TMB = peso_kg * 22
calorias_mantenimiento = TMB * nivel_actividad

Si tipo_deficit == "porcentaje":
    calorias_objetivo = calorias_mantenimiento * (1 - valor_deficit)
Si tipo_deficit == "fijo":
    calorias_objetivo = calorias_mantenimiento - valor_deficit

proteina_g = peso_kg * proteina_factor
proteina_kcal = proteina_g * 4

grasa_g = peso_kg * grasa_factor
grasa_kcal = grasa_g * 9

carbohidratos_kcal = calorias_objetivo - (proteina_kcal + grasa_kcal)
carbohidratos_g = carbohidratos_kcal / 4

deficit_diario = calorias_objetivo - calorias_consumidas + calorias_actividad_ajustada
calorias_actividad_ajustada = calorias_dispositivo * factor_correccion   [factor_correccion ∈ 0.8–0.9]
```

**Validación obligatoria:** `carbohidratos_kcal < 0` lanza `NegativeCarbohydrateException` — nunca se persiste un plan con carbohidratos negativos. Exactamente 0 sí es válido.

**Nota de diseño conocida y aceptada:** `TMB = peso_kg * 22` es una simplificación intencional. `estatura_m`, `edad` y `sexo` se capturan pero no se usan en el cálculo. No migrar a Mifflin-St Jeor u otra fórmula sin que se pida explícitamente.

- **`calculatePlan(...?float $caloriasObjetivoVigente = null): array`** — devuelve `calorias_objetivo`/`proteina_g`/`grasa_g`/`carbohidratos_g`. Cuando `$caloriasObjetivoVigente` no es `null` (es `users.calorias_objetivo`, sección 5.2), **sustituye** al objetivo derivado del déficit; los macros y la validación de carbohidratos negativos se aplican igual sobre él.
- **`calculateMaintenanceCalories()`** — pública para que `ActivitySuggestionService` dimensione el déficit sin reimplementar la fórmula.
- **`calculateAdjustedActivityCalories()`**, **`calculateDailyDeficit()`**.
- **`InvalidNutritionParameterException`**: `proteina_factor`/`grasa_factor`/`factor_correccion` fuera de rango, o `tipo_deficit` desconocido — validado en el servicio, no solo en el Form Request, porque el cierre y el generador de planes también invocan el cálculo fuera de HTTP.
- El servicio devuelve floats sin redondear (el redondeo es responsabilidad de la capa de persistencia) y no depende de Eloquent.

Cubierto por `tests/Unit/NutritionCalculatorServiceTest.php`.

## 8. Reglas de negocio

- Mantener las calorías objetivo constantes en el corto plazo; no recalcular el objetivo por un solo día atípico.
- Ajustes automáticos de `calorias_objetivo` se basan en promedios móviles de 7 días, nunca en un valor diario aislado: pérdida < umbral lento → sugerir reducir; pérdida > umbral rápido → sugerir aumentar (umbrales de fábrica 0.5%/1%, ajustables — sección 5.11).
- Todo ajuste automático se registra como `RecomendacionSistema` y requiere confirmación del usuario antes de modificar el objetivo vigente — nunca se aplica solo.
- Validar siempre `proteina_factor` y `grasa_factor` dentro de rango antes de calcular un plan.

## 9. Convenciones de código

- PSR-12, formateado con Laravel Pint antes de cada commit.
- Nombres de tablas/columnas en español — no traducir a mitad de camino.
- Validación de entrada siempre vía Form Requests, nunca inline en el controlador.
- Eloquent y sus relaciones; SQL crudo solo si es estrictamente necesario, documentando por qué.
- Timezone: usar `config('app.timezone')`, nunca `UTC` a pelo.
- **Campos decimales:** nunca `<input type="number">` — usar `type="text" inputmode="decimal"` y el trait `App\Http\Requests\Concerns\NormalizaDecimales` en el Form Request (acepta coma decimal, sin recortar ni rechazar nada por su cuenta).

## 10. Testing

- Framework: Pest. Comando: `php artisan test` (o `./vendor/bin/pest`).
- Todo Service de dominio requiere tests unitarios de casos normales y de borde.
- Todo flujo de usuario nuevo requiere al menos un test de feature.
- No se considera terminada una tarea si los tests no pasan.

## 11. Flujo de trabajo Git

- Un commit por tarea completada, mensaje descriptivo (`feat: ...`, `fix: ...`, `test: ...`).
- No commitear `.env`, `vendor/`, `node_modules/`.
- Antes de dar una tarea por terminada: `php artisan test` en verde y `vendor/bin/pint` sin cambios pendientes.

## 12. Despliegue (Hostinger)

**Paso a paso completo en `DEPLOY.md`.** Resumen de las decisiones de fondo:

- Un solo cron job (`schedule:run` cada minuto); toda la automatización diaria vive en `routes/console.php`.
- El promedio móvil se calcula en PHP (sección 5.7) — no depende de la versión de MySQL del hosting.
- Variables sensibles solo en `.env`. `GEMINI_API_KEY` es opcional (sin ella se desactiva la distribución de comidas con un mensaje, no un 500; el dictado por voz **no** depende de ella desde la sección 5.9); `MAIL_*` hace falta para que salgan los correos del alta (sin SMTP, la única vía es la consola).
- **Estructura del proyecto en producción: Opción A** (proyecto fuera de `public_html`, con enlaces simbólicos hacia `public/`) — `DEPLOY.md` secciones 2 y 7 detallan el paso de sincronización obligatorio tras cada `git pull`.
- Primer despliegue: `migrate --force`, `tudi:hacer-admin {email}`, `tudi:diagnostico` para verificar.
- Modelos con `#[Fillable([...])]` explícito; rutas con route-model-binding verifican propiedad con `abort_unless(...usuario_id === $request->user()->id, 403)`; CSRF en todos los formularios.

## 13. Reglas para Claude Code al trabajar en este proyecto

1. **Actualiza este archivo al final de cada tarea** si agregaste un modelo, servicio, comando, endpoint o tomaste una decisión de diseño no trivial — edita el bloque correspondiente, no añadas una entrada histórica nueva.
2. No introduzcas dependencias nuevas (Composer/npm) sin que estén justificadas por la tarea en curso.
3. No sobre-diseñes: si una tarea se resuelve con una clase de servicio simple, no introduzcas patrones (repositorios, eventos, colas) que el documento de arquitectura no pidió.
4. Si una tarea es ambigua, toma la decisión más simple consistente con las secciones 7 y 8, impleméntala y documéntala aquí — no te detengas a preguntar salvo que la ambigüedad afecte datos financieros/de salud del usuario de forma irreversible.
5. Nunca implementes un ajuste automático de calorías objetivo sin pasar por `RecomendacionSistema` y confirmación del usuario (sección 8).
6. **Toda llamada a un modelo de IA pasa por una interfaz en `app/Services/AI`**, nunca desde un controlador, modelo o vista. Ningún fallo del proveedor puede tumbar la aplicación: se traduce a excepción de dominio y mensaje, nunca a un 500.
7. **Ninguna cifra que entre al balance energético la calcula un modelo de IA.** El modelo estima macros por alimento; las sumas, objetivos y déficit los calcula PHP con `NutritionCalculatorService`.
8. **El sistema visual es cerrado** (sección 5.12): no inventes colores, tamaños, radios ni tipografías fuera de `resources/css/tudi-tokens.css`. La lima es progreso y nada más. Ningún párrafo de instrucciones en pantalla: la ayuda va en el `placeholder` o detrás de un "¿Cómo funciona?". Única excepción documentada: las explicaciones de nivel de actividad y objetivo en la Calculadora (sección 5.2), porque esconderlas falsea el resultado.
9. **Mobile-first no es opcional**: toda vista nueva se diseña primero para móvil y se ensancha con `sm:`/`lg:`, no al revés.
10. **Nada que espere a un servicio externo dentro de una petición web sin timeout acotado** (sección 5.13): cada petición en curso ocupa un proceso entero de PHP-FPM. Si algo puede tardar, se acota por debajo del timeout del gateway o se manda a la cola.
11. **Toda acción que dependa del proveedor de IA declara `data-cargando`** (sección 5.12): sin señal visible, el usuario vuelve a pulsar y gasta otra llamada.
12. **En Hostinger, un archivo nuevo en `public/` no llega solo a producción** (sección 5.12/`DEPLOY.md` §7): si una tarea añade algo a `public/`, recuerda el paso de sincronización en el checklist de despliegue.
13. **El dictado por voz no puede volver a depender del proveedor de IA** (sección 5.9): lo resuelve el reconocedor nativo del navegador. Cualquier camino que mande audio al servidor va detrás de `TRANSCRIPCION_FALLBACK_SERVIDOR`, apagado por defecto — si no, cada dictado se factura y ocupa un worker de PHP-FPM.
