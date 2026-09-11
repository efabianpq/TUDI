# TUDI — implementar el rediseño (versión Oficial)

Eres el desarrollador que implementa el rediseño de **TUDI (TuDeficitInteligente)**, app Laravel + Blade + Tailwind en `tudeficitinteligente.online`. Repo: `efabianpq/TUDI`, rama `main`.

El diseño ya está aprobado. Tu trabajo es implementarlo, no rediseñarlo. **No inventes colores, tamaños, radios ni tipografías que no estén en `tokens/tudi-tokens.css`.**

## Archivos que te entrego

| Archivo | Qué es |
| --- | --- |
| `design/TUDI-diseno-oficial.dc.html` | El mockup aprobado de la app (Inicio, Plan diario, Calculadora — móvil y escritorio). Ábrelo en el navegador: es la referencia visual y de maquetación. Copia de aquí estructura, jerarquía y valores. |
| `design/TUDI-landing-publica.dc.html` | La landing pública aprobada para `tudeficitinteligente.online`. Es la referencia para `resources/views/welcome.blade.php`. |
| `tokens/tudi-tokens.css` | Los tokens y las clases base (`.tudi-*`). Esta es la única fuente de verdad de color, tipografía, radio y espaciado. |

## Regla de oro del rediseño

Los usuarios se quejaron de tres cosas. Cada cambio que hagas debe atacar una de ellas:

1. **Demasiado texto explicativo.** Todo párrafo de instrucciones sale de la pantalla. Va como `placeholder` del campo, o detrás de un enlace "¿Cómo funciona?" que abre un modal. Ninguna pantalla lleva más de dos frases de ayuda visibles.
2. **Registrar comidas es largo.** Un campo, un botón. Escribir o dictar → "Registrar". Nada de formularios de varios pasos para una comida.
3. **Se siente clínico.** El fondo blanco desaparece: todo es crema `#EFE9DE`. Una sola cifra manda por pantalla y se muestra en un panel carbón. La lima `#C8F03C` significa progreso y **solo** progreso — nunca es decoración ni relleno de botón sobre crema.

## Sistema visual

- **Fondo:** crema `--tudi-bg`. Las tarjetas son `--tudi-card` con borde `--tudi-border`. Nunca `#fff` a pantalla completa.
- **Dato protagonista:** panel carbón (`.tudi-panel`) con la cifra grande en lima. Uno por pantalla, no dos.
- **Tipografía:** Instrument Sans para todo; JetBrains Mono en mayúsculas para etiquetas, macros y metadatos (`.tudi-label`, `.tudi-meta`). Cargar por Google Fonts:
  `https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400..700&family=JetBrains+Mono:wght@400;500&display=swap`
- **Radios:** contenedores 20–24px; botones y chips 16px o pastilla; los controles táctiles son pastilla.
- **Botones:** sobre crema, primario = carbón (`.tudi-btn-primary`). Sobre panel oscuro, primario = lima (`.tudi-btn-lime`). Secundario = borde `--tudi-input-border`.
- **Objetivos táctiles:** mínimo 44px en móvil; los botones principales 48–54px de alto.
- **Enlaces y texto lima sobre crema:** usa `--tudi-lime-700` (`#5C6B14`), nunca la lima pura — no tiene contraste.

## Tareas, en orden

### 1. Base

- Copia `tokens/tudi-tokens.css` a `resources/css/tudi-tokens.css` e impórtalo desde `resources/css/app.css` **antes** de las directivas de Tailwind.
- Refleja los tokens en `tailwind.config.js` (`theme.extend.colors.tudi`, `fontFamily`, `borderRadius`) para poder usar `bg-tudi-bg`, `text-tudi-muted`, etc. Los valores salen del CSS, no los reescribas a ojo.
- `resources/views/layouts/app.blade.php`: fondo crema en `<body>`, precarga de las dos fuentes, `<meta name="theme-color" content="#171512">`.

