# CLAUDE.md — TUDéficit Inteligente

Este archivo es la memoria de proyecto para Claude Code. Se carga al inicio de cada sesión: mantenlo actualizado, pero no lo infles con detalle que no se necesita en *cada* tarea (ese detalle adicional vive en los documentos de `/docs`, si se crean más adelante).

## 1. Qué es este proyecto

**TUDéficit Inteligente** es una aplicación web de gestión inteligente de pérdida de peso: genera planes de comida a partir de ingredientes disponibles, registra consumo real y actividad física, calcula el balance energético diario, y detecta tendencias (no valores diarios aislados) para ajustar el plan del usuario.

Referencia funcional completa: `Arquitectura_TUDeficit_Inteligente.docx` (si está en el repo) o el documento de arquitectura entregado junto a este archivo.

## 2. Stack tecnológico (fijo, no proponer alternativas sin pedirlo)

**Versión de Laravel instalada:** 13.x (última estable al momento de inicializar el proyecto). Se intentó fijar 10.x/11.x, pero el audit de seguridad de Composer bloquea la resolución de *todas* las versiones 10.x y 11.x de `laravel/framework` (advisories sin parche disponible en esas líneas) — no es viable instalarlas sin desactivar el chequeo de seguridad. Se optó por la última estable (13.x) en su lugar. No hace falta discutir esto de nuevo salvo que surja un motivo concreto para fijar una versión distinta.


