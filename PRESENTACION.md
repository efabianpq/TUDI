# TUDéficit Inteligente — guion de presentación y demo

Cómo enseñar la aplicación en vivo, qué decir mientras se enseña, y qué material
sirve para promocionarla. Los datos de las cuentas de demostración los crea
`php artisan tudi:demo` (ver la sección 4 y `CLAUDE.md` §5.17).

---

## 1. La idea, en una frase

> **TUDéficit Inteligente convierte "voy a hacer dieta" en un número concreto al
> día, y lo va ajustando contigo.**

Y si hay tiempo para tres frases más:

> Le dices lo que tienes en la nevera, en tu idioma, y te lo reparte entre
> desayuno, almuerzo y cena con las calorías y los macros de cada plato.
> Al final del día le cuentas qué comiste de verdad —no lo que planeaste— y te
> cierra el balance.
> Y cuando lleva un par de semanas mirándote, te propone subir o bajar tu
> objetivo. Nunca lo hace solo: lo confirmas tú.

### Qué lo diferencia

| No es | Es |
|---|---|
| Una tabla de calorías que hay que buscar alimento por alimento | Escribes "huevos, arepa y queso" y él hace el resto |
| Una app que te riñe por un día malo | Solo mira promedios de 7 días: un día atípico no cambia nada |
| Una IA que decide por ti cuánto comer | La IA solo interpreta lo que dices; **las cuentas las hace el sistema**, y los ajustes los apruebas tú |
| Un plan rígido | Puedes cambiar el reparto del día, rehacer una comida o reiniciar el día entero |

---

## 2. Recorrido de demostración (8–10 minutos)

Cada paso indica con **qué cuenta** entrar. Contraseña de todas: `demo1234`.

### Paso 1 · "Empiezo desde cero" — 1 min
**Cuenta:** `sin-calculadora@demo.tudeficitinteligente.online`

- Entra a **Inicio**. Todo pide lo mismo: completar la Calculadora.
- Ve a **Calculadora déficit**. Enseña que **no pregunta factores técnicos**:
  pregunta sexo, peso, estatura, edad, cuán activo eres y qué objetivo quieres.
- Mueve el objetivo entre −10 % y −30 % y señala que **la cifra de arriba cambia
  en vivo**, con los tres macros debajo.
- Si hay video y guía publicados, señala los dos botones: *"esto es lo primero
  que ve alguien que no sabe qué es un macronutriente"*.

> **Frase:** "En treinta segundos ya sabe cuántas calorías debe comer, y por qué."

### Paso 2 · "Un día normal" — 3 min
**Cuenta:** `en-ritmo@demo.tudeficitinteligente.online`

- Abre **Planes diarios → el plan de hoy**. Está a medias a propósito.
- Arriba, el panel **Objetivo del día** con proteína, grasas y carbohidratos.
- Abre **Reparto del día**: cambia a 20 / 45 / 35 y aplica. Señala que los
  objetivos por comida se recalculan y que **los planes ya hechos no cambian**.
- Abre una comida sin resolver, escribe (o **dicta con el micrófono**) unos
  ingredientes y pulsa **Generar distribución**.

> **Frase:** "Una sola consulta para las tres comidas. Y fíjate: el modelo
> propone los alimentos, pero las calorías las suma el sistema. Ninguna cifra
> que entre al balance la inventa una IA."

- Baja a **Actividad física**: la sugerencia del día y lo que ya lleva hecho.

### Paso 3 · "Cerrar el día" — 2 min
**Misma cuenta.**

- Baja al **Cierre del día**. Muestra la tarjeta **"Lo que llevas comido"**:
  los cuatro macros, real contra objetivo.
- En **"¿Cumpliste con lo sugerido?"**, activa el interruptor de una comida y en
  otra escribe *"al final me comí un sándwich y una gaseosa"*.
- Pulsa **Cerrar mi día**.
- Enseña **"Lo que respondiste"** y el botón **Cambiar mi respuesta** (hay que
  reabrir el día primero).

> **Frase:** "Aquí está la diferencia. No registra lo que planeaste: registra lo
> que pasó. Y si te equivocas al responder, se cambia."

### Paso 4 · "El sistema me corrige" — 2 min
**Cuenta:** `baja-lento@demo.tudeficitinteligente.online`

