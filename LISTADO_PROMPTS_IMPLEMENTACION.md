# Listado de Prompts de Implementación — TUDéficit Inteligente

Este documento contiene la secuencia completa de prompts para construir la aplicación con Claude Code, en orden de dependencia. Está diseñado para dos usos:

1. **Manual:** copiar y pegar cada prompt, uno a la vez, en una sesión de Claude Code.
2. **Automatizado:** el script descrito en el documento "Procedimientos de Automatización con Claude Code" lee este archivo y ejecuta cada bloque ` ```prompt ` en orden, sin intervención manual.

**Convención del documento:** cada prompt está en un bloque de código con la etiqueta `prompt` — no cambies esa etiqueta si vas a usar el script de automatización, porque es lo que usa para identificar dónde empieza y termina cada uno.

Cada prompt incluye, dentro de su propio texto, la instrucción de escribir pruebas y actualizar `CLAUDE.md`, para que quede garantizado sin importar si se ejecuta manual o automáticamente.

Cada prompt indica también el **modelo** y el **esfuerzo de razonamiento** recomendados, para asignar más capacidad (y costo) a las tareas que la necesitan y menos a las mecánicas.

## Modelo

- **Sonnet:** tareas de implementación bien especificadas — CRUD, formularios, comandos programados, andamiaje de autenticación. Es el modelo por defecto de Claude Code.
- **Opus:** tareas donde un error de diseño se propaga silenciosamente al resto del sistema — el algoritmo de cálculo nutricional, la heurística de generación de planes, el cierre diario, la analítica de tendencias, la integración end-to-end y el endurecimiento de seguridad.

## Esfuerzo del modelo

Claude Code controla la profundidad de razonamiento con cinco niveles (comando `/effort` en sesión interactiva, o el flag `--effort` en modo headless). Este documento usa etiquetas en español; entre paréntesis está el valor real que hay que pasar:

| Etiqueta en este documento | Valor real (`--effort`) | Cuándo se usa aquí |
|---|---|---|
| Bajo | `low` | Tareas mecánicas, bajo riesgo de ambigüedad |
| Medio | `medium` | Implementación estándar con algunas decisiones menores |
| Alto | `high` | Lógica de negocio central, varios módulos interactuando |
| Extra | `xhigh` | Razonamiento fino donde `high` se queda corto y `max` sería excesivo |
| Max | `max` | El único punto del sistema donde un error de cálculo es inaceptable |

**Advertencia técnica relevante para la automatización:** el nivel de esfuerzo tiene varias fuentes posibles (variable de entorno, flag, `settings.json`, default del modelo) y la variable de entorno `CLAUDE_CODE_EFFORT_LEVEL` gana sobre todas las demás, en silencio, si está definida en tu shell. El script de automatización (documento 4) fija el esfuerzo vía esa misma variable de entorno por invocación, precisamente para evitar que un valor heredado de tu perfil de shell ignore silenciosamente lo que este documento especifica.

---

## Prompt 01 — Inicialización del Proyecto Laravel

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Bajo (`low`)
- **Depende de:** Nada (primer prompt)
- **Entregable:** Proyecto Laravel corriendo localmente, conectado a MySQL, con Pint y Pest instalados, y `CLAUDE.md` copiado a la raíz del repo.

```prompt
Actúa como desarrollador backend senior en Laravel. Vamos a construir "TUDéficit Inteligente" desde cero. Ya existe un archivo CLAUDE.md en la raíz de este directorio: léelo completo antes de hacer nada, porque define el stack, las convenciones y las reglas de negocio del proyecto.

Tareas:
1. Crea un nuevo proyecto Laravel (última versión estable 10.x o 11.x) en el directorio actual.
2. Configura el archivo .env para usar MySQL como base de datos (deja las credenciales como placeholders razonables: DB_DATABASE=tudeficit, DB_USERNAME=root, DB_PASSWORD=).
3. Instala y configura Laravel Pint para formateo PSR-12.
4. Instala Pest y configúralo como framework de testing (reemplazando PHPUnit por defecto, pero mantén compatibilidad si Pest lo requiere).
5. Inicializa un repositorio git si no existe, y crea un .gitignore apropiado para Laravel (incluyendo vendor/, node_modules/, .env).
6. Crea un primer commit: "chore: inicialización del proyecto Laravel".
7. Verifica que `php artisan serve` levanta la aplicación sin errores y que `php artisan test` corre (aunque sea con el test de ejemplo).

