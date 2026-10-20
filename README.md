# 📱 Comentarios — TP Integrador N.º 1

**Materia:** Aplicaciones Móviles y Cloud Computing  
**Tema de la app:** Sistema de comentarios fullstack (PHP + MySQL) con API REST, autenticación, votos, notificaciones y avatar de perfil.  
**Proveedor cloud:** InfinityFree (hosting compartido gratuito + MySQL remoto).  
**URL pública de la app (frontend):** `https://prueba123.gt.tc`  
**URL de la API:** `https://prueba123.gt.tc/api/comentarios.php`  
**Repositorio:** https://github.com/HillkirkLautaro/prueba123  
**Integrante:** Lautaro Hillkirk — trabajo individual, creado solo por él.

---

## 1. Presentación

Esta aplicación permite a los usuarios registrarse, iniciar sesión, crear comentarios, responder a comentarios existentes, votar (me gusta / no me gusta), editar y eliminar sus propios comentarios, y gestionar su perfil con foto de avatar. Incluye un panel de notificaciones en tiempo real (sondeo cada 8 segundos sin recargar) y páginas legales (términos y privacidad).

La app está desplegada en **InfinityFree**, accesible por **HTTPS** desde cualquier dispositivo (computadora o celular con datos móviles), con la base de datos también en la nube (MySQL remoto de InfinityFree).

**Modo Android:** la app se entrega como **PWA (Progressive Web App)**. Desde Chrome en Android, el usuario puede "Instalar app" y la abre como una aplicación nativa (icono en pantalla de inicio, barra de direcciones ocultada, `display: standalone`). Se eligió PWA en lugar de wrapper (WebView/Play Store) porque InfinityFree es hosting compartido: no permite instalar binarios nativos ni publicar en tiendas, y una PWA es accesible únicamente por URL HTTPS, funciona en cualquier dispositivo y cumple el requisito R6 sin complejidad adicional.

---

## 0. Estructura de carpetas

```
prueba123/
├── api/                 # Endpoints REST (accesibles por URL)
│   ├── comentarios.php  # CRUD de comentarios (GET, POST, PUT, DELETE)
│   └── weather.php      # Clima actual (Open-Meteo + geolocalización por IP)
├── src/
│   └── services/        # Servicios backend internos (no accedidos directamente)
│       ├── ip_location.php   # Geolocalización por IP (ip-api.com)
│       ├── resend.php        # Email transaccional (Resend)
│       └── weather.php       # Clima (Open-Meteo)
├── js/
│   └── api-client.js    # Cliente fetch para la API REST (sin recargar página)
├── sql/
│   └── schema.sql       # Script SQL para crear la base de datos
├── backups/
│   ├── backup_db.sh     # Backup automático de MySQL
│   └── backup_files.sh  # Backup automático de archivos
├── (puntos de entrada PHP públicos en raíz)
│   ├── index.php        # Página principal (comentarios + widget clima)
│   ├── login.php        # Autenticación
│   ├── register.php     # Registro de usuarios
│   ├── profile.php      # Perfil y avatar
│   ├── edit.php         # Editar comentario
│   ├── delete.php       # Eliminar comentario
│   ├── actions.php      # Responder y votar comentarios
│   ├── notifications.php# Notificaciones (GET/POST)
│   ├── logout.php       # Cierre de sesión
│   ├── google_login.php # OAuth con Google
│   ├── google_callback.php# Callback OAuth Google
│   ├── terminos.php     # Términos y Condiciones
│   ├── privacidad.php   # Política de Privacidad
│   ├── config.php       # Configuración, DB, sesiones, cabeceras de seguridad
│   ├── csrf.php         # Tokens CSRF
│   └── security_monitor.php # Monitoreo de seguridad (CLI/cron)
├── styles.css           # Hojas de estilo
├── manifest.json        # Manifiesto PWA
├── sw.js                # Service Worker PWA
├── timezone.js          # Formateo de fechas en zona horaria local
├── .env.example         # Plantilla de variables de entorno (no subir .env real)
├── .gitignore           # Archivos ignorados por git
├── .htaccess            # Restricción de acceso a archivos sensibles
├── README.md            # Este archivo (entregable)
├── DEPLOYMENT.md        # Guía de despliegue
├── SECURITY.md          # Documentación de seguridad
└── INCIDENT_RESPONSE.md # Procedimientos de respuesta a incidentes
```