- **Backend:** Laravel (PHP 8.x), monolito — sin API REST separada en el MVP.
- **Frontend:** Blade (server-rendered) + Chart.js para gráficos del dashboard. Sin SPA, sin build de frontend pesado.
- **Base de datos:** MySQL 8.x / MariaDB 10.6+.
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
| Cierre diario | `app/Services/DailyClosureService.php`, `app/Console/Commands/RunDailyClosure.php` |
| Analítica | `app/Models/MetricaTendencia.php`, `app/Services/TrendAnalyticsService.php` (pendiente), `app/Console/Commands/CalculateTrends.php` (pendiente) |
| Motor de recomendaciones | `app/Services/RulesEngineService.php`, `app/Http/Controllers/RecomendacionSistemaController.php` |
| Motor de IA/reglas | `app/Services/AI/NutritionAiProviderInterface.php` + implementación concreta |

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
- **`registros_diarios`**: `usuario_id` (FK cascade), `fecha` (date, único junto a `usuario_id`), `calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, `proteina_objetivo_g`, `proteina_consumida_g` (todas decimal nullable, se rellenan en el cierre diario), `cerrado` (boolean) + `cerrado_en` (dateTime nullable) — ver sección 4.5.
- **`ingredientes_disponibles`**: `registro_diario_id` (FK cascade) — son entradas ad-hoc por registro diario, no un catálogo maestro compartido; no hay caso de `restrict` en este modelo de datos porque no existen tablas de referencia compartidas en el MVP.
- **`planes_comida`**: `registro_diario_id` (FK cascade), `tipo_comida` enum(desayuno,almuerzo,cena,snack), macros estimados.
- **`comidas_reales`**: `plan_comida_id` (FK **unique** + cascade, implementa la relación 1—1), macros reales, `consumido_en`, `notas`.
- **`actividades_fisicas`**: `registro_diario_id` (FK cascade), `calorias_dispositivo`, `factor_correccion` (default 0.85, rango 0.8–0.9 según sección 5), `calorias_ajustadas` (= dispositivo × factor).
- **`metricas_tendencia`**: `usuario_id` (FK cascade), `fecha` (único junto a `usuario_id`), promedios móviles y `tendencia` enum.
- **`recomendaciones_sistema`**: `registro_diario_id` (FK cascade), `estado` enum(pendiente,confirmada,rechazada) default pendiente — refleja la regla de la sección 6 (nunca se aplica un ajuste sin confirmación).
- **`onDelete`:** cascade en todas las FKs — todas las entidades hijas son datos propios del usuario/registro sin sentido fuera de su padre; no hay entidades de catálogo compartido que requieran `restrict` en este MVP.
- **Tests:** `tests/Feature/ModeloDatosTest.php` cubre cada relación y dos casos de cascade delete. Se activó `RefreshDatabase` en `tests/Pest.php` (estaba comentado); `phpunit.xml` ya usa sqlite en memoria para testing.

`ComidaReal` es una entidad separada de `PlanComida` (no se sobrescribe el plan) — preserva el historial de "planificado vs. ejecutado". No cambiar este diseño sin discutirlo explícitamente.

### Autenticación (implementada)

Laravel Breeze (stack Blade, con Pest) instalado vía `laravel/breeze` (dev dependency) — provee registro, login, logout, recuperación/reseteo de contraseña y confirmación de contraseña. Rutas en `routes/auth.php` (`register`, `login`, `logout`, `forgot-password`, `reset-password/{token}`, `verify-email`, `confirm-password`). Vistas en `resources/views/auth/*`. Todas las rutas del área autenticada (`/dashboard`, `/profile`, `/profile/parametros`) están protegidas con el middleware `auth`.

- **Parámetros nutricionales tras el registro:** en vez de extender el formulario de registro (para no complicar el flujo estándar de Breeze), tras registrarse el usuario es redirigido a una pantalla separada "Completa tu perfil" (`ProfileParametersController@edit`, ruta `GET /profile/parametros` → `profile.parametros.edit`) donde define `peso_kg`, `estatura_m`, `edad`, `sexo`, `nivel_actividad`, `tipo_deficit`, `valor_deficit`, `proteina_factor`, `grasa_factor`. Se guarda con `PUT /profile/parametros` (`profile.parametros.update`) vía `ProfileParametersController@update`. Accesible en cualquier momento después desde el dropdown de navegación ("Parámetros nutricionales").
- **`ProfileParametersRequest`** (`app/Http/Requests/ProfileParametersRequest.php`): valida los rangos de la sección 5/6 — `nivel_actividad` 1.2–1.725, `proteina_factor` 1.6–2.2, `grasa_factor` 0.6–1.0, `sexo` in(masculino,femenino), `tipo_deficit` in(porcentaje,fijo). No calcula `calorias_objetivo` aquí — eso es responsabilidad de `NutritionCalculatorService` (sección 5), que aún no existe; este Form Request solo persiste los parámetros base.
- **Tests:** `tests/Feature/ProfileParametersTest.php` cubre acceso protegido por `auth`, edición exitosa, y validación fuera de rango (dataset con `nivel_actividad`, `proteina_factor`, `grasa_factor`, `sexo`, `tipo_deficit` inválidos). `tests/Feature/Auth/RegistrationTest.php` se ajustó para esperar redirect a `profile.parametros.edit` en vez de `dashboard`.

## 4.1. Ingredientes disponibles (implementado)

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

## 4.2. Generación del plan de comidas (implementado)

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
- **`PlanComidaController`** (`app/Http/Controllers/PlanComidaController.php`), rutas bajo `auth`:
  - `GET /plan` (`planes.index`) — muestra el plan de hoy y el botón "Generar mi plan de hoy".
  - `POST /plan/generar` (`planes.generar`) — genera el plan del `RegistroDiario` de hoy del usuario autenticado.
- **Sin Form Request:** `generar` no recibe ningún input del usuario (el día es "hoy" y los parámetros salen del perfil), así que no hay nada que validar; la sección 7 exige Form Requests para validar entrada, y aquí no hay entrada.
- **Ningún fallo de dominio produce un 500:** si faltan parámetros nutricionales del perfil o el cálculo lanza `NegativeCarbohydrateException` / `InvalidNutritionParameterException`, se redirige a `profile.parametros.edit` con `error`; si no hay `RegistroDiario` de hoy o no hay ingredientes (`NoIngredientsAvailableException`), se redirige a `ingredientes.create` con `error`.
- **`NoIngredientsAvailableException`** (`app/Exceptions/`, extiende `DomainException`): día sin ingredientes reportados. Constructor con nombre `paraRegistroDiario(?int $id)`.
- **Vista** `resources/views/planes/index.blade.php`: una tarjeta por comida con sus macros, el desglose de ingredientes y gramos, y el total planificado del día.
- **Navegación:** se añadieron los enlaces "Ingredientes" y "Plan de hoy" a `resources/views/layouts/navigation.blade.php` (menú de escritorio y responsive) — hasta ahora el módulo de ingredientes no era alcanzable desde la UI.

**Tests:** `tests/Unit/MealPlanGeneratorServiceTest.php` (el servicio toca Eloquent, así que ese archivo hace `uses(TestCase::class, RefreshDatabase::class)` explícito — `tests/Pest.php` solo aplica `RefreshDatabase` a `Feature`) cubre que el reparto suma 100%, plan con despensa suficiente cercano al objetivo, cada comida en su porcentaje, día sin ingredientes → excepción, que nunca se reparten más gramos de los reportados, que se persiste `ingredientes_detalle` cuadrando con los totales, y que regenerar conserva las comidas ya consumidas. `tests/Feature/PlanComidaTest.php` cubre el flujo HTTP: acceso protegido, generación de las tres comidas, la vista del plan, y los tres caminos de fallo controlado (sin registro diario, sin ingredientes, sin parámetros de perfil).

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
- **Vista** `resources/views/comidas-reales/create.blade.php`; `resources/views/planes/index.blade.php` muestra, por cada comida, el enlace "Registrar comida real" si aún no tiene una, o sus macros reales + notas + imagen si ya la tiene.

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
- **`ActividadFisicaController`** (`app/Http/Controllers/`), rutas bajo `auth`:
  - `GET /actividades` (`actividades.create`) — formulario + listado de las actividades ya registradas hoy.
  - `POST /actividades` (`actividades.store`) — crea (o reutiliza, mismo patrón que `IngredienteDisponibleController`) el `RegistroDiario` de hoy, registra la actividad con el factor ya aplicado, y recalcula `calorias_actividad_ajustada` del `RegistroDiario` como la suma de `calorias_ajustadas` de todas sus actividades — igual que `ComidaRealService` recalcula `calorias_consumidas` desde cero en vez de acumular con `+=`, para que sea idempotente. Todo dentro de una transacción.
- **Vista** `resources/views/actividades/create.blade.php`: formulario simple (sin filas dinámicas, una actividad a la vez) y listado de "Actividades de hoy" con calorías del dispositivo, factor aplicado y calorías ajustadas. Enlace "Actividad física" añadido a `resources/views/layouts/navigation.blade.php`.

**Tests:** `tests/Unit/ActivityCorrectionServiceTest.php` cubre el factor de un tipo conocido, el fallback al factor por defecto para un tipo no listado, insensibilidad a mayúsculas, y que un factor personalizado fuera de rango lanza excepción (no se normaliza) mientras uno dentro de rango sí sobreescribe la tabla. `tests/Feature/ActividadFisicaTest.php` cubre acceso protegido por `auth`, que registrar una actividad aplica el factor correcto y persiste `pasos`/`fuente`, creación automática del `RegistroDiario` de hoy, que `calorias_actividad_ajustada` refleja la suma correcta al registrar varias actividades el mismo día, y validación de `duracion_min`/`fuente` inválidos.

## 4.5. Cierre diario (implementado)

`app/Services/DailyClosureService.php` calcula, persiste y congela el cierre de un día. No reimplementa ninguna fórmula: el objetivo calórico y la proteína objetivo salen de `NutritionCalculatorService::calculatePlan()` y el déficit de `calculateDailyDeficit()` (sección 5).

**Métodos públicos:**

- **`resumen(RegistroDiario): array`** — las cinco cifras del cierre, listas para la vista. Si el día está **abierto** se calculan en vivo (vista previa de lo que produciría cerrarlo); si está **cerrado** se leen del snapshot persistido, para que un cambio posterior de perfil no reescriba la historia. Claves: `calorias_objetivo`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`, `proteina_objetivo_g`, `proteina_consumida_g`, `cumplimiento_proteina_pct`, `recomendaciones`.
- **`cerrar(RegistroDiario): array`** — calcula, persiste y marca el día como cerrado dentro de una transacción. Lanza `DayAlreadyClosedException` si el día ya estaba cerrado (no es idempotente a propósito: ver abajo). Devuelve el resumen ya persistido.
- **`reabrir(RegistroDiario): void`** — acción explícita del usuario; deja el día abierto de nuevo. No-op si ya estaba abierto.

Las calorías consumidas y la proteína consumida se recalculan siempre desde las `ComidaReal` del día, y el gasto por actividad desde las `ActividadFisica` — no se confía en los acumuladores que mantienen `ComidaRealService` / `ActividadFisicaController`, para que el cierre sea autoritativo aunque esos totales quedaran desfasados.

**Punto de extensión para el Prompt 10:** el método privado `generarRecomendaciones(RegistroDiario, array $resumen)` se invoca dentro de la transacción del cierre y hoy devuelve una colección vacía a propósito — la sección 6 prohíbe derivar un ajuste de un solo día; las recomendaciones vendrán de los promedios móviles de 7 días de `TrendAnalyticsService` y siempre como `RecomendacionSistema` pendiente de confirmación. `resumen()` ya expone las `RecomendacionSistema` del día para que la vista las muestre cuando existan.

### Qué significa "cerrado" y cómo se reabre (decisión documentada)

- **No se añadió una columna `estado_cierre`.** El estado del cierre es la columna `cerrado` (boolean, ya existía) más `cerrado_en` (dateTime nullable, nueva): dos valores para un estado binario con marca de tiempo, en vez de un enum redundante con el boolean.
- **Columnas nuevas** (migración `add_cierre_a_registros_diarios_table`): `proteina_objetivo_g` decimal(6,2), `proteina_consumida_g` decimal(6,2), `cerrado_en` dateTime — todas nullable. El resto de las cifras del cierre usa las columnas que ya existían (`calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario`).
- **Cumplimiento de macros = proteína.** Se persiste solo el par objetivo/real de proteína (es la métrica que pide el cierre); `cumplimiento_proteina_pct` se deriva de esas dos columnas y no se persiste. Grasa y carbohidratos no se incluyen en el cierre para no persistir un snapshot parcial que después habría que mantener sincronizado.
- **Un día cerrado es inmutable.** `ComidaRealService::registrar()` lanza `DayAlreadyClosedException` si el `RegistroDiario` está cerrado (`ComidaRealController` la traduce a redirect con `error`, tanto en el formulario como en el POST), y `ActividadFisicaController@store` rechaza igual una actividad nueva sobre un día cerrado — ambas cambiarían los totales de los que se calculó el cierre. Reportar ingredientes y regenerar el plan **no** se bloquean: no alteran ninguna cifra del cierre ya persistida.
- **Sí se permite reabrir, pero solo de forma explícita** (`POST /cierre/{registroDiario}/reabrir`). Un usuario que olvidó registrar la cena no debe perder el día entero, y el MVP no tiene otra vía para corregirlo. Reabrir deja las cifras del cierre anterior visibles hasta que se vuelva a cerrar, momento en el que **se recalculan todas desde cero** (no se acumula sobre el cierre previo).
- **Cerrar un día ya cerrado falla en vez de ser idempotente.** Un segundo cierre silencioso escondería que el usuario cree estar cerrando un día que ya estaba cerrado; se prefiere el error explícito, y el camino correcto (reabrir → cerrar) queda a un clic.

### Endpoint y vista

`CierreDiarioController` (`app/Http/Controllers/CierreDiarioController.php`), rutas bajo `auth`:

- `GET /cierre` (`cierre.index`) — resumen del día de hoy (previo si está abierto, definitivo si está cerrado) y el botón "Cerrar mi día" / "Reabrir mi día".
- `POST /cierre` (`cierre.cerrar`) — cierra el `RegistroDiario` de hoy del usuario autenticado.
- `POST /cierre/{registroDiario}/reabrir` (`cierre.reabrir`) — 403 si el registro no pertenece al usuario autenticado.

Sin Form Request: ninguna de las tres acciones recibe entrada del usuario (el día es "hoy" y los parámetros salen del perfil). Ningún fallo de dominio produce un 500 — mismo patrón que `PlanComidaController`: si faltan parámetros nutricionales o el cálculo lanza `NegativeCarbohydrateException`/`InvalidNutritionParameterException` se redirige a `profile.parametros.edit` con `error`; si no hay `RegistroDiario` de hoy o el día ya está cerrado, a `cierre.index` con `error`.

**Vista** `resources/views/cierre/index.blade.php`: los cinco puntos del cierre (objetivo vs. consumidas, gasto por actividad ajustado, déficit estimado, cumplimiento de proteína) más el bloque de "Recomendaciones", vacío hasta el Prompt 10. Enlace "Cierre del día" añadido a `resources/views/layouts/navigation.blade.php`.

**Pendiente:** `app/Console/Commands/RunDailyClosure.php` (cierre automático programado, sección 3) todavía no existe — por ahora el cierre es solo manual.

**`RegistroDiarioFactory`:** su definición por defecto ahora produce un día **abierto** con las columnas del cierre en `null` (antes rellenaba cifras aleatorias y `cerrado` aleatorio, lo que con la nueva regla de inmutabilidad hacía fallar de forma intermitente a los tests que escriben sobre el día). Para un día ya cerrado hay un estado explícito: `RegistroDiario::factory()->cerrado()`.

**Tests:** `tests/Unit/DailyClosureServiceTest.php` (usa `TestCase` + `RefreshDatabase` explícitos, como el test del generador de planes) cubre el cierre de un día completo contra un cálculo manual verificado paso a paso en comentarios (2112 kcal objetivo / 1950 consumidas / 500 de actividad / 662 de déficit / 90.625% de proteína), que cerrar dos veces lanza excepción sin duplicar ni alterar nada, que el resumen de un día cerrado es el snapshot congelado aunque cambie el perfil, que reabrir permite volver a cerrar recalculando, y un día sin comidas ni actividad. `tests/Feature/CierreDiarioTest.php` cubre el flujo HTTP: acceso protegido por `auth`, cierre exitoso con las cinco cifras visibles en la vista, segundo cierre rechazado, día cerrado que rechaza `ComidaReal` (formulario y POST) y `ActividadFisica`, reapertura explícita que vuelve a admitir una `ComidaReal`, 403 al reabrir el día de otro usuario, perfil incompleto, y que el resumen de un día abierto es solo una vista previa que no lo cierra.

## 4.6. Motor de recomendaciones (implementado)

`app/Services/RulesEngineService.php` es el único punto donde se decide si corresponde sugerir un ajuste de `calorias_objetivo` o alertar de un estancamiento, y el único camino por el que una `RecomendacionSistema` confirmada llega a modificar de verdad `calorias_objetivo` del usuario — nunca de forma automática (sección 6).

**No reimplementa el pipeline de tendencias.** `TrendAnalyticsService`/`CalculateTrends` (fila "Analítica" de la sección 3) todavía no existen — nada calcula hoy el promedio móvil de 7 días real a partir de un historial de peso (de hecho no hay ninguna tabla que registre el peso día a día; `users.peso_kg` es un único valor "actual"). `RulesEngineService` recibe el `porcentaje_perdida_semanal` (o las variaciones de peso semana a semana, para estancamiento) **ya calculado** como parámetro — cuando Prompt 11 implemente el pipeline real, este servicio es el que debe invocar con esos números; no antes.

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

403 si `recomendacion->registroDiario->usuario_id` no es el usuario autenticado (misma verificación transitiva que usa `RecomendacionSistema`, que no tiene `usuario_id` propio — sección 4). `RecomendacionYaProcesadaException` se traduce a redirect a `cierre.index` con `error`, igual patrón que `CierreDiarioController`. La vista `resources/views/cierre/index.blade.php` (donde ya se listaban las recomendaciones del día) ahora muestra botones "Confirmar"/"Rechazar" para las que están `pendiente`.

**Pendiente:** wiring real dentro de `DailyClosureService::generarRecomendaciones()` (el punto de extensión que menciona la sección 4.5) — hoy sigue devolviendo una colección vacía a propósito, porque no existe todavía una fuente real del promedio móvil de 7 días para invocar este motor desde el cierre. Cuando `TrendAnalyticsService`/`CalculateTrends` existan, ese es el lugar donde deben llamar a `RulesEngineService::generarRecomendacionAjusteCalorico()`/`detectarEstancamiento()`.

**Tests:** `tests/Unit/RulesEngineServiceTest.php` (usa `TestCase` + `RefreshDatabase` explícitos, mismo patrón que los demás Services que tocan Eloquent) cubre: pérdida simulada <0.5% semanal → recomienda reducir; >1% → recomienda aumentar; entre 0.5% y 1% → no genera nada; confirmar sí actualiza `calorias_objetivo` del usuario; rechazar no lo modifica; confirmar dos veces lanza excepción; estancamiento detectado con 3 semanas de variación mínima; no detectado si alguna semana reciente varió más del umbral; no detectado con menos de 3 semanas de datos. `tests/Feature/RecomendacionSistemaTest.php` cubre el flujo HTTP: acceso protegido por `auth`, 403 al confirmar la recomendación de otro usuario, confirmar actualiza `calorias_objetivo` vía HTTP, rechazar no lo modifica, y que confirmar una ya rechazada falla sin efectos.

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

- **`calculatePlan(float $pesoKg, float $nivelActividad, string $tipoDeficit, float $valorDeficit, float $proteinaFactor, float $grasaFactor): array`** — devuelve `['calorias_objetivo', 'proteina_g', 'grasa_g', 'carbohidratos_g']` (claves en español, coinciden con las columnas). `$valorDeficit` es una fracción (0.2 = 20%) cuando `$tipoDeficit === 'porcentaje'` y kcal cuando es `'fijo'`.
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

- Un solo cron job: `* * * * * php /home/USER/domains/DOMINIO/public_html/artisan schedule:run >> /dev/null 2>&1`.
- Verificar la versión real de MySQL/MariaDB del plan contratado antes de usar `AVG() OVER (...)` en `TrendAnalyticsService`; si no está disponible, calcular el promedio móvil en PHP sobre los últimos 7 `RegistroDiario`.
- Variables sensibles (API key del proveedor de IA, credenciales de MySQL) solo en `.env`, nunca hardcodeadas ni commiteadas.
- Antes de cada despliegue: `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache`.

## 11. Reglas para Claude Code al trabajar en este proyecto

1. **Actualiza este archivo (`CLAUDE.md`) al final de cada tarea** si agregaste un modelo, servicio, comando, endpoint/ruta, o tomaste una decisión de diseño no trivial. Agrega la información al bloque correspondiente arriba; no crees un log histórico interminable — este archivo describe el estado actual del proyecto, no su historia.
2. No introduzcas dependencias nuevas (paquetes Composer/npm) sin que estén justificadas por la tarea en curso.
3. No sobre-diseñes: si una tarea puede resolverse con una clase de servicio simple, no introduzcas patrones (repositorios, eventos, colas) que el documento de arquitectura no pidió para el MVP.
4. Si una tarea es ambigua o el documento de arquitectura no cubre un caso, toma la decisión más simple consistente con las secciones 5 y 6 de este archivo, impleméntala, y dócumentala aquí — no te detengas a preguntar salvo que la ambigüedad afecte datos financieros/de salud del usuario de forma irreversible.
5. Nunca implementes lógica que aplique automáticamente un ajuste de calorías objetivo sin pasar por `RecomendacionSistema` y confirmación del usuario (ver sección 6).