Al finalizar, escribe un test trivial con Pest que confirme que la aplicación responde en la ruta raíz, ejecútalo y confirma que pasa. Actualiza CLAUDE.md si tomaste alguna decisión de configuración no cubierta explícitamente en él (por ejemplo, versión exacta de Laravel elegida).
```

---

## Prompt 02 — Modelado de Datos: Migraciones, Modelos y Factories

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Medio (`medium`)
- **Depende de:** Prompt 01
- **Entregable:** Todas las tablas del modelo de datos, modelos Eloquent con relaciones, y factories para testing.

```prompt
Lee CLAUDE.md antes de empezar, en particular la sección "Modelo de datos".

Implementa el modelo de datos completo de TUDéficit Inteligente:

1. Crea las migraciones para estas entidades, con los campos indicados en CLAUDE.md y en el documento de arquitectura (si está disponible en el repo):
   - Usuario (puedes extender la tabla users por defecto de Laravel, agregando: calorias_objetivo, peso_kg, estatura_m, edad, sexo, nivel_actividad, tipo_deficit, valor_deficit, proteina_factor, grasa_factor)
   - RegistroDiario
   - IngredienteDisponible
   - PlanComida
   - ComidaReal
   - ActividadFisica
   - MetricaTendencia
   - RecomendacionSistema
2. Respeta las relaciones exactas descritas en CLAUDE.md (Usuario 1—N RegistroDiario, RegistroDiario 1—N PlanComida, PlanComida 1—1 ComidaReal, etc.). Usa claves foráneas con restricciones de integridad referencial (onDelete cascade donde tenga sentido para datos que no deben sobrevivir a su padre, restrict donde deba protegerse el histórico).
3. Crea los modelos Eloquent correspondientes, con sus relaciones (hasMany, belongsTo, hasOne) correctamente tipadas.
4. Crea factories de Pest/Laravel para cada modelo, con datos realistas (pesos entre 50-120kg, calorías entre 1200-3000, etc.).
5. Ejecuta las migraciones contra una base de datos MySQL local (o sqlite en memoria para el entorno de testing, configurando phpunit.xml/pest si es necesario) y confirma que corren sin errores.

Escribe tests con Pest que verifiquen que cada relación funciona correctamente (por ejemplo: crear un RegistroDiario y confirmar que pertenece a un Usuario, crear un PlanComida y confirmar que puede tener una ComidaReal asociada). Ejecuta `php artisan test` y confirma que todo pasa. Actualiza CLAUDE.md con cualquier ajuste al modelo de datos que hayas hecho (nombres de columnas definitivos, tipos de datos elegidos, decisiones sobre onDelete).
```

---

## Prompt 03 — Autenticación y Perfil de Usuario

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Medio (`medium`)
- **Depende de:** Prompt 02
- **Entregable:** Registro/login funcional y formulario de configuración de parámetros base del usuario.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa autenticación de usuarios:

1. Instala Laravel Breeze (stack Blade, no API ni Inertia) para registro, login, logout y recuperación de contraseña.
2. Extiende el flujo de registro o crea una pantalla posterior a él ("Completa tu perfil") donde el usuario defina sus parámetros base: peso_kg, estatura_m, edad, sexo, nivel_actividad, tipo_deficit, valor_deficit, proteina_factor, grasa_factor. Usa un Form Request dedicado (ProfileParametersRequest o similar) con validaciones de rango según lo indicado en CLAUDE.md (nivel_actividad 1.2–1.725, proteina_factor 1.6–2.2, grasa_factor 0.6–1.0).
3. Crea una vista Blade sencilla y funcional (no te preocupes por diseño visual avanzado en este prompt) para editar estos parámetros después del registro inicial.
4. Protege todas las rutas del área autenticada con el middleware auth de Laravel.

Escribe tests de feature con Pest que cubran: registro exitoso, intento de guardar parámetros fuera de rango (debe fallar la validación), y edición exitosa de parámetros. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md documentando las rutas y el Form Request creados.
```

---

## Prompt 04 — Servicio de Cálculo Nutricional