> ⚠️ Los archivos de punto de entrada PHP (`index.php`, `login.php`, etc.) deben permanecer en la raíz porque InfinityFree es hosting compartido y no permite cambiar el document root. Los servicios internos en `src/services/` no se acceden directamente por URL; son incluidos por los endpoints de `api/`.

---

## 2. Cómo correrla en local (XAMPP)

1. Instala [XAMPP](https://www.apachefriends.org/) (Apache + PHP 8.0+ + MySQL).
2. Clona o copia este proyecto en `htdocs/prueba123/`.
3. Crea la base de datos ejecutando el script SQL:
   ```bash
   # En phpMyAdmin o por consola MySQL
   mysql -u root -p < sql/schema.sql
   ```
   O importa `sql/schema.sql` desde phpMyAdmin.
4. Copia `.env.example` a `.env` y completa las credenciales de tu base de datos local:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=mi_crud_local
   DB_USER=root
   DB_PASSWORD=
   APP_ENV=local
   ```
   ⚠️ El archivo `.env` **no se sube al repositorio** (está en `.gitignore`).
5. Si usas hCaptcha y Google OAuth, completa `HCAPTCHA_SITEKEY`, `HCAPTCHA_SECRET`, `GOOGLE_CLIENT_ID_OAUTH`, `GOOGLE_SECRET_CLIENT` y `GOOGLE_OAUTH_REDIRECT_URI` en `.env`. Son opcionales para probar en local.
6. Accede a `http://localhost/prueba123/index.php`.

El script `sql/schema.sql` crea las tablas `usuarios`, `comentarios`, `votos` y `notificaciones` con sus relaciones y claves foráneas.

---

## 3. Endpoints de la API

El recurso principal es **comentarios**, con CRUD completo respondiendo JSON (cumple R1 y R2).

| Método | Ruta | Qué recibe | Qué devuelve | Códigos de estado |
|--------|------|------------|--------------|-------------------|
| GET | `/api/comentarios.php` | `?page=N` (opcional) | `{ success, data: [..], page, per_page }` | 200 |
| GET | `/api/comentarios.php?id=X` | — | `{ success, data: { comentario } }` | 200, **404** si no existe |
| POST | `/api/comentarios.php` | JSON `{ contenido, csrf_token, "h-captcha-response" }` | `{ success, message, data: { id } }` | **201** al crear, 401, **422** datos inválidos, 500 |
| PUT | `/api/comentarios.php?id=X` | JSON `{ contenido, csrf_token }` | `{ success, message }` | 200, 401, 403, **404**, **422**, 500 |
| DELETE | `/api/comentarios.php?id=X` | JSON `{ csrf_token }` | `{ success, message }` | 200, 401, 403, **404**, 500 |

**Notas:**
- Las operaciones de escritura (POST/PUT/DELETE) requieren sesión iniciada y ser el autor del comentario (protección IDOR).
- Todas las respuestas son `application/json`.
- No se usa `Access-Control-Allow-Origin: *` (mismo origen; cumple R8).
- Los códigos 400/422 se devuelven ante datos inválidos, 404 si el recurso no existe, 500 solo ante fallas del servidor.

---

## 4. Variables de entorno

Todas las credenciales y claves se leen con `getenv()` desde `.env` (no hay valores reales en el código; cumple **R7**).

| Variable | Para qué sirve |
|----------|----------------|
| `DB_HOST` | Host de la base de datos MySQL (ej. `sqlXXX.infinityfree.com`) |
| `DB_PORT` | Puerto de MySQL (default `3306`) |
| `DB_NAME` | Nombre de la base de datos |
| `DB_USER` | Usuario de la base de datos |
| `DB_PASSWORD` | Contraseña de la base de datos |
| `APP_ENV` | Entorno: `local`, `staging` o `production` |
| `HCAPTCHA_SITEKEY` | Clave pública de hCaptcha (anti-bot) |
| `HCAPTCHA_SECRET` | Secreto de hCaptcha (verificación en backend) |
| `GOOGLE_CLIENT_ID_OAUTH` | Client ID de Google OAuth (opcional) |
| `GOOGLE_SECRET_CLIENT` | Secreto de Google OAuth (opcional) |
| `GOOGLE_OAUTH_REDIRECT_URI` | URI de redirección de Google OAuth (HTTPS en producción) |
| `RESEND_API_KEY` | **API key de Resend** (servicio de email transaccional, backend) |
| `ALLOWED_ORIGINS` | Orígenes permitidos para validación de peticiones |

⚠️ **Nunca subas el archivo `.env` ni valores reales al repositorio.** El archivo `.env.example` solo contiene placeholders.

---

## 5. Servicios externos

| Servicio | Quién lo llama | Por qué | Datos personales que salen | Qué pasa si falla (degradación elegante, R5) |
|----------|----------------|---------|----------------------------|----------------------------------------------|
| **Resend** (email transaccional) | **Backend** (PHP) | Enviar correos (bienvenida, notificaciones) desde el servidor. Se requiere que al menos un servicio externo se llame desde el backend (R4). | Dirección de correo del destinatario y contenido del mensaje → `api.resend.com` | Si `RESEND_API_KEY` no está configurada o la petición falla, `resend_send_email()` devuelve `success => false` y la app **sigue funcionando** (solo se registra en los logs). |
| **Open-Meteo** (clima) | **Backend** (PHP) | Mostrar el clima actual en el perfil del usuario. Servicio gratuito, sin API key. | Latitud y longitud del usuario → `api.open-meteo.com` | Si el servicio no responde, `weather_get_current()` devuelve `success => false` y la app **sigue funcionando** sin el dato del clima. |
| hCaptcha | Backend + Frontend | Verificación anti-bot en formularios. | Token de challenge → `api.hcaptcha.com` | Si falla, se bloquea la creación del comentario con mensaje de error al usuario. |
| Google OAuth | Backend | Login con cuenta de Google (opcional). | Perfil de Google → `oauth2.googleapis.com` | Si falla, el usuario puede usar login con email/contraseña. |

**Decisión de diseño:** Resend y Open-Meteo se llaman desde el **backend** para no exponer claves ni datos sensibles en el frontend (cumple R7). hCaptcha se valida en ambos extremos porque el challenge se resuelve en el navegador y se verifica en el servidor.

---

## 6. Despliegue

**Proveedor elegido:** InfinityFree (hosting compartido gratuito).  
**Modelo:** PaaS/hosting compartido gestionado (no requiere administrar servidor Linux). Se eligió porque es gratuito, soporta PHP + MySQL remoto, ofrece HTTPS y es accesible desde cualquier dispositivo.

Pasos para volver a desplegar:
1. Sube los archivos del repositorio al servidor (por FTP o el administrador de archivos de InfinityFree), excepto `.env`, `*.log`, `*.sql` y la carpeta `/uploads` (están en `.gitignore`).
2. En el panel de InfinityFree, crea la base de datos MySQL y anota host, usuario, contraseña y nombre de BD.
3. Crea el archivo `.env` directamente en el servidor (no se sube desde el repositorio) con las credenciales reales y `RESEND_API_KEY`.
4. Importa `sql/schema.sql` en la base de datos remota (phpMyAdmin de InfinityFree).
5. Asigna permisos: archivos PHP `644`, directorios `755`, `.env` `600`.
6. Verifica que la URL responda por HTTPS desde una computadora y desde un celular con datos móviles (R6).
7. No borres el despliegue después de entregar: el backend se usará en el TP N.º 2 (app Android).

Para ejecutar el monitoreo de seguridad y los backups, usa `setup_security.sh` y `security_monitor.php` si tienes acceso a cron en el servidor; en hosting compartido gratuito estas tareas pueden limitarse.

---

## 7. Limitaciones conocidas

- **Hosting compartido gratuito (InfinityFree):** puede bloquear peticiones que no provengan de navegadores (por ejemplo, la app Android del TP N.º 2 si intenta consumir la API como cliente no navegador). La PWA mitiga esto al ejecutarse dentro del navegador.
- **No hay defensa oral:** la nota depende exclusivamente del entregable (URL pública, código y README).
- **Sin WebSocket persistente:** las notificaciones se actualizan mediante sondeo corto (polling) cada 8 segundos solo en pestañas visibles, ya que InfinityFree no permite procesos persistentes.
- **hCaptcha y Google OAuth requieren claves reales:** en local sin configurar, la creación de comentarios con hCaptcha y el login con Google no funcionarán; se puede probar el resto de la app sin ellas.
- **Un solo nivel de respuestas:** no se permiten respuestas a respuestas (anidación limitada a 1 nivel).

Declarar estos errores suma: un error escondido resta más (rúbrica del TP).

---

### Aportes

Trabajo individual. El integrante **Lautaro Hillkirk** creó el proyecto por sí solo; el requisito R10 (evidencia de participación por commits de todos los integrantes) **no aplica**.

---

**Última actualización:** 20 de octubre de 2026  
**Entrega:** martes 20/10/2026, hasta las 23:59 h, por el campus.