- Entra a **Inicio**. En **Ajustes de tu objetivo** hay una propuesta pendiente:
  *reducir* el objetivo porque baja por debajo del 0,5 % semanal.
- Abre el **"¿Qué es esto?"** y lee en voz alta el criterio.
- Enseña **Tu tendencia** (promedio móvil, gráfico) y **Tu seguimiento**
  (adherencia por semana, historial con propuestas ya confirmadas y rechazadas).
- Vuelve al panel y pulsa **Confirmar**: el objetivo cambia.

> **Frase:** "No mira el día de ayer. Compara el promedio de esta semana con el
> de la anterior. Y aunque tenga clarísimo que hay que ajustar, no toca nada
> hasta que la persona diga que sí."

**Contraste rápido:** entra con `baja-rapido@…` (propone lo contrario: *aumentar*,
porque baja demasiado deprisa) y con `en-ritmo@…` (no propone nada, y **lo dice**:
"tu ritmo está dentro de lo esperado").

### Paso 5 · "Y quién lo administra" — 1 min
**Cuenta:** `admin@demo.tudeficitinteligente.online`

- **Administración → Usuarios**: la cola de activación con el código de cada
  cuenta pendiente. Activa `pendiente@…` con el código `DEMO2024`.
- **Parámetros maestros**: los umbrales del motor se cambian sin desplegar.
- **Material de apoyo**: aquí se publica el video y la guía de la Calculadora.

> **Frase:** "El registro es abierto, pero nadie entra sin que un administrador
> le entregue su código. Y los criterios del motor se ajustan desde aquí, sin
> tocar código."

### Cierre — 30 s

> "Resumen: calcula tu objetivo, te organiza el día con lo que tienes, registra
> lo que de verdad comiste, y te propone ajustes basados en tu tendencia real.
> Funciona desde el móvil, se instala como app y no necesita que apuntes nada a
> mano."

---

## 3. Material para publicidad

### Titulares cortos

- *Tu déficit, en un solo número.*
- *Dile lo que tienes en la nevera. Él arma tu día.*
- *No cuenta calorías. Cuenta tu progreso.*
- *Un mal día no arruina tu plan. Ni cambia tu objetivo.*
- *La IA te entiende. Las cuentas las hace bien.*

### Textos por red

**Instagram / Facebook (imagen + texto corto)**

> ¿Cuántas calorías tienes que comer hoy?
> Ni idea, ¿verdad?
> TUDéficit Inteligente te lo dice en 30 segundos, y después te organiza el día
> con lo que ya tienes en casa. 🇨🇴
> Sin pesar cada alimento. Sin apuntar nada a mano.
> **tudeficitinteligente.online**

**Historia / Reel (guion de 20 s)**

| Segundo | En pantalla | Voz en off |
|---|---|---|
| 0–4 | Nevera abierta | "Tienes esto. ¿Y ahora qué?" |
| 4–9 | Escribiendo "huevos, arepa y queso" | "Se lo dices así, como se lo dirías a alguien." |
| 9–14 | El plan generándose, con los macros | "Y te arma el desayuno, el almuerzo y la cena." |
| 14–18 | Anillo de déficit en Inicio | "Al final del día, tu balance real." |
| 18–20 | Logo | "TUDéficit Inteligente." |

**LinkedIn / nota de producto**

> La mayoría de las apps de nutrición fallan en lo mismo: exigen que el usuario
> haga de nutricionista y de contable a la vez.
>
> TUDéficit Inteligente separa las dos cosas. Un modelo de lenguaje interpreta
> lo que la persona escribe en su idioma —"pollo, arroz y una ensalada"— y lo
> convierte en alimentos con sus porciones. A partir de ahí, **ninguna cifra del
> balance energético la calcula la IA**: las sumas, los objetivos y el déficit
> los hace el sistema, con fórmulas auditables.
>
> Los ajustes al objetivo calórico se derivan de promedios móviles de 7 días,
> nunca de un día suelto, y **siempre requieren la confirmación del usuario**.
>
> Es una decisión de diseño, no una limitación técnica: en salud, quien decide
> cuánto come una persona no puede ser una caja negra.

### Preguntas frecuentes (para la landing o el pitch)

