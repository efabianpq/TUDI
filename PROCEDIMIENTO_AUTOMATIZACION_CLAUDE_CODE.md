# Procedimiento de Automatización con Claude Code — TUDéficit Inteligente

Este documento explica cómo dejar que Claude Code construya la aplicación completa ejecutando, en orden y sin intervención manual, cada prompt de `LISTADO_PROMPTS_IMPLEMENTACION.md`. Está verificado contra la documentación oficial vigente de Claude Code (modos de permisos y modo headless) al momento de escribir esto.

## 1. Qué vas a usar y por qué

Claude Code puede ejecutarse de forma **no interactiva** (modo *headless*) con el flag `-p`/`--print`: recibe un prompt, hace el trabajo, y termina — sin pedirte que confirmes cada acción, si así lo configuras. Eso es exactamente lo que necesitas para "ejecutar prompt por prompt sin mi intervención".

Para que no pida confirmación en ningún paso, hay que fijar un **modo de permisos**. Claude Code tiene varios; los relevantes para esto son:

| Modo | Qué hace | ¿Sirve para esto? |
|---|---|---|
| `default` | Pide confirmación antes de cada edición o comando | No — requiere intervención constante |
| `acceptEdits` | Aprueba automáticamente ediciones de archivo y comandos de filesystem comunes (mkdir, mv, cp, etc.); el resto de comandos Bash sigue pidiendo confirmación | Parcial — se detendría en cualquier comando no listado (por ejemplo `composer install`) |
| `plan` | Solo explora y propone, nunca edita | No — es para revisar antes de aprobar, lo opuesto a lo que buscas |
| **`dontAsk`** | **Deniega automáticamente todo lo que no esté explícitamente permitido en `permissions.allow`; nunca pregunta** | **Sí — es el modo documentado para pipelines de CI/automatización sin supervisión** |
| `bypassPermissions` (`--dangerously-skip-permissions`) | Desactiva todo el sistema de permisos; ejecuta cualquier cosa sin preguntar | Técnicamente sí, pero la documentación oficial es explícita: *"nunca uses este modo en una sesión donde Claude pueda tomar acciones inesperadas; solo para contenedores/VMs aislados"* |

**Recomendación de este documento: usar `dontAsk` con una lista explícita de herramientas permitidas (`allowed-tools.json`)**, no `bypassPermissions`. La diferencia práctica: con `dontAsk`, si Claude Code intenta algo que no anticipaste (por ejemplo, borrar un directorio), la acción se **deniega silenciosamente y el proceso sigue** en vez de ejecutarse sin control. Es la forma de tener "cero intervención" sin firmar un cheque en blanco.

Si vas a correr esto dentro de un contenedor o VM completamente desechable (no tu máquina de desarrollo real, no un servidor con datos), `bypassPermissions` es una alternativa válida y más simple — pero no la uses en tu entorno de trabajo habitual.

## 2. Archivos que necesitas (ya entregados junto a este documento)

