// Cliente API REST para comentarios (fetch, sin recargar la página)
// Cumple el requisito R3 del TP Integrador N.º 1.
// Ruta relativa para que también funcione si el proyecto está dentro de una
// subcarpeta (por ejemplo, /prueba123/ en XAMPP).
const API_BASE = 'api/comentarios.php';

/**
 * Petición genérica a la API REST.
 * @param {string} path - ruta relativa o absoluta
 * @param {string} method - GET, POST, PUT, DELETE
 * @param {object|null} body - cuerpo JSON
 * @returns {Promise<object>} cuerpo JSON decodificado
 */
async function fetchJson(path, method, body = null) {
    const url = path.startsWith('http') ? path : API_BASE + path;
    const opts = {
        method,
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    };
    if (body !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const response = await fetch(url, opts);
    let data;
    try {
        data = await response.json();
    } catch (_) {
        data = {};
    }
    if (!response.ok) {
        throw new Error(data.error || 'Error ' + response.status);
    }
    return data;
}

/**
 * Listar comentarios (GET). Retorna { success, data: [], page, per_page }.
 */
async function listComments(page = 1) {
    return fetchJson('?page=' + encodeURIComponent(page), 'GET');
}

/**
 * Obtener un comentario por id (GET). Retorna { success, data }.
 * Lanza error 404 si no existe.
 */
async function getComment(id) {
    return fetchJson('?id=' + encodeURIComponent(id), 'GET');
}

/**
 * Crear un comentario (POST). Retorna { success, message, data: { id } }.
 * Lanza error 401/422/500 según corresponda.
 * Protección anti-bot simple: honeypot (campo oculto) del lado del servidor.
 */
async function createComment(contenido, csrfToken) {
    return fetchJson('', 'POST', { contenido, csrf_token: csrfToken });
}

/**
 * Modificar un comentario (PUT). Requiere ser el autor.
 */
async function updateComment(id, contenido, csrfToken) {
    return fetchJson('?id=' + encodeURIComponent(id), 'PUT', { contenido, csrf_token: csrfToken });
}

/**
 * Eliminar un comentario (DELETE). Requiere ser el autor.
 */
async function deleteComment(id, csrfToken) {
    return fetchJson('?id=' + encodeURIComponent(id), 'DELETE', { csrf_token: csrfToken });
}

/**
 * Mostrar un mensaje flash temporal en la página.
 */
function showFlash(message, type = 'success') {
    const container = document.getElementById('js-flash');
    if (!container) return;
    container.textContent = message;
    container.className = 'flash ' + type;
    container.hidden = false;
    setTimeout(() => { container.hidden = true; }, 4000);
}
