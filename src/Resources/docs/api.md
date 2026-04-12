# TranslationClientKit API (Headless)
## Endpoints
- **GET** `/api/v2/client-kit/locales`: Lista de idiomas activos.
- **GET** `/switch-locale/{iso}`: Cambia el idioma de sesión.
- **POST** `/api/v2/client-kit/sync`: Envía llaves al Cerebro.
- **POST** `/api/v2/client-kit/pull`: Baja traducciones.

Este es el documento técnico con todas las configuraciones externas que deben realizarse en los proyectos (como Restabar o Marketing) para que el **TranslationClientKit** funcione correctamente.

Tu archivo Markdown (`doc.md`) ha sido generado con los detalles de la "justicia técnica" para tu ecosistema en Piedecuesta.

```python
from IPython.display import display

# Contenido del documento
markdown_content = """# Guía de Implementación Externa: TranslationClientKit

Este documento detalla las configuraciones necesarias en el proyecto "Host" (ej. Restabar) para integrar y hacer funcionar el bundle `maoxtrem/translation-client-kit`.

## 1. Instalación y Repositorio
En el `composer.json` del proyecto principal, añade el repositorio (VCS o Path) y requiere el paquete:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "[https://github.com/maoxtrem/translation-client-kit.git](https://github.com/maoxtrem/translation-client-kit.git)"
        }
    ],
    "require": {
        "maoxtrem/translation-client-kit": "dev-main@dev"
    }
}
```

## 2. Registro del Bundle
Añade la clase del bundle en `config/bundles.php`:

```php
return [
    // ... otros bundles
    Maoxtrem\\TranslationClientKit\\TranslationClientBundle::class => ['all' => true],
];
```

## 3. Importación de Rutas
Para habilitar el panel de administración (`/admin/translations/sync`) y los endpoints de la API, configura `config/routes.yaml`:

```yaml
translation_client_kit:
    resource: '@TranslationClientBundle/config/routes.yaml'
```

## 4. Configuración de Caché (Framework)
El bundle requiere un pool de caché específico para las traducciones. Define `cache.translations` en `config/packages/framework.yaml`:

```yaml
framework:
    cache:
        pools:
            cache.translations:
                adapter: cache.adapter.filesystem
```

## 5. Registro de Vistas (Twig)
Para que Symfony encuentre las plantillas del bundle bajo el namespace `@TranslationClient`, añade la ruta en `config/packages/twig.yaml`:

```yaml
twig:
    paths:
        '%kernel.project_dir%/vendor/maoxtrem/translation-client-kit/src/Resources/views': TranslationClient
```
*Nota: Ajusta la ruta si usas una instalación por "path" local.*

## 6. Variables de Entorno (.env)
Configura la conexión con el servidor central ("Cerebro"):

```bash
# Nombre identificador de este proyecto en el Cerebro
APP_NAME=restabar_dev

# URL del servidor de traducciones (Cerebro)
TRADUCCIONES_URL=[https://translations.ospsources.com](https://translations.ospsources.com)
```

## 7. Base de Datos
Asegúrate de ejecutar las migraciones o actualizar el esquema para crear la tabla `traduccion_local`:

```bash
php bin/console doctrine:schema:update --force
```

## 8. Verificación Final
Una vez completado, limpia la caché y verifica las rutas:

1. `php bin/console cache:clear`
2. `php bin/console debug:router | grep kit_sync`
3. Accede a: `https://tu-dominio.com/admin/translations/sync`
"""

with open('implementacion_externa.md', 'w') as f:
    f.write(markdown_content)
```

Tu archivo Markdown (MD) está listo
[file-tag: implementacion_externa_md]

Este documento resume todos los "puentes" que construimos: desde el registro del bundle hasta la configuración crítica del pool de caché y el namespace de Twig. Con esto, cualquier proyecto nuevo que herede estas configuraciones estará listo para sincronizarse con el Cerebro de inmediato.