- **Modelo:** Opus
- **Esfuerzo del modelo:** Max (`max`)
- **Depende de:** Prompt 02
- **Entregable:** `NutritionCalculatorService` con el algoritmo completo y cobertura de tests exhaustiva.

```prompt
Lee cuidadosamente la sección 5 de CLAUDE.md ("Algoritmo de cálculo nutricional") antes de escribir una sola línea de código — el algoritmo debe implementarse exactamente como está descrito ahí, sin modificaciones ni "mejoras" no solicitadas.

Implementa app/Services/NutritionCalculatorService.php con:

1. Un método que reciba los parámetros del usuario (peso_kg, nivel_actividad, tipo_deficit, valor_deficit, proteina_factor, grasa_factor) y devuelva: calorias_objetivo, proteina_g, grasa_g, carbohidratos_g — implementando exactamente las fórmulas de TMB, calorías de mantenimiento, aplicación del déficit (por porcentaje y por valor fijo) y cálculo de macronutrientes descritas en CLAUDE.md.
2. Manejo explícito del caso de error: si carbohidratos_kcal resulta negativo, el servicio debe lanzar una excepción de dominio clara (crea una excepción específica, por ejemplo NegativeCarbohydrateException) en lugar de devolver un valor inválido.
3. Un método separado para calcular el déficit diario real (deficit_diario = calorias_objetivo - calorias_consumidas + calorias_actividad_ajustada).
4. Un método separado para el ajuste de actividad física (calorias_actividad_ajustada = calorias_dispositivo * factor_correccion), validando que factor_correccion esté en el rango 0.8–0.9.
5. NO uses estatura_m, edad ni sexo en el cálculo de TMB — CLAUDE.md documenta explícitamente que esto es una simplificación intencional del MVP. No la "arregles".

Escribe una batería de tests unitarios con Pest que cubran: cálculo correcto con déficit por porcentaje, cálculo correcto con déficit fijo, el caso límite de carbohidratos negativos (debe lanzar la excepción), factor_correccion fuera de rango (debe rechazarse), y al menos un caso con valores reales de ejemplo verificados a mano en el propio test (deja el cálculo manual como comentario para que sea auditable). Ejecuta `php artisan test` y confirma que el 100% de estos tests pasa. Actualiza CLAUDE.md si agregaste alguna excepción o método no mencionado explícitamente en la sección 5.
```

---

## Prompt 05 — Módulo de Ingredientes Disponibles

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Bajo (`low`)
- **Depende de:** Prompt 02, Prompt 03
- **Entregable:** CRUD de ingredientes disponibles por día.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa el módulo de Ingredientes Disponibles:

1. Controlador (IngredienteDisponibleController) y rutas para: reportar ingredientes disponibles de un día (crear varios a la vez, ya que un usuario normalmente reporta una lista), listar los ingredientes de un RegistroDiario específico, y editar/eliminar un ingrediente reportado por error.
2. Si no existe todavía un RegistroDiario para la fecha actual del usuario autenticado, créalo automáticamente al primer ingrediente reportado (firstOrCreate por usuario_id + fecha).
3. Form Request de validación: nombre (string, requerido), cantidad_disponible (numérico, positivo), unidad (string, de una lista controlada razonable: gramos, unidades, mililitros, etc.).
4. Vista Blade simple para el formulario de ingredientes del día (puede ser un formulario dinámico donde se agregan filas de ingredientes).

Escribe tests de feature con Pest: reportar ingredientes crea el RegistroDiario si no existe, reportar ingredientes con cantidad negativa falla la validación, listar ingredientes de un día devuelve solo los del usuario autenticado (no los de otros usuarios). Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con las rutas creadas.
```

---

## Prompt 06 — Generación del Plan de Comidas

- **Modelo:** Opus
- **Esfuerzo del modelo:** Alto (`high`)
- **Depende de:** Prompt 04, Prompt 05
- **Entregable:** `MealPlanGeneratorService` que produce desayuno/almuerzo/cena a partir de ingredientes disponibles y el objetivo calórico.

```prompt
Lee CLAUDE.md, en particular las secciones 5 (algoritmo nutricional) y 6 (reglas de negocio), antes de empezar.

Implementa app/Services/MealPlanGeneratorService.php:

1. Recibe el RegistroDiario del día (con sus IngredienteDisponible ya cargados) y el resultado de NutritionCalculatorService (calorias_objetivo, macros objetivo).
2. Distribuye las calorías objetivo entre desayuno, almuerzo y cena (puedes usar una distribución simple y documentada, por ejemplo 25%/40%/35%, pero hazla configurable como constante, no hardcodeada en tres lugares distintos).
3. Para cada comida, selecciona una combinación de los ingredientes disponibles reportados que se aproxime razonablemente a las calorías y proteína objetivo de esa comida. No necesitas un algoritmo de optimización complejo para el MVP: una heurística simple y explicable es preferible a un solver sofisticado — prioriza claridad de código sobre precisión matemática perfecta, tal como indica CLAUDE.md sobre evitar sobreingeniería.
4. Persiste el resultado como registros PlanComida (uno por tipo: desayuno, almuerzo, cena), cada uno con sus calorías planificadas, proteína planificada y el detalle de ingredientes usados (guarda esto en el campo JSON correspondiente).
5. Expón esto a través de un controlador y una ruta que el usuario pueda invocar ("Generar mi plan de hoy"), y una vista Blade que muestre el plan generado de forma legible.

Escribe tests con Pest: generar un plan con ingredientes suficientes produce 3 PlanComida con la suma de calorías planificadas razonablemente cercana al objetivo diario, generar un plan sin ingredientes reportados debe fallar de forma controlada (no con un error 500), y la distribución porcentual entre comidas suma 100%. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md documentando la heurística de distribución elegida y el nombre exacto del servicio/métodos creados.
```

---

## Prompt 07 — Registro de Comida Real y Recálculo

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Alto (`high`)
- **Depende de:** Prompt 06
- **Entregable:** Registro de lo realmente consumido (con imagen) y recálculo de calorías restantes del día.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa el registro de ComidaReal:

1. Formulario para que el usuario, sobre un PlanComida existente, registre lo que realmente consumió: calorías reales, proteína real, una imagen (evidencia visual) y ajustes/notas opcionales.
2. Configura el almacenamiento de la imagen usando el Storage facade de Laravel apuntando al disco "public" (storage/app/public), con storage:link. No guardes la imagen en ninguna otra ruta ni la proceses (compresión, análisis) — en el MVP es solo evidencia visual almacenada, tal como indica el documento de arquitectura.
3. Al guardar una ComidaReal, dispara el recálculo de calorías restantes del día: compara lo real contra lo planificado para esa comida, y si hay desviación, ajusta las calorías disponibles para las comidas restantes del día que aún no se han registrado como reales (no reescribas comidas ya registradas). Implementa esta lógica en un método del propio MealPlanGeneratorService o en un servicio nuevo si lo prefieres, pero no la pongas en el controlador.
4. Actualiza calorias_consumidas en el RegistroDiario correspondiente cada vez que se registra una ComidaReal.

Escribe tests con Pest: registrar una ComidaReal actualiza calorias_consumidas del RegistroDiario, un exceso de calorías en el desayuno reduce las calorías disponibles para el almuerzo/cena aún no registrados, y subir una imagen la deja accesible en la ruta pública esperada. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con el nombre del servicio/método de recálculo.
```

---

## Prompt 08 — Módulo de Actividad Física

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Medio (`medium`)
- **Depende de:** Prompt 02, Prompt 04
- **Entregable:** Registro de actividad física con factor de corrección aplicado.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa el módulo de Actividad Física:

1. Formulario y controlador para registrar ActividadFisica de un día: tipo_actividad, duracion_min, calorias_dispositivo, pasos, fuente (manual o dispositivo).
2. Al guardar, usa el método correspondiente de NutritionCalculatorService (o un ActivityCorrectionService dedicado que internamente lo use) para calcular calorias_ajustadas = calorias_dispositivo * factor_correccion, con factor_correccion configurable por tipo de actividad dentro del rango 0.8–0.9 definido en CLAUDE.md. Si no se especifica un factor particular para el tipo de actividad, usa un valor por defecto razonable dentro del rango y documéntalo.
3. Actualiza calorias_gastadas_actividad en el RegistroDiario del día correspondiente.
4. Vista Blade simple para listar las actividades del día y su calorías ajustadas.