### 2. Navegación — `resources/views/layouts/navigation.blade.php`

- **Móvil:** barra inferior fija con tres destinos: Inicio, Calculadora, Planes. Marca el activo con `aria-current="page"` (ver `.tudi-tabbar`). Fuera el menú hamburguesa para estos tres.
- **Escritorio:** barra lateral de 232px, fondo `--tudi-surface`, ítems en pastilla, el activo en carbón. Al pie, la tarjeta de usuario con nombre y objetivo diario.
- La marca es el isotipo: círculo con `conic-gradient(#C8F03C 0 252deg, #171512 252deg 360deg)` y un círculo interior del color del fondo, junto al logotipo "tudi" en 600 y tracking −4%.

### 3. Inicio — `resources/views/dashboard.blade.php`

Orden exacto, de arriba a abajo:

1. Cabecera: isotipo + "tudi" a la izquierda; fecha corta y avatar a la derecha.
2. **Anillo de déficit** (`.tudi-ring`, `--pct` = avance del día): la cifra de déficit en lima, 54px, con "kcal por debajo" debajo. Bajo el anillo, una línea: `2.528 consumidas · 2.376 objetivo`.
3. Dos tarjetas carbón (`.tudi-panel-tile`) en fila: Actividad (kcal) y Proteína (% con `.tudi-bar`).
4. Lista "Comidas" con contador `3 / 3`: una fila por comida, punto lima si está registrada, kcal a la derecha. Sin descripciones aquí.
5. Botón lima a ancho completo: "Abrir el plan de hoy".

Fuera de esta pantalla: la tabla de cuatro cifras que había antes, y todo texto explicativo.

En escritorio la misma información en dos columnas: panel de déficit ancho a la izquierda, lista de comidas a la derecha, y debajo tres tarjetas de tendencia (peso media 7 días, déficit promedio, racha en puntos).

### 4. Plan diario — `resources/views/planes/show.blade.php`

- Cabecera con "Plan del DD/MM" y una etiqueta de estado (`Abierto` / `Cerrado`).
- **Panel de objetivo** carbón: kcal objetivo grande, y las tres macros como barras etiquetadas P / G / C con el gramaje a la derecha. Al pie del panel: "Planificado: X kcal" y la acción "Peso de hoy +".
- Encabezado de sección `CÁLCULO ALIMENTICIO` con el enlace "¿Cómo funciona?" a su derecha (modal, no párrafo).
- **Acordeón de comidas: solo una abierta a la vez.** Las cerradas son una pastilla con punto, nombre y `kcal · %`. La abierta muestra, en este orden:
  - el campo de ingredientes con `placeholder="huevos, queso chitagá y tinto"` y botón de dictado;
  - el nombre del plato sugerido;
  - los ingredientes como filas `nombre · cantidad` → kcal;
  - los chips de kcal y macros (`.tudi-chip`);
  - si falta algún macro, un `.tudi-note` ámbar con la sugerencia concreta;
  - dos botones: "Rehacer" (secundario) y "Registrar" (primario).
- Guardar sin recargar la página: envía por fetch y actualiza el panel de objetivo en su sitio.

### 5. Calculadora de déficit — `resources/views/profile/parametros.blade.php`

El resultado va arriba, no abajo: tarjeta de acento con el objetivo diario en grande y los chips de macros. Debajo, los controles — nueve campos numéricos pasan a controles táctiles:

- Sexo → segmentado de dos opciones (`.tudi-seg`).
- Peso, estatura, edad → tres campos numéricos en una fila.
- Nivel de actividad → segmentado de cuatro (Sedentario / Ligero / Activo / Intenso), con el factor visible en pequeño.
- Déficit → segmentado Porcentaje / Fijo + un slider con el valor calculado al lado (`15% · −420 kcal`).
- Factores de proteína y grasa (g/kg) → dos tarjetas pequeñas editables.
- Botón "Guardar y recalcular".

