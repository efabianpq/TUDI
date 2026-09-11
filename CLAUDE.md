# CLAUDE.md — TUDéficit Inteligente

Memoria de proyecto para Claude Code. Describe el **estado actual** de la aplicación, no su historia — al terminar una tarea, actualiza el bloque correspondiente en vez de añadir una entrada nueva de "qué cambió". Detalle que solo importa una vez (bugs ya corregidos, migraciones ya aplicadas, rediseños ya completados) no vive aquí.

## 1. Qué es este proyecto

**TUDéficit Inteligente** es una aplicación web de gestión inteligente de pérdida de peso: calcula el objetivo calórico del usuario, genera planes de comida a partir de lo que dice tener disponible (con IA), registra consumo real y actividad física, calcula el balance energético diario, y detecta tendencias de 7 días (nunca un valor diario aislado) para sugerir ajustes al plan.

Referencia funcional completa: `Arquitectura_TUDeficit_Inteligente.docx` (si está en el repo).

## 2. Stack tecnológico (fijo, no proponer alternativas sin pedirlo)

- **Backend:** Laravel 13.x (PHP 8.3+), monolito — sin API REST separada.
- **IA generativa:** **OpenAI / ChatGPT** (`gpt-4.1` por defecto) vía `chat/completions`, con el cliente HTTP de Laravel (`Http`), sin SDK de Composer ni nada de npm — esto es un monolito PHP. Resuelve dos cosas detrás de dos interfaces distintas: distribución de comidas (`MealDistributionProviderInterface`, sección 5.3) y transcripción de audio (`TranscripcionAudioProviderInterface`, sección 5.9, endpoint y modelo aparte). Opcional: sin `OPENAI_API_KEY` la app funciona y solo se desactivan esas dos cosas, con un mensaje — nunca un 500.
- **Frontend:** Blade (server-rendered), mobile-first, con el sistema visual TUDI (sección 5.12) + Alpine.js (vía Breeze). Sin SPA. **Sin librería de gráficos y sin ningún `<script>` de CDN**: el único gráfico que queda —el sparkline de pesajes de Inicio— se dibuja como SVG en línea desde el servidor (`x-tudi.sparkline`, sección 5.8), así que funciona con JavaScript desactivado y no añade una petición externa. El CSS/JS propio (Tailwind + Alpine, vía Vite) sí requiere build. El hosting no tiene Node/npm: `public/build/` se compila en local con `npm run build` y **se commitea al repo**. Correr `npm run build` antes de cada commit que toque `resources/css`, `resources/js`, `tailwind.config.js` **o las clases de Tailwind que usan las vistas** (el escaneo de plantillas cambia el CSS compilado aunque no se toque una hoja de estilos).
- **Base de datos:** MySQL 8.x / MariaDB 10.6+. Versión mínima asumida: MySQL 5.7 / MariaDB 10.1 (ninguna consulta usa funciones de ventana ni CTEs — sección 5.7 explica por qué). Tests sobre SQLite en memoria.
- **Sesiones:** `SESSION_DRIVER=database`. **Nunca `file` en producción**: ese driver serializa las peticiones de una misma sesión y, con llamadas a la IA de varios segundos, dos pestañas bastan para provocar un 504 (sección 5.13).
- **Tareas programadas:** Laravel Task Scheduling vía un único cron de Hostinger (`schedule:run`). Sin Redis ni colas externas: la cola de correos usa el driver `database` y se vacía cada minuto desde ese mismo cron con `queue:work --stop-when-empty --max-time=50 --tries=3` (termina en cuanto no hay trabajo, y `--max-time=50` evita solaparse con la ejecución del minuto siguiente aunque falle `withoutOverlapping()`).
- **Timezone:** `America/Bogota` (GMT-5) por defecto — de ahí depende dónde cae la medianoche que decide "hoy" en todo el dominio. La suite de tests corre en la misma zona.
- **Idioma:** `APP_LOCALE=es` (con `en` de *fallback*). Toda la interfaz está en español, incluidas las pantallas de Breeze (login, registro, recuperación de contraseña) y los mensajes de validación del framework — `lang/es.json` y `lang/es/{auth,passwords,validation,pagination}.php`. Un texto nuevo sin traducir cae al inglés de Laravel, así que cualquier vista o mensaje nuevo necesita su entrada ahí.
- **Instalable como app:** manifest + metas de Apple + iconos del isotipo (sección 5.12). Sin service worker ni funcionamiento offline.
- **Almacenamiento de imágenes:** disco local vía `Storage` facade (`storage/app/public`, con `storage:link`). Nunca rutas hardcodeadas.
- **Testing:** Pest sobre PHPUnit.
- **Despliegue:** hosting compartido/Business de Hostinger, ver `DEPLOY.md`.

## 3. Estructura de carpetas por dominio

