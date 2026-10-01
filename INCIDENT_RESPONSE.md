# 🛡️ Procedimientos de Respuesta a Incidentes de Seguridad

## 1. Clasificación de Incidentes

### 🔴 Crítica (P0)
- **Tiempo de respuesta:** Inmediato (15 minutos)
- **Ejemplos:**
  - Compromiso de base de datos
  - RCE (Remote Code Execution)
  - Robo masivo de datos de usuarios
  - Brecha de seguridad activa

### 🟠 Alta (P1)
- **Tiempo de respuesta:** 1 hora
- **Ejemplos:**
  - Intento de SQL Injection exitoso
  - XSS almacenado activo
  - Cuentas de usuario comprometidas
  - Ataque de fuerza bruta en progreso

### 🟡 Media (P2)
- **Tiempo de respuesta:** 4 horas
- **Ejemplos:**
  - Intentos de acceso no autorizado
  - Actividad sospechosa en logs
  - Múltiples intentos de login fallidos
  - Patrones de tráfico anómalos

### 🟢 Baja (P3)
- **Tiempo de respuesta:** 24 horas
- **Ejemplos:**
  - Escaneo de puertos
  - Intentos de CSRF fallidos
  - Probes de vulnerabilidades
  - Actividad de bots

---

## 2. Procedimiento de Respuesta

### Fase 1: Detección y Análisis
```
1. Identificar el tipo de incidente
2. Determinar el alcance del compromiso
3. Recopilar evidencias (logs, capturas, timestamps)
4. Clasificar la severidad (P0-P3)
5. Notificar al equipo correspondiente
```

### Fase 2: Contención
```
1. Aislar sistemas afectados si es necesario
2. Bloquear IPs maliciosas
3. Deshabilitar cuentas comprometidas
4. Aplicar parches de emergencia
5. Preservar evidencias forenses
```

### Fase 3: Erradicación
```
1. Eliminar código malicioso
2. Corregir vulnerabilidades explotadas
3. Actualizar dependencias vulnerables
4. Rotar credenciales comprometidas
5. Verificar integridad del sistema
```

### Fase 4: Recuperación
```
1. Restaurar servicios afectados
2. Verificar funcionamiento normal
3. Monitorear actividad post-incidente
4. Validar seguridad de datos restaurados
5. Comunicar a usuarios afectados si es necesario
```

### Fase 5: Lecciones Aprendidas
```
1. Documentar el incidente
2. Analizar causa raíz
3. Actualizar procedimientos
4. Implementar mejoras preventivas
5. Capacitar al equipo
```

---

## 3. Contactos de Emergencia

| Rol | Nombre | Email | Teléfono |
|-----|--------|-------|----------|
| CISO | - | security@dominio.com | - |
| Admin BD | - | dba@dominio.com | - |
| DevOps | - | devops@dominio.com | - |
| Legal | - | legal@dominio.com | - |

---

## 4. Indicadores de Compromiso (IOCs)

### Comandos de Monitoreo Rápido
```bash
# Ver intentos de login fallidos
grep "login_failed" /var/log/apache2/error.log | tail -50

# Ver intentos de CSRF
grep "csrf_attempt" /var/log/apache2/error.log | tail -50

# Ver accesos no autorizados
grep "unauthorized" /var/log/apache2/error.log | tail -50

# Ver IPs sospechosas
grep "SECURITY" /var/log/apache2/error.log | awk '{print $7}' | sort | uniq -c | sort -rn | head -20

# Ver actividad de las últimas 24 horas
grep "SECURITY" /var/log/apache2/error.log | grep "$(date -d '24 hours ago' '+%Y-%m-%d')"
```

---

## 5. Playbooks Específicos

### 5.1 Ataque de Fuerza Bruta
```bash
# 1. Identificar IP atacante
grep "login_failed" /var/log/apache2/error.log | grep "IP:" | awk '{print $7}' | sort | uniq -c | sort -rn | head -5

# 2. Bloquear IP (iptables)
iptables -A INPUT -s IP_MALICIOSA -j DROP

# 3. Verificar cuentas comprometidas
# Revisar logins exitosos desde esa IP

# 4. Notificar a usuarios afectados
```

### 5.2 SQL Injection
```bash
# 1. Identificar patrón de ataque
grep "SQL" /var/log/apache2/error.log | tail -100

# 2. Revisar consultas afectadas
# Verificar logs de MySQL

# 3. Aplicar WAF temporal
# Bloquear patrones específicos

# 4. Parchear código vulnerable
# Revisar sentencias preparadas
```

### 5.3 XSS Almacenado
```bash
# 1. Identificar contenido malicioso
grep -r "script" /var/www/html/comentarios/

# 2. Eliminar contenido afectado
# Limpiar base de datos

# 3. Notificar a usuarios que vieron el contenido

# 4. Mejorar sanitización
# Revisar funciones de escape
```

### 5.4 Session Hijacking
```bash
# 1. Identificar sesiones sospechosas
grep "session_hijacking" /var/log/apache2/error.log

# 2. Invalidar sesiones afectadas
# Limpiar tabla de sesiones

# 3. Forzar re-autenticación de usuarios

# 4. Revisar logs de acceso
```

---

## 6. Checklist Post-Incidente

- [ ] Incidente documentado en su totalidad
- [ ] Causa raíz identificada
- [ ] Vulnerabilidad corregida
- [ ] Sistemas verificados y limpios
- [ ] Usuarios notificados (si aplica)
- [ ] Lecciones aprendidas documentadas
- [ ] Procedimientos actualizados
- [ ] Métricas de seguridad revisadas
- [ ] Backup de seguridad verificado
- [ ] Incidente cerrado formalmente

---

## 7. Métricas de Seguridad

| Métrica | Objetivo | Frecuencia |
|---------|----------|------------|
| Tiempo medio de detección (MTTD) | < 1 hora | Diario |
| Tiempo medio de respuesta (MTTR) | < 4 horas | Diario |
| Intentos de login fallidos | < 10/minuto | Tiempo real |
| Alertas de seguridad críticas | 0 sin atender | Tiempo real |
| Vulnerabilidades críticas abiertas | 0 | Semanal |
| Backups verificados | 100% | Diario |

---

## 8. Recursos Adicionales

- [OWASP Testing Guide](https://owasp.org/www-project-web-security-testing-guide/)
- [NIST Cybersecurity Framework](https://www.nist.gov/cyberframework)
- [CISA Incident Response](https://www.cisa.gov/incident-response)
- [SANS Incident Handler's Handbook](https://www.sans.org/white-papers/33901/)

---

**Última actualización:** 2026-10-01
**Próxima revisión:** 2027-01-01
**Responsable:** Equipo de Seguridad
