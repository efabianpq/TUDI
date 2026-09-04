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

`ComidaReal` es una entidad separada de `PlanComida` (no se sobrescribe el plan) — preserva el historial de "planificado vs. ejecutado". No cambiar este diseño sin discutirlo explícitamente.

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