**¿Tengo que pesar la comida?**
No. Describes lo que comiste con tus palabras y él estima las porciones.

**¿Y si un día me salgo del plan?**
No pasa nada. El sistema solo mira promedios de 7 días, así que un día atípico
no cambia tu objetivo.

**¿Tengo que pesarme todos los días?**
No. Con pesarte un par de veces por semana es suficiente: los días sin peso
simplemente no entran en el promedio.

**¿La app decide por mí cuánto comer?**
Nunca. Te propone un ajuste y te explica por qué; tú lo confirmas o lo rechazas.

**¿Funciona sin internet?**
Necesita conexión para generar los planes. El dictado por voz, en cambio, lo
resuelve tu propio teléfono.

---

## 4. Preparar y retirar la demo

### Sembrar

```bash
php artisan tudi:demo            # pregunta antes de crear
php artisan tudi:demo --force    # sin preguntar (scripts)
```

Crea seis cuentas, todas con contraseña `demo1234`:

| Correo (`@demo.tudeficitinteligente.online`) | Escenario |
|---|---|
| `admin@` | Administradora · consola, parámetros y material de apoyo |
| `pendiente@` | Registrada sin activar · código **DEMO2024** |
| `sin-calculadora@` | Activa sin perfil · el primer paso del recorrido |
| `en-ritmo@` | 21 días · ~0,87 % semanal → **sin ajuste**, y lo explica |
| `baja-lento@` | 21 días · ~0,29 % semanal → propone **reducir** |
| `baja-rapido@` | 21 días · ~1,47 % semanal → propone **aumentar** |

Las tres últimas traen tres semanas de días cerrados, con **el día de hoy
abierto y a medias** para poder enseñar el flujo completo en vivo.

### Retirar

```bash
php artisan tudi:demo --limpiar
```

Borra solo las cuentas cuyo correo termina en `@demo.tudeficitinteligente.online`
y todo su historial en cascada. **Nunca toca una cuenta real.**

> ⚠️ Estas cuentas usan una contraseña conocida y una de ellas es
> administradora. Retíralas en cuanto termines la demostración.

---

## 5. Desplegar la demo en Hostinger por SSH

El despliegue normal está en `DEPLOY.md`. Esto es lo que se añade **solo** para
dejar la demostración lista.

```bash
# 1. Conéctate y ve a la carpeta del proyecto (Opción A: fuera de public_html)
ssh -p 65002 USUARIO@IP-DEL-SERVIDOR
cd ~/domains/tudeficitinteligente.online/app_laravel

# 2. Trae los cambios y aplica migraciones
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force

# 3. Siembra las cuentas de demostración
php artisan tudi:demo --force

# 4. Refresca cachés (config:cache congela .env: hazlo DESPUÉS de tocarlo)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Sincroniza public_html (idempotente; ver DEPLOY.md §7)
cd ~/domains/tudeficitinteligente.online/public_html
for item in ../app_laravel/public/*; do
    name=$(basename "$item")
    if [ "$name" != ".htaccess" ] && [ "$name" != "index.php" ] && [ ! -e "$name" ]; then
        ln -s "$item" "$name"
        echo "enlazado: $name"
    fi
done

# 6. Verifica antes de enseñar nada
cd ~/domains/tudeficitinteligente.online/app_laravel
php artisan tudi:diagnostico
```

### Antes de la demostración, comprueba

- [ ] `tudi:diagnostico` sin ningún fallo crítico.
- [ ] `APP_DEBUG=false` — una traza de error en mitad de una demo es lo peor que
      puede pasar, y además expone rutas internas del servidor.
- [ ] `SESSION_DRIVER=database` — con `file`, dos pestañas abiertas a la vez
      bastan para provocar un 504 (`CLAUDE.md` §5.13).
- [ ] `APP_TIMEZONE=America/Bogota` — si no, "hoy" no es hoy y el plan del día
      aparece vacío.
- [ ] `GEMINI_API_KEY` configurada, si vas a generar una distribución en vivo.
- [ ] Video y guía publicados en **Administración → Material de apoyo**, si vas
      a enseñarlos.
- [ ] Entra una vez con cada cuenta antes de empezar: la primera carga siempre
      es la más lenta.

### Después

```bash
php artisan tudi:demo --limpiar
```
