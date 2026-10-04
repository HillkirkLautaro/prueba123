<?php
require_once __DIR__ . '/config.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Términos y Condiciones</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>Términos y Condiciones</h1>
            <p class="last-update">Última actualización: <?= date('d/m/Y') ?></p>
        </header>

        <main class="legal-content">
            <section>
                <h2>1. Aceptación de los Términos</h2>
                <p>Al acceder y utilizar <strong><?= e(APP_NAME) ?></strong> (en adelante, "el Sitio"), usted acepta estar sujeto a estos Términos y Condiciones. Si no está de acuerdo con alguna parte de estos términos, le rogamos que no utilice nuestro Sitio.</p>
            </section>

            <section>
                <h2>2. Descripción del Servicio</h2>
                <p><?= e(APP_NAME) ?> es una plataforma de discusión y comentarios que permite a los usuarios registrados publicar comentarios, responder a otros comentarios y participar en conversaciones sobre diversos temas.</p>
            </section>

            <section>
                <h2>3. Registro de Usuarios</h2>
                <p>Para acceder a ciertas funcionalidades del Sitio, los usuarios deben registrarse proporcionando información veraz y actualizada, incluyendo:</p>
                <ul>
                    <li>Nombre de usuario único</li>
                    <li>Dirección de correo electrónico válida</li>
                    <li>Contraseña segura</li>
                </ul>
                <p>El usuario es responsable de mantener la confidencialidad de sus credenciales de acceso y de todas las actividades que ocurran bajo su cuenta.</p>
            </section>

            <section>
                <h2>4. Uso Aceptable</h2>
                <p>Los usuarios se comprometen a no:</p>
                <ul>
                    <li>Publicar contenido ilegal, difamatorio, ofensivo, discriminatorio o que infrinja derechos de terceros</li>
                    <li>Suplantar la identidad de otra persona o entidad</li>
                    <li>Publicar spam, publicidad no solicitada o contenido comercial sin autorización</li>
                    <li>Utilizar el Sitio para distribuir malware, virus o cualquier código malicioso</li>
                    <li>Intentar acceder no autorizado a sistemas o cuentas de otros usuarios</li>
                    <li>Recopilar información personal de otros usuarios sin su consentimiento</li>
                    <li>Utilizar bots, scrapers o herramientas automatizadas sin autorización expresa</li>
                    <li>Interferir o interrumpir el funcionamiento del Sitio</li>
                </ul>
            </section>

            <section>
                <h2>5. Contenido del Usuario</h2>
                <p>El usuario conserva la propiedad del contenido que publica en el Sitio. Sin embargo, al publicar contenido, otorga a <?= e(APP_NAME) ?> una licencia no exclusiva, mundial y libre de regalías para mostrar, reproducir y distribuir dicho contenido en el marco del funcionamiento del Sitio.</p>
                <p>El usuario es el único responsable del contenido que publica y garantiza que posee los derechos necesarios para compartir dicho contenido.</p>
            </section>

            <section>
                <h2>6. Moderación</h2>
                <p>Nos reservamos el derecho de moderar, editar o eliminar cualquier contenido que considere inapropiado, ofensivo o que viole estos Términos y Condiciones, sin previo aviso y a nuestra entera discreción.</p>
                <p>Los usuarios pueden reportar contenido inapropiado utilizando los mecanismos disponibles en el Sitio.</p>
            </section>

            <section>
                <h2>7. Propiedad Intelectual</h2>
                <p>Todo el contenido del Sitio, incluyendo pero no limitado a textos, gráficos, logotipos, imágenes, software y diseño, es propiedad de <?= e(APP_NAME) ?> o de sus licenciantes y está protegido por las leyes de propiedad intelectual aplicables.</p>
            </section>

            <section>
                <h2>8. Limitación de Responsabilidad</h2>
                <p>El Sitio se proporciona "tal cual" y "según disponibilidad". No garantizamos que el servicio sea ininterrumpido, seguro o libre de errores. En la máxima medida permitida por la ley, <?= e(APP_NAME) ?> no será responsable de daños directos, indirectos, incidentales o consecuentes derivados del uso o la imposibilidad de uso del Sitio.</p>
            </section>

            <section>
                <h2>9. Modificaciones</h2>
                <p>Nos reservamos el derecho de modificar estos Términos y Condiciones en cualquier momento. Los cambios serán efectivos inmediatamente después de su publicación en el Sitio. El uso continuado del Sitio después de dichos cambios constituye su aceptación de los nuevos términos.</p>
            </section>

            <section>
                <h2>10. Terminación</h2>
                <p>Podemos suspender o terminar su acceso al Sitio en cualquier momento, sin previo aviso, por conducta que consideremos que viola estos Términos y Condiciones o es perjudicial para otros usuarios o para nosotros.</p>
            </section>

            <section>
                <h2>11. Ley Aplicable</h2>
                <p>Estos Términos y Condiciones se regirán e interpretarán de acuerdo con las leyes de la República Argentina. Cualquier disputa relacionada con estos términos será sometida a la jurisdicción de los tribunales competentes.</p>
            </section>

            <section>
                <h2>12. Contacto</h2>
                <p>Si tiene preguntas sobre estos Términos y Condiciones, puede contactarnos a través de <a href="mailto:legal@dominio.com">legal@dominio.com</a>.</p>
            </section>
        </main>

        <footer class="page-footer">
            <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Todos los derechos reservados.</p>
            <p><a href="index.php">Volver al inicio</a> | <a href="privacidad.php">Política de Privacidad</a></p>
        </footer>
    </div>
</body>
</html>
