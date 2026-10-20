# Comentarios

Aplicación web de conversación comunitaria desarrollada con PHP, MySQL y JavaScript. Permite crear una cuenta, publicar comentarios, responderlos en un nivel, votar, recibir notificaciones y administrar un perfil con avatar.

- **Sitio público:** <https://prueba123.gt.tc>
- **API de comentarios:** <https://prueba123.gt.tc/api/comentarios.php>
- **Repositorio:** <https://github.com/HillkirkLautaro/prueba123>
- **Autor:** Lautaro Hillkirk

## Funcionalidades

- Registro e inicio de sesión mediante usuario y contraseña; inicio con Google opcional.
- Comentarios con alta, consulta, edición y eliminación para su autor.
- Respuestas de un único nivel, reacciones de *Me gusta* y *No me gusta* y notificaciones asociadas.
- Actualización dinámica de comentarios y notificaciones sin recargar la página.
- Preferencias visuales persistentes: modo oscuro y visualización progresiva de respuestas de a tres.
- Perfil público, actualización de avatar y recorte de imágenes compatible.
- Widget de clima actual con ubicación aproximada por IP, estado visible y reintentos graduales ante fallas.
- Fechas mostradas en la zona horaria del navegador.

## Arquitectura y estructura

```text
.
├── api/
│   ├── comentarios.php       API REST del recurso comentarios
│   └── weather.php           API interna para ubicación y clima
├── js/api-client.js          Cliente fetch de comentarios
├── sql/schema.sql            Esquema inicial de MySQL
├── src/services/
│   ├── ip_location.php       Adaptadores de geolocalización por IP
│   ├── weather.php           Adaptador de Open-Meteo
│   └── resend.php            Adaptador opcional de correo
├── uploads/avatars/          Avatares cargados en tiempo de ejecución
├── index.php                 Muro de comentarios y preferencias
├── actions.php               Respuestas y votos mediante formularios
├── notifications.php         Consulta y marcado de notificaciones
├── profile.php               Perfil y avatar
├── login.php / register.php  Autenticación local
├── google_*.php              Flujo OAuth 2.0 con PKCE de Google
├── config.php                Entorno, PDO, sesión y cabeceras de seguridad
├── csrf.php                  Protección CSRF y utilidades de origen
├── styles.css                Estilos compartidos y tema oscuro
├── manifest.json             Metadatos de instalación web
└── sw.js                     Estrategia de caché estática disponible
```

La interfaz y la API comparten origen. La capa PHP centraliza la sesión, las validaciones y el acceso a MySQL mediante PDO. Los servicios de terceros se encuentran aislados en `src/services/`.

## Ejecución local

### Requisitos

- XAMPP, Laragon o un servidor equivalente con Apache, PHP 8.0 o superior y MySQL/MariaDB.
- Extensiones PHP recomendadas: `pdo_mysql`, `mbstring`, `curl`, `fileinfo` y `gd`.

### Instalación

1. Copiá el proyecto en el directorio público del servidor, por ejemplo `htdocs/comentarios`.
2. Creá la base e importá el esquema:

   ```bash
   mysql -u root -p < sql/schema.sql
   ```

   También se puede importar `sql/schema.sql` desde phpMyAdmin. El script crea la base local `mi_crud_local`, las tablas `usuarios`, `comentarios`, `votos` y `notificaciones`, sus claves foráneas e índices.

3. Creá `.env` a partir de `.env.example` y configurá, como mínimo, la conexión local:

   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=mi_crud_local
   DB_USER=root
   DB_PASSWORD=
   APP_ENV=local
   ```

4. Asegurá permisos de escritura del usuario web sobre `uploads/avatars/` si se van a cargar imágenes.
5. Abrí `http://localhost/comentarios/index.php` (adaptando la carpeta a tu instalación).

> `.env` contiene secretos y no debe versionarse ni publicarse. La aplicación lee primero las variables del proceso y después el archivo `.env` local.

## API HTTP

### Recurso REST: comentarios

Ruta base: `/api/comentarios.php`. Las respuestas son JSON y las operaciones que cambian datos requieren una sesión autenticada y un `csrf_token` válido.