| Dominio | Ubicación |
|---|---|
| Usuarios y perfil | `app/Models/User.php`, `app/Http/Controllers/ProfileController.php` |
| Ciclo de vida de la cuenta | `app/Services/CuentaService.php`, `app/Http/Controllers/ActivacionController.php`, `app/Http/Middleware/EnsureCuentaActiva.php`, `app/Notifications/*` |
| Plan del usuario y prueba de Premium | `app/Services/PlanService.php`, `config/planes.php`, `app/Console/Commands/ExpirarPruebas.php` (`app:expirar-pruebas`, `dailyAt('00:45')`) |
| Control de acceso a las funciones de IA | `app/Services/AI/PremiumGatedMealDistributionProvider.php` + `PremiumGatedTranscripcionProvider.php` (decoradores bindeados en `AppServiceProvider`) |
| Cuota diaria de llamadas a la IA | `app/Services/CuotaIaService.php`, `app/Services/AI/CuotaDiariaMealDistributionProvider.php` |
| Landing pública | `app/Http/Controllers/LandingController.php`, `resources/views/welcome.blade.php` |
| Cálculo nutricional | `app/Services/NutritionCalculatorService.php` (fuente de verdad, sección 7) |
| Calculadora Déficit | `app/Http/Controllers/ProfileParametersController.php` (rutas `/calculadora`) |
| Planes diarios (hub del día) | `app/Http/Controllers/PlanComidaController.php`, compone `MealDistributionService` + `ActivitySuggestionService` + `DailyClosureService` |
| Ciclo de vida de un plan diario | `app/Services/PlanDiarioService.php` (reiniciar / eliminar el día) |
| Reparto automático entre comidas | `app/Services/RepartoComidasService.php` |
| Ajuste del plan con IA | `app/Services/MealDistributionService.php`, `app/Services/AI/MealDistributionProviderInterface.php` + `OpenAiMealDistributionProvider.php` (vigente) |
| Registro de comida real | `app/Services/ComidaRealService.php`, `app/Models/PlanComida.php`, `app/Models/ComidaReal.php` |
| Actividad física | `app/Services/ActivitySuggestionService.php`, `app/Services/ActivityCorrectionService.php`, `app/Models/ActividadFisica.php` |
| Reporte y cierre de cada comida | `app/Services/ReporteComidaService.php`, `app/Http/Controllers/ReporteComidaController.php`, `app/Services/ComidasFrecuentesService.php` |
| Cierre diario | `app/Services/DailyClosureService.php`, `app/Console/Commands/RunDailyClosure.php` (`dailyAt('00:15')`) |
| Motor de recomendaciones | `app/Services/RulesEngineService.php`, `app/Http/Controllers/RecomendacionSistemaController.php` |
| Analítica de tendencias | `app/Services/TrendAnalyticsService.php`, `app/Services/SeguimientoService.php`, `app/Console/Commands/CalculateTrends.php` (`dailyAt('00:30')`) |
| Inicio (dashboard) | `app/Http/Controllers/DashboardController.php`, `app/Services/DashboardEstadoService.php` |
| Dictado por voz | `resources/js/tudi/dictado.js` (reconocimiento nativo, camino normal); plan B apagado por defecto: `app/Services/AI/TranscripcionAudioProviderInterface.php` + `OpenAiTranscripcionProvider.php`, `app/Http/Controllers/TranscripcionController.php` |
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
- **`users`**: perfil nutricional (`peso_kg`, `estatura_m`, `edad`, `sexo`, `nivel_actividad`, `tipo_deficit`, `valor_deficit`, `proteina_factor`, `grasa_factor`, `calorias_objetivo` — todas nullable hasta completar la Calculadora), administración (`rol` enum(usuario,admin), `estado` enum(pendiente,activo,suspendido) default `activo`, `codigo_activacion`, `activado_en` — sección 5.1) y **plan** (`plan` enum(gratis,trial,premium) default `gratis`, `plan_expira_en` timestamp nullable — sección 5.18). `estado` y `plan` son dos dimensiones distintas: el primero decide **si** entra, el segundo **qué** funciones tiene.
- **`registros_diarios`**: `usuario_id` + `fecha` (único), `peso_kg` del día, el snapshot del cierre (`calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, y objetivo/consumido de los tres macros: `proteina_objetivo_g`/`proteina_consumida_g`, `grasa_objetivo_g`/`grasa_consumida_g`, `carbohidratos_objetivo_g`/`carbohidratos_consumidos_g`), `cerrado` + `cerrado_en`, `ingredientes_desayuno`/`ingredientes_almuerzo`/`ingredientes_cena` (texto libre por comida).
- **`planes_comida`**: `tipo_comida` enum(desayuno,almuerzo,cena,snack), `origen` enum(plan,reporte) default `plan`, macros estimados, `descripcion` + `preparacion` + `notas_ia`, `ingredientes_detalle` (json, snapshot denormalizado a propósito — sigue siendo legible aunque se editen los ingredientes de origen). `origen = reporte` marca la fila que existe solo para colgar de ella una `ComidaReal` de una comida que nunca se planificó (sección 5.5): sus macros estimados son cero porque no se sugirió nada, y al reabrir la comida se borra entera.
- **`comidas_reales`**: `plan_comida_id` único (relación 1—1, nunca se sobrescribe el plan), macros reales, `consumido_en`, `notas`, `imagen_evidencia`.
- **`actividades_fisicas`**: `calorias_dispositivo`, `factor_correccion` (0.8–0.9), `calorias_ajustadas`, `pasos`, `fuente` enum(manual,dispositivo).
- **`metricas_tendencia`**: promedios móviles de 7 días (`promedio_movil_peso_kg`, `promedio_movil_calorias`, `promedio_movil_deficit_kcal`), `indice_consistencia_pct`, `dias_con_datos`, `porcentaje_perdida_semanal`, `tendencia` enum.
- **`recomendaciones_sistema`**: `estado` enum(pendiente,confirmada,rechazada) default `pendiente` — nunca se aplica un ajuste sin confirmación (sección 8).
- **`parametros_maestros`**: `clave` única, `valor` (texto), `actualizado_por` — solo guarda lo que el administrador cambió; una clave ausente significa "el valor de fábrica".
- **`recursos_didacticos`**: `clave` única, `tipo` (url|archivo), `valor`, `nombre_original`, `actualizado_por` — misma forma que la anterior pero para contenido, no umbrales (sección 5.15).
- **`onDelete`: cascade en todas las FKs de dominio** — no hay catálogo compartido en el modelo de datos. Excepción deliberada: `parametros_maestros.actualizado_por` y `recursos_didacticos.actualizado_por` usan `nullOnDelete()` — borrar al administrador que tocó un umbral o publicó un recurso no debe llevarse por delante el valor o el archivo.

`ComidaReal` es una entidad separada de `PlanComida` a propósito: preserva el historial "planificado vs. ejecutado". No cambiar este diseño sin discutirlo.

## 5. Funcionalidades de la plataforma

### 5.1 Cuentas: registro, activación y ciclo de vida

Cualquiera puede registrarse (`RegisteredUserController` → `CuentaService::registrar()`) y **la cuenta nace activa, con su prueba de Premium ya corriendo** (sección 5.18) y va directa a la Calculadora. Desde que hay landing pública (sección 5.19) el registro es el embudo de adquisición: hacer esperar a un visitante a que un administrador le pase un código convertía la landing en una lista de espera.

**La validación manual por código no se tiró, cambió de sitio.** El estado `pendiente`, la pantalla de activación y el middleware siguen enteros, y ahora la puerta de entrada a `pendiente` es `CuentaService::regenerarCodigo()`: el administrador devuelve una cuenta a revisión y entonces sí salen los dos correos de siempre (`CuentaPendienteDeActivacion` al usuario, sin el código; `NuevoUsuarioPendiente` a los administradores, con él). El código es de 8 caracteres, alfabeto sin 0/O/1/I/L.

- **Middleware `cuenta.activa`** (`EnsureCuentaActiva`): una cuenta `pendiente` va a `GET/POST /activacion` sin perder la sesión; una `suspendida` pierde la sesión y vuelve al login con el motivo. No se aplica a las rutas de autenticación ni a la propia activación (evitaría un bucle). **Ningún plan pasa por aquí**: el plan nunca deja a nadie fuera.
- **`ActivacionController`** canjea el código contra la cuenta con sesión iniciada (`hash_equals`, tiempo constante); al activar se quema el código y se avisa por correo (`CuentaActivada`).
- **`CuentaService`** es el único punto con lógica de ciclo de vida: `registrar()`, `activarConCodigo()`, `activar()` (desde consola), `suspender()` (no borra datos), `regenerarCodigo()`. El plan con el que nace la cuenta lo pone `PlanService`, que es su dueño.
- Correos del alta: al usuario `CuentaActivada` (con los días de prueba si los tiene), a los administradores `NuevoUsuarioRegistrado` (sin código, porque ya no hay ninguno que entregar).
- Todas las notificaciones son `ShouldQueue` — esperar al SMTP en la petición web ocupa un worker de PHP-FPM (sección 5.13).
- `php artisan tudi:hacer-admin {email}` crea el primer administrador (activa la cuenta de paso, porque la consola exige ya serlo).
- Auth estándar de Breeze (registro, login, logout, recuperación de contraseña) en `routes/auth.php`. `GET /` sirve la landing sin sesión y redirige a `dashboard` con ella (sección 5.19).

### 5.2 Calculadora Déficit (`/calculadora`)

Primer paso del flujo, dimensiona todo lo demás. `ProfileParametersController` (rutas `calculadora.edit`/`calculadora.update`; la clase conserva su nombre de la época en que la ruta se llamaba `/profile/parametros`).

- **Orientada a objetivo, no a factores.** Pregunta sexo, peso, estatura, edad, **"¿qué tan activo eres?"** (cuatro escalones con explicación visible bajo el control — excepción documentada a la regla 8 de la sección 13) y **"tu objetivo"** (Mantener/−10%/−20%/−30%, también explicado). Proteína y grasa se **derivan** del objetivo elegido (cuanto más agresivo el déficit, más proteína) y quedan en "Ajustes avanzados", editables a mano si se necesita.
- **Material de apoyo** (sección 5.15): si el administrador publicó video o guía en PDF, aparecen como **dos botones** al pie del panel "Tu objetivo diario" — el video se abre en una capa sobre la página (con `x-if`, para no cargar el iframe hasta que se pulsa), el PDF se descarga. Son una ayuda, no reordenan la pantalla: el ancho de la Calculadora no cambia. Sin nada publicado, se ve exactamente igual que sin la funcionalidad.
- El resultado se muestra **arriba** del formulario, recalculado en vivo por un espejo en JS de la fórmula de la sección 7 (`calculadoraDeficit()` en la vista) — la cifra que se persiste la calcula siempre `NutritionCalculatorService` en el servidor, al guardar.
- `ProfileParametersController@update` calcula y persiste `users.calorias_objetivo`, que es **el objetivo calórico vigente**: lo consumen `MealDistributionService` y `DailyClosureService` en vez de recalcular desde la fórmula cruda, y solo lo mueve `RulesEngineService::confirmar()` (sección 5.6) o una edición explícita de parámetros. Si el cálculo lanza `NegativeCarbohydrateException`/`InvalidNutritionParameterException` no se persiste nada.
- Se ve en toda la plataforma junto al nombre del usuario en la navegación.
- Campos decimales (peso, estatura, proteína, grasa) son `type="text" inputmode="decimal"`, nunca `type="number"` — ver sección 9.

### 5.3 Planes diarios: comidas y ajuste del plan con IA (`/planes`)

**"Planes diarios" es el menú; un plan diario es un `RegistroDiario` con todo su día dentro.** `PlanComidaController`: listado paginado (`GET /planes`), crear el de hoy (`POST /planes`), detalle-hub (`GET /planes/{registroDiario}`) con cuatro secciones: cálculo alimenticio, reporte de comidas, actividad física, cierre. 403 si el plan no es del usuario.

**Cálculo alimenticio.** El usuario escribe (o dicta) un párrafo por comida y pulsa **un único** "Calcular mi plan" — las tres comidas viajan juntas en una sola llamada al proveedor, porque el reparto del día es un solo problema de asignación. Se llama *calcular* y no *generar* porque lo que reparte es el **saldo** del día: `MealDistributionService::distribuirDia()` clasifica cada comida y descuenta primero lo que ya se comió de verdad.

| Clase | Cuándo | Presupuesto |
|---|---|---|
| fija | ya cerrada (tiene `ComidaReal`), o ya resuelta y con el texto sin cambios | se descuenta del día con sus cifras **reales**, no se toca |
| a generar | texto nuevo/cambiado, pedida con `rehacer`, **o abierta cuando el saldo del día se movió** | recibe su parte proporcional del reparto vigente |
| reservada | sin texto todavía | se aparta su parte, no se resuelve |

- **Una comida cerrada no se toca mientras lo esté** (sección 5.5): ni se regenera ni se le reescribe el texto de ingredientes, ni siquiera si llega en la petición. Para cambiarla hay que reabrirla.
- **El saldo movido rehace lo que falta.** Si una comida cerrada se comió por una cifra distinta de la planificada (más de `TOLERANCIA_SALDO_KCAL`, 1 kcal de ruido de redondeo), las comidas abiertas con texto se regeneran aunque su texto no haya cambiado: se generaron contra un presupuesto que ya no es el que queda, y arreglarlo es justo para lo que existe el botón. Una comida cerrada con "cumplí lo sugerido" no lo dispara —lo real y lo planificado coinciden—, así que pulsar sin que haya pasado nada sigue sin gastar una llamada.
- `MealPlanGeneratorService::DISTRIBUCION_COMIDAS` (`desayuno 0.30 / almuerzo 0.40 / cena 0.30`) declara qué comidas hay y el reparto **balanceado de partida**; cuál rige en cada día lo deriva `RepartoComidasService` a partir de la actividad física registrada (sección 5.14).
- "Rehacer solo el X" fuerza a regenerar una comida sin texto nuevo; una comida cerrada nunca se regenera.
- Los presupuestos por comida y los totales de cada plan los calcula **PHP**, nunca el modelo (regla 7, sección 13).
- **Cuota diaria**: cada pulsación es una llamada facturable y gasta una unidad de `ia_limite_distribuciones_dia` (sección 5.20). Sin cuota, el botón desaparece y la pantalla dice qué se puede seguir haciendo.
- **`OpenAiMealDistributionProvider`** (vigente): `chat/completions` con salida estructurada **estricta** (`response_format.json_schema`, `strict: true`), `temperature = 0.1`, `max_completion_tokens`, `OPENAI_MODEL=gpt-4.1`. Timeout 20 s / connect 5 s (sección 5.13). Ningún fallo produce 500: `MealDistributionUnavailableException` cubre sin clave, fallo del proveedor, negativa explícita (`refusal`), filtro de contenido, corte por longitud, respuesta ininterpretable, cuota agotada o "nada que distribuir". El texto del usuario se guarda aunque la generación falle. `GeminiMealDistributionProvider` y `ClaudeMealDistributionProvider` siguen en el repo sin bindear (sección 6).
  - **El esquema se arma por llamada**, con el `enum` de los tipos de comida que de verdad se pidieron: una comida inventada ("merienda") ya no es posible ni a nivel de API. La validación en PHP sigue ahí igualmente — el esquema es de la API, no del dominio.
  - **La comida posterior al entrenamiento va como contexto, no como otro objetivo de macros.** `contexto_dia.comida_post_actividad` le dice al modelo cuál es, para que **dentro** del presupuesto de esa comida prefiera los carbohidratos. Retocar solo los carbohidratos de una comida rompería la coherencia entre sus macros y sus calorías; lo que sí crece es su parte del día, y eso lo decide PHP (sección 5.14).
  - **Corrección de macros acotada, no reintento ciego.** PHP suma los totales de la distribución y los compara con los objetivos de las comidas resueltas (calorías y proteína, que son los dos que el dominio persigue). Si se desvían más de `OPENAI_TOLERANCIA_MACROS` (5%), se le devuelve al modelo **su propia respuesta con las sumas concretas que fallaron** —el dato que él no tiene— para que reajuste los gramos. Como mucho una corrección (`OPENAI_REINTENTOS_MACROS`), y **la corrección hereda el tiempo que sobra** de `OPENAI_PRESUPUESTO_TOTAL` (25 s) en vez de estrenar otro timeout entero: el techo total de la petición sigue por debajo del gateway (regla 10). Si aun así no cuadra, **se devuelve el mejor intento, nunca un error**: con lo que la persona tiene puede ser imposible llegar al objetivo, y ese es justo el caso que el campo `notas` explica. El prompt de corrección insiste en no inventar alimentos para cuadrar.
  - **`OPENAI_TEMPERATURE=null`** omite el parámetro del cuerpo. Hace falta con las familias de razonamiento (gpt-5, o3, o4), que rechazan con un 400 cualquier valor distinto de 1.
- **Dictado por voz** en los textareas de ingredientes — ver sección 5.9.
- **Lo que se comió se reporta comida a comida** en su propia sección (sección 5.5). `ComidaRealController@create`/`@store` (`/plan/{planComida}/comida-real`) sigue existiendo sin enlazar desde la interfaz, para corregir macros a mano si hace falta.
- **Peso del día** (`POST /planes/{registroDiario}/peso`) vive en el panel de objetivo del plan diario, no en pantalla propia; no toca `users.peso_kg` (el de perfil).
- **Guardado sin recargar:** los formularios `data-fetch` (ajuste del plan, cierre y reapertura de cada comida, peso) se envían por `fetch` y reemplazan `#tudi-avisos`/`#panel-objetivo`/`#lista-comidas`/`#seccion-actividad`/`#seccion-cierre` con la respuesta; sin JS se envían normal. El acordeón deja **una comida abierta a la vez**: como cada tarjeta lleva dentro su plan y su cierre, abrir una y plegar el resto es lo que evita tener media pantalla de formularios.

### 5.4 Actividad física

`ActivitySuggestionService::sugerir()` propone actividad para el día (no persiste nada, no toca `calorias_objetivo`): objetivo = una parte del déficit dietético (mantenimiento − objetivo vigente), acotado entre un suelo y un techo — ambos ajustables desde parámetros maestros (sección 5.11) —, con duraciones por la fórmula MET estándar. `ActividadFisicaController@store` (`POST /planes/{registroDiario}/actividades`) registra lo real y aplica `ActivityCorrectionService::FACTORES_POR_TIPO` (0.80–0.85 según tipo, case-insensitive; un factor fuera de 0.8–0.9 se rechaza, nunca se recorta en silencio). Ambas viven agrupadas en una sola sección desplegable del plan diario, con la cabecera resumiendo lo hecho contra el objetivo sin necesidad de abrirla.

**Lo registrado no solo se resta: reparte.** Una `ActividadFisica` del día desplaza el reparto entre comidas hacia la comida posterior al entrenamiento (sección 5.14), y esa misma comida es la que el prompt marca para priorizar carbohidratos (sección 5.3). Las calorías de la actividad siguen entrando en el déficit por donde siempre — la fórmula de la sección 7 no cambia.

### 5.5 Cierre de cada comida y cierre del día

**Cada comida se cierra por separado, en cuanto se come.** Antes lo que se había comido se preguntaba todo junto al cerrar el día, cuando ya no servía para ajustar nada: hasta ese momento el almuerzo y la cena se dimensionaban contra el objetivo entero aunque el desayuno se hubiera ido 300 kcal por encima. Ahora **cada comida lo lleva todo dentro de su propia tarjeta** del cálculo alimenticio —los ingredientes, lo que se le planificó, "Rehacer solo el X" y su cierre—, porque planificar una comida y contar qué se comió en ella son dos pasos del mismo gesto; tenerlos en dos secciones obligaba a buscar la misma comida dos veces en la pantalla. Desde que una comida se cierra, sus cifras entran en el saldo del día (sección 5.21) y en el siguiente "Calcular mi plan".

**Un solo botón que cambia de papel.** "Cerrar desayuno" abre los campos del reporte dentro de la tarjeta y se convierte en "Confirmar cierre"; con la comida ya cerrada queda "Reabrir desayuno". Así la tarjeta no enseña un formulario de reporte a quien todavía no ha comido. Visualmente es del mismo tamaño y color que "Rehacer solo el X" (`tudi-btn-primary`, ancho de su texto en escritorio): una acción normal de la tarjeta, nunca al nivel de "Calcular mi plan", que es la que reparte el día entero.

**El formulario de "Calcular mi plan" no envuelve a las tarjetas**: cada comida lleva dentro su propio \`<form>\` de cierre y un \`<form>\` no puede anidarse en otro. Los campos de ingredientes y los botones de ajuste se asocian a él por el atributo \`form=\`, que existe exactamente para esto; \`FormData\` y \`submitter\` los recogen igual, así que el guardado sin recargar no cambia.

`ReporteComidaService::reportar()` + `ReporteComidaController` (`POST /planes/{registroDiario}/comidas/{tipoComida}/cerrar|reabrir`; 404 si el tipo de comida no está en `DISTRIBUCION_COMIDAS`). Cuatro caminos, y solo uno cuesta una llamada:

| Camino | Qué hace | Cuota |
|---|---|---|
| "Cumplí lo sugerido" | `ComidaReal` con los macros del propio `PlanComida` | no gasta |
| "Lo que sueles comer", sin tocar el texto | copia los macros de un reporte anterior (sección 5.22) | no gasta |
| Contarlo por escrito (o "lo que sueles comer" editado) | `estimarConsumoReal()` con esa comida sola | gasta `ia_limite_reportes_dia` |
| Sin plan previo | crea un `PlanComida` con `origen = reporte` y macros a cero para colgar de él la `ComidaReal` | según el camino |

- **"Lo que sueles comer" es un atajo de escritura, no un envío.** El chip solo copia su texto en el campo "Cuéntanos qué comiste de verdad…" (con el id de aquel reporte en un campo oculto) y no manda nada por su cuenta. `ReporteComidaService::reportar()` reutiliza los macros de ese reporte anterior únicamente si el texto llega **tal cual** se copió (`ComidasFrecuentesService::coincideCon()`); en cuanto se edita o se dicta encima, el campo oculto se limpia y lo que se envía pasa por el proveedor como cualquier otro texto. Así el atajo nunca cuesta una llamada mientras siga siendo la misma comida.
- **El texto manda** sobre el interruptor: si contó qué comió, esa es la información más fiel.
- **La foto de evidencia se adjunta aquí.** Una imagen sola, sin decir qué se comió, no crea ninguna `ComidaReal` y devuelve un error legible.
- **Reabrir una comida** (`ComidaRealService::eliminar()`) borra su `ComidaReal` y su imagen, recalcula `calorias_consumidas` y devuelve la comida al estado "planificada". Si el `PlanComida` era `origen = reporte`, se borra también: sin su `ComidaReal` no queda nada dentro. **Reabrir no devuelve cuota** — si la devolviera, abrir y cerrar la misma comida sería una llamada gratis infinita (sección 5.20).
- Un segundo envío sobre una comida ya cerrada se rechaza en vez de sobrescribir: un doble clic no puede borrar lo que ya se contó.

**Cerrar el día ya no llama a la IA.** `DailyClosureService`: `resumen()` (vista previa si el día está abierto, snapshot congelado si está cerrado), `cerrar()` (suma, calcula el déficit con `NutritionCalculatorService` y congela; lanza `DayAlreadyClosedException` si ya estaba cerrado — no es idempotente a propósito), `reabrir()` (acción explícita, no-op si ya estaba abierto), `comidasSinReportar()` y `diagnosticoRecomendaciones()`. Las cifras se recalculan siempre desde `ComidaReal`/`ActividadFisica`, nunca desde acumuladores. Cerrar el día es gratis, instantáneo y no puede fallar por un servicio externo.

**Controles de validación del cierre** (`CierreDiarioController`), porque un día cerrado sin reportar nada no es un día sin comer sino un día sin contar, y su cero entra luego en el promedio móvil de 7 días como si fuera un dato bueno:

- **Sin ninguna comida reportada** no se cierra, y se dice qué falta.
- **Con alguna comida sin reportar** se cierra solo con `confirmar_sin_reportar`; la pantalla lista cuáles son.

El snapshot congela **objetivo y consumido de los tres macros**, no solo de la proteína: la tarjeta "Resultado real del día" los muestra los cuatro, y para un día cerrado tienen que salir del snapshot y no del perfil actual. Esa tarjeta es **la única** lectura de las cifras del día: la fila de cuatro indicadores sueltos (objetivo, consumidas, déficit, proteína) que había encima se retiró porque repetía lo mismo dos veces. El déficit sí se conserva, dentro de la tarjeta: es la única cifra que las barras no dan —incluye el gasto por actividad, que no es un macro— y es el número que persigue todo el producto (sección 7). Los días cerrados antes de que existieran esas columnas devuelven `null` y se pintan como "—", nunca como cero.

Un día cerrado es inmutable: `ComidaReal`/`ActividadFisica` nuevas se rechazan, y borrarlas también; escribir texto de ingredientes, ajustar el plan y registrar el peso sí se permiten (no alteran cifras del cierre). `CierreDiarioController` (`POST /planes/{registroDiario}/cierre|reabrir`). Automatizado: `app:run-daily-closure` cierra los `RegistroDiario` de ayer sin cerrar, `dailyAt('00:15')` — el comando llama al servicio directamente, así que las validaciones de arriba son del camino web, no del dominio.

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

`DashboardController` es de solo composición: pide el estado completo a `DashboardEstadoService::calcular()` y lo pasa a la vista sin tocarlo. **Qué sub-estado corresponde en cada bloque lo decide ese servicio, nunca la vista ni el controlador** — cada combinación es un caso de borde cubierto por `tests/Unit/DashboardEstadoServiceTest.php`, sin necesidad de levantar una petición HTTP. Cinco bloques:

- **0. Avisos** — el estado del plan (sección 5.18) y, si ayer quedó alguna comida sin reportar, una línea con el enlace para completarla (sección 5.24). Se pintan siempre, incluso en el primer login.
- **1. Bienvenida** — solo cuando el usuario no tiene **ningún** `RegistroDiario` todavía: sustituye enteros a los bloques 2-4 con un único mensaje y una sola acción, para no enseñar un anillo en 0%, un gráfico sin puntos o una tabla en blanco justo en el peor momento para desmotivar a quien recién llega. CTA a la Calculadora si además le faltan parámetros base; si no, CTA a "Crear tu primer plan de hoy".
- **2. Hoy** — tres sub-estados sobre el `RegistroDiario` de hoy, más el caso transversal de parámetros incompletos (mismo mensaje que antes, con CTA a la Calculadora):
  - *Sin plan* — usuario recurrente que no ha abierto el día: tarjeta simple con botón "Generar plan de hoy".
  - *En curso* — **el anillo deja de ser la cifra y pasa a ser el indicador**: `.tudi-ring-mini` (76px) con su porcentaje dentro (`--pct` = consumidas / (objetivo + actividad), acotado 0–100), y al lado la cifra protagonista, que es la accionable: **lo que queda** (`MealDistributionService::saldoDelDia()`, sección 5.21: "Te quedan / Te pasaste por X kcal para [comidas pendientes]"), nunca un "déficit" — ese sustantivo de resultado solo se gana con el día cerrado (regla transversal más abajo). Completan el panel la barra de proteína real contra objetivo, las tres comidas como **chips en línea** (lima con ✓ la cerrada, blanca la planificada, apagada la pendiente) y el enlace al plan. Todo dentro del único panel carbón de la pantalla: en móvil apilado, en `sm:` dos mitades.
  - *Cerrado* — la tarjeta "Resultado real del día" (`x-tudi.resultado-dia`, sección 5.5) sobre el snapshot congelado de `DailyClosureService::resumen()`, con enlace a "Ver el detalle del día". Sin anillo: el día ya terminó.
- **3. Tu tendencia** — depende de `datos_suficientes` (`TrendAnalyticsService::calcular()`, ventana de 7 días):
  - *Insuficiente* — el mismo checklist que ya usa el cierre del plan diario (`x-tudi.diagnostico-checklist`, alimentado por `TrendAnalyticsService::diagnosticoRecomendaciones()`, que ya no depende de un `RegistroDiario` concreto). Nunca un gráfico vacío ni un promedio con un solo dato.
  - *Suficiente* — **una sola tarjeta crema** con, dentro, dos fichas separadas a propósito y nunca fundidas en una cifra: "Último peso registrado" (el pesaje real más reciente, `TrendAnalyticsService::ultimoPesoRegistrado()`, fechado **en días** — `fecha` no guarda hora, así que un `diffForHumans()` diría "hace 9 horas", una precisión que el dato no tiene) y "Tendencia 7 días" (promedio móvil + % semanal con flecha ↓/↑, y la aclaración de una línea "no es tu peso de hoy"). Debajo, el sparkline de pesajes reales y dos filas etiqueta→valor: "Déficit promedio" y "Racha de días cerrados" (sección 5.23).
    - **El sparkline sustituyó al gráfico grande de Chart.js, que ya no existe.** `x-tudi.sparkline` lo dibuja como SVG en línea desde el servidor a partir de `serieHistorica()` (que expone el `peso_kg` crudo de cada día, además del promedio móvil). La línea va en un `<svg preserveAspectRatio="none">` con `vector-effect="non-scaling-stroke"` y **los puntos son elementos HTML colocados en porcentajes**, no `<circle>`: dentro de un SVG estirado un círculo se vería como una elipse. Los días sin pesaje no se rellenan ni se interpolan — solo se dibuja lo que de verdad se pesó, en su sitio de la ventana (sección 5.7). Casos degenerados cubiertos por `tests/Feature/SparklineTest.php`: sin pesajes dice qué hacer, con uno solo no dibuja una línea inexistente, y con todos los pesos iguales sale plana en vez de dividir por cero.
- **4. Tu seguimiento** — depende de si ya pasó una semana natural completa desde el primer `RegistroDiario` del usuario (`SeguimientoService::primerDiaRegistrado()`; "completa" es contra el calendario, no contra la adherencia — basta que hayan pasado los 7 días naturales, aunque no se hayan cerrado todos):
  - *Incompleta* — un mensaje breve con la fecha en la que tendrá sentido volver a mirarla, sin tabla en blanco.
  - *Completa* — las seis semanas (una en el plan Gratis) con adherencia/comidas/peso medio/variación/déficit, y debajo "Ajustes de tu objetivo": el historial de recomendaciones con sus botones Confirmar/Rechazar (antes era una sección aparte; vive aquí porque ambas cuentan la misma historia de varias semanas).

`/progreso` es un `Route::redirect` a `/dashboard` (pantalla ya fusionada, se conserva el enlace).

**Regla transversal de honestidad de datos:** ningún bloque muestra una cifra como definitiva antes de que su condición de cierre se cumpla (día cerrado, semana natural completa, 7 días de historial). Mientras no se cumpla, el texto usa "te queda"/"vas en"/"todavía no", nunca un sustantivo de resultado — es la razón por la que el bloque Hoy en curso dejó de decir "Déficit de hoy".

**Reutilizar, no duplicar:** la tarjeta "Resultado real del día" (`x-tudi.resultado-dia`) y el checklist de diagnóstico (`x-tudi.diagnostico-checklist`) son componentes Blade compartidos entre el cierre del plan diario (`planes/show.blade.php`) e Inicio — un solo sitio que pintar, dos pantallas que lo usan. La ficha de "Hoy en curso", en cambio, **no** se comparte con el plan diario: el plan diario nunca mostró un anillo de progreso (solo el panel "Objetivo del día" con el saldo en barras), así que se construyó como partial propio de Inicio (`resources/views/dashboard/partials/hoy.blade.php`) reutilizando los mismos servicios de dominio (`DailyClosureService::resumen()`, `MealDistributionService::saldoDelDia()`) en vez de la vista del plan diario — decisión documentada aquí por si en el futuro se unifica también la presentación.

### 5.9 Dictado por voz

**Reconocimiento nativo del navegador, sin coste.** El único camino normal es la Web Speech API: quien reconoce la voz es el sistema operativo (Windows, Android, macOS e iOS lo traen), el audio no sale del dispositivo y no cuesta ninguna llamada al proveedor.

- **iOS entra por el mismo camino.** Safari soporta `webkitSpeechRecognition` pero ignora `continuous = true`: corta la sesión sola en cada pausa. La solución nativa es reconocer **por tramos y reengancharlos** (`continuous = false` + arrancar otra sesión en `end` mientras el usuario no pulse "Listo"), acumulando el texto definitivo entre tramos. `TRAMOS_MUDOS_MAXIMOS` evita reenganchar para siempre con un micrófono callado.
- **"Listo" no espera al evento `end`.** En iOS Safari, llamar a `stop()` no siempre lo dispara a tiempo —y si el clic cae en mitad de un reenganche entre tramos, puede no llegar nunca—, así que `dictado.js` inserta el texto (lo confirmado más lo que todavía estuviera a medio reconocer) en el mismo clic de "Listo", y solo después intenta parar la sesión en segundo plano. Un `end` tardío que llegue después ya no hace nada (guardián `cerrado`).
- **El plan B —grabar y transcribir en el servidor— está apagado por defecto.** `POST /transcribir` (único endpoint JSON, `throttle:30,1`, `OpenAiTranscripcionProvider`) sigue implementado y probado, pero solo responde con `TRANSCRIPCION_FALLBACK_SERVIDOR=true`: cada dictado sería una llamada facturable y ocuparía un worker de PHP-FPM (sección 5.13). Apagado, el layout ni siquiera emite la meta `ruta-transcribir`, así que el JS no tiene a dónde mandar audio.
  - Endpoint propio (`audio/transcriptions`, multipart) y modelo propio (`OPENAI_MODEL_TRANSCRIPCION`), no el de texto. **La extensión del nombre de archivo no es cosmética**: es como la API elige el decodificador, y sin ella responde 400 — de ahí el mapa MIME→extensión del proveedor. El idioma se fija en `es` en vez de dejar que lo detecte: equivocarse sobre dos palabras devuelve una transcripción inservible.
  - Las muletillas que el modelo inventa sobre audio en silencio ("gracias por ver el video", artefacto de haberse entrenado con subtítulos) se filtran y se tratan como "no se escuchó nada": si no, acabarían escritas en el campo de ingredientes del usuario.

Si el navegador no puede reconocer voz y el plan B está apagado, el botón del micrófono queda oculto y se escribe a mano. Cubre los textareas de ingredientes y el texto del feedback de cierre, con un popup de grabación (tiempo, transcripción en vivo, "Listo"/"Cancelar").

### 5.10 Consola de administración (`/admin`)

Rutas bajo `auth` + `cuenta.activa` + `admin` (`EnsureEsAdministrador`, **403 y no redirect**).

- `GET /admin` — cifras y cola de activación con el código de cada pendiente a la vista, más los accesos a parámetros maestros y material de apoyo.
- `GET /admin/usuarios` — búsqueda por nombre/correo/código, filtro por estado, pendientes primero; activar/suspender/promover/degradar/dar o quitar Premium a mano/eliminar (con confirmación, cascade se lleva todo el historial). "Nuevo código" ya no está: con la cuenta naciendo activa (sección 5.1), regenerar código solo tiene sentido al devolver una cuenta a `pendiente` desde el cambio de estado, y ahí lo sigue haciendo `CuentaService::regenerarCodigo()` por su cuenta.
- **Un administrador no puede degradarse, suspenderse ni borrarse a sí mismo** (`ActualizarUsuarioRequest::after()` + `abort_if`).
- "Administración" es un ítem más de la barra lateral **solo para administradores**, y está en el menú del avatar; no entra en la barra inferior de móvil (esos tres destinos son el flujo diario del usuario).

### 5.11 Parámetros maestros (`/admin/parametros`)

`ParametrosMaestrosService::CATALOGO` es la **única declaración** de qué parámetros existen, tipo, límites y explicación; sus valores de fábrica referencian las constantes públicas de los servicios que los consumen (no una copia). Once parámetros: cinco del motor de recomendaciones (sección 5.6), cuatro de la sugerencia de actividad (sección 5.4) y dos de la cuota diaria de IA (sección 5.20). La tabla solo guarda lo que cambió; los valores se cachean juntos y para siempre, invalidados al guardar. Si la tabla no existe todavía (deploy antes de `migrate`), cae a los valores de fábrica sin tumbar la aplicación.

**Qué NO entra, a propósito:** el reparto entre comidas (no es un umbral de criterio sino una función del día que deriva `RepartoComidasService` — sección 5.14), el material de apoyo (es contenido y uno de sus valores es un archivo subido — sección 5.15), las fórmulas de la sección 7 (son la definición del producto), el modelo/timeout del proveedor de IA (configuración de despliegue, vive en `.env`).

### 5.12 Identidad visual, mobile-first y shell instalable

- **`resources/css/tudi-tokens.css`** es la única fuente de verdad de color, tipografía, radio y espaciado; se importa antes de las directivas de Tailwind. `tailwind.config.js` refleja los mismos valores — si un token cambia, se cambia en el CSS y se copia allí, nunca al revés. Fondo crema siempre, un panel carbón por pantalla con la cifra protagonista, **lima solo para progreso** (sobre crema el primario es carbón), ámbar para avisos (no hay rojo en la paleta).
- **Tres tamaños de anillo, y los nombres no son intercambiables:** `.tudi-ring` (214px, el anillo protagonista), `.tudi-ring-sm` (176px, ese mismo anillo en escritorio — vive en `app.css`) y `.tudi-ring-mini` (76px, el indicador que acompaña a una cifra en vez de contenerla, Inicio sección 5.8). Antes de añadir una variante a un componente `.tudi-*`, comprobar que el nombre no esté ya tomado en el otro archivo: los dos se concatenan en el mismo CSS y gana el último, sin aviso.
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

- `OPENAI_TIMEOUT=20` / `OPENAI_CONNECT_TIMEOUT=5` / `OPENAI_PRESUPUESTO_TOTAL=25` — los tres deben quedar por debajo del `fastcgi_read_timeout` del servidor para que corte la aplicación (con mensaje) y no el gateway. El presupuesto es el techo del conjunto de intentos, no de uno solo (sección 5.3).
- Correos en cola (sección 5.1), no en la petición web.
- `php artisan tudi:diagnostico`: comprueba en segundos y solo leyendo entorno/timezone, límites de PHP, latencia y tablas de la base de datos, migraciones pendientes, driver de sesión, cola, permisos, `public/build/manifest.json`, y si el hosting bloquea la salida HTTPS a OpenAI. Código de salida distinto de cero si algo crítico falla.
- `DEPLOY.md` sección 8 documenta el triaje completo de un 504 (`/up`, pool de PHP-FPM, por qué `SESSION_DRIVER=file` lo provoca).

### 5.14 Reparto de calorías entre comidas

**Ya no lo teclea el usuario: se deriva.** Había un panel "Reparto del día" con tres porcentajes editables y se retiró — repartir el día es una decisión nutricional, no una preferencia de interfaz, y pedirle a quien está aprendiendo a comer mejor que elija los porcentajes es pedirle justo lo que ha venido a que le resuelvan. `RepartoComidasService::paraElDia()` lo calcula a partir de dos cosas y nada más:

1. **Un reparto balanceado de partida** — `MealPlanGeneratorService::DISTRIBUCION_COMIDAS`, **30/40/30**. Ninguna comida queda testimonial y la cena no carga con el día, que es el hábito que se quiere corregir: quien desayuna poco llega a la noche con hambre y come de más cuando menos actividad le queda por delante.
2. **La actividad física registrada ese día** — la comida posterior al entrenamiento recibe más parte del día. Cuánto más es proporcional a lo quemado sobre el objetivo (`calorias_ajustadas / calorias_objetivo`), con techo en `PUNTOS_MAXIMOS_ACTIVIDAD` (10 puntos) para que una sola sesión larga no desfigure el reparto, y suelo `PROPORCION_MINIMA` (15%) para el resto.

- **Qué comida es "la posterior"**: la primera todavía abierta cuya `HORA_DE_REFERENCIA` (desayuno 8, almuerzo 13, cena 19) cae en o después de la hora de la actividad; si ya pasaron todas, la última que siga abierta. La hora sale del `created_at` de la `ActividadFisica` —se registran al terminar— y por eso no hizo falta una columna nueva. Si todas las comidas están cerradas no hay nada que desplazar y rige el balanceado.
- **El día no crece, solo cambia de forma.** La suma sigue siendo 1.0; las calorías de la actividad entran en el déficit por donde siempre (sección 7).
- **No se persiste en ninguna columna.** Es una función pura del día, así que guardarlo solo abriría la puerta a que una fila contradiga al cálculo. Las columnas `users.reparto_comidas` y `registros_diarios.reparto_comidas` se eliminaron con la ruta y el formulario.
- **No recalcula nada ya generado.** Los macros de un `PlanComida` están persistidos; el reparto dimensiona los objetivos que se muestran y el presupuesto de lo que queda por generar. Cuando solo faltan dos comidas, lo disponible se reparte con los pesos del reparto vigente, no a partes iguales.
- `explicacion()` devuelve qué comida recibió el desplazamiento y de cuánto fue, para poder contarlo en pantalla **en una línea** — nunca un párrafo de instrucciones (regla 8).
- Las **claves** siguen saliendo de `DISTRIBUCION_COMIDAS` porque son nombres de columna (`ingredientes_*`) y de campo de formulario; lo único que varía es el porcentaje.
- **Los carbohidratos post-entreno no son un reparto aparte.** La comida posterior recibe más de *todo*, y al proveedor se le dice cuál es para que **dentro** de ella prefiera los carbohidratos (sección 5.3). Un reparto distinto por macro haría que los macros de una comida no sumaran sus propias calorías.

### 5.15 Material de apoyo (`/admin/recursos`)

`RecursosDidacticosService::CATALOGO` declara los dos recursos que existen: `calculadora_video_url` (tipo `url`) y `calculadora_guia_pdf` (tipo `archivo`). El administrador los publica desde la consola y el usuario los ve en la Calculadora (sección 5.2).

- **El video se incrusta, no se aloja.** `urlIncrustable()` traduce un enlace de YouTube o Vimeo a su URL de reproducción (`youtube-nocookie.com/embed/…`); cualquier otra URL no se incrusta y la consola lo avisa. Servir un MP4 desde el hosting compartido ocuparía un worker de PHP-FPM por reproducción (sección 5.13). **El PDF sí se sube**, a `storage/app/public/recursos` (máximo 20 MB); reemplazarlo borra el anterior del disco.
- Tabla y servicio propios, no `parametros_maestros`: aquello son umbrales numéricos con mínimo/máximo cuyo catálogo referencia constantes de servicios; esto es contenido con un archivo detrás. Misma mecánica de caché (para siempre, invalidada al guardar) y la misma tolerancia a que la tabla no exista todavía.
- Sin nada publicado, la Calculadora se ve exactamente igual que antes de que existiera la funcionalidad.

### 5.16 Reiniciar y eliminar un plan diario

`PlanDiarioService`, dos acciones destructivas que el usuario pide explícitamente y que van detrás de una confirmación en línea (nunca `confirm()` de JavaScript):

- **`resetear()`** (`POST /planes/{registroDiario}/resetear`, botón "Reiniciar este día" al final del plan): deja el día como recién creado — sin planes de comida, sin lo registrado, sin actividades, sin recomendaciones, sin textos de ingredientes, sin peso, sin las cifras del cierre y abierto. Conserva la fecha. El reparto vuelve por sí solo al balanceado, porque se deriva de la actividad del día y esa también se borra (sección 5.14). **Solo para el día de hoy**: un día pasado ya no puede volver a vivirse, así que vaciarlo solo borraría historial de la ventana de 7 días; la vista ni pinta el botón fuera de hoy y `PlanComidaController@resetear` lo rechaza igual si llega por la ruta directamente. Para deshacerse de un día viejo está `eliminar()`.
- **`eliminar()`** (`DELETE /planes/{registroDiario}`, desde el listado y desde el propio plan): borra el `RegistroDiario`; las FK `cascade` se llevan el resto.

Las dos llaman antes a `ComidaRealService::borrarImagenesDelDia()`: la cascada de la base de datos se lleva las filas, pero no los archivos del disco.

**Por qué el reset también borra el peso:** "volver a empezar" incluye el peso, y dejarlo suelto en un día del que no queda nada sería un dato huérfano. No rompe la ventana de 7 días — el promedio móvil ignora los días sin peso en vez de contarlos como cero (sección 5.7) y el índice de consistencia mide días cerrados, no pesajes. El seguimiento no depende solo del peso: nadie se pesa a diario.

### 5.17 Datos de demostración (`tudi:demo`)

`DemoSeeder` + `php artisan tudi:demo` siembran seis cuentas, cada una parada en un punto distinto del recorrido, para poder enseñar la plataforma sin esperar tres semanas a que alguien acumule historial. Guion de la demostración y material de publicidad en `PRESENTACION.md`.

- **El historial no se inventa:** se crean los `PlanComida`/`ComidaReal`/`ActividadFisica` de cada día y se llama a `DailyClosureService::cerrar()`, así que el déficit y las recomendaciones salen de la lógica de dominio. **No llama al proveedor de IA**: los planes se escriben desde un catálogo de comidas de ejemplo (sembrar 21 días × 6 cuentas costaría cientos de llamadas facturables).
- **Escenarios cubiertos:** administradora, cuenta pendiente con código, cuenta activa sin Calculadora, y tres perfiles con 21 días de historial cuya pendiente de peso los sitúa en ritmo correcto (sin recomendación), demasiado lento (propone reducir) y demasiado rápido (propone aumentar). El día de hoy queda **abierto y a medias** a propósito. Las tres con historial van en `premium` (si no, el motor de recomendaciones no correría y sus tres escenarios se verían iguales); las dos que estrenan cuenta van en `trial`, y la de "sin Calculadora" a 1 día de vencer, para enseñar también el aviso del plan.
- **Idempotente y acotado:** cada cuenta se busca por correo y su historial se rehace; `--limpiar` borra solo las cuentas cuyo correo termina en `@demo.tudeficitinteligente.online`, así que es seguro correrlo sobre producción. Cubierto por `tests/Feature/DemoSeederTest.php`, que fija el escenario de cada cuenta: si "baja-lento" dejara de generar su recomendación, la demo enseñaría una pantalla vacía.
- Las cuentas usan una contraseña conocida y una es administradora: **retirarlas al terminar**.

### 5.18 Plan del usuario: gratis, prueba y Premium

`users.plan` (gratis|trial|premium) + `users.plan_expira_en`. **Dos columnas y no una tabla `suscripciones`**: mientras no haya cobro, un usuario tiene exactamente un plan y una fecha en la que deja de tenerlo — el mismo patrón que `estado`/`activado_en`, no un mecanismo nuevo. La sesión de la pasarela de pago añadirá las tablas de cobros que necesite; estas dos seguirán siendo el plan vigente. Por defecto `gratis`, para que las cuentas que ya existían no estrenen una prueba retroactiva de la que nadie las avisó.

**Quién tiene Premium se responde contra el reloj, no contra la tabla.** `User::tienePremium()` da falso a un `trial` cuya fecha ya pasó aunque el cron nocturno todavía no lo haya degradado, así que el vencimiento se nota en el mismo instante en que ocurre. `enPrueba()`, `diasDePruebaRestantes()` (redondea hacia arriba) y `pruebaTerminada()` distinguen "se te acabó" de "nunca la tuviste", que son dos mensajes distintos.

- **`PlanService`** es el espejo de `CuentaService` y el único sitio con transiciones de plan: `iniciarPrueba()` (al registrarse, sin pedirla y sin tarjeta; idempotente hacia arriba — no le acorta la prueba a quien ya la tiene ni se la quita a un Premium), `expirarVencidos()` (UPDATE masivo), `activarPremium()`/`degradarAGratis()` (las manijas que usará el cobro) y `precios()`.
- **`app:expirar-pruebas`**, `dailyAt('00:45')` — **después** del cierre (00:15) y las tendencias (00:30): así el último día de prueba se cierra y genera sus recomendaciones antes de que la cuenta caiga a Gratis. Solo ordena la tabla, no decide.
- **Vencer no quita nada.** El usuario conserva cuenta, acceso y todo su historial; solo deja de ver las funciones de Premium. No existe ningún estado en el que el plan cierre la aplicación.

**Qué es de pago, y dónde se corta:**

| Función | Punto de corte |
|---|---|
| Ajuste del plan con IA | `PremiumGatedMealDistributionProvider::distribuirDia()` |
| Reporte de una comida contado por escrito | `PremiumGatedMealDistributionProvider::estimarConsumoReal()` |
| Plan B del dictado (transcribir en el servidor) | `PremiumGatedTranscripcionProvider::transcribir()` |
| Motor de recomendaciones | `DailyClosureService::generarRecomendaciones()` |
| Historial: gráfico > 7 días y seguimiento > 1 semana | `DashboardController` |

- **El control vive en el borde del proveedor, no en los controladores.** Las interfaces de `app/Services/AI` son el único camino hacia el proveedor de IA, así que se envuelven en `AppServiceProvider` y un solo decorador cubre las dos funciones que las cruzan. Ningún camino nuevo puede saltárselo por olvidar un `if`. Los decoradores lanzan las excepciones de dominio que los controladores ya traducían (`MealDistributionUnavailableException::requierePremium()`, `TranscripcionNoDisponibleException::requierePremium()`), así que ningún controlador cambió y el usuario ve un mensaje que explica qué plan hace falta, nunca un error genérico ni un 500.
- **Sin sesión no hay plan que comprobar** (consola, seeders): se deja pasar. Hoy nada de eso llama al proveedor.
- **Dictar ingredientes es gratis para todos**, y por eso no aparece como exclusiva de Premium en la landing: lo resuelve el reconocedor del navegador, el audio no sale del dispositivo y no cuesta una llamada (sección 5.9). Lo gateado es solo el plan B de servidor, que se factura y está apagado por defecto. Es la única desviación deliberada respecto de la tabla del mockup de la landing, que lo listaba como Premium cuando ya no dependía de la IA.
- **El corte de las recomendaciones va en la generación, no en la vista**: una recomendación creada y luego escondida seguiría moviendo `calorias_objetivo` el día que el usuario volviera a Premium y la confirmara sin haberla visto nunca. Cerrar el día **no** es Premium ni cuesta una llamada (sección 5.5): en Gratis se cierra con todas sus cifras, cerrando cada comida por el camino de "cumplí lo sugerido" o repitiendo una frecuente.
- **Premium tampoco es ilimitado en llamadas**: las dos funciones de arriba tienen además una cuota diaria (sección 5.20). El plan decide qué funciones hay; la cuota, cuántas veces al día se usan.
- **Precios en un solo sitio**: `config/planes.php` (mensual, anual, días de prueba y qué incluye cada plan). El descuento anual no se declara — `PlanService::precios()` lo deriva de los dos importes para que no pueda contradecirlos. Cuando entre el cobro, el importe cobrado tiene que salir de ese mismo archivo.
- La interfaz lo dice en una línea y sin bloquear (`x-tudi.plan` en Inicio): días de prueba restantes, o que la prueba terminó y el historial sigue ahí. Un Premium pagante no ve nada.
- **`UserFactory` nace `premium`** por el mismo motivo que nace `activo`: casi ningún test va del cobro. Para eso están `gratis()`, `enPrueba()` y `pruebaVencida()`.
- **Control manual desde la consola** (sección 5.10): `Admin\UsuarioController::plan()` (`POST /admin/usuarios/{usuario}/plan`) alterna Premium/Gratis con `PlanService::activarPremium()`/`degradarAGratis()` — la manija provisional mientras no exista el cobro. Quitar Premium no toca `estado` ni borra nada.
- **Pendiente para la siguiente sesión: la pasarela de pago (Wompi).** No hay checkout, ni webhooks, ni facturación, ni forma de pasar a `premium` salvo el botón manual de la consola. La landing anuncia el precio; el botón "Actualizar a Premium" del aviso de plan (`x-tudi.plan`) es a propósito un `<button type="button">` sin acción — se ve como el resto de la interfaz, pero no navega a ningún sitio, porque `tudeficitinteligente.online` va a usarse para pilotos de viabilidad y todavía no hay checkout que ofrecer. En cuanto lo haya, es el único botón que hay que enlazar.
- **Días de prueba:** 3 por defecto (`TUDI_PRUEBA_DIAS`, `config/planes.php`), no 7. Se lee dinámicamente en toda la aplicación (notificaciones, landing, `DemoSeeder`) — cambiarlo es cambiar esa única línea.

### 5.19 Landing pública (`/`)

`LandingController` sobre `resources/views/welcome.blade.php` (que era el starter de Laravel sin usar). Sin sesión sirve la página; con sesión sigue redirigiendo a `dashboard`, como antes de que existiera.

- Secciones del mockup aprobado (`resources/branding/.../design/TUDI-landing-publica.dc.html`): nav, hero con el anillo de déficit, tres pasos, diferenciador, precios y CTA final. **Los textos se acotaron a lo que la aplicación hace de verdad** — el dictado se describe como lo que es, reconocimiento del navegador, y el pie de precios dice que el cobro todavía no está abierto.
- **No usa `layouts.guest`**: aquel es la tarjeta centrada del login. Sí usa el mismo sistema visual (tokens y `.tudi-*`), así que pasar de la landing al registro no cambia de mundo. Mobile-first, con la barra de navegación acortando su CTA por debajo de `sm:` para no partir en dos líneas.
- Los dos botones de precios llevan al **mismo** `register`: no hay ruta de alta distinta para Premium. "Probar X días gratis" es el refuerzo visual de lo que el registro ya hace solo (sección 5.18).


### 5.20 Cuota diaria de llamadas a la IA

`CuotaIaService` + `CuotaDiariaMealDistributionProvider`. **Premium es ilimitado en funciones, no en llamadas.** Cada "Calcular mi plan" y cada reporte contado por escrito es una llamada facturable que además ocupa un worker de PHP-FPM mientras dura (sección 5.13), y nada impedía abrir y cerrar la misma comida veinte veces para ver qué macros salían. El límite acota ese bucle sin quitarle a nadie la posibilidad de registrar su día — el mismo patrón que los planes de pago de las propias herramientas de IA.

- **Dos conceptos, dos límites**, ambos parámetros maestros (sección 5.11) y por tanto ajustables sin desplegar: `ia_limite_distribuciones_dia` (12 de fábrica) e `ia_limite_reportes_dia` (15).
- **Qué gasta cuota y qué no.** Solo lo que llama al proveedor: ajustar el plan y contar por escrito qué se comió. Cerrar una comida con "cumplí lo sugerido", repetir una comida frecuente (sección 5.22), cerrar el día, dictar por voz y todo lo demás son gratis. Esa es también la salida honesta para quien agota el día: se sigue registrando todo, solo que sin IA, y la pantalla lo dice así.
- **El corte va en un decorador, no en un `if`** (regla 14): `PremiumGatedMealDistributionProvider` por fuera, `CuotaDiariaMealDistributionProvider` por dentro. A quien está en Gratis se le dice qué plan necesita, no cuánta cuota le queda de algo que no tiene. Sin sesión (consola, seeders) se deja pasar, igual que el control de plan.
- **Se descuenta al pedir, no al acertar.** Un intento que falla en el proveedor ya ha costado tokens, y cobrar solo los aciertos dejaría un bucle de fallos llamando gratis para siempre. Por lo mismo, **reabrir una comida no devuelve cuota**.
- **El contador vive en la caché**, con clave por usuario, concepto y fecha local, y expira solo a medianoche. No hace falta tabla: no es un dato del dominio, no se consulta históricamente y perderlo solo regala el resto del día. El plan diario enseña cuántas quedan, para que el límite no sorprenda a nadie a media tarde.
- El plan B del dictado (`/transcribir`) no entra aquí: está apagado por defecto y ya lleva `throttle:30,1` (sección 5.9).

### 5.21 Saldo del día, macro a macro

`MealDistributionService::saldoDelDia()` devuelve objetivo, consumido y **saldo** de calorías y de los tres macros, más qué comidas quedan pendientes. Lo calcula PHP desde las `ComidaReal` del día (regla 7) y no persiste nada.

Es lo que hace visible el cierre por comida: el panel "Objetivo del día" pasa de enseñar solo la cifra objetivo a enseñar **lo que queda** ("te quedan 1.180 kcal para almuerzo y cena", "quedan 72,0 g de proteína"), con las barras midiendo lo comido de verdad contra el objetivo. Un saldo negativo se pinta en ámbar con "te pasaste por", nunca en lima — la lima es progreso y nada más (regla 8).

El mismo saldo es el que reparte "Calcular mi plan" entre las comidas que faltan (sección 5.3). Por eso `ComidaRealService` **ya no redistribuye** el presupuesto de las comidas pendientes al registrar una: aquello reescribía `calorias_estimadas` en silencio y dejaba planes incoherentes con sus propios ingredientes. Ahora un `PlanComida` persistido significa exactamente lo que dice, y quien reparte de nuevo es una acción explícita del usuario.

### 5.22 Comidas frecuentes ("lo que sueles comer")

`ComidasFrecuentesService`. Casi nadie desayuna algo distinto cada día: sin esto, quien repite su desayuno seis días de siete lo vuelve a escribir (o a dictar) seis veces, y cada una cuesta una llamada para obtener los mismos macros que ya se calcularon el lunes.

**Es un atajo de escritura, no un botón que reporta por su cuenta** (sección 5.5): al pulsar un chip se copia su texto en el campo "Cuéntanos qué comiste de verdad…" y nada más — no envía el formulario ni llama a nadie. Si ese texto llega sin tocar, `ReporteComidaService` reutiliza los macros de aquel reporte (`coincideCon()`); si se edita, lo que manda es el texto nuevo y sí pasa por la IA. Antes era un botón `type="submit"` que reportaba directo: se cambió porque un envío inmediato no dejaba corregir "lo mismo pero sin arroz" sin gastar una llamada aparte.

- Mira los últimos `DIAS_HISTORIAL` (30) días de ese tipo de comida, agrupa por lo que el usuario ve —la nota del reporte sin el detalle que el modelo añade tras un guion, o la descripción del plan si cumplió lo sugerido—, exige `REPETICIONES_MINIMAS` (2) y ofrece como mucho `MAXIMO_SUGERENCIAS` (3), de la más repetida a la menos. La plantilla es el reporte más reciente del grupo.
- **La normalización se hace en PHP, no en SQL** (mismo criterio que el promedio móvil, sección 5.7): son unas decenas de filas por usuario y la comparación con acentos no es portable entre MySQL y SQLite.
- **No hay tabla de plantillas**: la plantilla ES el reporte anterior. `deUsuario()` comprueba la propiedad ahí mismo, porque es el único camino por el que una `ComidaReal` se copia de un día a otro.

### 5.23 Racha de días cerrados

`TrendAnalyticsService::rachaDiasCerrados()`: días seguidos cerrados contando hacia atrás desde hoy, con tope en `DIAS_MAXIMOS_RACHA` (365). **Hoy sin cerrar no rompe la racha** — el día sigue en curso y aún puede cerrarse; se corta en el primer día anterior sin `RegistroDiario` o con el registro abierto.

Es la lectura amable del mismo dato que ya mide `indice_consistencia_pct`: el índice dice cuánto adherió en la ventana, la racha dice cuánto lleva sin fallar. Se enseña en la tarjeta "Racha" de Inicio, encima de los siete puntos de la semana, que se conservan.

### 5.24 Aviso de comidas sin reportar

`DashboardController::comidasSinReportarAyer()`: si el plan de **ayer** existe, tiene alguna comida reportada y alguna sin reportar, Inicio muestra una línea en ámbar con las que faltan y un enlace al día para completarlo (reabriéndolo si hiciera falta).

- Un día sin **nada** reportado no se avisa: no es un descuido, es un día que no se usó, y recordárselo sería ruido.
- Solo el día anterior: recordar lo de hace tres días ya no es fiable.
- **Aviso en pantalla y no correo**, a propósito: no hace falta cron nuevo, no gasta cola y no manda correo diario a nadie que no lo haya pedido. El valor es el mismo — un hueco sin reportar entra luego en el promedio móvil como calorías que nunca se comieron, y `diagnosticoRecomendaciones` lo cuenta como día sin datos.

## 6. Rutas y código sin usar, conservados a propósito

No son deuda técnica olvidada — cada uno se conserva por una razón concreta y está cubierto por tests:

- **Ingredientes estructurados** (`IngredienteDisponibleController`, rutas `/ingredientes`): el camino normal es el texto libre del plan diario (sección 5.3); estas rutas siguen siendo la entrada de `MealPlanGeneratorService`.
- **`MealPlanGeneratorService` + `POST /planes/{registroDiario}/generar`**: heurística de reparto por macro (proteína → grasa → carbohidratos) sobre ingredientes estructurados. Sustituida por la distribución con IA (sección 5.3) como camino del usuario, pero sigue funcionando y probada. Su constante `DISTRIBUCION_COMIDAS` no es código muerto: es la declaración de qué comidas hay y del reparto de fábrica (sección 5.14).
- **`NutritionAiProviderInterface` + `RuleBasedNutritionProvider`**: desacopla "quién decide" la selección de ingredientes sobre inventario estructurado (usada por `MealPlanGeneratorService`) de una futura IA generativa para ese mismo problema — distinto del problema que resuelve `MealDistributionProviderInterface` (interpretar lenguaje natural).
- **`ComidaRealController`** (`/plan/{planComida}/comida-real`, "Registrar con detalle"): las tres acciones siguen sin estar enlazadas desde la interfaz. Lo que se comió se reporta ahora desde la propia tarjeta de cada comida (sección 5.5), que además cubre las comidas sin plan previo; este controlador se conserva para corregir macros exactos a mano y está cubierto por tests.
- **`GeminiMealDistributionProvider` + `GeminiTranscripcionProvider`**: proveedores de IA anteriores a OpenAI, con sus tests enteros. No están fuera del alcance de `AppServiceProvider` como el resto de esta sección: `AI_PROVEEDOR_DISTRIBUCION`/`AI_PROVEEDOR_TRANSCRIPCION` (`.env`, valores `openai`|`gemini`, por defecto `openai`) eligen entre los dos en tiempo de arranque —sin desplegar código, solo `config:cache`—, pensado para comparar los dos proveedores durante el piloto. Un valor no reconocido cae a OpenAI en vez de fallar. **`ClaudeMealDistributionProvider`** sigue en el repo, sin bindear ni cubierto por el interruptor (no tiene proveedor de transcripción equivalente), por si hiciera falta volver atrás.
- **Activación por código** (`ActivacionController`, `/activacion`, estado `pendiente`, `EnsureCuentaActiva`): ya no es el camino del alta (sección 5.1), pero sigue siendo la herramienta con la que un administrador devuelve una cuenta a validación manual desde `regenerarCodigo()`.

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
- **`Tests\TestCase` llama a `Http::preventStrayRequests()`.** Por defecto Laravel **ejecuta de verdad** las peticiones que no casan con ningún stub de `Http::fake()`, así que un fake desactualizado (porque cambió el proveedor, el endpoint o el modelo) pasaba en verde gastando llamadas facturables a OpenAI en cada `php artisan test`. Ahora una petición sin stub falla en el acto y dice qué URL era.
- Todo Service de dominio requiere tests unitarios de casos normales y de borde.
- Todo flujo de usuario nuevo requiere al menos un test de feature.
- No se considera terminada una tarea si los tests no pasan.

## 11. Flujo de trabajo Git

- Un commit por tarea completada, mensaje descriptivo (`feat: ...`, `fix: ...`, `test: ...`).
- No commitear `.env`, `vendor/`, `node_modules/`.
- Antes de dar una tarea por terminada: `php artisan test` en verde y `vendor/bin/pint` sin cambios pendientes.

## 12. Despliegue (Hostinger)

**Paso a paso completo en `DEPLOY.md`.** Resumen de las decisiones de fondo:

- Un solo cron job (`schedule:run` cada minuto); toda la automatización diaria vive en `routes/console.php`: cierre 00:15, tendencias 00:30, vencimiento de pruebas 00:45, cola de correos cada minuto.
- El promedio móvil se calcula en PHP (sección 5.7) — no depende de la versión de MySQL del hosting.
- Variables sensibles solo en `.env`. `OPENAI_API_KEY` es opcional (sin ella se desactiva la distribución de comidas con un mensaje, no un 500; el dictado por voz **no** depende de ella desde la sección 5.9); `MAIL_*` hace falta para que salgan los correos del alta (sin SMTP, la única vía es la consola).
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
14. **Toda llamada nueva a un proveedor de IA entra por una interfaz de `app/Services/AI` ya envuelta en su decorador de plan** (sección 5.18). No añadas la comprobación de plan en un controlador: si una función de pago necesita un camino nuevo, el corte va en el borde del proveedor, que es el único sitio por el que no se puede pasar de largo.
15. **Ningún plan puede dejar a nadie fuera de la aplicación** (sección 5.18). El plan quita funciones; quien decide si se entra es `estado` y su middleware. Y ningún cambio de plan borra, oculta ni recalcula datos históricos.
16. **Un precio no se escribe en una vista** (sección 5.18): vive en `config/planes.php` y se lee por `PlanService::precios()`. Lo que se pueda derivar de otro importe se deriva, no se declara.
17. **La cuota diaria de IA se descuenta en el borde del proveedor, y solo lo que de verdad llama** (sección 5.20). Si añades un camino que use el proveedor, la cuota ya lo cubre; si añades un atajo que NO llama (repetir algo ya calculado, copiar un plan), no lo cobres — la salida honesta para quien agota el día es que siga pudiendo registrarlo todo sin IA.
