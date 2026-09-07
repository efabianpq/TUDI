# CLAUDE.md — TUDéficit Inteligente

Este archivo es la memoria de proyecto para Claude Code. Se carga al inicio de cada sesión: mantenlo actualizado, pero no lo infles con detalle que no se necesita en *cada* tarea (ese detalle adicional vive en los documentos de `/docs`, si se crean más adelante).

## 1. Qué es este proyecto

**TUDéficit Inteligente** es una aplicación web de gestión inteligente de pérdida de peso: genera planes de comida a partir de ingredientes disponibles, registra consumo real y actividad física, calcula el balance energético diario, y detecta tendencias (no valores diarios aislados) para ajustar el plan del usuario.

Referencia funcional completa: `Arquitectura_TUDeficit_Inteligente.docx` (si está en el repo) o el documento de arquitectura entregado junto a este archivo.

## 2. Stack tecnológico (fijo, no proponer alternativas sin pedirlo)

**Versión de Laravel instalada:** 13.x (última estable al momento de inicializar el proyecto). Se intentó fijar 10.x/11.x, pero el audit de seguridad de Composer bloquea la resolución de *todas* las versiones 10.x y 11.x de `laravel/framework` (advisories sin parche disponible en esas líneas) — no es viable instalarlas sin desactivar el chequeo de seguridad. Se optó por la última estable (13.x) en su lugar. No hace falta discutir esto de nuevo salvo que surja un motivo concreto para fijar una versión distinta.


- **Backend:** Laravel (PHP 8.x), monolito — sin API REST separada en el MVP.
- **IA generativa:** Claude (Anthropic) **Haiku 4.5** vía la Messages API, llamada con el cliente HTTP de Laravel (`Http`), sin SDK de Composer — ver sección 4.12 para el porqué. Es la única dependencia externa de red del MVP y es opcional: sin `ANTHROPIC_API_KEY` la app funciona, solo se desactiva "Generar distribución".
- **Frontend:** Blade (server-rendered), **mobile-first** (sección 4.15) + Chart.js para gráficos del dashboard. Sin SPA, sin build de frontend pesado. Chart.js se carga **desde CDN** (`cdn.jsdelivr.net`) en la vista que lo necesita, empujado al stack `scripts` que declara `resources/views/layouts/app.blade.php`; no está en `package.json`. Así no hace falta `npm run build` en el hosting para que el gráfico funcione, y ninguna página que no dibuje gráficos carga la librería.
- **Base de datos:** MySQL 8.x / MariaDB 10.6+. Versión mínima asumida: **MySQL 5.7 / MariaDB 10.1** — ver sección 4.7, ninguna consulta usa funciones de ventana ni CTEs. El entorno de desarrollo es MySQL 8.0.30 (verificado con `php artisan db:show`) y la suite de tests corre sobre SQLite en memoria.
- **Tareas programadas:** Laravel Task Scheduling (`schedule:run`) vía cron de Hostinger. No usar Redis ni colas externas en el MVP.
- **Almacenamiento de imágenes:** disco local vía `Storage` facade (`storage/app/public`), con `storage:link`. No hardcodear rutas — todo a través del facade para poder migrar a S3 sin tocar código.
- **Testing:** Pest (preferido) sobre PHPUnit.
- **Despliegue objetivo:** hosting compartido/Business de Hostinger.

## 3. Estructura de carpetas por dominio

| Dominio | Ubicación |
|---|---|
| Usuarios y perfil | `app/Models/User.php`, `app/Http/Controllers/ProfileController.php` |
| Nutrición | `app/Models/PlanComida.php`, `app/Models/ComidaReal.php`, `app/Services/NutritionCalculatorService.php`, `app/Services/MealPlanGeneratorService.php` |
| Actividad física | `app/Models/ActividadFisica.php`, `app/Services/ActivityCorrectionService.php` |
| Cierre diario | `app/Services/DailyClosureService.php`, `app/Console/Commands/RunDailyClosure.php` (programado `dailyAt('00:15')`) |
| Analítica | `app/Models/MetricaTendencia.php`, `app/Services/TrendAnalyticsService.php`, `app/Http/Controllers/ProgresoController.php`, `app/Console/Commands/CalculateTrends.php` (programado `dailyAt('00:30')`) |
| Motor de recomendaciones | `app/Services/RulesEngineService.php`, `app/Http/Controllers/RecomendacionSistemaController.php` |
| Motor de IA/reglas | `app/Services/AI/NutritionAiProviderInterface.php` + implementación concreta |
| Distribución de comidas con IA | `app/Services/MealDistributionService.php`, `app/Services/AI/MealDistributionProviderInterface.php` + `app/Services/AI/ClaudeMealDistributionProvider.php` |
| Feedback de cumplimiento del cierre | `app/Services/CierreFeedbackService.php` |
| Sugerencia de actividad física | `app/Services/ActivitySuggestionService.php` |
| Seguimiento periódico | `app/Services/SeguimientoService.php` |
| Planes diarios (listado + hub del día) | `app/Http/Controllers/PlanComidaController.php` (`GET /planes`, `GET /planes/{registroDiario}`), compone `MealDistributionService` + `ActivitySuggestionService` + `DailyClosureService`, sin lógica propia |
| Inicio (dashboard + progreso) | `app/Http/Controllers/DashboardController.php` (`GET /dashboard`), compone `DailyClosureService` + `TrendAnalyticsService` + `SeguimientoService` + `RecomendacionSistema`, sin lógica propia |

**Regla no negociable:** los controladores son delgados (reciben, validan con Form Requests, delegan). Toda la lógica de negocio vive en `app/Services`. Nada de lógica de negocio en modelos Eloquent ni en controladores.

## 4. Modelo de datos (entidades principales)

`Usuario` 1—N `RegistroDiario` 1—N `IngredienteDisponible`
`RegistroDiario` 1—N `PlanComida` 1—1 `ComidaReal`
`RegistroDiario` 1—N `ActividadFisica`
`Usuario` 1—N `MetricaTendencia`
`RegistroDiario` 1—N `RecomendacionSistema`

### Estado actual (implementado)

Migraciones, modelos Eloquent y factories del modelo de datos completo ya existen. Notas de implementación:

- **Nombres de tabla:** el pluralizador de Laravel no acierta con los nombres compuestos en español (p.ej. `RegistroDiario` → `registro_diarios` en vez de `registros_diarios`), así que cada modelo define `protected $table` explícito. Tablas reales: `registros_diarios`, `ingredientes_disponibles`, `planes_comida`, `comidas_reales`, `actividades_fisicas`, `metricas_tendencia`, `recomendaciones_sistema`.
- **`users` extendida** (migración `add_perfil_nutricional_a_users_table`): añade `peso_kg` decimal(5,2), `estatura_m` decimal(3,2), `edad` unsignedTinyInteger, `sexo` enum(masculino,femenino), `nivel_actividad` decimal(4,3), `tipo_deficit` enum(porcentaje,fijo), `valor_deficit` decimal(6,2), `proteina_factor` decimal(3,2), `grasa_factor` decimal(3,2), `calorias_objetivo` decimal(7,2) — todas nullable porque el perfil se completa después del registro.
- **`registros_diarios`**: `usuario_id` (FK cascade), `fecha` (date, único junto a `usuario_id`), `peso_kg` (decimal(5,2) nullable, el peso de *ese* día — ver sección 4.7), `calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, `proteina_objetivo_g`, `proteina_consumida_g` (todas decimal nullable, se rellenan en el cierre diario), `cerrado` (boolean) + `cerrado_en` (dateTime nullable) — ver sección 4.5 — y `ingredientes_desayuno` / `ingredientes_almuerzo` / `ingredientes_cena` (text nullable): el texto libre que el usuario escribe o dicta por comida, ver sección 4.12.
- **`ingredientes_disponibles`**: `registro_diario_id` (FK cascade) — son entradas ad-hoc por registro diario, no un catálogo maestro compartido; no hay caso de `restrict` en este modelo de datos porque no existen tablas de referencia compartidas en el MVP.
- **`planes_comida`**: `registro_diario_id` (FK cascade), `tipo_comida` enum(desayuno,almuerzo,cena,snack), macros estimados, `descripcion` + `preparacion` + `notas_ia` (text nullable, lo que produce la distribución con IA — sección 4.12) y `ingredientes_detalle` (json, sección 4.2).
- **`comidas_reales`**: `plan_comida_id` (FK **unique** + cascade, implementa la relación 1—1), macros reales, `consumido_en`, `notas`.
- **`actividades_fisicas`**: `registro_diario_id` (FK cascade), `calorias_dispositivo`, `factor_correccion` (default 0.85, rango 0.8–0.9 según sección 5), `calorias_ajustadas` (= dispositivo × factor).
- **`metricas_tendencia`**: `usuario_id` (FK cascade), `fecha` (único junto a `usuario_id`), promedios móviles (`promedio_movil_peso_kg`, `promedio_movil_calorias`, `promedio_movil_deficit_kcal`), `indice_consistencia_pct`, `dias_con_datos`, `porcentaje_perdida_semanal` y `tendencia` enum — ver sección 4.7.
- **`recomendaciones_sistema`**: `registro_diario_id` (FK cascade), `estado` enum(pendiente,confirmada,rechazada) default pendiente — refleja la regla de la sección 6 (nunca se aplica un ajuste sin confirmación).
- **`onDelete`:** cascade en todas las FKs — todas las entidades hijas son datos propios del usuario/registro sin sentido fuera de su padre; no hay entidades de catálogo compartido que requieran `restrict` en este MVP.
- **Tests:** `tests/Feature/ModeloDatosTest.php` cubre cada relación y dos casos de cascade delete. Se activó `RefreshDatabase` en `tests/Pest.php` (estaba comentado); `phpunit.xml` ya usa sqlite en memoria para testing.

`ComidaReal` es una entidad separada de `PlanComida` (no se sobrescribe el plan) — preserva el historial de "planificado vs. ejecutado". No cambiar este diseño sin discutirlo explícitamente.

### Autenticación (implementada)

Laravel Breeze (stack Blade, con Pest) instalado vía `laravel/breeze` (dev dependency) — provee registro, login, logout, recuperación/reseteo de contraseña y confirmación de contraseña. Rutas en `routes/auth.php` (`register`, `login`, `logout`, `forgot-password`, `reset-password/{token}`, `verify-email`, `confirm-password`). Vistas en `resources/views/auth/*`. Todas las rutas del área autenticada (`/dashboard`, `/profile`, `/calculadora`, `/planes`, ...) están protegidas con el middleware `auth`.

- **`GET /` ya no muestra `welcome.blade.php`** (la landing por defecto de Laravel, sin tocar desde la inicialización del proyecto — sección 1). Ahora redirige a `dashboard` si hay sesión iniciada o a `login` si no la hay; la app no tiene una landing pública propia en el MVP. `tests/Feature/ExampleTest.php` (el placeholder del skeleton de Laravel, que esperaba un 200 en "/") se reescribió para cubrir ambos redirects.

- **Calculadora Déficit tras el registro:** en vez de extender el formulario de registro (para no complicar el flujo estándar de Breeze), tras registrarse el usuario es redirigido a la "Calculadora Déficit" (`ProfileParametersController@edit`, ruta `GET /calculadora` → `calculadora.edit`) donde define `peso_kg`, `estatura_m`, `edad`, `sexo`, `nivel_actividad`, `tipo_deficit`, `valor_deficit`, `proteina_factor`, `grasa_factor`. Se guarda con `PUT /calculadora` (`calculadora.update`) vía `ProfileParametersController@update`. Es un menú principal, accesible en cualquier momento — ver sección 4.14.
- **`ProfileParametersRequest`** (`app/Http/Requests/ProfileParametersRequest.php`): valida los rangos de la sección 5/6 — `nivel_actividad` 1.2–1.725, `proteina_factor` 1.6–2.2, `grasa_factor` 0.6–1.0, `sexo` in(masculino,femenino), `tipo_deficit` in(porcentaje,fijo). Solo valida: el cálculo de `calorias_objetivo` lo hace el controlador con `NutritionCalculatorService`, no el Form Request.
- **`ProfileParametersController@update` calcula y persiste `users.calorias_objetivo`** a partir de los parámetros recién guardados, vía `NutritionCalculatorService::calculatePlan()` — ver sección 4.10, donde está la razón. Si el cálculo lanza `NegativeCarbohydrateException`/`InvalidNutritionParameterException` (macros que no caben en el objetivo), **no se persiste nada** y se vuelve al formulario con el mensaje en `valor_deficit` (el campo que en la práctica exprime los carbohidratos); persistir un perfil del que no se puede derivar un plan solo mueve el error al plan diario. Editar los parámetros recalcula el objetivo desde la fórmula, descartando un ajuste confirmado previo: es una acción explícita del usuario, no un ajuste automático, así que no contradice la sección 6.
- **Tests:** `tests/Feature/ProfileParametersTest.php` cubre acceso protegido por `auth`, edición exitosa (incluido el `calorias_objetivo` derivado), validación fuera de rango (dataset con `nivel_actividad`, `proteina_factor`, `grasa_factor`, `sexo`, `tipo_deficit` inválidos), y el perfil cuyos macros no caben en su objetivo, que no se guarda. `tests/Feature/Auth/RegistrationTest.php` se ajustó para esperar redirect a `calculadora.edit` en vez de `dashboard`.

## 4.1. Ingredientes disponibles (implementado, ya no es el camino principal)

> **Estado:** el camino normal del usuario es ahora el texto libre por comida de la sección 4.12; "Ingredientes" dejó de ser un menú. Estas rutas y su controlador siguen existiendo y funcionando (son la entrada de `MealPlanGeneratorService`), pero no están enlazadas desde la interfaz.

`IngredienteDisponibleController` (`app/Http/Controllers/IngredienteDisponibleController.php`) gestiona el reporte diario de ingredientes disponibles. Rutas (todas bajo middleware `auth`):

- `GET /ingredientes` (`ingredientes.create`): formulario con filas dinámicas (Alpine.js, ya incluido por Breeze) para reportar varios ingredientes a la vez, más la lista de lo ya reportado hoy.
- `POST /ingredientes` (`ingredientes.store`): crea los ingredientes del array `ingredientes[]` para el `RegistroDiario` de **hoy** del usuario autenticado. Si no existe un `RegistroDiario` para `usuario_id` + fecha de hoy, se crea automáticamente.
- `GET /registros-diarios/{registroDiario}/ingredientes` (`ingredientes.index`): lista los ingredientes de un `RegistroDiario` concreto; 403 si el registro no pertenece al usuario autenticado.
- `PUT /ingredientes/{ingrediente}` (`ingredientes.update`) y `DELETE /ingredientes/{ingrediente}` (`ingredientes.destroy`): editar/eliminar un ingrediente reportado por error; 403 si no pertenece al usuario autenticado (verificado vía `ingrediente->registroDiario->usuario_id`).

**Decisión de diseño:** el enunciado original de la tarea sugería campos `cantidad_disponible` + `unidad` (lista controlada), pero el modelo de datos ya implementado (sección 4, migración `create_ingredientes_disponibles_table`) usa `cantidad_g` (gramos, unidad canónica única) junto con macros por 100g (`calorias_por_100g`, `proteina_por_100g`, `grasa_por_100g`, `carbohidratos_por_100g`) — necesarios para que `MealPlanGeneratorService` pueda calcular macros de un plan a partir de ingredientes disponibles. Se optó por no introducir un campo `unidad` nuevo y usar los campos reales del modelo ya persistido, evitando una migración adicional no solicitada explícitamente y manteniendo una sola unidad de medida canónica.

**Detalle de implementación:** la búsqueda/creación del `RegistroDiario` de hoy en `store()` usa `where('usuario_id', ...)->whereDate('fecha', ...)->first()` en vez de `firstOrCreate` con `fecha` en el array de atributos — `firstOrCreate` compara el valor crudo contra la columna `fecha` (cast `datetime` internamente en SQLite) y no coincide con `now()->toDateString()`, lo que producía un registro duplicado y violaba la restricción única `usuario_id`+`fecha`.

**Form Requests:** `IngredienteDisponibleRequest` (array `ingredientes.*` con `nombre`, `cantidad_g` positivo, macros ≥ 0) para el alta múltiple; `UpdateIngredienteDisponibleRequest` (mismos campos, singulares) para editar un ingrediente.

**Vistas:** `resources/views/ingredientes/create.blade.php` (formulario dinámico con Alpine.js) y `resources/views/ingredientes/index.blade.php` (listado de un `RegistroDiario`).

**Tests:** `tests/Feature/IngredienteDisponibleTest.php` cubre creación automática del `RegistroDiario` de hoy, reutilización si ya existe, validación de cantidad negativa, aislamiento por usuario en el listado (403 si no es el dueño), y edición/eliminación con la misma verificación de propiedad.

## 4.2. Generación heurística del plan de comidas (implementado, ya no expuesta en la UI)

> **Estado:** `POST /planes/{registroDiario}/generar` sigue existiendo y cubierto por tests, pero "Generar distribución" (sección 4.12) lo sustituyó como camino del usuario y el botón ya no está en la vista. Sus fallos redirigen a `planes.show` del propio día.


`app/Services/MealPlanGeneratorService.php` construye el plan del día a partir de los `IngredienteDisponible` reportados. **No recalcula el objetivo calórico**: recibe ya hecho el resultado de `NutritionCalculatorService::calculatePlan()` (sección 5 sigue siendo la única fuente de verdad de las fórmulas).

- **`generarPlan(RegistroDiario $registroDiario, array $planNutricional): Collection<PlanComida>`** — único método público. `$planNutricional` es el array que devuelve `calculatePlan()` (`calorias_objetivo`, `proteina_g`, `grasa_g`, `carbohidratos_g`). Devuelve una `Collection` con un `PlanComida` por clave de `DISTRIBUCION_COMIDAS`, en ese orden. Lanza `NoIngredientsAvailableException` si el día no tiene ingredientes reportados.
- **`DISTRIBUCION_COMIDAS`** (constante pública) — `desayuno 0.25 / almuerzo 0.40 / cena 0.35`. Es el **único** sitio donde vive el reparto; debe sumar siempre 1.0 (hay un test que lo verifica). No hay `snack` en el reparto aunque el enum de `tipo_comida` lo permita.
- **Regenerar es idempotente y no destruye historial:** las comidas que ya tienen una `ComidaReal` asociada se conservan intactas (regla de la sección 4: se preserva "planificado vs. ejecutado"); solo se borran y rehacen las pendientes. Todo dentro de una transacción.
- **El inventario se consume:** los gramos asignados a una comida se restan de un inventario en memoria, así que los mismos 200 g de pollo no se planifican tres veces.

### Heurística de selección (elegida y documentada)

Codiciosa, **una pasada por macro en orden proteína → grasa → carbohidratos**. En cada pasada se recorre el inventario del ingrediente más denso en ese macro hacia abajo, tomando los gramos que faltan para cerrar el hueco de ese macro, sin exceder nunca el presupuesto calórico de la comida. Cada porción tomada descuenta a la vez las calorías y **los tres macros**, de modo que las pasadas siguientes no vuelven a servir lo que ya aportó la anterior.

No hace falta una pasada de calorías propiamente dicha: por construcción de la sección 5, `4·proteína + 9·grasa + 4·carbos = calorías_objetivo`, así que cubrir los macros cubre las calorías. Sí queda una pasada final de calorías como **fallback** para cuando el inventario no puede cubrir algún macro (p.ej. no se reportó ninguna fuente de carbohidratos) y la comida se quedaría corta de energía.

**Por qué así y no "proteína primero, luego lo más calórico":** esa variante más simple acierta calorías y proteína pero rellena el hueco con el ingrediente más denso en calorías, que casi siempre es una grasa — producía planes de pollo + 150 g de aceite, con el triple de la grasa objetivo y cero carbohidratos. Repartir por macro cuesta lo mismo en complejidad (el mismo bucle, un campo distinto) y da planes realistas.

**Precisión esperada (medida, no teórica):** con despensa suficiente las calorías caen prácticamente sobre el objetivo (±0.1%), pero **la proteína se pasa ~14% y los carbohidratos se quedan ~20% cortos**. Es inherente al reparto codicioso: las pasadas de grasa y carbohidratos arrastran la proteína que llevan dentro sus ingredientes (el arroz aporta 7.5 g/100 g) y solo saben sumar, nunca corregir hacia abajo. Evitarlo exigiría anticipar en la primera pasada lo que aportarán las siguientes — lookahead que el MVP no pide. Los tests fijan los márgenes en 5% para calorías y 20% para proteína.

**Otros detalles:** se descartan porciones de menos de 1 g (`GRAMOS_MINIMOS_POR_INGREDIENTE`) para no imprimir "aceite (0.4 g)"; un ingrediente elegido en varias pasadas se fusiona en una sola línea del plan.

### Persistencia y exposición

- **Migración `add_ingredientes_detalle_a_planes_comida_table`**: añade `ingredientes_detalle` (json, nullable) a `planes_comida`, con cast `array` en el modelo. Es un **snapshot denormalizado a propósito** (`ingrediente_id`, `nombre`, `cantidad_g`, `calorias`, `proteina_g`, `grasa_g`, `carbohidratos_g`): debe seguir siendo legible aunque después se editen o borren los `IngredienteDisponible` de origen.
- **`PlanComidaController@generar`** (`app/Http/Controllers/PlanComidaController.php`), ruta bajo `auth`:
  - `POST /planes/{registroDiario}/generar` (`planes.generar`) — genera el plan de ese `RegistroDiario`; 403 si no es del usuario autenticado.
- **Sin Form Request:** `generar` no recibe ningún input del usuario (el día llega por route-model-binding y los parámetros salen del perfil), así que no hay nada que validar; la sección 7 exige Form Requests para validar entrada, y aquí no hay entrada.
- **Ningún fallo de dominio produce un 500:** si faltan parámetros nutricionales del perfil o el cálculo lanza `NegativeCarbohydrateException` / `InvalidNutritionParameterException`, se redirige a `calculadora.edit` con `error`; si no hay ingredientes (`NoIngredientsAvailableException`), se redirige a `planes.show` con `error`.
- **`NoIngredientsAvailableException`** (`app/Exceptions/`, extiende `DomainException`): día sin ingredientes reportados. Constructor con nombre `paraRegistroDiario(?int $id)`.
- **Vista:** `resources/views/planes/show.blade.php` es el detalle del plan diario (secciones 4.12–4.15); muestra una tarjeta por comida con sus macros y el desglose de ingredientes, ya vengan de esta heurística o de la distribución con IA.

**Tests:** `tests/Unit/MealPlanGeneratorServiceTest.php` (el servicio toca Eloquent, así que ese archivo hace `uses(TestCase::class, RefreshDatabase::class)` explícito — `tests/Pest.php` solo aplica `RefreshDatabase` a `Feature`) cubre que el reparto suma 100%, plan con despensa suficiente cercano al objetivo, cada comida en su porcentaje, día sin ingredientes → excepción, que nunca se reparten más gramos de los reportados, que se persiste `ingredientes_detalle` cuadrando con los totales, y que regenerar conserva las comidas ya consumidas. `tests/Feature/PlanComidaTest.php` cubre el flujo HTTP: acceso protegido, 403 sobre el día de otro usuario, generación de las tres comidas, la vista del plan, y los caminos de fallo controlado (sin ingredientes, sin parámetros de perfil).

## 4.3. Registro de comida real (implementado)

`app/Services/ComidaRealService.php` (método público `registrar(PlanComida $planComida, array $datos, ?UploadedFile $imagen = null): ComidaReal`) es el único punto de entrada para registrar lo que el usuario realmente comió. Todo corre dentro de una transacción y hace tres cosas en orden:

1. Guarda la imagen de evidencia (si se subió) con `$imagen->store('comidas-reales', 'public')` — disco `public` (`storage/app/public`, requiere `storage:link`, ya ejecutado). Sin ningún procesamiento (compresión, análisis): es solo evidencia visual, tal como indica el documento de arquitectura. Crea el `ComidaReal` con `consumido_en = now()`.
2. **Redistribución de calorías pendientes** (método privado `redistribuirCaloriasPendientes`): calcula `desviacion = calorias_reales - calorias_estimadas` de la comida recién registrada y la reparte, con signo, entre los `PlanComida` del mismo `RegistroDiario` que **todavía no tienen** `ComidaReal` (`doesntHave('comidaReal')`), proporcionalmente a la participación de cada uno en el total planificado pendiente. Un exceso reduce `calorias_estimadas` de las comidas restantes; comer de menos lo aumenta. Nunca deja una comida en negativo (`max(0, ...)`). Las comidas ya registradas no se tocan nunca.
3. **Actualiza `calorias_consumidas`** del `RegistroDiario` (método privado `actualizarCaloriasConsumidas`) como la suma de `calorias_reales` de todos los `ComidaReal` del día — se recalcula desde cero en cada registro, no se acumula con `+=`, para que sea idempotente si algún día se permite editar una `ComidaReal`.

**Decisión de diseño — por qué reparto proporcional y no reparto parejo:** almuerzo y cena no tienen el mismo presupuesto planificado (40% vs. 35% del día); repartir el excedente/déficit a partes iguales entre ambos distorsionaría más a la comida más pequeña. Repartir según la participación actual de cada una en el total pendiente mantiene la proporción relativa del reparto original de `MealPlanGeneratorService::DISTRIBUCION_COMIDAS`.

- **Columna nueva:** migración `add_imagen_evidencia_a_comidas_reales_table` añade `imagen_evidencia` (string nullable) a `comidas_reales`. `ComidaReal::imagenUrl(): ?string` expone `Storage::disk('public')->url(...)` o `null` si no se subió imagen.
- **`ComidaRealRequest`** (`app/Http/Requests/`): `calorias_reales`, `proteina_g`, `grasa_g`, `carbohidratos_g` (numéricos, `min:0`, requeridos), `imagen` (nullable, `image`, `max:4096` KB), `notas` (nullable, string, `max:1000`). No valida `grasa_g`/`carbohidratos_g` contra el plan — son datos reales, pueden diferir de lo planificado libremente.
- **`ComidaRealController`** (`app/Http/Controllers/`), rutas bajo `auth`:
  - `GET /plan/{planComida}/comida-real` (`comida-real.create`) — formulario.
  - `POST /plan/{planComida}/comida-real` (`comida-real.store`) — llama a `ComidaRealService::registrar()`.
  - 403 si `planComida->registroDiario->usuario_id` no es el usuario autenticado; redirige a `planes.index` con `error` si el `PlanComida` ya tiene una `ComidaReal` (relación 1—1, no se sobrescribe).
- **Vista** `resources/views/comidas-reales/create.blade.php`; `resources/views/planes/show.blade.php` muestra, por cada comida, el enlace "Registrar con detalle" si aún no tiene una, o sus macros reales + notas + imagen si ya la tiene.

**Tests:** `tests/Feature/ComidaRealTest.php` cubre: acceso protegido por `auth` y por dueño del `RegistroDiario` (403 cruzado entre usuarios), que registrar una `ComidaReal` actualiza `calorias_consumidas` del `RegistroDiario` (y que se acumula correctamente al registrar una segunda comida), que un exceso de calorías en el desayuno reduce proporcionalmente `calorias_estimadas` de almuerzo y cena (aún no registrados) sin tocar el desayuno ya registrado, y que subir una imagen (`Storage::fake('public')`) la deja accesible en `storage/comidas-reales/*` con la URL pública esperada.

## 4.4. Actividad física (implementado)

`app/Services/ActivityCorrectionService.php` decide qué `factor_correccion` aplica a una actividad y delega la multiplicación (`calorias_ajustadas = calorias_dispositivo * factor_correccion`) en `NutritionCalculatorService::calculateAdjustedActivityCalories`, que sigue siendo la única fuente de verdad de esa fórmula y de la validación del rango 0.8–0.9.

**Factores de corrección por tipo de actividad** (constante pública `FACTORES_POR_TIPO`, todos dentro de 0.8–0.9):

| Tipo | Factor | Motivo |
|---|---|---|
| caminata | 0.85 | Cardio de baja intensidad, wearables razonablemente calibrados (valor por defecto). |
| trote | 0.85 | Ídem. |
| ciclismo | 0.85 | Ídem. |
| natación | 0.85 | Ídem. |
| pesas | 0.80 | Los sensores ópticos de frecuencia cardíaca son menos fiables con movimientos intermitentes/explosivos; los dispositivos sobreestiman más el gasto en fuerza. |
| *(cualquier otro tipo)* | 0.85 (`FACTOR_POR_DEFECTO`) | Valor conservador dentro del rango cuando no hay un factor específico documentado. |

La comparación de `tipo` contra la tabla es case-insensitive (`mb_strtolower`).

**Factor fuera de rango: rechazado, no normalizado.** `calcularCaloriasAjustadas()` acepta un `factorPersonalizado` opcional (pensado para uso interno/futuro, no expuesto en el formulario); si ese factor —o uno mal configurado en la tabla— cae fuera de 0.8–0.9, se propaga la `InvalidNutritionParameterException` que ya lanza `NutritionCalculatorService`, en vez de recortarlo (`clamp`) al límite más cercano. Normalizar en silencio escondería un error de configuración dentro de un cálculo de balance energético del usuario; se prefiere que falle de forma explícita.

- **Columnas nuevas en `actividades_fisicas`** (migración `add_pasos_y_fuente_a_actividades_fisicas_table`): `pasos` (unsignedInteger, nullable) y `fuente` (enum `manual`/`dispositivo`, default `manual`). La columna de tipo de actividad ya existía como `tipo` (migración original de la sección 4) — el formulario y el Form Request usan el nombre `tipo_actividad` (más descriptivo de cara al usuario) y el controlador lo mapea a la columna `tipo` real; no se renombró la columna para no romper lo ya implementado.
- **`ActividadFisicaRequest`** (`app/Http/Requests/`): `tipo_actividad` (string requerido), `duracion_min` (integer ≥ 1), `calorias_dispositivo` (numeric ≥ 0, requerido siempre — se necesita para calcular `calorias_ajustadas` incluso si `fuente` es `manual`), `pasos` (nullable, integer ≥ 0), `fuente` (requerido, `in:manual,dispositivo`).
- **`ActividadFisicaController`** (`app/Http/Controllers/`), ruta bajo `auth`:
  - `POST /planes/{registroDiario}/actividades` (`actividades.store`) — registra la actividad en **ese** plan diario (403 si no es del usuario autenticado) con el factor ya aplicado, y recalcula `calorias_actividad_ajustada` del `RegistroDiario` como la suma de `calorias_ajustadas` de todas sus actividades — igual que `ComidaRealService` recalcula `calorias_consumidas` desde cero en vez de acumular con `+=`, para que sea idempotente. Todo dentro de una transacción.
- **No tiene pantalla propia:** el formulario y el listado de actividades del día son la segunda sección del detalle del plan diario (sección 4.13). No queda ningún `GET /actividades` que asuma "hoy".

**Tests:** `tests/Unit/ActivityCorrectionServiceTest.php` cubre el factor de un tipo conocido, el fallback al factor por defecto para un tipo no listado, insensibilidad a mayúsculas, y que un factor personalizado fuera de rango lanza excepción (no se normaliza) mientras uno dentro de rango sí sobreescribe la tabla. `tests/Feature/ActividadFisicaTest.php` cubre acceso protegido por `auth`, 403 sobre el plan de otro usuario, que registrar una actividad aplica el factor correcto y persiste `pasos`/`fuente`, que `calorias_actividad_ajustada` refleja la suma correcta al registrar varias actividades el mismo día, y validación de `duracion_min`/`fuente` inválidos.

## 4.5. Cierre diario (implementado)

`app/Services/DailyClosureService.php` calcula, persiste y congela el cierre de un día. No reimplementa ninguna fórmula: el objetivo calórico y la proteína objetivo salen de `NutritionCalculatorService::calculatePlan()` y el déficit de `calculateDailyDeficit()` (sección 5).

**Métodos públicos:**

- **`resumen(RegistroDiario): array`** — las cinco cifras del cierre, listas para la vista. Si el día está **abierto** se calculan en vivo (vista previa de lo que produciría cerrarlo); si está **cerrado** se leen del snapshot persistido, para que un cambio posterior de perfil no reescriba la historia. Claves: `calorias_objetivo`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, `proteina_objetivo_g`, `proteina_consumida_g`, `cumplimiento_proteina_pct`, `recomendaciones`.
- **`cerrar(RegistroDiario): array`** — calcula, persiste y marca el día como cerrado dentro de una transacción. Lanza `DayAlreadyClosedException` si el día ya estaba cerrado (no es idempotente a propósito: ver abajo). Devuelve el resumen ya persistido.
- **`reabrir(RegistroDiario): void`** — acción explícita del usuario; deja el día abierto de nuevo. No-op si ya estaba abierto.

Las calorías consumidas y la proteína consumida se recalculan siempre desde las `ComidaReal` del día, y el gasto por actividad desde las `ActividadFisica` — no se confía en los acumuladores que mantienen `ComidaRealService` / `ActividadFisicaController`, para que el cierre sea autoritativo aunque esos totales quedaran desfasados.

**Generación de recomendaciones:** el método privado `generarRecomendaciones(RegistroDiario, array $resumen)` se invoca dentro de la transacción del cierre y traduce los promedios móviles de 7 días de `TrendAnalyticsService` en `RecomendacionSistema` pendientes de confirmación vía `RulesEngineService` — nunca deriva un ajuste de un solo día (sección 6). El wiring completo está descrito en la sección 4.11. `resumen()` expone las `RecomendacionSistema` del día para que la vista las muestre.

### Qué significa "cerrado" y cómo se reabre (decisión documentada)

- **No se añadió una columna `estado_cierre`.** El estado del cierre es la columna `cerrado` (boolean, ya existía) más `cerrado_en` (dateTime nullable, nueva): dos valores para un estado binario con marca de tiempo, en vez de un enum redundante con el boolean.
- **Columnas nuevas** (migración `add_cierre_a_registros_diarios_table`): `proteina_objetivo_g` decimal(6,2), `proteina_consumida_g` decimal(6,2), `cerrado_en` dateTime — todas nullable. El resto de las cifras del cierre usa las columnas que ya existían (`calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`).
- **Cumplimiento de macros = proteína.** Se persiste solo el par objetivo/real de proteína (es la métrica que pide el cierre); `cumplimiento_proteina_pct` se deriva de esas dos columnas y no se persiste. Grasa y carbohidratos no se incluyen en el cierre para no persistir un snapshot parcial que después habría que mantener sincronizado.
- **Un día cerrado es inmutable.** `ComidaRealService::registrar()` lanza `DayAlreadyClosedException` si el `RegistroDiario` está cerrado (`ComidaRealController` la traduce a redirect con `error`, tanto en el formulario como en el POST), y `ActividadFisicaController@store` rechaza igual una actividad nueva sobre un día cerrado — ambas cambiarían los totales de los que se calculó el cierre. Escribir el texto de ingredientes, generar una distribución (sección 4.12), reportar ingredientes estructurados y regenerar el plan **no** se bloquean: no alteran ninguna cifra del cierre ya persistida. Registrar el peso del día tampoco (sección 4.11).
- **Sí se permite reabrir, pero solo de forma explícita** (`POST /planes/{registroDiario}/reabrir`). Un usuario que olvidó registrar la cena no debe perder el día entero, y el MVP no tiene otra vía para corregirlo. Reabrir deja las cifras del cierre anterior visibles hasta que se vuelva a cerrar, momento en el que **se recalculan todas desde cero** (no se acumula sobre el cierre previo).
- **Cerrar un día ya cerrado falla en vez de ser idempotente.** Un segundo cierre silencioso escondería que el usuario cree estar cerrando un día que ya estaba cerrado; se prefiere el error explícito, y el camino correcto (reabrir → cerrar) queda a un clic.

### Endpoint y vista

`CierreDiarioController` (`app/Http/Controllers/CierreDiarioController.php`), rutas bajo `auth`, las dos sobre un plan diario concreto (403 si no es del usuario autenticado):

- `POST /planes/{registroDiario}/cierre` (`cierre.cerrar`) — consolida el feedback de cumplimiento (sección 4.16) y cierra el día.
- `POST /planes/{registroDiario}/reabrir` (`cierre.reabrir`).

**`CierreDiarioRequest`** valida el feedback (sección 4.16); `reabrir` no recibe entrada y no necesita Form Request. Ningún fallo de dominio produce un 500 — mismo patrón que `PlanComidaController`: si faltan parámetros nutricionales o el cálculo lanza `NegativeCarbohydrateException`/`InvalidNutritionParameterException` se redirige a `calculadora.edit` con `error`; si el día ya está cerrado o el proveedor de IA no puede interpretar el feedback, se vuelve al plan con `error` y **el día no se cierra**.

**Vista:** el cierre es la tercera sección del detalle del plan diario (`resources/views/planes/show.blade.php` — secciones 4.13/4.15). No hay una pantalla `/cierre` suelta: un cierre siempre pertenece a un día concreto, y una página que asumiera "hoy" contradecía el listado de planes de la sección 4.12. `cerrar`/`reabrir` usan `Redirect::back(fallback: route('planes.show', $registroDiario))`.

**Automatización (implementada):** `app/Console/Commands/RunDailyClosure.php` (`app:run-daily-closure`) recorre los `RegistroDiario` de **ayer** con `cerrado = false` y llama a `DailyClosureService::cerrar()` sobre cada uno; un perfil incompleto o un cálculo inválido (`NegativeCarbohydrateException`/`InvalidNutritionParameterException`) se registra con `$this->warn()` y se salta, sin interrumpir el resto del lote. Programado en `routes/console.php` vía `Schedule::command('app:run-daily-closure')->dailyAt('00:15')`.

**`RegistroDiarioFactory`:** su definición por defecto ahora produce un día **abierto** con las columnas del cierre en `null` (antes rellenaba cifras aleatorias y `cerrado` aleatorio, lo que con la nueva regla de inmutabilidad hacía fallar de forma intermitente a los tests que escriben sobre el día). Para un día ya cerrado hay un estado explícito: `RegistroDiario::factory()->cerrado()`.

**Tests:** `tests/Unit/DailyClosureServiceTest.php` (usa `TestCase` + `RefreshDatabase` explícitos, como el test del generador de planes) cubre el cierre de un día completo contra un cálculo manual verificado paso a paso en comentarios (2112 kcal objetivo / 1950 consumidas / 500 de actividad / 662 de déficit / 90.625% de proteína), que cerrar dos veces lanza excepción sin duplicar ni alterar nada, que el resumen de un día cerrado es el snapshot congelado aunque cambie el perfil, que reabrir permite volver a cerrar recalculando, y un día sin comidas ni actividad. `tests/Feature/CierreDiarioTest.php` cubre el flujo HTTP: acceso protegido por `auth`, cierre exitoso con las cinco cifras visibles en la vista, segundo cierre rechazado, día cerrado que rechaza `ComidaReal` (formulario y POST) y `ActividadFisica`, reapertura explícita que vuelve a admitir una `ComidaReal`, 403 al cerrar o reabrir el día de otro usuario, perfil incompleto, que el resumen de un día abierto es solo una vista previa que no lo cierra, y los cuatro caminos del feedback de la sección 4.16.

## 4.6. Motor de recomendaciones (implementado)

`app/Services/RulesEngineService.php` es el único punto donde se decide si corresponde sugerir un ajuste de `calorias_objetivo` o alertar de un estancamiento, y el único camino por el que una `RecomendacionSistema` confirmada llega a modificar de verdad `calorias_objetivo` del usuario — nunca de forma automática (sección 6).

**No reimplementa el pipeline de tendencias.** `RulesEngineService` recibe el `porcentaje_perdida_semanal` (o las variaciones de peso semana a semana, para estancamiento) **ya calculado** como parámetro; quien lo calcula es `TrendAnalyticsService` (sección 4.7), que expone exactamente esa cifra en `calcular()['porcentaje_perdida_semanal']`. Sus umbrales `UMBRAL_PERDIDA_LENTA_PCT` (0.5) y `UMBRAL_PERDIDA_RAPIDA_PCT` (1.0) son públicos precisamente para que `TrendAnalyticsService` clasifique la tendencia con los mismos números en vez de duplicarlos.

- **`evaluarTendenciaPeso(float $porcentajePerdidaSemanal): ?string`** — aplica literalmente la regla de la sección 6: `< 0.5` → `'reducir'`, `> 1.0` → `'aumentar'`, cualquier otro caso → `null`. Público para poder testear la regla de umbral aislada de la persistencia.
- **`generarRecomendacionAjusteCalorico(RegistroDiario, float $porcentajePerdidaSemanal): ?RecomendacionSistema`** — si `evaluarTendenciaPeso` no devuelve dirección, no persiste nada y retorna `null`. Si hay dirección, persiste una `RecomendacionSistema` (`tipo` = `ajuste_calorico`, `estado` = `pendiente`) con `calorias_objetivo_sugeridas` y una `justificacion` en texto legible. **No toca `calorias_objetivo` del usuario en este paso** — eso solo ocurre al confirmar.
- **`detectarEstancamiento(RegistroDiario, array $variacionesPesoKg): ?RecomendacionSistema`** — recibe la variación de peso (kg, con signo) semana a semana, de la más antigua a la más reciente. Si las últimas **3 semanas** (`SEMANAS_ESTANCAMIENTO`) tuvieron todas una variación absoluta menor a **0.2 kg** (`UMBRAL_ESTANCAMIENTO_KG`), persiste una `RecomendacionSistema` de `tipo` = `alerta_estancamiento`, sin `calorias_objetivo_sugeridas` (queda `null`) y sin ninguna acción automática asociada — es puramente informativa, tal como pide la tarea. Con menos de 3 semanas de datos, no hay suficiente información y no genera nada.
- **`confirmar(RecomendacionSistema): RecomendacionSistema`** / **`rechazar(RecomendacionSistema): RecomendacionSistema`** — transicionan `estado` (`pendiente` → `confirmada`/`rechazada`) dentro de una transacción; lanzan `RecomendacionYaProcesadaException` si la recomendación ya no está `pendiente` (no son idempotentes a propósito, mismo criterio que `DailyClosureService::cerrar()`). **Solo `confirmar()` sobre una recomendación `tipo = ajuste_calorico` con `calorias_objetivo_sugeridas` no nulo actualiza `calorias_objetivo` del `User`** — una `alerta_estancamiento` se puede confirmar (queda marcada como vista) pero nunca trae una cifra que aplicar. Rechazar nunca modifica `calorias_objetivo`.
- **Ajuste fijo de 150 kcal, no un rango.** La sección 6 pide sugerir "100–200 kcal"; en vez de exponer ese rango como variable de entrada (una decisión de UX que el MVP no pidió — ¿el usuario elige la cifra exacta?), se usa el punto medio fijo `AJUSTE_KCAL_SUGERIDO = 150.0` como único valor sugerido, documentado aquí en vez de vía config.
- **Se reutiliza la columna `estado` que ya existía en `recomendaciones_sistema`, no se añadió una columna `aceptada_por_usuario`.** Mismo criterio que la decisión de cierre diario (sección 4.5): un enum `pendiente/confirmada/rechazada` + `confirmada_en` (dateTime, ya existía) representa el mismo estado sin una columna booleana redundante.

### Endpoint

`RecomendacionSistemaController` (`app/Http/Controllers/RecomendacionSistemaController.php`), rutas bajo `auth`:

- `POST /recomendaciones/{recomendacion}/confirmar` (`recomendaciones.confirmar`)
- `POST /recomendaciones/{recomendacion}/rechazar` (`recomendaciones.rechazar`)

403 si `recomendacion->registroDiario->usuario_id` no es el usuario autenticado (misma verificación transitiva que usa `RecomendacionSistema`, que no tiene `usuario_id` propio — sección 4). `RecomendacionYaProcesadaException` se traduce a redirect con `error`, igual patrón que `CierreDiarioController`. Las recomendaciones se listan con sus botones "Confirmar"/"Rechazar" en la tercera sección del plan diario y en Inicio; ambos formularios postean a las mismas rutas y `Redirect::back(fallback: route('dashboard'))` devuelve a la pantalla desde la que se pulsó.

**Wiring:** `DailyClosureService::generarRecomendaciones()` llama a este motor en cada cierre, pasándole `porcentaje_perdida_semanal` y las variaciones semanales de peso que calcula `TrendAnalyticsService` — ver sección 4.11 para el detalle. La vista de las recomendaciones (con sus botones Confirmar/Rechazar) está en la tercera sección de el plan diario y también en Inicio.

**Tests:** `tests/Unit/RulesEngineServiceTest.php` (usa `TestCase` + `RefreshDatabase` explícitos, mismo patrón que los demás Services que tocan Eloquent) cubre: pérdida simulada <0.5% semanal → recomienda reducir; >1% → recomienda aumentar; entre 0.5% y 1% → no genera nada; confirmar sí actualiza `calorias_objetivo` del usuario; rechazar no lo modifica; confirmar dos veces lanza excepción; estancamiento detectado con 3 semanas de variación mínima; no detectado si alguna semana reciente varió más del umbral; no detectado con menos de 3 semanas de datos. `tests/Feature/RecomendacionSistemaTest.php` cubre el flujo HTTP: acceso protegido por `auth`, 403 al confirmar la recomendación de otro usuario, confirmar actualiza `calorias_objetivo` vía HTTP, rechazar no lo modifica, y que confirmar una ya rechazada falla sin efectos.

## 4.7. Analítica de tendencias (implementado)

`app/Services/TrendAnalyticsService.php` es la fuente de los promedios móviles que la sección 6 exige para ajustar el objetivo calórico (nunca un día aislado). Calcula y persiste; no decide nada — quien traduce esas cifras en una `RecomendacionSistema` pendiente de confirmación es `RulesEngineService` (sección 4.6).

**Métodos públicos:**

- **`calcular(User, ?Carbon $fechaCorte = null): array`** — las métricas de la ventana de 7 días que termina en la fecha de corte (hoy por defecto). Claves: `fecha_corte`, `promedio_movil_peso_kg`, `promedio_movil_calorias`, `promedio_movil_deficit_kcal`, `indice_consistencia_pct`, `dias_con_datos`, `dias_cerrados`, `datos_suficientes`, `porcentaje_perdida_semanal`, `tendencia`.
- **`calcularYPersistir(User, ?Carbon): MetricaTendencia`** — el mismo cálculo, guardado como **una sola fila por usuario y fecha de corte** (índice único `usuario_id` + `fecha`). Recalcular la misma fecha actualiza la fila en sitio, no la duplica.
- **`serieHistorica(User, int $dias = 30, ?Carbon): array`** — un punto por día, cada uno con el promedio de *su propia* ventana de 7 días. Es lo que alimenta el gráfico. Se resuelve con **una sola consulta** (los 30 + 6 días necesarios) y las ventanas se recortan en memoria, en vez de 30 consultas o una función de ventana SQL.

**Columna nueva `registros_diarios.peso_kg`** (migración `add_peso_kg_a_registros_diarios_table`, decimal(5,2) nullable). Sin historial de peso no hay promedio móvil que calcular: `users.peso_kg` es un único valor "actual" que se pisa en cada edición del perfil. No se creó una tabla nueva de pesajes porque `RegistroDiario` ya es el registro único por usuario+fecha. La columna se rellena vía `RegistroPesoController` — ver sección 4.11.

**Columnas nuevas en `metricas_tendencia`** (migración `add_analitica_a_metricas_tendencia_table`): `promedio_movil_deficit_kcal` decimal(7,2), `indice_consistencia_pct` decimal(5,2), `dias_con_datos` unsignedTinyInteger. `promedio_movil_calorias` conserva su significado original (calorías **consumidas**) y no se recicló para el déficit: son dos cifras distintas y confundirlas falsearía el balance energético.

### Versión de motor asumida y por qué el promedio se calcula en PHP

La sección 10 exige verificar la versión real antes de usar `AVG() OVER (...)`. Verificado con `php artisan db:show`: **desarrollo es MySQL 8.0.30**, que sí soporta funciones de ventana. Aun así el promedio se calcula **en PHP**, porque:

1. el objetivo de despliegue es hosting compartido de Hostinger, cuya versión no está garantizada ni bajo nuestro control, y
2. la suite corre sobre SQLite en memoria (`phpunit.xml`).

La ventana son 7 filas por usuario: promediarlas en PHP no tiene coste apreciable y el mismo código funciona en los tres motores. **Versión mínima asumida en todo el proyecto: MySQL 5.7 / MariaDB 10.1** (ninguna consulta usa funciones de ventana ni CTEs). Si algún día se fija la versión del servidor, `TrendAnalyticsService` es el único sitio que habría que tocar; la razón está documentada también en el docblock de la clase.

### Definición exacta del índice de consistencia

```
indice_consistencia_pct = días con RegistroDiario cerrado / 7 * 100
```

- **El divisor es siempre 7**, los días naturales de la ventana (incluida la fecha de corte), nunca el número de días con registro: quien solo cerró 2 de los últimos 7 días tiene 28.6%, no 100%.
- **Se cuenta el cierre, no la existencia del `RegistroDiario`**: un registro se crea con solo reportar un ingrediente, mientras que cerrarlo implica haber registrado comidas y actividad — es la señal real de adherencia.
- Siempre es un número: cero días cerrados de siete es `0.0`, no "desconocido".

### Datos insuficientes: se marcan, no se lanzan

`calcular()` nunca lanza por falta de historial. Con menos de 7 días devuelve `datos_suficientes => false` y promedia lo que haya; sin ni un dato, los promedios son `null`. Los días sin valor en una columna **se ignoran, no cuentan como cero** ni en el numerador ni en el divisor (un día sin pesarse no hunde el promedio a la mitad). `promedio_movil_deficit_kcal` sale de `deficit_diario`, que solo se rellena al cerrar el día: en la práctica promedia únicamente días cerrados, y la vista lo advierte.

`porcentaje_perdida_semanal` compara el promedio móvil actual contra el de la ventana que terminó 7 días antes (positivo = adelgazó); es `null` si falta cualquiera de los dos. `tendencia` traduce esa cifra al enum de la tabla usando los umbrales públicos de `RulesEngineService` (0.5 / 1.0) más un margen propio de ±0.1% (`UMBRAL_ESTABLE_PCT`, ~0.08 kg para 80 kg: el ruido de una báscula doméstica) por debajo del cual la tendencia es `estable`.

### Dónde se ve

No hay pantalla propia: estas métricas son el bloque "Tu tendencia" de Inicio (sección 4.17), que es también quien llama a `calcularYPersistir()`. `ProgresoController` y `resources/views/progreso/` ya no existen; `/progreso` quedó como `Route::redirect` a `/dashboard` para no romper enlaces guardados.

**Automatización (implementada):** `app/Console/Commands/CalculateTrends.php` (`app:calculate-trends`) recorre todos los `User` (no existe una columna "activo" en `users` en el MVP, así que "usuarios activos" es todo usuario registrado — el propio servicio no falla si un usuario no tiene ningún `RegistroDiario` todavía) y llama a `TrendAnalyticsService::calcularYPersistir()` con la fecha de corte de hoy. Programado en `routes/console.php` vía `Schedule::command('app:calculate-trends')->dailyAt('00:30')` — 15 minutos después del cierre diario, para que ya estén persistidas las columnas (`deficit_diario`, etc.) que este comando promedia.

**Tests:** `tests/Unit/TrendAnalyticsServiceTest.php` (usa `TestCase` + `RefreshDatabase` explícitos, mismo patrón que los demás Services que tocan Eloquent; fecha de corte fija `2026-03-15` para no depender del día de ejecución) cubre: el promedio móvil de peso sobre 10 días de pesos conocidos contra el cálculo manual escrito en el propio test (los 3 días más antiguos quedan fuera de la ventana y se verifica que no la mueven), el promedio móvil de déficit, el índice de consistencia con 4/7, 0/7 y 7/7 días cerrados (y que un día cerrado fuera de la ventana no lo infla), historial de 3 días → `datos_suficientes` false sin excepción, usuario sin ningún registro, registros sin peso apuntado, días sin peso ignorados en vez de contados como cero, las cuatro clasificaciones de `tendencia`, aislamiento entre usuarios, persistencia de una única fila y recálculo que actualiza en sitio, y la serie histórica (fechas correctas, ventana de cada punto, y huecos `null` en vez de excepción). La cobertura HTTP de estas cifras vive ahora en `tests/Feature/DashboardTest.php` (sección 4.17).

## 4.8. Dashboard principal → ver sección 4.17

`DashboardController` sigue existiendo y sigue sirviendo `GET /dashboard`, pero la pantalla se fusionó con "Mi progreso": su descripción completa está en la **sección 4.17**.

## 4.9. Interfaz de proveedor de IA/reglas (implementado)

`app/Services/AI/NutritionAiProviderInterface.php` desacopla el dominio de "quién decide" dos cosas que hoy resuelve una heurística de reglas pero que en el futuro podría resolver un modelo de IA generativa: qué ingredientes usar en una comida (`sugerirIngredientesParaComida()`) y cómo redactar el texto de una `RecomendacionSistema` (`generarTextoRecomendacion()`).

- **`RuleBasedNutritionProvider`** (`app/Services/AI/RuleBasedNutritionProvider.php`) es la única implementación hoy y la que está bindeada. **No duplica lógica**: la heurística codiciosa de selección de ingredientes (antes privada en `MealPlanGeneratorService`, sección 4.2) y las plantillas de texto de las recomendaciones (antes privadas en `RulesEngineService`, sección 4.6) se movieron aquí tal cual; `MealPlanGeneratorService` y `RulesEngineService` ahora reciben `NutritionAiProviderInterface` por inyección de constructor y delegan en ella en vez de implementar la lógica ellos mismos.
- **Binding:** `AppServiceProvider::register()` liga `NutritionAiProviderInterface` a `RuleBasedNutritionProvider`. Es el único sitio que habría que tocar para cambiar de proveedor.
- **`NutritionAiProviderInterface` sigue sin hacer ninguna llamada externa**, y es correcto: recibe un inventario ya estructurado y solo reparte gramos. La IA generativa del proyecto entró por otra interfaz, `MealDistributionProviderInterface` (sección 4.12), porque el problema que resuelve es distinto: interpretar lenguaje natural y estimar macros. Si algún día se quisiera que también la selección sobre inventario estructurado la decidiera un modelo, bastaría con una `GenerativeAiProvider` que implemente esta interfaz y cambiar su binding en `AppServiceProvider` — el resto del dominio no cambiaría.

**Tests:** `tests/Unit/RuleBasedNutritionProviderTest.php` (usa `TestCase` explícito para poder resolver el binding vía el contenedor, sin necesitar base de datos) cubre que la interfaz resuelve a `RuleBasedNutritionProvider`, que la selección de ingredientes prioriza proteína → grasa → carbohidratos igual que documenta la sección 4.2, que el inventario se descuenta in place entre comidas sucesivas, los tres textos de recomendación (reducir, aumentar, estancamiento) con el mismo formato que ya cubrían los tests de `RulesEngineService`, y que un tipo desconocido lanza `InvalidArgumentException`. Los tests existentes de `MealPlanGeneratorServiceTest` y `RulesEngineServiceTest` siguen pasando sin cambios de aserciones (solo se ajustó cómo se instancian los servicios en los tests, vía `app()` en vez de `new`, porque ahora tienen una dependencia de constructor).

## 4.10. `users.calorias_objetivo` es el objetivo vigente (corrección de integración)

`tests/Feature/DailyFlowTest.php` recorre el día completo de un usuario de punta a punta (registro → parámetros → ingredientes → plan → comida real → actividad → cierre → recomendaciones) y verifica el estado tras cada paso contra las fórmulas de la sección 5 calculadas a mano en el propio test. Escribirlo destapó que `users.calorias_objetivo` estaba **desconectada por los dos extremos**; esta sección documenta la corrección para que no se repita.

**Qué estaba roto:**

1. **Nadie inicializaba la columna.** `ProfileParametersController` guardaba los parámetros base pero no derivaba `calorias_objetivo`, así que todo usuario registrado desde la app la tenía en `null`. `RulesEngineService::generarRecomendacionAjusteCalorico()` la leía con `(float) $usuario->calorias_objetivo` → `0.0`, y persistía una recomendación de **-150 kcal** con el texto "de 0 a -150 kcal". Confirmarla escribía ese -150 en el perfil.
2. **Nadie la leía donde importa.** `confirmar()` sí actualizaba la columna, pero `PlanComidaController` y `DailyClosureService` siempre recalculaban el objetivo desde la fórmula cruda (`peso × 22 × nivel × déficit`), así que **un ajuste confirmado no cambiaba nada**: ni el plan del día siguiente ni el cierre. La sección 6 promete que confirmar "modifica el objetivo calórico vigente"; no lo hacía.

**Cómo quedó:** `users.calorias_objetivo` es **el objetivo calórico vigente** del usuario, la única cifra que dimensiona plan y cierre.

- La **inicializa** `ProfileParametersController@update` desde la fórmula de la sección 5 (ver sección 4 / Autenticación).
- La **mueve** solo `RulesEngineService::confirmar()` sobre una recomendación `ajuste_calorico` — o un cambio explícito de parámetros, que la recalcula desde la fórmula.
- La **consumen** `PlanComidaController@generar` y `DailyClosureService::calcular()`, que se la pasan a `calculatePlan()` en el nuevo parámetro opcional `?float $caloriasObjetivoVigente` (séptimo, por defecto `null` — todas las llamadas anteriores siguen funcionando igual). Cuando llega un valor, **sustituye** al objetivo derivado del déficit; los macros se siguen derivando exactamente como manda la sección 5 (proteína y grasa de peso × factor, carbohidratos como resto), así que la validación obligatoria de carbohidratos negativos también se aplica al objetivo ajustado.
- `RulesEngineService::generarRecomendacionAjusteCalorico()` devuelve `null` si el objetivo vigente es `null` o ≤ 0: no hay ajuste posible sobre un objetivo que todavía no existe, y así nunca se sugiere una cifra negativa aunque quede algún usuario antiguo sin la columna rellena.
- **`UserFactory` deriva `calorias_objetivo`** de los propios parámetros del usuario (closure `fn (array $atributos)`, para que vea también los valores sobrescritos en `factory()->create([...])`) en vez del número aleatorio suelto que ponía antes. Ahora que la columna dimensiona el plan, un fixture con un objetivo que no cuadra con su peso y su déficit generaría planes que no corresponden al perfil. Si los parámetros aleatorios resultan inviables, queda `null` — el mismo estado de un usuario recién registrado.

**Nota para tests:** `actingAs($usuario)` fija *esa instancia* como usuario autenticado de las peticiones siguientes, así que tras una acción HTTP que modifique el perfil (confirmar una recomendación, p. ej.) hay que hacer `$usuario->refresh()` antes de seguir; en producción cada petición recarga el usuario de la base de datos. No es un bug de la app.

**Brecha cerrada en la sección 4.11:** la captura de peso diario y el wiring de `DailyClosureService::generarRecomendaciones()` con `TrendAnalyticsService`/`RulesEngineService` ya están implementados — ver esa sección.

**Tests:** `tests/Feature/DailyFlowTest.php` cubre el día completo paso a paso (incluido que el cierre cuadra con lo introducido y que con un solo día de historial **no** se genera ninguna recomendación, como exige la sección 6), que un día cerrado queda congelado y su resumen no depende del perfil posterior, y que un ajuste confirmado sí pasa a dimensionar el plan y el cierre. `tests/Unit/NutritionCalculatorServiceTest.php` cubre el objetivo vigente que sustituye al derivado y el que es demasiado pequeño para sus propios macros. `tests/Unit/RulesEngineServiceTest.php` cubre que no se sugiere un ajuste sobre un objetivo inexistente.

## 4.11. Captura de peso diario y wiring del motor de recomendaciones (implementado)

Cierra la brecha que dejaban abiertas las secciones 4.7 y 4.10: hasta ahora nada rellenaba `registros_diarios.peso_kg`, así que `porcentaje_perdida_semanal` era siempre `null` y `DailyClosureService::generarRecomendaciones()` devolvía siempre una colección vacía — el motor de reglas (sección 4.6) y su endpoint de confirmación funcionaban pero nunca se disparaban solos.

- **`PlanComidaController@peso`**, ruta bajo `auth`:
  - `POST /planes/{registroDiario}/peso` (`planes.peso`) — guarda (o corrige) `peso_kg` de **ese** plan diario; 403 si no es del usuario autenticado.
- **No tiene pantalla propia.** "Registrar peso" fue un menú suelto y no aportaba nada por sí mismo: el peso es un dato más del día, así que el campo vive en la tarjeta de objetivo del detalle del plan diario (sección 4.12), junto a las cifras que dimensiona.
- **A propósito no toca `users.peso_kg`** (el peso de perfil que dimensiona el TMB en `NutritionCalculatorService`): ese sigue siendo un dato que el usuario edita explícitamente en `ProfileParametersController`, no algo que un pesaje diario deba desplazar en silencio y que recalculara `calorias_objetivo` sin pasar por una `RecomendacionSistema` confirmada (sección 6).
- **Permitido en un día cerrado:** `peso_kg` no alimenta ninguna cifra de `DailyClosureService::armarResumen()` (calorías, déficit, proteína), solo la analítica de tendencias, así que registrar el peso no rompe la inmutabilidad del cierre (a diferencia de `ComidaReal`/`ActividadFisica`, sección 4.5).
- **`RegistroPesoRequest`**: `peso_kg` requerido, numérico, entre 20 y 400 (mismo rango plausible que la columna).

**`TrendAnalyticsService::variacionesSemanalesPesoKg(User, int $semanas = 3, ?Carbon $fechaCorte = null): array<int, float>`** — el insumo que pide `RulesEngineService::detectarEstancamiento()`. Reutiliza el mismo promedio móvil de 7 días de `calcular()`, muestreado cada 7 días hacia atrás en vez de una sola vez, y devuelve las diferencias entre promedios consecutivos (de la más antigua a la más reciente). Si el promedio de alguna semana de la cadena falta (ningún peso apuntado esa semana), la variación que la involucra se omite en vez de compararse contra un dato inexistente — como mucho hay menos variaciones disponibles y `detectarEstancamiento()` no dispara nada, nunca una alerta calculada sobre un hueco.

**`DailyClosureService::generarRecomendaciones()` ya no devuelve una colección vacía a propósito.** Ahora, dentro de la misma transacción de `cerrar()`:

1. Llama a `TrendAnalyticsService::calcular($usuario, $registroDiario->fecha)` (la fecha del propio registro, no "hoy" — así funciona igual desde un cierre en vivo que desde `app:run-daily-closure` sobre el día de ayer). Si `datos_suficientes` es `true` y hay `porcentaje_perdida_semanal`, se lo pasa a `RulesEngineService::generarRecomendacionAjusteCalorico()`.
2. Llama a `TrendAnalyticsService::variacionesSemanalesPesoKg($usuario, 3, $registroDiario->fecha)`; con al menos 3 variaciones disponibles, se las pasa a `RulesEngineService::detectarEstancamiento()`.

Ambos pasos respetan la sección 6 al no aplicar nada directamente: solo persisten una `RecomendacionSistema` `pendiente` (si el motor de reglas decide que corresponde), que el usuario confirma o rechaza como ya hacía la sección 4.6.

**Tests:** `tests/Unit/TrendAnalyticsServiceTest.php` cubre `variacionesSemanalesPesoKg` con cuatro semanas de peso constante por bloque (verificación manual de las tres diferencias) y con una semana intermedia sin peso (la variación que la involucra se omite, no se calcula sobre `null`). `tests/Unit/DailyClosureServiceTest.php` cubre que cerrar un día con 13 días previos de historial de peso (ventana anterior a 80.5 kg, ventana actual a ~80.29 kg, pérdida ≈0.27% semanal) genera una `RecomendacionSistema` `ajuste_calorico` pendiente con la cifra sugerida esperada. `tests/Feature/RegistroPesoTest.php` cubre acceso protegido por `auth`, 403 sobre el plan de otro usuario, que un segundo pesaje el mismo día corrige el primero en vez de duplicar el registro, que `users.peso_kg` no se toca, la validación de rango, y que un día ya cerrado sigue aceptando el pesaje.


## 4.12. Planes diarios: listado, detalle y distribución con Claude Haiku 4.5 (implementado)

**"Planes diarios" es el menú; un plan diario es un `RegistroDiario` con todo su día dentro.** El usuario entra al listado, ve el histórico completo de días que ha llevado, abre cualquiera para consultarlo o completarlo, y crea el de hoy si aún no existe.

- `GET /planes` (`planes.index`) — listado paginado (30 por página, `PLANES_POR_PAGINA`), del día más reciente al más antiguo, con el estado de cada uno (abierto/cerrado, comidas registradas de las planificadas, actividades, peso, y déficit si ya está cerrado). Los conteos salen de `withCount` con alias, no de N+1.
- `POST /planes` (`planes.crear`) — abre el `RegistroDiario` de hoy y lleva a su detalle. Si ya existía, no duplica: redirige al mismo con `status = plan-existente`.
- `GET /planes/{registroDiario}` (`planes.show`) — el detalle, que es el hub del día: cálculo alimenticio (1), actividad física (2) y cierre (3). 403 si el plan no es del usuario autenticado, como todas las rutas con route-model-binding de la sección 10.

**Todas las acciones del día cuelgan de su plan** (`/planes/{registroDiario}/...`: distribución, peso, actividades, cierre, reabrir, generar). Antes asumían "hoy" en el servidor, lo que era incompatible con poder abrir el plan de anteayer. `PlanComidaController` conserva su nombre de clase aunque la sección se llame "Planes diarios": el cambio es de cara al usuario y renombrar solo añadiría ruido al diff (mismo criterio que `ProfileParametersController`, sección 4.14).

### Cálculo alimenticio: un solo "Generar distribución" para las tres comidas

El usuario escribe (o **dicta**) un párrafo por comida — "tengo dos huevos, media palta y pan integral" — y pulsa **una sola vez** "Generar distribución". Las tres comidas viajan juntas al modelo porque el reparto del día es **un único problema de asignación**: pedirlo comida a comida obligaba al modelo a decidir a ciegas cuánto dejar para lo que viniera después.

Ninguna comida es obligatoria. `MealDistributionService::distribuirDia()` clasifica las tres en cada pasada:

| Clase | Cuándo | Qué se hace con su presupuesto |
|---|---|---|
| **fija** | ya tiene `PlanComida` y su texto no cambió, o ya tiene `ComidaReal` | no se toca; sus macros (reales si están registrados, estimados si no) se **descuentan** del objetivo del día |
| **a generar** | tiene texto y no tiene plan, o su texto cambió, o llega en `rehacer` | recibe una parte de lo que queda, proporcional a su peso dentro del 25/40/35 |
| **reservada** | todavía sin texto | **no se resuelve, pero su parte del objetivo se aparta** para cuando se escriba |

De ahí sale el comportamiento que se pidió: si por la mañana solo se escriben desayuno y almuerzo, la cena conserva sus calorías tentativas; y si por la tarde se escribe la cena, **solo se genera la cena** — desayuno y almuerzo siguen siendo literalmente los mismos registros, y lo que ya se comió es lo que gasta presupuesto.

- **"Rehacer solo el X"** (un `<button name="rehacer" value="desayuno">` dentro del mismo formulario) fuerza a regenerar una comida cuyo texto no cambió. Sin él, pulsar "Generar distribución" sin nada nuevo devuelve `MealDistributionUnavailableException::nadaQueDistribuir()` en vez de gastar una llamada al proveedor.
- **Una comida con `ComidaReal` no se regenera nunca**, ni con `rehacer`: se preserva el historial "planificado vs. ejecutado" (sección 4).
- **Los presupuestos por comida los calcula PHP**, no el modelo (regla 7 de la sección 11): el servicio resta lo fijo y lo reservado del objetivo del día y reparte el resto; el modelo solo recibe la cifra ya hecha.

### Columnas y clases

- **Columnas nuevas en `registros_diarios`** (migración `add_ingredientes_texto_a_registros_diarios_table`): `ingredientes_desayuno`, `ingredientes_almuerzo`, `ingredientes_cena` (text nullable). Una columna por comida en vez de una tabla nueva: `RegistroDiario` ya es la fila única por usuario+fecha y el reparto de comidas es fijo, así que no hay cardinalidad variable que justifique otra tabla. Comparar el texto entrante con el guardado es también lo que decide si una comida hay que regenerarla: no hizo falta ninguna columna extra para eso.
- **Columnas nuevas en `planes_comida`** (migración `add_preparacion_y_notas_a_planes_comida_table`): `preparacion` y `notas_ia` (text nullable) — lo que produce la IA y no cabe en `descripcion`. Nullable porque `MealPlanGeneratorService` (sección 4.2) no las produce.
- **`MealDistributionProviderInterface`** (`app/Services/AI/`) es un contrato **aparte** de `NutritionAiProviderInterface` (sección 4.9), a propósito: aquella recibe un inventario ya estructurado y solo decide gramos; esta recibe lenguaje natural y tiene que estimar además los macros. Tiene dos operaciones: `distribuirDia()` (antes de comer) y `estimarConsumoReal()` (al cerrar — sección 4.16). Binding en `AppServiceProvider`.
- **`ClaudeMealDistributionProvider`** es la implementación: `POST https://api.anthropic.com/v1/messages` con **`claude-haiku-4-5`**.

### Decisiones de la integración con la API

- **Cliente HTTP de Laravel, no el SDK de Anthropic para PHP.** La regla 2 de la sección 11 pide no añadir dependencias de Composer que la tarea no justifique, y el objetivo es hosting compartido (sección 10): una sola llamada a `/v1/messages` no justifica un paquete nuevo cuando el facade `Http` ya viene con el framework. Si en el futuro hacen falta streaming, batches o tool use, el SDK oficial sí valdría la pena — y sería un cambio localizado en esa clase.
- **Salida estructurada (`output_config.format` con `json_schema`), no "devuélveme JSON" en el prompt.** La API garantiza que la respuesta valida contra el esquema, así que no hay que parsear texto libre ni reintentar por JSON malformado. Haiku 4.5 soporta structured outputs. El esquema no puede llevar restricciones numéricas (`minimum`/`maximum`) ni de longitud, y exige `additionalProperties: false` y un `required` completo en cada objeto. La respuesta es `{ comidas: [ { tipo_comida, descripcion, preparacion, notas, ingredientes[] } ] }`; **`tipo_comida` no se declara como `enum`** sino como string libre que PHP valida contra las comidas que se pidieron — más estricto que cualquier enum fijo, y así una alucinación de tipo ("merienda") se descarta en vez de persistirse.
- **Sin `thinking` ni `effort`.** Haiku 4.5 es anterior a la familia 4.6: `effort` da error y omitir `thinking` significa sin razonamiento extendido, que es lo que se quiere para una tarea corta y de baja latencia. Hay un test que lo fija. `max_tokens` es 4096 porque ahora caben tres comidas en una respuesta.
- **Los totales de la comida se suman en PHP, no se le piden al modelo.** Un LLM estima bien los macros de un alimento pero no es una calculadora, y estas cifras entran directamente en el balance energético del cierre. El modelo devuelve macros por ingrediente; quien llama los suma.
- **El texto del usuario se manda entre delimitadores y el prompt de sistema dice que es *dato*, no instrucción.** Es entrada propia del usuario (riesgo bajo), pero no cuesta nada tratarla como no confiable.
- **Ningún fallo produce un 500.** `MealDistributionUnavailableException` (extiende `RuntimeException`) cubre: sin `ANTHROPIC_API_KEY`, fallo/timeout del proveedor, `stop_reason` de `refusal` o `max_tokens`, respuesta ininterpretable, "no reconocí ningún alimento" (donde el mensaje del modelo se le muestra tal cual al usuario) y "nada nuevo que distribuir". El controlador la traduce a redirect con mensaje, mismo patrón que la sección 4.2.
- **El texto se guarda aunque la generación falle**, para no perder lo que el usuario escribió o dictó.
- **Configuración** en `config/services.php` → `anthropic` (`key`, `model`, `endpoint`, `timeout`), poblada desde `.env` (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`). Nunca hardcodeada ni commiteada.

### Endpoint y dictado por voz

- `POST /planes/{registroDiario}/distribucion` (`planes.distribucion`).
- **`DistribucionDiaRequest`**: `ingredientes` array con `ingredientes.desayuno|almuerzo|cena` (nullable, string, 3–2000 caracteres) y `rehacer` opcional (`in:` las claves de `DISTRIBUCION_COMIDAS`). Un hook `after()` exige que al menos una comida traiga texto. Las claves que no sean tipos de comida conocidos se ignoran en el servicio. Solo valida forma y tamaño: si el texto describe alimentos de verdad lo decide el modelo, que devuelve un aviso legible cuando no reconoce ninguno.
- **Dictado por voz con la Web Speech API del navegador** (`SpeechRecognition`/`webkitSpeechRecognition`, `lang = es-ES`), en JS plano dentro de `planes/show.blade.php`: sin dependencias nuevas y sin que el audio pase por nuestro servidor. Cubre tanto los textos de ingredientes como los del feedback del cierre (sección 4.16). Si el navegador no la soporta, el botón del micrófono queda oculto (`hidden` por defecto, se revela desde JS) y el usuario escribe a mano.

**Tests:** `tests/Unit/ClaudeMealDistributionProviderTest.php` (con `Http::fake`) cubre que se llama a Haiku 4.5 con salida estructurada y sin `thinking`/`effort`, que las tres comidas y el contexto del día (fijas y reservadas) viajan en un único prompt, la normalización de la respuesta, el descarte de una comida que no se pidió, la estimación de consumo real, y los caminos de fallo. `tests/Unit/MealDistributionServiceTest.php` (con un proveedor de mentira inyectado en el contenedor) cubre el reparto 25/40/35, que se usa el objetivo vigente y no el de la fórmula, guardar/limpiar el texto, la suma de macros en PHP, la reserva del presupuesto de la cena, que añadir la cena después no toca desayuno ni almuerzo, la regeneración por cambio de texto, `rehacer`, y que no se pisa una comida ya registrada. `tests/Feature/PlanDelDiaTest.php` cubre el flujo HTTP completo, incluidos el listado, su paginación, el aislamiento entre usuarios y los 403.

## 4.13. Actividad física dentro del plan diario (implementado)

`app/Services/ActivitySuggestionService.php` propone qué actividad hacer ese día a partir del resultado de la Calculadora Déficit. Es solo sugerencia: no persiste nada, no toca `calorias_objetivo` y no genera ninguna `RecomendacionSistema`.

- **Objetivo de actividad = 40% del déficit dietético** (`PROPORCION_DEL_DEFICIT`), acotado a **150–600 kcal**. Se deriva del propio déficit del usuario (mantenimiento − objetivo vigente) en vez de ser una cifra fija: así un plan agresivo pide más actividad que uno conservador, sin añadir un parámetro nuevo al perfil. El acotado mantiene la sugerencia entre "una caminata corta" y "una sesión exigente".
- **`NutritionCalculatorService::calculateMaintenanceCalories()` es nuevo y público** precisamente para esto: el servicio necesita el mantenimiento para dimensionar el déficit y no debe reimplementar `peso × 22 × nivel_actividad`. La sección 5 sigue siendo la única fuente de verdad.
- **Duraciones con la fórmula MET estándar** (`kcal/min = MET × 3.5 × peso_kg / 200`), con METs por tipo en `MET_POR_TIPO`. Los tipos coinciden con los de `ActivityCorrectionService::FACTORES_POR_TIPO` para que lo sugerido se pueda registrar tal cual y reciba el factor correcto.
- **Tope de 90 minutos** (`DURACION_MAXIMA_MIN`): cuando una actividad poco intensa necesitaría más, se recorta y se informan las calorías que esa duración recortada **sí** quema (`alcanza_objetivo => false`), nunca las del objetivo — no se promete un gasto que la sugerencia no alcanza.
- **El registro de lo que se hizo es `ActividadFisicaController@store`**, ahora sobre `POST /planes/{registroDiario}/actividades` (sección 4.4).

**Tests:** `tests/Unit/ActivitySuggestionServiceTest.php` cubre el objetivo derivado del déficit, el acotado por arriba y por abajo, el caso de objetivo mayor que el mantenimiento (nunca negativo), la duración calculada con MET verificada a mano, el recorte a 90 minutos informando las calorías reales, que todos los tipos sugeridos tienen factor de corrección, y el escalado con el peso.

## 4.14. Calculadora Déficit y objetivo calórico global (implementado)

Los "parámetros nutricionales" pasaron de ser una entrada del menú desplegable a ser **"Calculadora Déficit"**, un menú principal junto a Inicio — es el primer paso del flujo y lo que dimensiona todo lo demás.

- **Rutas renombradas:** `calculadora.edit` / `calculadora.update` en `/calculadora` (antes `profile.parametros.*` en `/profile/parametros`). La clase `ProfileParametersController` y la vista `profile/parametros.blade.php` **conservan su nombre**: el cambio es de cara al usuario y renombrar los archivos solo habría añadido ruido al diff. El docblock del controlador lo aclara.
- **La vista muestra el resultado arriba del formulario**, en una tarjeta destacada: "Tu objetivo calórico diario — N kcal". Antes el usuario guardaba y no veía la cifra que acababa de calcular.
- **El objetivo calórico se ve en toda la plataforma**, junto al nombre del usuario en la barra de navegación (`layouts/navigation.blade.php` lee `Auth::user()->calorias_objetivo`, que es el objetivo vigente de la sección 4.10). Si todavía es `null`, en su lugar aparece un aviso ámbar "Calcula tu objetivo" que enlaza a la calculadora.

**Tests:** `tests/Feature/PlanDelDiaTest.php` cubre que la cifra aparece en Inicio, en el listado de planes y en el detalle de un plan. `tests/Feature/ProfileParametersTest.php` se ajustó a la URL nueva.

## 4.15. Mobile-first y navegación (implementado)

- **`layouts/navigation.blade.php`** tiene dos barras: la superior (logo + enlaces) solo en `sm:` hacia arriba, y una **barra inferior fija estilo app** (`fixed bottom-0`, `grid-cols-3`, con iconos SVG en línea) que solo aparece por debajo de `sm`. Se eliminó el menú hamburguesa.
- **Tres destinos, no cinco:** Inicio, Calculadora Déficit y Planes diarios. "Mi progreso" se fusionó con Inicio (sección 4.17) y "Registrar peso" pasó a ser un campo del plan diario (sección 4.11); antes de eso ya habían salido del menú "Ingredientes", "Actividad física" y "Cierre del día", que son secciones del plan diario. Menos entradas, cada una con una razón para existir.
- **`layouts/app.blade.php`**: `viewport-fit=cover` + `pb-[env(safe-area-inset-bottom)]` en la barra para respetar el *safe area* de iOS, `theme-color`, y `pb-24 sm:pb-0` en `<main>` para que el contenido no quede debajo de la barra.
- **Componentes compartidos**: `x-text-input` usa `text-base sm:text-sm` (por debajo de 16px Safari hace zoom automático al enfocar un campo) y `py-2.5`; `x-primary-button`/`x-secondary-button` tienen `min-h-[2.75rem]` (44px, el área táctil mínima recomendada). Al ser componentes, esto arregla todos los formularios de una vez.
- **Pasada en todas las vistas**: `py-6 sm:py-12` en vez de `py-12`, `px-4` en móvil (antes las tarjetas iban a sangre), `shadow-sm rounded-xl`, títulos `text-lg sm:text-xl`, y rejillas que apilan en móvil. Las tablas anchas (el seguimiento semanal de la sección 4.17) van dentro de un `overflow-x-auto` para que la página no scrollee en horizontal.
- **`layouts/guest.blade.php`** (login/registro) también recibió `px-4`, más aire vertical y esquinas redondeadas en móvil.

## 4.16. Feedback de cumplimiento en el cierre del día (implementado)

Antes, cerrar el día solo contaba lo que el usuario hubiera registrado a mano comida por comida; en la práctica cerraba con las comidas sin registrar y el déficit salía inflado. Ahora el cierre **pregunta**, comida a comida, si se cumplió lo sugerido.

`app/Services/CierreFeedbackService.php` es el servicio de dominio. Dos caminos por comida, ninguno obligatorio:

- **"Sí, comí lo que se sugirió"** (una casilla) — se crea la `ComidaReal` con los macros del propio `PlanComida`. Es un clic y **no cuesta ninguna llamada al proveedor de IA**.
- **Contar qué se comió** (un textarea, también dictable) — "al final me comí un sándwich de pollo y una gaseosa" pasa por `MealDistributionProviderInterface::estimarConsumoReal()`, que lo traduce a alimentos con sus macros. **Las tres comidas viajan en una sola llamada** para que la estimación sea coherente entre ellas, y el plan sugerido va como referencia de porciones.

Decisiones:

- **El texto manda sobre la casilla.** Si el usuario marca "lo cumplí" *y* además escribe qué comió, gana el texto: es la información más fiel.
- **Primero se resuelve la IA, después se escribe.** Un fallo del proveedor deja el día **sin cerrar y sin ninguna `ComidaReal` a medias**, para que el usuario pueda corregir el texto y reintentar.
- **Las cifras se suman en PHP** a partir de los ingredientes que estima el modelo, nunca de un total que devuelva él (regla 7 de la sección 11). La persistencia va por `ComidaRealService::registrar()`, que es quien redistribuye el presupuesto pendiente del día y recalcula `calorias_consumidas` (sección 4.3).
- **Nunca sobrescribe lo ya registrado.** Una comida con `ComidaReal` —o sin `PlanComida`— se ignora; el camino detallado con macros exactos e imagen sigue siendo `ComidaRealController` ("Registrar con detalle").
- **Cerrar sin decir nada sigue siendo válido:** las comidas sin registrar simplemente no suman calorías consumidas.
- **`CierreDiarioRequest`** valida `feedback.{comida}.cumplio` (boolean nullable) y `feedback.{comida}.texto` (string nullable, `max:1000`, el mismo tope que `comidas_reales.notas`).

**Tests:** `tests/Unit/CierreFeedbackServiceTest.php` cubre la casilla sin llamada al proveedor, el texto interpretado con los macros sumados en PHP, la consolidación de varias comidas en una sola llamada, que el texto manda sobre la casilla, que se ignoran las comidas ya registradas o sin plan, y que un fallo del proveedor no deja nada escrito. `tests/Feature/CierreDiarioTest.php` cubre el formulario y los cuatro caminos vía HTTP.

## 4.17. Inicio: dashboard y "Mi progreso" unificados (implementado)

Eran dos pantallas que costaba distinguir: el dashboard mostraba tendencias y "Mi progreso" también. Ahora hay una sola, `GET /dashboard` (`dashboard`, protegida por `auth`+`verified`), con tres alturas de mirada — y `/progreso` es un `Route::redirect` a ella para no romper enlaces guardados.

1. **Hoy** — `DailyClosureService::resumen()` (las cuatro cifras del día), el estado de las tres comidas (`pendiente`/`planificada`/`registrada`, derivado en memoria de las relaciones) y el botón para abrir o crear el plan de hoy.
2. **Tu tendencia** — lo que era "Mi progreso": promedio móvil de peso con su % semanal y su tendencia, déficit promedio, índice de consistencia, y el gráfico de Chart.js (CDN, `data-serie` en el `<canvas>`; si el CDN no carga, las cifras server-rendered siguen ahí y el script se autolimita).
3. **Tu seguimiento** — `SeguimientoService`, el bloque nuevo: seis semanas, una fila por semana, con adherencia (días cerrados / 7, mismo divisor que el índice de consistencia), comidas registradas de las planificadas, peso medio, **variación de peso contra la semana anterior** y déficit medio. Debajo, el **historial de ajustes**: todas las `RecomendacionSistema` del usuario con su estado, en una línea de tiempo — el rastro de cómo se ha ido moviendo su objetivo. Las pendientes siguen teniendo sus botones "Confirmar"/"Rechazar".

- **`SeguimientoService` solo lee y agrega.** No calcula ninguna fórmula nutricional ni decide ningún ajuste: suma lo que ya persistió el cierre diario en `registros_diarios` y lo que persistió `RulesEngineService` en `recomendaciones_sistema`. `DIAS_POR_SEMANA` se toma de `TrendAnalyticsService::DIAS_VENTANA` para que los dos bloques de la pantalla hablen del mismo tamaño de semana.
- **Los días sin dato se ignoran, no cuentan como cero** (mismo criterio que la sección 4.7), y la variación de peso es `null` si falta el promedio de cualquiera de las dos semanas, en vez de calcularse sobre un hueco.
- **`whereDate` y no `whereBetween`** al traer los registros del rango: la columna `fecha` guarda la hora 00:00:00 y comparar como cadena dejaba fuera el propio día de corte. Mismo patrón que `TrendAnalyticsService::registrosEntre`.
- **El GET persiste el snapshot de tendencias** (`calcularYPersistir()`, una fila por usuario+fecha actualizada en sitio). Lo hacía `ProgresoController`; al desaparecer esa pantalla, la escritura se mudó aquí para que la serie de `metricas_tendencia` siga alimentada allí donde el cron de `app:calculate-trends` no corre.
- **`RecomendacionSistemaController::confirmar()`/`rechazar()`** usan `Redirect::back(fallback: route('dashboard'))`, así que confirmar desde Inicio vuelve a Inicio y desde el plan diario vuelve al plan.

**Tests:** `tests/Unit/SeguimientoServiceTest.php` (fecha de corte fija `2026-03-15`) cubre el resumen semanal contra cifras calculadas a mano, el divisor 7 de la adherencia, el conteo de comidas, los días sin peso ignorados, la variación `null`, un usuario sin datos, el aislamiento entre usuarios, y el historial ordenado y acotado. `tests/Feature/DashboardTest.php` cubre las tres secciones vía HTTP, el redirect de `/progreso`, el aviso de perfil incompleto sin 500, y que no se mezclan datos entre usuarios.

## 5. Algoritmo de cálculo nutricional (fuente de verdad)

Implementar exactamente así en `NutritionCalculatorService` (o el nombre que se use), con tests unitarios por cada fórmula:

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

**Validación obligatoria:** si `carbohidratos_kcal` resulta negativo (déficit agresivo + factores altos de proteína/grasa), el servicio debe lanzar una excepción de dominio o devolver un resultado de error explícito — nunca persistir un plan con carbohidratos negativos.

**Nota de diseño conocida y aceptada:** `TMB = peso_kg * 22` es una simplificación intencional del MVP. `estatura_m`, `edad` y `sexo` se capturan en el modelo de Usuario pero no se usan todavía en el cálculo. No "arreglar" esto por iniciativa propia migrando a Mifflin-St Jeor u otra fórmula sin que se pida explícitamente — es una decisión de producto documentada, no un bug.

### Estado actual (implementado)

`app/Services/NutritionCalculatorService.php` implementa el algoritmo anterior. Es la fuente de verdad del cálculo: ningún otro punto del código debe reimplementar estas fórmulas.

- **`calculatePlan(float $pesoKg, float $nivelActividad, string $tipoDeficit, float $valorDeficit, float $proteinaFactor, float $grasaFactor, ?float $caloriasObjetivoVigente = null): array`** — devuelve `['calorias_objetivo', 'proteina_g', 'grasa_g', 'carbohidratos_g']` (claves en español, coinciden con las columnas). `$valorDeficit` es una fracción (0.2 = 20%) cuando `$tipoDeficit === 'porcentaje'` y kcal cuando es `'fijo'`. `$caloriasObjetivoVigente`, cuando no es `null`, **sustituye** al objetivo derivado del déficit (es `users.calorias_objetivo` — ver sección 4.10); los macros y la validación de carbohidratos negativos se aplican igual sobre él.
- **`calculateMaintenanceCalories(float $pesoKg, float $nivelActividad): float`** — `(peso_kg * 22) * nivel_actividad`. Público para que `ActivitySuggestionService` (sección 4.13) dimensione el déficit sin reimplementar la fórmula; `calculatePlan()` lo usa internamente.
- **`calculateAdjustedActivityCalories(float $caloriasDispositivo, float $factorCorreccion): float`** — `calorias_dispositivo * factor_correccion`.
- **`calculateDailyDeficit(float $caloriasObjetivo, float $caloriasConsumidas, float $caloriasActividadAjustada): float`** — `objetivo - consumidas + actividad_ajustada`.
- **Constantes públicas** para rangos y tipos de déficit (`TIPO_DEFICIT_PORCENTAJE`, `TIPO_DEFICIT_FIJO`, `PROTEINA_FACTOR_MIN/MAX`, `GRASA_FACTOR_MIN/MAX`, `FACTOR_CORRECCION_MIN/MAX`) — usarlas en vez de repetir literales en otros servicios o Form Requests.

**Excepciones de dominio** (`app/Exceptions/`, no existía esa carpeta antes de este servicio):

- **`NegativeCarbohydrateException`** (extiende `DomainException`): se lanza cuando `carbohidratos_kcal < 0`, cumpliendo la validación obligatoria de arriba. Exactamente `0` sí se acepta (es un plan válido, aunque extremo).
- **`InvalidNutritionParameterException`** (extiende `InvalidArgumentException`): se lanza cuando `proteina_factor` o `grasa_factor` caen fuera de rango (regla de la sección 6, validada dentro del servicio y no solo en el Form Request, porque el cierre diario y el generador de planes también invocarán el cálculo sin pasar por HTTP), cuando `factor_correccion` está fuera de 0.8–0.9, o cuando `tipo_deficit` no es `porcentaje`/`fijo`. `nivel_actividad` sigue validándose solo en `ProfileParametersRequest`: la sección 6 exige explícitamente validar los factores de macros, no ese.

**Decisiones menores:** el servicio devuelve floats sin redondear (el redondeo a la precisión de cada columna decimal es responsabilidad de la capa de persistencia) y no depende de Eloquent ni de la base de datos — recibe escalares, así que sus tests son unitarios puros.

**Tests:** `tests/Unit/NutritionCalculatorServiceTest.php` cubre déficit por porcentaje, déficit fijo, un caso real verificado a mano paso a paso en comentarios (con comprobación cruzada de que las kcal de los macros suman las calorías objetivo), el borde de carbohidratos negativos y el de carbohidratos exactamente 0, factores de macros fuera de rango, `tipo_deficit` desconocido, `factor_correccion` fuera de rango y en ambos extremos, y el déficit diario (positivo y negativo). Las comparaciones de floats usan `toEqualWithDelta` porque las fórmulas encadenan multiplicaciones de decimales no representables en binario.

## 6. Reglas de negocio

- Mantener las calorías objetivo constantes en el corto plazo; no recalcular el objetivo por un solo día atípico.
- Los ajustes automáticos de calorías objetivo se basan en promedios móviles de 7 días, nunca en un valor diario aislado:
  - pérdida < 0.5% semanal → sugerir reducir 100–200 kcal
  - pérdida > 1% semanal → sugerir aumentar 100–200 kcal
- Todo ajuste automático se registra como `RecomendacionSistema` y requiere confirmación del usuario antes de modificar el objetivo calórico vigente — nunca se aplica solo.
- Validar siempre que `proteina_factor` y `grasa_factor` estén dentro de los rangos definidos antes de calcular un plan.

## 7. Convenciones de código

- PSR-12, formateado con Laravel Pint antes de cada commit.
- Nombres de tablas/columnas en español (coinciden con el modelo de datos del documento de arquitectura) — no traducir a inglés a mitad de camino.
- Validación de entrada siempre vía Form Requests, nunca validación inline en el controlador.
- Usar Eloquent y sus relaciones; evitar SQL crudo salvo que sea estrictamente necesario (por ejemplo, promedios móviles si la versión de MySQL no soporta funciones de ventana — documentar la razón en el propio código si esto ocurre).
- Fechas y cálculos de balance energético: cuidado con timezones — usar la timezone configurada en `config/app.php`, no `UTC` a pelo, para que el "día" del usuario tenga sentido.

## 8. Testing (obligatorio en cada tarea)

- Framework: Pest.
- Todo Service de dominio (`app/Services/*`) requiere tests unitarios que cubran casos normales y de borde (especialmente el cálculo nutricional y el cierre diario).
- Todo flujo de usuario nuevo (registrar ingredientes, generar plan, registrar comida real, cerrar el día) requiere al menos un test de feature.
- Comando para correr toda la suite: `php artisan test` (o `./vendor/bin/pest`).
- No se considera terminada una tarea si los tests no pasan.

## 9. Flujo de trabajo Git

- Un commit por tarea/prompt completado, con mensaje descriptivo (`feat: servicio de cálculo nutricional`, `test: cobertura de cierre diario`, etc.).
- No hacer commit de `.env`, `vendor/`, ni `node_modules/`.
- Antes de dar una tarea por terminada: `php artisan test` en verde y `vendor/bin/pint` sin cambios pendientes.

## 10. Despliegue (Hostinger)

**Paso a paso completo en `DEPLOY.md`** (raíz del proyecto): estructura de carpetas en el servidor, comandos del primer despliegue y de actualizaciones posteriores, configuración del cron job, y checklist de verificación post-despliegue. Esta sección solo resume las decisiones de fondo; el "cómo" operativo vive en ese archivo.

- Un solo cron job: `* * * * * php /home/USER/domains/DOMINIO/public_html/artisan schedule:run >> /dev/null 2>&1`. Toda la automatización diaria (`app:run-daily-closure` 00:15, `app:calculate-trends` 00:30) está registrada en el Scheduler vía `routes/console.php` (Laravel 13 no usa `app/Console/Kernel.php`) para poder depender de este único cron — no asumir que Hostinger permite varios cron jobs de Laravel independientes.
- **Resuelto:** `TrendAnalyticsService` calcula el promedio móvil en PHP sobre los últimos 7 `RegistroDiario`, así que no hace falta verificar la versión del plan contratado — no se usa `AVG() OVER (...)` en ningún sitio. Versión mínima asumida: MySQL 5.7 / MariaDB 10.1 (sección 4.7).
- Variables sensibles (API key del proveedor de IA, credenciales de MySQL) solo en `.env`, nunca hardcodeadas ni commiteadas. `.env.example` documenta cada variable esperada.
- **`ANTHROPIC_API_KEY` es obligatoria para que funcione "Generar distribución"** (sección 4.12). Sin ella el resto de la aplicación funciona con normalidad y ese botón devuelve un mensaje pidiendo configurarla — no un 500. `ANTHROPIC_MODEL` por defecto es `claude-haiku-4-5`. El hosting debe permitir salida HTTPS a `api.anthropic.com`; si el plan de Hostinger bloquea las conexiones salientes, hay que habilitarla antes de desplegar.
- Antes de cada despliegue: `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache`.
- **`config('app.timezone')` es configurable vía `APP_TIMEZONE`** (antes estaba fijo en `'UTC'` en `config/app.php`, contradiciendo la sección 7). Por defecto sigue siendo `UTC` para no alterar el comportamiento de la suite de tests, pero antes de desplegar hay que fijarlo en `.env` a la timezone del mercado objetivo — de eso depende directamente dónde cae la medianoche que usa `now()->toDateString()` para decidir "el día de hoy" en todo el dominio (registro diario, cierre, tendencias).
- **Revisión de seguridad hecha (Prompt 16):** los ocho modelos Eloquent ya declaraban `#[Fillable([...])]` (sintaxis de atributos de Laravel 13, equivalente a `protected $fillable`) desde que se crearon — no había mass assignment sin controlar. Todas las rutas bajo `auth` que reciben un ID de recurso por route-model-binding (`{registroDiario}`, `{ingrediente}`, `{planComida}`, `{recomendacion}`) ya verificaban propiedad con `abort_unless(...usuario_id === $request->user()->id, 403)`; se completó la cobertura de tests que faltaba para tres de esos casos (`ingredientes.update`, `comida-real.create` vía GET, `recomendaciones.rechazar`) — el código ya los rechazaba, solo faltaba el test. Todos los formularios Blade usan `@csrf` y no hay ninguna excepción configurada sobre `VerifyCsrfToken`. El límite de subida de imágenes lo gobierna la validación de aplicación (`ComidaRealRequest`, `max:4096` KB — sección 4.3); `DEPLOY.md` sección 5 documenta verificar que `upload_max_filesize`/`post_max_size` del hosting no queden por debajo de ese límite.

## 11. Reglas para Claude Code al trabajar en este proyecto

1. **Actualiza este archivo (`CLAUDE.md`) al final de cada tarea** si agregaste un modelo, servicio, comando, endpoint/ruta, o tomaste una decisión de diseño no trivial. Agrega la información al bloque correspondiente arriba; no crees un log histórico interminable — este archivo describe el estado actual del proyecto, no su historia.
2. No introduzcas dependencias nuevas (paquetes Composer/npm) sin que estén justificadas por la tarea en curso.
3. No sobre-diseñes: si una tarea puede resolverse con una clase de servicio simple, no introduzcas patrones (repositorios, eventos, colas) que el documento de arquitectura no pidió para el MVP.
4. Si una tarea es ambigua o el documento de arquitectura no cubre un caso, toma la decisión más simple consistente con las secciones 5 y 6 de este archivo, impleméntala, y dócumentala aquí — no te detengas a preguntar salvo que la ambigüedad afecte datos financieros/de salud del usuario de forma irreversible.
5. Nunca implementes lógica que aplique automáticamente un ajuste de calorías objetivo sin pasar por `RecomendacionSistema` y confirmación del usuario (ver sección 6).
6. **Toda llamada a un modelo de IA pasa por una interfaz en `app/Services/AI`**, nunca desde un controlador, un modelo o una vista. Y ninguna puede tumbar la aplicación: un fallo del proveedor (sin clave, timeout, respuesta rara) se traduce a una excepción de dominio y a un mensaje para el usuario, nunca a un 500 — ver sección 4.12.
7. **Ninguna cifra que entre al balance energético del usuario la calcula un modelo de IA.** El modelo estima macros por alimento; las sumas, los objetivos y el déficit los calcula PHP con `NutritionCalculatorService` (sección 5).
8. **Mobile-first no es opcional** (sección 4.15): toda vista nueva se diseña primero para móvil (una columna, `text-base` en campos, objetivos táctiles de 44px) y se ensancha con `sm:`/`lg:`, no al revés.
