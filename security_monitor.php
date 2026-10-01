<?php
/**
 * Script de Monitoreo de Seguridad
 * 
 * Este script debe ejecutarse periódicamente (cada 5 minutos) vía cron
 * para monitorear la seguridad de la aplicación en tiempo real.
 * 
 * Uso: php security_monitor.php
 * Cron: */5 * * * * /usr/bin/php /ruta/al/script/security_monitor.php
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// Configuración de alertas
const ALERT_EMAIL = 'security@dominio.com';
const ALERT_THRESHOLD_LOGINS = 10;        // Intentos de login fallidos por IP
const ALERT_THRESHOLD_CSRF = 5;            // Intentos de CSRF por IP
const ALERT_THRESHOLD_UNAUTHORIZED = 3;    // Intentos de acceso no autorizado
const ALERT_WINDOW_MINUTES = 5;            // Ventana de tiempo para análisis

/**
 * Obtener entradas de log de seguridad recientes
 */
function getRecentSecurityLogs(int $minutes = 5): array
{
    $logFile = ini_get('error_log');
    if (empty($logFile) || !file_exists($logFile)) {
        return [];
    }

    $logs = [];
    $cutoffTime = time() - ($minutes * 60);
    $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[SECURITY\] \[(\w+)\] \[IP: ([\d.]+)\] \[User: ([\w-]+)\] \[URI: ([^\]]+)\] \[UA: ([^\]]+)\] (.+)/';

    $handle = @fopen($logFile, 'r');
    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        if (preg_match($pattern, $line, $matches)) {
            $timestamp = strtotime($matches[1]);
            if ($timestamp >= $cutoffTime) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'event' => $matches[2],
                    'ip' => $matches[3],
                    'user' => $matches[4],
                    'uri' => $matches[5],
                    'user_agent' => $matches[6],
                    'details' => $matches[7],
                ];
            }
        }
    }
    fclose($handle);

    return $logs;
}

/**
 * Analizar logs y detectar patrones sospechosos
 */
function analyzeSecurityLogs(array $logs): array
{
    $alerts = [];
    $ipStats = [];
    $eventStats = [];

    foreach ($logs as $log) {
        $ip = $log['ip'];
        $event = $log['event'];

        // Contar eventos por IP
        if (!isset($ipStats[$ip])) {
            $ipStats[$ip] = [
                'total' => 0,
                'events' => [],
                'first_seen' => $log['timestamp'],
                'last_seen' => $log['timestamp'],
            ];
        }
        $ipStats[$ip]['total']++;
        $ipStats[$ip]['events'][$event] = ($ipStats[$ip]['events'][$event] ?? 0) + 1;
        $ipStats[$ip]['last_seen'] = $log['timestamp'];

        // Contar eventos globales
        $eventStats[$event] = ($eventStats[$event] ?? 0) + 1;
    }

    // Detectar ataques de fuerza bruta
    foreach ($ipStats as $ip => $stats) {
        $loginFailures = $stats['events']['LOGIN_FAILED'] ?? 0;
        if ($loginFailures >= ALERT_THRESHOLD_LOGINS) {
            $alerts[] = [
                'severity' => 'HIGH',
                'type' => 'BRUTE_FORCE_ATTACK',
                'ip' => $ip,
                'message' => "Posible ataque de fuerza bruta desde {$ip}: {$loginFailures} intentos de login fallidos",
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => 'Considerar bloqueo de IP',
            ];
        }

        $csrfAttempts = $stats['events']['CSRF_ATTEMPT'] ?? 0;
        if ($csrfAttempts >= ALERT_THRESHOLD_CSRF) {
            $alerts[] = [
                'severity' => 'MEDIUM',
                'type' => 'CSRF_ATTACK',
                'ip' => $ip,
                'message' => "Múltiples intentos de CSRF desde {$ip}: {$csrfAttempts} intentos",
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => 'Investigar origen de peticiones',
            ];
        }

        $unauthorized = ($stats['events']['UNAUTHORIZED_EDIT_ATTEMPT'] ?? 0) +
                       ($stats['events']['UNAUTHORIZED_DELETE_ATTEMPT'] ?? 0) +
                       ($stats['events']['UNAUTHORIZED_PROFILE_UPDATE_ATTEMPT'] ?? 0);
        if ($unauthorized >= ALERT_THRESHOLD_UNAUTHORIZED) {
            $alerts[] = [
                'severity' => 'HIGH',
                'type' => 'UNAUTHORIZED_ACCESS',
                'ip' => $ip,
                'message' => "Múltiples intentos de acceso no autorizado desde {$ip}: {$unauthorized} intentos",
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => 'Revisar logs detallados y considerar bloqueo',
            ];
        }

        $sessionHijacking = $stats['events']['SESSION_HIJACKING_ATTEMPT'] ?? 0;
        if ($sessionHijacking > 0) {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'type' => 'SESSION_HIJACKING',
                'ip' => $ip,
                'message' => "Posible session hijacking desde {$ip}: {$sessionHijacking} intentos",
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => 'Invalidar sesiones de usuario afectado inmediatamente',
            ];
        }
    }

    // Detectar patrones globales
    if (($eventStats['INVALID_ORIGIN'] ?? 0) > 20) {
        $alerts[] = [
            'severity' => 'MEDIUM',
            'type' => 'MASS_INVALID_ORIGIN',
            'message' => "Alto número de peticiones con origen inválido: " . $eventStats['INVALID_ORIGIN'],
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'Posible ataque coordinado, revisar WAF',
        ];
    }

    return $alerts;
}