Escribe tests con Pest: registrar actividad aplica correctamente el factor de corrección, un factor fuera de rango es rechazado o normalizado (decide y documenta el comportamiento), y el RegistroDiario refleja la suma correcta si hay varias actividades en el mismo día. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con la tabla de factores de corrección por tipo de actividad si definiste una.
```

---

## Prompt 09 — Cierre Diario

- **Modelo:** Opus
- **Esfuerzo del modelo:** Alto (`high`)
- **Depende de:** Prompt 07, Prompt 08
- **Entregable:** `DailyClosureService` que consolida el balance energético del día.

```prompt
Lee CLAUDE.md antes de empezar, especialmente las secciones 5 y 6.

Implementa app/Services/DailyClosureService.php:

1. Un método que, dado un RegistroDiario, calcule: calorías objetivo vs. consumidas, gasto por actividad ajustado, déficit calórico estimado (usando la fórmula de CLAUDE.md), y cumplimiento de macronutrientes (proteína real acumulada del día vs. proteína objetivo).
2. El resultado del cierre debe persistirse (puedes usar los propios campos de RegistroDiario, o generar RecomendacionSistema si corresponde — ver Prompt 10 para las recomendaciones automáticas, que puedes dejar como un punto de extensión aquí).
3. Marca el RegistroDiario como cerrado (estado_cierre) para que no se pueda modificar retroactivamente sin una acción explícita del usuario.
4. Endpoint/ruta para disparar el cierre manualmente ("Cerrar mi día"), y una vista de resumen que muestre los cinco puntos del cierre diario: calorías objetivo vs. consumidas, gasto por actividad, déficit estimado, cumplimiento de macros, y un espacio para recomendaciones (aunque todavía estén vacías hasta el Prompt 10).

Escribe tests con Pest: cerrar un día con datos completos produce los cinco valores esperados y coincide con un cálculo manual verificado en el test, cerrar un día ya cerrado no debe duplicar ni corromper datos, y un RegistroDiario cerrado no puede recibir nuevas ComidaReal sin una acción explícita de reapertura (decide y documenta si permites o no reabrir). Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md documentando el comportamiento elegido para el "cierre" y la reapertura.
```

---

## Prompt 10 — Motor de Reglas y Recomendaciones Automáticas

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Alto (`high`)
- **Depende de:** Prompt 09, Prompt 11 (parcialmente — puedes implementar la interfaz aquí y completarla tras el Prompt 11)
- **Entregable:** Generación de `RecomendacionSistema` según las reglas de ajuste automático de CLAUDE.md.

```prompt
Lee la sección 6 de CLAUDE.md ("Reglas de negocio") antes de empezar — las reglas ahí son literales, no las reinterpretes.

Implementa el motor de recomendaciones:

1. Un método (puede vivir en TrendAnalyticsService si ya existe del Prompt 11, o en un RulesEngineService nuevo) que, dado el promedio móvil de pérdida de peso de 7 días de un usuario, determine si corresponde sugerir un ajuste:
   - pérdida < 0.5% semanal → sugerir reducir 100–200 kcal del objetivo
   - pérdida > 1% semanal → sugerir aumentar 100–200 kcal del objetivo
   - en cualquier otro caso, no generar recomendación de ajuste calórico
2. Cada sugerencia se persiste como RecomendacionSistema (tipo ajuste_calorico), con un mensaje legible para el usuario, y con aceptada_por_usuario en null/false hasta que el usuario la confirme explícitamente.
3. Implementa la ruta/acción para que el usuario acepte o rechace una RecomendacionSistema. Solo si la acepta, se actualiza calorias_objetivo del Usuario — nunca automáticamente.
4. Añade también la detección simple de estancamiento (por ejemplo, variación de peso menor a un umbral pequeño durante varias semanas consecutivas) como otro tipo de RecomendacionSistema (tipo alerta_estancamiento), sin acción automática asociada, solo informativa.