| Método | Ruta | Entrada | Resultado | Estados principales |
|---|---|---|---|---|
| `GET` | `/api/comentarios.php?page=1` | `page` opcional | Lista de comentarios raíz, página y tamaño de página | `200` |
| `GET` | `/api/comentarios.php?id={id}` | Identificador en query string | Un comentario | `200`, `404` |
| `POST` | `/api/comentarios.php` | JSON: `contenido`, `csrf_token` | Comentario creado e identificador | `201`, `401`, `403`, `422`, `500` |
| `PUT` | `/api/comentarios.php?id={id}` | JSON: `contenido`, `csrf_token` | Confirmación de edición | `200`, `400`, `401`, `403`, `404`, `422`, `500` |
| `DELETE` | `/api/comentarios.php?id={id}` | JSON: `csrf_token` | Confirmación de eliminación | `200`, `400`, `401`, `403`, `404`, `500` |

Ejemplo de creación:

```json
{
  "contenido": "Mi primer comentario",
  "csrf_token": "token-de-la-sesion"
}
```

Los errores siguen el formato `{"success": false, "error": "mensaje"}`. `js/api-client.js` verifica `response.ok` y propaga el mensaje para mostrarlo en la interfaz.

### Rutas auxiliares

| Ruta | Uso | Método |
|---|---|---|
| `/api/weather.php` | Obtiene ubicación aproximada y clima actual; devuelve datos y estados de ambos servicios | `GET` |
| `/notifications.php` | Devuelve las diez notificaciones más recientes y el total no leído; también permite marcarlas como leídas | `GET`, `POST` |
| `/actions.php` | Publica una respuesta o crea/cambia/elimina un voto desde formularios | `POST` |

Estas rutas validan sesión, origen y/o token CSRF según la acción. Las respuestas anidadas no forman parte del CRUD REST: se crean desde `actions.php` y sólo se permite un nivel.

## Configuración

| Variable | Descripción | Obligatoria |
|---|---|---|
| `DB_HOST` | Servidor MySQL | Sí |
| `DB_PORT` | Puerto MySQL; normalmente `3306` | Sí |
| `DB_NAME` | Nombre de la base de datos | Sí |
| `DB_USER` | Usuario de la base de datos | Sí |
| `DB_PASSWORD` | Contraseña de la base de datos | Sí en producción |
| `APP_ENV` | Entorno: `local`, `staging` o `production` | Sí |
| `HCAPTCHA_SITEKEY` | Clave pública de hCaptcha | No |
| `HCAPTCHA_SECRET` | Secreto para validar hCaptcha en el servidor | No |
| `GOOGLE_CLIENT_ID_OAUTH` | Identificador OAuth de Google | No |
| `GOOGLE_SECRET_CLIENT` | Secreto OAuth de Google | No |
| `GOOGLE_OAUTH_REDIRECT_URI` | URL de callback OAuth; debe usar HTTPS en producción | No |
| `RESEND_API_KEY` | Credencial para el adaptador de correo Resend | No |
| `RESEND_FROM` | Remitente autorizado en Resend, con formato `Nombre <correo@dominio>` | No |
| `APP_URL` | URL pública base de la aplicación; si se omite, se usa la URL de la petición para el enlace de verificación | No |
| `ALLOWED_ORIGINS` | Lista separada por comas de orígenes adicionales permitidos | No |

En `production`, la configuración rechaza credenciales de ejemplo, una base local y una conexión no HTTPS. Nunca incluyas valores reales en documentación, commits ni archivos distribuidos.

## Servicios externos y privacidad