/**
 * Enviar alerta por email
 */
function sendAlert(array $alert): void
{
    $subject = "[SEGURIDAD] {$alert['severity']}: {$alert['type']}";
    $message = "
ALERTA DE SEGURIDAD - Aplicación Web
=====================================

Severidad: {$alert['severity']}
Tipo: {$alert['type']}
Fecha: {$alert['timestamp']}

Mensaje:
{$alert['message']}

Acción recomendada:
{$alert['action']}

" . (isset($alert['ip']) ? "IP: {$alert['ip']}\n\n" : "\n") . "
---
Este es un mensaje automático del sistema de monitoreo de seguridad.
Por favor, no responda a este email.

";

    $headers = "From: security-monitor@{$_SERVER['HTTP_HOST']}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail(ALERT_EMAIL, $subject, $message, $headers);
}

/**
 * Registrar alerta en log
 */
function logAlert(array $alert): void
{
    $logEntry = sprintf(
        "[%s] [ALERT] [%s] [%s] %s",
        $alert['timestamp'],
        $alert['severity'],
        $alert['type'],
        $alert['message']
    );
    error_log($logEntry);
}

/**
 * Bloquear IP temporalmente (requiere iptables)
 */
function blockIp(string $ip): void
{
    // Comando para bloquear IP (requiere permisos root)
    $command = sprintf('iptables -A INPUT -s %s -j DROP 2>/dev/null', escapeshellarg($ip));
    @exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] [SECURITY] [IP_BLOCKED] IP {$ip} bloqueada temporalmente");
    }
}

/**
 * Generar reporte de estado
 */
function generateStatusReport(array $logs, array $alerts): string
{
    $report = "Reporte de Seguridad - " . date('Y-m-d H:i:s') . "\n";
    $report .= str_repeat("=", 60) . "\n\n";

    $report .= "Eventos de seguridad en los últimos " . ALERT_WINDOW_MINUTES . " minutos: " . count($logs) . "\n";
    $report .= "Alertas generadas: " . count($alerts) . "\n\n";

    if (count($alerts) > 0) {
        $report .= "ALERTAS:\n";
        $report .= str_repeat("-", 60) . "\n";
        foreach ($alerts as $alert) {
            $report .= "[{$alert['severity']}] {$alert['type']}: {$alert['message']}\n";
        }
        $report .= "\n";
    }

    // Estadísticas por tipo de evento
    $eventStats = [];
    foreach ($logs as $log) {
        $event = $log['event'];
        $eventStats[$event] = ($eventStats[$event] ?? 0) + 1;
    }

    if (count($eventStats) > 0) {
        $report .= "Estadísticas por evento:\n";
        $report .= str_repeat("-", 60) . "\n";
        arsort($eventStats);
        foreach ($eventStats as $event => $count) {
            $report .= sprintf("  %-40s: %d\n", $event, $count);
        }
    }

    return $report;
}

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

echo "Iniciando monitoreo de seguridad...\n";

// Obtener logs recientes
$logs = getRecentSecurityLogs(ALERT_WINDOW_MINUTES);
echo "Eventos encontrados: " . count($logs) . "\n";

// Analizar logs
$alerts = analyzeSecurityLogs($logs);
echo "Alertas generadas: " . count($alerts) . "\n";

// Procesar alertas
foreach ($alerts as $alert) {
    logAlert($alert);
    sendAlert($alert);

    // Bloquear IP automáticamente para alertas críticas
    if ($alert['severity'] === 'CRITICAL' && isset($alert['ip'])) {
        blockIp($alert['ip']);
    }
}

// Generar y guardar reporte
$report = generateStatusReport($logs, $alerts);
$reportFile = __DIR__ . '/logs/security_report_' . date('Y-m-d_His') . '.txt';

// Crear directorio de logs si no existe
$logDir = dirname($reportFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

file_put_contents($reportFile, $report);

echo "Reporte guardado en: {$reportFile}\n";
echo "Monitoreo completado.\n";

// Si hay alertas críticas, salir con código de error
$criticalAlerts = array_filter($alerts, fn($a) => $a['severity'] === 'CRITICAL');
if (count($criticalAlerts) > 0) {
    exit(1);
}

exit(0);
