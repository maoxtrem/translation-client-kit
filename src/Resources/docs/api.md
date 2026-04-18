# TranslationClientKit - Guia de Implementacion

Esta guia explica lo necesario para instalar y usar el bundle en un proyecto host Symfony.

## 1) Registro del Bundle

Archivo: `config/bundles.php`

```php
<?php

return [
    // ...
    Maoxtrem\TranslationClientKit\TranslationClientBundle::class => ['all' => true],
];
```

## 2) Importar Rutas del Bundle

Archivo: `config/routes.yaml`

```yaml
translation_client_kit:
  resource: '@TranslationClientBundle/config/routes.yaml'
```

Con esto quedan disponibles las rutas del panel, locales y switch de idioma.

## 3) Configuracion YAML Requerida

### 3.1 Cache pool para traducciones

Archivo: `config/packages/framework.yaml`

```yaml
framework:
  cache:
    pools:
      cache.translations:
        adapter: cache.adapter.filesystem
```

### 3.2 Twig path del bundle (si renderizas vistas del bundle)

Archivo: `config/packages/twig.yaml`

```yaml
twig:
  paths:
    '%kernel.project_dir%/vendor/maoxtrem/translation-client-kit/src/Resources/views': TranslationClient
```

Si instalas el bundle por path local, ajusta la ruta.

### 3.3 Translator (recomendado)

El bundle crea archivos marcador en `translations/db/*.db` para disparar el loader `db`.
En la mayoria de proyectos Flex funciona con el path por defecto `translations/`, pero se recomienda explicitar:

Archivo: `config/packages/translation.yaml` o `framework.yaml`

```yaml
framework:
  translator:
    default_path: '%kernel.project_dir%/translations'
    paths:
      - '%kernel.project_dir%/translations/db'
```

### 3.4 Doctrine mapping (si tu proyecto no autodetecta la entidad del bundle)

Archivo: `config/packages/doctrine.yaml`

```yaml
doctrine:
  orm:
    mappings:
      TranslationClientKit:
        is_bundle: false
        type: attribute
        dir: '%kernel.project_dir%/vendor/maoxtrem/translation-client-kit/src/Entity'
        prefix: 'Maoxtrem\TranslationClientKit\Entity'
        alias: TranslationClientKit
```

## 4) Variables de Entorno

Archivo: `.env` o `.env.local`

```dotenv
# Identificador del proyecto en el servidor de traducciones
APP_NAME=mi_proyecto

# URL base del servidor de traducciones (Cerebro)
TRADUCCIONES_URL=https://translations.midominio.com

# Opcional: tamano de paquete para push/push legacy
TRADUCCIONES_SYNC_CHUNK_SIZE=200
```

## 5) Base de Datos

La entidad `traduccion_local` debe existir en el host.

Opciones:

1. Generar migracion y ejecutarla.
2. O actualizar esquema directo en desarrollo:

```bash
php bin/console doctrine:schema:update --force
```

## 6) Endpoints Disponibles

### Documentacion
- `GET /api/v2/client-kit/docs`

### Admin Sync
- `GET /admin/translations/sync`
- `POST /admin/translations/pull`
- `POST /admin/translations/push`
- `POST /admin/translations/migrate-legacy`
- `POST /admin/translations/migrate-legacy/scan`
- `POST /admin/translations/migrate-legacy/send`
- `POST /admin/translations/migrate-legacy/send-chunk`

### Locales
- `GET /api/v2/client-kit/locales`
- `GET /switch-locale/{_locale}`

## 7) Uso del JS con Boton (Ejemplo Facil)

Este ejemplo consume las rutas del bundle para cambio de idioma desde tu propia vista del host.

### HTML

```html
<select id="kit-locale-select" disabled>
  <option value="">Cargando...</option>
</select>
<button id="kit-locale-apply" type="button" disabled>Cambiar idioma</button>
```

### JavaScript

```html
<script>
async function loadKitLocales() {
  const select = document.getElementById('kit-locale-select');
  const button = document.getElementById('kit-locale-apply');

  const response = await fetch('/api/v2/client-kit/locales');
  const payload = await response.json();
  const locales = Array.isArray(payload.data) ? payload.data : [];

  select.innerHTML = '';
  locales.forEach((locale) => {
    const option = document.createElement('option');
    option.value = String(locale).toLowerCase();
    option.textContent = String(locale).toUpperCase();
    select.appendChild(option);
  });

  select.disabled = false;
  button.disabled = false;
}

function applyKitLocale() {
  const select = document.getElementById('kit-locale-select');
  const locale = String(select.value || '').trim().toLowerCase();
  if (locale === '') {
    return;
  }
  window.location.href = '/switch-locale/' + encodeURIComponent(locale);
}

document.addEventListener('DOMContentLoaded', () => {
  loadKitLocales();
  document.getElementById('kit-locale-apply').addEventListener('click', applyKitLocale);
});
</script>
```

Opcional: en vez de boton, puedes aplicar en `change` del `select`.

## 8) Verificacion Rapida

```bash
php bin/console cache:clear
php bin/console debug:router | grep -E "kit_|client-kit"
```

Comprueba:

1. Abrir `GET /admin/translations/sync`.
2. Ejecutar `pull` y validar que escribe en `traduccion_local`.
3. Probar selector/boton de idioma y confirmar cambio de locale en sesion.