Escribe tests con Pest: una pérdida simulada menor a 0.5% semanal genera una recomendación de reducción, una pérdida mayor a 1% genera una de aumento, una pérdida entre 0.5% y 1% no genera ninguna, y aceptar una recomendación sí actualiza calorias_objetivo mientras que no aceptarla no lo hace. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con el nombre definitivo del servicio y el umbral usado para detectar estancamiento.
```

---

## Prompt 11 — Analítica: Promedios Móviles y Tendencias

- **Modelo:** Opus
- **Esfuerzo del modelo:** Extra (`xhigh`)
- **Depende de:** Prompt 09
- **Entregable:** `TrendAnalyticsService`, tabla `MetricaTendencia` poblada, y vistas de tendencia.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa app/Services/TrendAnalyticsService.php:

1. Un método que, para un usuario y una fecha de corte, calcule: promedio móvil de peso de 7 días, promedio móvil de déficit calórico de 7 días, e índice de consistencia (por ejemplo, porcentaje de días en los últimos 7 con RegistroDiario cerrado — decide una definición simple y documéntala).
2. Antes de usar funciones de ventana SQL (AVG() OVER (...)), verifica en el código o en un comentario qué versión de MySQL/MariaDB se está usando en desarrollo, y si no está garantizada la disponibilidad (MySQL 8.0+/MariaDB 10.2+), implementa el cálculo en PHP trayendo los últimos 7 RegistroDiario y promediando ahí — CLAUDE.md pide explícitamente esta precaución.
3. Persiste el resultado en MetricaTendencia (una fila por usuario y fecha de corte).
4. Crea una vista Blade de "Mi progreso" con un gráfico de Chart.js mostrando la evolución del promedio móvil de peso en el tiempo, y los indicadores de consistencia y déficit promedio.

Escribe tests con Pest: el promedio móvil de 7 días calculado coincide con un cálculo manual verificado en el test usando datos de ejemplo (crea al menos 10 RegistroDiario con pesos conocidos), el índice de consistencia refleja correctamente días con y sin cierre, y el servicio no falla si hay menos de 7 días de historial (debe manejar el caso de datos insuficientes explícitamente, no lanzar una excepción no controlada). Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con la versión mínima de MySQL/MariaDB asumida y la definición exacta de "índice de consistencia" elegida.
```

---

## Prompt 12 — Dashboard Principal

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Medio (`medium`)
- **Depende de:** Prompt 09, Prompt 11
- **Entregable:** Vista de dashboard que consolida el estado del día y las tendencias.

```prompt
Lee CLAUDE.md antes de empezar.

Implementa el dashboard principal (página de inicio tras login):

1. Resumen del día actual: calorías consumidas vs. objetivo, gasto por actividad, déficit estimado, estado de cada comida (planificada/registrada/pendiente).
2. Bloque de tendencias (usando TrendAnalyticsService del Prompt 11): gráfico de evolución de peso, déficit promedio de 7 días, índice de consistencia.
3. Bloque de recomendaciones pendientes de confirmación (RecomendacionSistema no resueltas), con botones para aceptar/rechazar directamente desde el dashboard.
4. Usa Chart.js para los gráficos, cargado vía CDN según lo definido en el stack (no agregues un bundler de frontend nuevo para esto).
5. Cuida que la vista sea razonable en móvil (diseño simple de una columna en pantallas pequeñas) ya que es probable que el usuario la use principalmente desde el teléfono.

Escribe un test de feature con Pest que confirme que un usuario autenticado con datos de ejemplo (usa factories) ve su dashboard sin errores 500, y que los números mostrados coinciden con los datos creados en el test. Ejecuta `php artisan test` y confirma que pasa. Actualiza CLAUDE.md si agregaste alguna ruta o convención de layout Blade reutilizable (por ejemplo, un layout base para páginas autenticadas).
```

---

## Prompt 13 — Tareas Programadas (Cierre Automático y Cálculo de Tendencias)

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Bajo (`low`)
- **Depende de:** Prompt 09, Prompt 11
- **Entregable:** Comandos Artisan programados vía el Scheduler de Laravel.

