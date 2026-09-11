# TUDI — paquete de entrega (versión Oficial)

Rediseño de marca y UX/UI de TUDI (TuDeficitInteligente).

```
handoff_tudi/
├─ CLAUDE_CODE_PROMPT.md            ← pega esto en Claude Code
├─ design/
│  ├─ TUDI-diseno-oficial.dc.html   ← mockup de la app (ábrelo en el navegador)
│  ├─ TUDI-landing-publica.dc.html ← mockup de la landing pública
│  └─ support.js                    ← runtime que necesitan los mockups, déjalo al lado
└─ tokens/
   └─ tudi-tokens.css               ← tokens y clases base, fuente de verdad
```

## Cómo usarlo

1. Abre los dos `.dc.html` en `design/` en un navegador para ver el diseño.
2. Abre Claude Code en el repo `efabianpq/TUDI`.
3. Copia esta carpeta dentro del repo (por ejemplo en `docs/rediseno/`).
4. Pega el contenido de `CLAUDE_CODE_PROMPT.md` como primera instrucción.

## La identidad en corto

- **Fondo** crema tostada `#EFE9DE`. Nada de blanco a pantalla completa.
- **Tinta** carbón `#171512`.
- **Lima** `#C8F03C` = progreso, y solo progreso. Sobre crema, el texto lima usa `#5C6B14`.
- **Ámbar** `#E8A33D` = aviso o ajuste sugerido.
- **Tipografía** Instrument Sans (cifras y texto) + JetBrains Mono en mayúsculas (etiquetas y macros).
- **Isotipo** anillo de progreso con el corte del déficit; funciona como ícono de app.
- **Claim** "Tu déficit, en un solo número."