| Archivo | Para qué |
|---|---|
| `CLAUDE.md` | Contexto de proyecto — Claude Code lo carga automáticamente en cada sesión |
| `LISTADO_PROMPTS_IMPLEMENTACION.md` | Los 16 prompts en orden, cada uno en un bloque ` ```prompt ` |
| `run_prompts.py` | Script que lee el listado y llama a Claude Code una vez por prompt, en orden |
| `allowed-tools.json` | Lista de herramientas/comandos que Claude Code puede usar sin preguntar |

Los cuatro deben estar en la **raíz del proyecto Laravel** (el mismo directorio donde vive `composer.json` una vez creado el proyecto en el Prompt 01 — o el directorio vacío donde se creará).

## 3. Requisitos previos

1. **Claude Code CLI instalado y autenticado.** Verifica con:
   ```bash
   claude --version
   ```
   Si no está instalado, sigue la guía oficial de instalación en `code.claude.com`. Necesitas haber iniciado sesión al menos una vez de forma interactiva (`claude`) antes de usar el modo headless.
2. **PHP 8.x, Composer y MySQL disponibles** en el entorno donde corre el script (no en Hostinger — esto se ejecuta en tu máquina de desarrollo o en un entorno de CI/staging; el despliegue a Hostinger es, de hecho, el último prompt de la lista).
3. **Python 3** disponible (para correr `run_prompts.py`). No necesitas instalar ninguna librería adicional — el script solo usa la librería estándar.
4. **Git instalado**, y el directorio de trabajo inicializado como repositorio (si no existe, el Prompt 01 lo crea; en ese caso corre primero solo el Prompt 01 y confirma el `git init` antes de lanzar el resto).

## 4. Cómo ejecutar

**Ver el plan sin ejecutar nada** (recomendado la primera vez, para confirmar que los 16 prompts se detectaron correctamente):

```bash
python3 run_prompts.py --dry-run
```

**Ejecutar todo desde el principio:**

```bash
python3 run_prompts.py
```

**Reanudar desde un prompt específico** (por ejemplo, si el proceso se detuvo en el prompt 7 por un error que ya corregiste manualmente):

```bash
python3 run_prompts.py --start-from 7
```

**Ejecutar solo un rango** (útil para probar el mecanismo con los primeros 2 prompts antes de lanzar los 16):

```bash
python3 run_prompts.py --start-from 1 --stop-after 2
```

## 5. Qué hace el script en cada paso

Para cada prompt, en orden:

1. Invoca `claude -p "<texto del prompt>" --permission-mode dontAsk --settings allowed-tools.json --output-format json`.
2. Guarda la salida completa (stdout/stderr) en `logs/build-<fecha>/prompt-NN-*.{json,log}` — así queda un registro auditable de lo que hizo Claude Code en cada paso, incluso corriendo sin supervisión.
3. Verifica el código de salida y el campo `is_error` de la respuesta JSON.
   - Si todo salió bien: hace `git add -A && git commit` con un mensaje que identifica el prompt, y continúa automáticamente con el siguiente.
   - Si algo falló: **se detiene** (no continúa con el siguiente prompt) e imprime el comando exacto para reanudar una vez corregido el problema.

**Por qué se detiene ante un error en vez de "seguir de todas formas":** cada prompt depende del trabajo del anterior (ver la columna "Depende de" en `LISTADO_PROMPTS_IMPLEMENTACION.md`). Si el Prompt 04 (cálculo nutricional) falla y el proceso igual continuara con el Prompt 06 (que depende de él), el resultado sería una aplicación con un módulo central roto y sin que te enteres hasta mucho más tarde. Detenerse ante el primer fallo real es lo que hace seguro dejarlo corriendo sin mirar — puedes lanzarlo y volver horas después a revisar si terminó bien o si quedó esperando en un prompt específico.

Si de verdad quieres que nunca se detenga pase lo que pase (no recomendado), tendrías que modificar el script para no llamar a `sys.exit()` en las ramas de error — está señalado explícitamente en el código con comentarios para que sea fácil de encontrar si decides asumir ese riesgo.

## 6. Por qué cada prompt es una sesión nueva (no `--continue`)

El script no encadena los prompts como una sola conversación larga (no usa `--continue` ni `--resume`). Cada prompt arranca una sesión nueva de Claude Code, que:

- Lee `CLAUDE.md` desde disco (que el prompt anterior actualizó) para saber en qué estado quedó el proyecto.
- Lee el código real en disco (que es la fuente de verdad, no lo que "recuerde" una conversación anterior).

Esto es intencional: con 16 prompts, encadenar todo en una sola sesión inflaría el contexto y podría degradar la calidad hacia el final. Al depender de `CLAUDE.md` + el código como memoria compartida entre prompts, cada uno arranca con exactamente el contexto que necesita — ni más ni menos — y es también la razón por la que cada prompt de la lista exige explícitamente actualizar `CLAUDE.md` antes de terminar.

## 7. Verificación después de correr todo

Aunque el objetivo es no intervenir mientras corre, sí conviene revisar al terminar:

```bash
git log --oneline          # confirmar que hay 16 commits, uno por prompt
php artisan test           # confirmar que la suite completa sigue en verde
cat CLAUDE.md               # confirmar que refleja el estado final del proyecto
```

Si algo no cuadra, el historial de git (un commit por prompt) te permite ubicar exactamente en qué paso se introdujo el problema, y los logs en `logs/build-<fecha>/` tienen la salida completa de Claude Code para ese paso específico.

## 8. Nota sobre costos

Cada invocación de `claude -p` consume uso de tu cuenta/API igual que una sesión interactiva — 16 prompts de la envergadura descrita (algunos de varias horas de trabajo equivalente) representan un uso considerable. El campo `total_cost_usd` en la salida JSON de cada paso (guardada en los logs) te permite sumar el costo real de la corrida completa si necesitas reportarlo.
