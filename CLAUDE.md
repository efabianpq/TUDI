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
| Analítica | `app/Models/MetricaTendencia.php`, `app/Services/TrendAnalyticsService.php`, `app/Console/Commands/CalculateTrends.php` |
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
- **`registros_diarios`**: `usuario_id` (FK cascade), `fecha` (date, único junto a `usuario_id`), `calorias_objetivo_dia`, `calorias_consumidas`, `calorias_actividad_ajustada`, `deficit_diario` (todas decimal nullable, se rellenan en el cierre diario), `cerrado` (boolean).
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