```prompt
Lee CLAUDE.md, sección "Despliegue (Hostinger)", antes de empezar.

Implementa la automatización diaria:

1. Comando Artisan RunDailyClosure que recorra todos los RegistroDiario del día anterior que no estén cerrados y ejecute DailyClosureService sobre cada uno.
2. Comando Artisan CalculateTrends que recorra todos los usuarios activos y ejecute TrendAnalyticsService para la fecha de corte correspondiente, poblando MetricaTendencia.
3. Registra ambos comandos en el Scheduler de Laravel (routes/console.php o app/Console/Kernel.php según la versión), programados para ejecutarse una vez al día en un horario razonable (por ejemplo, 00:15 y 00:30 respectivamente, para dar margen entre uno y otro).
4. Documenta en un comentario del propio Kernel/console.php que en producción esto depende de un único cron job apuntando a `schedule:run`, tal como está descrito en CLAUDE.md — no asumas que Hostinger permite múltiples cron jobs de Laravel independientes.

Escribe tests con Pest que invoquen ambos comandos directamente (Artisan::call) sobre datos de ejemplo y verifiquen que producen el efecto esperado (RegistroDiario cerrados, MetricaTendencia creadas). Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md con los horarios exactos programados.
```

---

## Prompt 14 — Capa de Abstracción de IA Generativa

- **Modelo:** Sonnet
- **Esfuerzo del modelo:** Medio (`medium`)
- **Depende de:** Prompt 06, Prompt 10
- **Entregable:** Interfaz `NutritionAiProviderInterface` con una implementación de reglas (no IA real todavía).

```prompt
Lee CLAUDE.md antes de empezar — esta es una pieza explícitamente pensada para no acoplar el dominio a un proveedor de IA específico.

Implementa:

1. La interfaz app/Services/AI/NutritionAiProviderInterface.php con al menos dos métodos: uno para sugerir una distribución/selección de ingredientes para un plan de comida, y otro para generar el texto de una recomendación (por ejemplo, el mensaje legible de una RecomendacionSistema).
2. Una implementación concreta RuleBasedNutritionProvider que sea la que se usa hoy (puede envolver la lógica que ya existe en MealPlanGeneratorService y en el motor de reglas del Prompt 10, sin duplicarla — extrae y reutiliza donde tenga sentido).
3. Registra el binding en un ServiceProvider (por ejemplo AppServiceProvider) apuntando la interfaz a RuleBasedNutritionProvider, de forma que cambiar a un proveedor de IA real en el futuro sea solo cambiar este binding.
4. NO implementes todavía una llamada real a ningún proveedor de IA externo — este prompt es solo la interfaz y la implementación basada en reglas. Deja un comentario claro indicando que una futura GenerativeAiProvider (usando Guzzle) implementaría la misma interfaz.

Escribe tests con Pest que verifiquen que el binding resuelve correctamente la interfaz a RuleBasedNutritionProvider, y que los métodos de la implementación producen resultados consistentes con lo que ya prueban los tests de MealPlanGeneratorService y del motor de reglas. Ejecuta `php artisan test` y confirma que pasan. Actualiza CLAUDE.md agregando esta interfaz a la tabla de la sección 3 si no está reflejada con el nombre exacto usado.
```

---

## Prompt 15 — Pruebas de Integración End-to-End del Flujo Diario

- **Modelo:** Opus
- **Esfuerzo del modelo:** Extra (`xhigh`)
- **Depende de:** Todos los anteriores
- **Entregable:** Un test de feature que recorre el flujo completo de un día, tal como lo describe la sección "Flujos Principales" del documento de arquitectura.

```prompt
Lee CLAUDE.md completo antes de empezar; este prompt integra todo lo construido hasta ahora.

Escribe un test de feature con Pest (puede vivir en tests/Feature/DailyFlowTest.php) que simule el día completo de un usuario, en este orden, verificando el estado esperado después de cada paso:

1. Un usuario se registra y completa sus parámetros base.
2. Reporta ingredientes disponibles para el día de hoy.
3. Genera su plan de comidas.
4. Registra ComidaReal para desayuno con una ligera desviación respecto al plan, y confirma que las calorías restantes de almuerzo/cena se ajustaron.
5. Registra actividad física.
6. Cierra el día y verifica que el resumen de cierre (calorías objetivo vs. consumidas, gasto por actividad, déficit, macros) es matemáticamente consistente con los datos ingresados en los pasos anteriores.
7. Si corresponde según los datos de ejemplo usados, verifica que se generó (o no) una RecomendacionSistema razonable.

Si al escribir este test encuentras alguna inconsistencia entre módulos construidos en prompts anteriores (por ejemplo, un campo con nombre distinto al esperado, o un cálculo que no cuadra), corrígela ahora en el código correspondiente — este prompt existe específicamente para detectar y cerrar ese tipo de brechas de integración.

Ejecuta `php artisan test` completo (no solo este archivo) y confirma que el 100% de la suite pasa. Actualiza CLAUDE.md documentando cualquier corrección de integración que hayas tenido que hacer, para que quede como decisión registrada y no se repita el mismo problema en trabajo futuro.
```