| Servicio | Llamado por | Datos enviados | Comportamiento ante una falla |
|---|---|---|---|
| [ipwho.is](https://ipwho.is/) / [ip-api.com](https://ip-api.com/) | Backend PHP | Dirección IP del visitante al proveedor de geolocalización | Se usa Buenos Aires como ubicación de respaldo y el estado se informa en el widget. |
| [Open-Meteo](https://open-meteo.com/) | Backend PHP | Latitud y longitud aproximadas | El resto de la aplicación sigue disponible. El navegador reintenta con espera exponencial, hasta 60 segundos entre intentos. |
| [hCaptcha](https://www.hcaptcha.com/) | Navegador y backend, si se configura | Token del desafío y dirección IP para validación | Si no se configuran las claves, el desafío no se muestra. Si está activo y falla, se rechaza el formulario con un mensaje. |
| [Google OAuth](https://developers.google.com/identity/protocols/oauth2) | Backend y redirección del navegador, si se configura | Datos básicos de identidad autorizados por la persona usuaria | El acceso tradicional con usuario y contraseña permanece disponible. |
| [Resend](https://resend.com/) | Backend PHP, durante el registro | Dirección de correo, nombre de usuario y enlace de verificación | No se crea una cuenta sin que pueda enviarse su enlace de activación. |

Cuando se configuran `RESEND_API_KEY` y `RESEND_FROM`, el registro genera un token aleatorio de un solo uso, guarda únicamente su hash SHA-256 con vencimiento de 24 horas y envía el enlace `/verify_email.php` mediante Resend. La cuenta no puede iniciar sesión hasta que se confirme ese enlace. `RESEND_FROM` debe ser un remitente o dominio verificado en Resend. Si se usa el remitente de prueba `onboarding@resend.dev`, Resend sólo permite enviar al correo del titular de la cuenta.

La ubicación no utiliza la API de geolocalización del navegador; se infiere de manera aproximada desde la IP. Las preferencias de modo oscuro y visibilidad de respuestas se guardan únicamente en `localStorage` del navegador.

## Seguridad

- Consultas preparadas PDO con emulación deshabilitada.
- Contraseñas con `password_hash()`/bcrypt y verificación con `password_verify()`.
- Cookies de sesión `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS; regeneración del identificador al autenticar.
- Tokens CSRF, validación de origen y restricciones de `Content-Type` en operaciones sensibles.
- Control de propiedad para editar, borrar comentarios y actualizar avatares.
- Validación de entradas, límites de longitud, comprobación de UTF-8 y validación de imágenes antes de guardarlas.
- Cabeceras CSP con nonce, HSTS en HTTPS, `X-Frame-Options`, `nosniff`, políticas de referencia y permisos restringidos.
- `display_errors` desactivado y eventos relevantes enviados al log del servidor.

La configuración ampliada y la operación de seguridad se documentan en [SECURITY.md](SECURITY.md) e [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md).

## Despliegue

La instancia pública actual se ejecuta en **InfinityFree**, con PHP, HTTPS y MySQL administrado por el proveedor. Para publicar una instancia propia:

1. Creá una base MySQL y un usuario con permisos sobre esa base.
2. Importá `sql/schema.sql`.
3. Subí la aplicación al directorio público, sin incluir `.env`, registros ni copias de seguridad.
4. Creá `.env` directamente en el servidor con las variables de producción.
5. Creá `uploads/avatars/` con permisos de escritura para el proceso PHP.
6. Configurá el dominio y HTTPS, y definí `GOOGLE_OAUTH_REDIRECT_URI` si se habilita OAuth.
7. Verificá el registro, sesión, CRUD de comentarios, carga de avatar, notificaciones y clima desde una conexión externa.

Para un servidor propio con Apache o Nginx, certificados y tareas de mantenimiento, consultá [DEPLOYMENT.md](DEPLOYMENT.md). El archivo `.htaccess` incluido deshabilita el listado de directorios y bloquea el acceso directo a archivos `.env`, `.log` y `.sql` en servidores Apache compatibles.

## Limitaciones conocidas

- Las respuestas admiten un solo nivel de anidación.
- Las notificaciones usan sondeo periódico mientras la pestaña está visible; no hay conexión WebSocket.
- La ubicación por IP es estimada y puede usar Buenos Aires como valor de respaldo.
- La disponibilidad de Google OAuth y hCaptcha depende de sus credenciales externas.
- La verificación depende de la disponibilidad de Resend. Si el correo no se puede enviar, el registro se cancela para no dejar una cuenta inaccesible.
- La estrategia de caché de `sw.js` está disponible en el proyecto; su registro desde el cliente debe verificarse o integrarse antes de depender de ella para uso sin conexión.
- Los hostings compartidos pueden limitar procesos persistentes, cron o peticiones procedentes de clientes no convencionales.

## Documentación relacionada

- [Guía de despliegue](DEPLOYMENT.md)
- [Controles de seguridad](SECURITY.md)
- [Respuesta a incidentes](INCIDENT_RESPONSE.md)
- [Esquema de base de datos](sql/schema.sql)