El objetivo se recalcula en vivo mientras el usuario mueve los controles; el botón solo persiste.

### 6. Cierre del día

Los checkbox y el textarea largo se reemplazan por un interruptor por comida ("¿Cumpliste con lo sugerido?"). Si el interruptor está en no, se despliega un solo campo: "Cuéntanos qué comiste de verdad…". Botón "Cerrar mi día" y una línea de aviso: "Al cerrar se congelan tus cifras del día."

### 7. Landing pública — `resources/views/welcome.blade.php`

Hoy `GET /` redirige directo a login o dashboard: no hay ninguna página pública. Reemplaza `welcome.blade.php` (el starter de Laravel, sin usar) por la landing de `design/TUDI-landing-publica.dc.html`, y ajusta la ruta raíz para que la sirva a un visitante sin sesión (si ya hay sesión, sigue redirigiendo a `dashboard`).

Secciones, en este orden — cópialas del mockup tal cual, son contenido aprobado, no las reescribas:

1. Nav: isotipo + "Precios" / "Iniciar sesión" / botón "Empieza gratis".
2. Hero: eyebrow "Nutrición con datos, no con dietas genéricas", H1 "Entiende cómo comes. Decide qué hacer con eso.", anillo de ejemplo etiquetado "TU BALANCE DE HOY", CTA "Calcula tu objetivo gratis" + "Ver precios".
3. Cómo funciona: Define tu objetivo → Genera tu plan → Registra y ajusta.
4. Diferenciador: "No es una app de dietas ni un contador de calorías más" con sus cuatro puntos, y la tarjeta "Nunca actúa solo".
5. ¿Para quién es esto?: tres tarjetas (bajar de peso / mantenerte / comer mejor) y la línea de cierre.
6. Precios: Gratis vs. Premium (COP $14.900/mes o $119.000/año). **La tabla de qué es gratis y qué es Premium debe coincidir exactamente con la sección 5.3 de este documento.** Hoy el dictado por voz es Gratis; Premium son distribución de comidas con IA, estimación de consumo, recomendaciones e historial completo. El cobro no está abierto: el pie debe decirlo.
7. CTA final "Deja de adivinar cómo comes." + footer.

El nombre del producto es **TUDI** (siglas de "Tu Déficit Inteligente"). No escribas "TUDéficit" en la interfaz.

Los botones "Empieza gratis" / "Calcula tu objetivo gratis" / "Crear mi cuenta gratis" enlazan a `register`; "Iniciar sesión" a `login`.

## Qué NO hacer

- No cambiar la lógica de cálculo, los modelos ni las migraciones. Esto es capa de presentación.
- No introducir gradientes de fondo, emoji ni iconos de relleno decorativo.
- No usar lima para nada que no sea progreso o la acción principal sobre oscuro.
- No reintroducir párrafos de ayuda en la pantalla.
- No añadir pantallas ni secciones que no estén en el mockup.

## Entregable

Un PR por pantalla (base+navegación / inicio / plan diario / calculadora / cierre / landing), con captura de móvil y escritorio en la descripción. Antes de abrirlo, comprueba en cada pantalla: una sola cifra protagonista, ningún párrafo de instrucciones, todo objetivo táctil ≥ 44px, y contraste de texto ≥ 4.5:1.

## Nota — control de acceso por plan, fuera de este alcance

Este encargo es solo la capa visual. La landing muestra precios y el split Gratis/Premium como contenido, pero **no** implementa cobro ni bloqueo real: eso requiere el modelo de datos de suscripciones, la pasarela de pago (Wompi) y el middleware de acceso por funcionalidad que describe el plan de negocio del proyecto (`suscripciones`, `pagos`, verificación antes de `MealDistributionService` y de la estimación de consumo). Si esa fase ya está en marcha, coordínala aparte — no la deduzcas de este documento.