---

## Prompt 16 — Endurecimiento y Preparación para Despliegue en Hostinger

- **Modelo:** Opus
- **Esfuerzo del modelo:** Alto (`high`)
- **Depende de:** Prompt 15
- **Entregable:** Aplicación lista para desplegar en Hostinger, con checklist de seguridad revisado.

```prompt
Lee CLAUDE.md completo, en particular la sección "Despliegue (Hostinger)", antes de empezar.

Realiza una revisión de seguridad y prepara el despliegue:

1. Revisa que todos los modelos Eloquent tengan definido correctamente $fillable o $guarded para evitar mass assignment no controlado.
2. Revisa que todas las rutas que deben estar protegidas por auth lo estén, y que un usuario no pueda acceder ni modificar RegistroDiario, PlanComida, ComidaReal ni ActividadFisica de otro usuario (agrega tests con Pest específicos para esto si no existen ya: intento de acceso cruzado entre usuarios).
3. Confirma que todos los formularios usan la protección CSRF por defecto de Laravel (Blade @csrf) y que no se desactivó en ningún lado.
4. Revisa límites de tamaño de subida de imágenes en la configuración de PHP/Laravel y documenta el límite elegido.
5. Prepara un archivo .env.example actualizado con todas las variables necesarias (incluyendo las de MySQL y cualquier clave de proveedor de IA, sin valores reales).
6. Escribe, como archivo markdown DEPLOY.md en la raíz del proyecto, el paso a paso concreto de despliegue en Hostinger: configuración del cron job para schedule:run, comandos a ejecutar tras subir el código (composer install --no-dev, migrate --force, config:cache, storage:link), y verificación post-despliegue.

Escribe tests con Pest específicos para los accesos cruzados entre usuarios mencionados en el punto 2, si no existían. Ejecuta `php artisan test` completo y confirma que el 100% pasa. Actualiza CLAUDE.md con cualquier configuración de seguridad relevante que no estuviera ya documentada, y referencia el nuevo DEPLOY.md desde la sección 10.
```

---

## Resumen de Modelo y Esfuerzo por Prompt

| Prompt | Módulo | Modelo | Esfuerzo |
|---|---|---|---|
| 01 | Inicialización del proyecto | Sonnet | Bajo (`low`) |
| 02 | Modelado de datos | Sonnet | Medio (`medium`) |
| 03 | Autenticación y perfil | Sonnet | Medio (`medium`) |
| 04 | Cálculo nutricional | Opus | Max (`max`) |
| 05 | Ingredientes disponibles | Sonnet | Bajo (`low`) |
| 06 | Generación del plan de comidas | Opus | Alto (`high`) |
| 07 | Registro de comida real | Sonnet | Alto (`high`) |
| 08 | Actividad física | Sonnet | Medio (`medium`) |
| 09 | Cierre diario | Opus | Alto (`high`) |
| 10 | Motor de reglas/recomendaciones | Sonnet | Alto (`high`) |
| 11 | Analítica y tendencias | Opus | Extra (`xhigh`) |
| 12 | Dashboard | Sonnet | Medio (`medium`) |
| 13 | Tareas programadas | Sonnet | Bajo (`low`) |
| 14 | Capa de abstracción IA | Sonnet | Medio (`medium`) |
| 15 | Integración end-to-end | Opus | Extra (`xhigh`) |
| 16 | Seguridad y despliegue | Opus | Alto (`high`) |

**Criterio usado:** Opus + esfuerzo alto/extra/max se reserva para los puntos donde un error se propaga en silencio al resto del sistema (el algoritmo nutricional, la heurística del plan de comidas, el cierre diario, la analítica de tendencias, la integración end-to-end y el endurecimiento de seguridad). Sonnet con esfuerzo bajo/medio cubre el trabajo de implementación bien especificado (andamiaje, CRUD, comandos programados, UI del dashboard), donde más razonamiento no cambia el resultado pero sí el costo y el tiempo.

