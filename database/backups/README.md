# Backups de base de datos

`tudeficit_dump.sql` es un volcado (`mysqldump`) de la base de datos local `tudeficit` en el momento del commit. Incluye estructura y datos (usuarios de prueba/QA, sin datos sensibles reales).

Para restaurarlo:

```bash
mysql -u root tudeficit < database/backups/tudeficit_dump.sql
```

Para el desarrollo normal, preferir `php artisan migrate --seed` en vez de este dump — el dump es una foto puntual, no la fuente de verdad del esquema (esa vive en `database/migrations`).
