<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/vendor/autoload.php';

use App\CommandLibrary;
use App\Database;

$config = require APP_ROOT . '/config/config.php';
$db = Database::connect($config['db']);
$commands = new CommandLibrary($db);

$items = [
    ['Ver progreso update cPanel (ultimo log)', 'LATEST=$(ls -t /var/cpanel/updatelogs/update.*.log 2>/dev/null | head -n1); if [ -n "$LATEST" ]; then echo "Log: $LATEST"; tail -n 160 "$LATEST"; else echo "No se encontraron logs de update en /var/cpanel/updatelogs"; fi', 'cPanel', 'cPanel', 'Muestra el ultimo log de actualizacion de cPanel para retomar el seguimiento si upcp ya estaba en ejecucion.', ['cpanel','upcp','progreso','update','log']],
    ['Seguir update cPanel en vivo', 'LATEST=$(ls -t /var/cpanel/updatelogs/update.*.log 2>/dev/null | head -n1); if [ -n "$LATEST" ]; then echo "Siguiendo: $LATEST"; tail -n 80 -f "$LATEST"; else echo "No se encontraron logs de update en /var/cpanel/updatelogs"; fi', 'cPanel', 'cPanel', 'Sigue en vivo el ultimo log de update de cPanel. Detenlo cerrando la ejecucion si ya no necesitas verlo.', ['cpanel','upcp','tail','follow','progreso']],
    ['Ver procesos de update cPanel', "ps -eo pid,ppid,etime,stat,cmd | grep -E 'upcp|cpanel/scripts/(maintenance|update|sysup|find_outdated_services|update-packages)|check_cpanel_rpms' | grep -v grep", 'cPanel', 'cPanel', 'Lista procesos relacionados con actualizaciones de cPanel/WHM para saber si siguen corriendo.', ['cpanel','upcp','procesos','update']],
    ['Ver version de cPanel', 'cat /usr/local/cpanel/version 2>/dev/null; /usr/local/cpanel/bin/whmapi1 version 2>/dev/null | head -n 30', 'cPanel', 'cPanel', 'Muestra la version instalada de cPanel/WHM.', ['cpanel','whm','version']],
    ['Ver locks de version cPanel/YUM', 'yum versionlock status 2>/dev/null || dnf versionlock list 2>/dev/null || echo "versionlock no disponible"', 'cPanel', 'cPanel', 'Revisa paquetes bloqueados por versionlock que pueden afectar updates.', ['cpanel','yum','dnf','versionlock']],
    ['Actualizar cPanel/WHM', '/scripts/upcp', 'Actualizaciones', 'cPanel', 'Ejecuta actualizacion de cPanel/WHM. Si ya hay una en curso, usa los comandos de progreso.', ['cpanel','whm','upcp','update']],

    ['Ver actualizaciones disponibles (DNF)', 'dnf check-update', 'Actualizaciones', 'AlmaLinux', 'Lista paquetes disponibles para actualizar en sistemas DNF.', ['dnf','almalinux','rocky','update']],
    ['Instalar actualizaciones (DNF)', 'dnf update -y', 'Actualizaciones', 'AlmaLinux', 'Instala actualizaciones del sistema usando DNF.', ['dnf','almalinux','rocky','update']],
    ['Historial de actualizaciones DNF/YUM', 'dnf history list 2>/dev/null || yum history list', 'Actualizaciones', 'General', 'Muestra el historial reciente de transacciones de paquetes.', ['dnf','yum','historial','update']],
    ['Servicios que requieren reinicio', 'needs-restarting -s 2>/dev/null || needs-restarting -r 2>/dev/null || echo "Instala yum-utils/dnf-utils para needs-restarting"', 'Actualizaciones', 'General', 'Detecta servicios o reinicio del sistema pendiente despues de actualizar.', ['reboot','servicios','update','needs-restarting']],

    ['Resumen de disco', 'df -hT', 'Disco', 'General', 'Uso de disco por filesystem con tipo de sistema de archivos.', ['disco','df','espacio']],
    ['Inodos por filesystem', 'df -ih', 'Disco', 'General', 'Uso de inodos; util cuando hay errores de disco lleno aunque haya GB libres.', ['disco','inodos','df']],
    ['Directorios mas pesados en /', 'du -xhd1 / 2>/dev/null | sort -hr | head -n 25', 'Disco', 'General', 'Top de directorios pesados sin cruzar otros filesystems.', ['disco','du','peso']],
    ['Directorios mas pesados en /home', 'du -xhd1 /home 2>/dev/null | sort -hr | head -n 25', 'Disco', 'General', 'Top de consumo dentro de /home.', ['disco','home','du']],
    ['Archivos mayores a 1GB', 'find / -xdev -type f -size +1G -printf "%s %p\\n" 2>/dev/null | sort -nr | head -n 30 | awk \'{printf "%.2f GB  %s\\n", $1/1024/1024/1024, $2}\'', 'Disco', 'General', 'Encuentra archivos grandes en el filesystem raiz.', ['disco','find','archivos grandes']],
    ['Estado SMART rapido', 'for d in /dev/sd? /dev/nvme?n?; do [ -e "$d" ] && echo "=== $d ===" && smartctl -H "$d" 2>/dev/null; done', 'Disco', 'General', 'Salud SMART rapida de discos si smartmontools esta instalado.', ['disco','smart','salud']],

    ['Servicios fallidos', 'systemctl --failed --no-pager', 'Servicios', 'General', 'Lista unidades systemd en estado fallido.', ['systemctl','failed','servicios']],
    ['Servicios activos principales', 'systemctl list-units --type=service --state=running --no-pager | head -n 80', 'Servicios', 'General', 'Muestra servicios activos principales.', ['systemctl','servicios','running']],
    ['Reinicios recientes del sistema', 'last -x reboot shutdown | head -n 20', 'Servicios', 'General', 'Historial de reinicios y apagados recientes.', ['reboot','shutdown','historial']],

    ['Carga CPU y memoria', 'uptime; echo; free -h; echo; top -bn1 | head -n 25', 'Performance', 'General', 'Resumen rapido de carga, RAM y procesos principales.', ['cpu','ram','top','load']],
    ['Procesos por memoria', 'ps aux --sort=-%mem | head -n 20', 'Procesos', 'General', 'Procesos que mas memoria consumen.', ['procesos','ram','memoria']],
    ['Procesos por CPU', 'ps aux --sort=-%cpu | head -n 20', 'Procesos', 'General', 'Procesos que mas CPU consumen.', ['procesos','cpu']],
    ['IO de disco por proceso', 'iotop -b -n 3 -o 2>/dev/null || pidstat -d 1 5 2>/dev/null || echo "Instala iotop o sysstat para ver IO por proceso"', 'Performance', 'General', 'Ayuda a detectar procesos generando IO alto.', ['io','disco','performance']],

    ['Puertos escuchando', 'ss -tulpen', 'Red', 'General', 'Puertos TCP/UDP escuchando con proceso asociado.', ['red','puertos','ss']],
    ['Conexiones establecidas por IP', 'ss -tan state established | awk \'{print $5}\' | cut -d: -f1 | sort | uniq -c | sort -nr | head -n 25', 'Red', 'General', 'Top de IPs con conexiones establecidas.', ['red','conexiones','ips']],
    ['IP publica y rutas', 'curl -s ifconfig.me; echo; ip route; echo; ip addr show | grep -E "^[0-9]+:|inet "', 'Red', 'General', 'IP publica, rutas y direcciones locales.', ['red','ip','rutas']],

    ['Ultimos accesos SSH fallidos', 'grep -h "Failed password" /var/log/secure /var/log/auth.log 2>/dev/null | tail -n 80', 'Seguridad', 'General', 'Ultimos intentos SSH fallidos en logs comunes.', ['ssh','seguridad','failed','logs']],
    ['Ultimos accesos SSH exitosos', 'grep -h "Accepted " /var/log/secure /var/log/auth.log 2>/dev/null | tail -n 80', 'Seguridad', 'General', 'Ultimos logins SSH aceptados.', ['ssh','seguridad','accepted','logs']],
    ['Usuarios conectados', 'who; echo; w', 'Seguridad', 'General', 'Usuarios actualmente conectados y actividad.', ['usuarios','sesiones','who','w']],

    ['Journal errores recientes', 'journalctl -p err -n 120 --no-pager', 'Logs', 'General', 'Errores recientes del journal de systemd.', ['journalctl','logs','errores']],
    ['Log del sistema reciente', 'tail -n 160 /var/log/messages 2>/dev/null || journalctl -n 160 --no-pager', 'Logs', 'General', 'Ultimos eventos generales del sistema.', ['logs','messages','journalctl']],
    ['Log errores cPanel', 'tail -n 160 /usr/local/cpanel/logs/error_log', 'Logs', 'cPanel', 'Ultimas lineas del log de errores de cPanel.', ['cpanel','logs','errores']],
    ['Exim cola y resumen', 'exim -bpc 2>/dev/null; exim -bp 2>/dev/null | head -n 80', 'Logs', 'cPanel', 'Revisa tamano y primeros mensajes de cola de correo Exim.', ['cpanel','exim','mail','cola']],

    ['Backups cPanel recientes', 'find /backup /home/backup -maxdepth 3 -type f \\( -name "*.tar.gz" -o -name "*.tar" -o -name "*.gz" \\) -printf "%TY-%Tm-%Td %TH:%TM %s %p\\n" 2>/dev/null | sort -r | head -n 50', 'Backups', 'cPanel', 'Lista backups recientes en rutas comunes.', ['backup','cpanel','archivos']],
    ['Estado de MySQL/MariaDB', 'systemctl status mariadb mysql --no-pager 2>/dev/null; mysqladmin status 2>/dev/null', 'Base de Datos', 'General', 'Estado rapido del servicio y mysqladmin.', ['mysql','mariadb','status']],
    ['Procesos MySQL activos', 'mysqladmin processlist 2>/dev/null || mysql -e "SHOW FULL PROCESSLIST" 2>/dev/null', 'Base de Datos', 'General', 'Muestra consultas activas si el usuario local tiene acceso.', ['mysql','mariadb','processlist']],
];

$byTitle = [];
foreach ($commands->getAll() as $row) {
    $byTitle[$row['title']] = (int)$row['id'];
}

$created = 0;
$updated = 0;

foreach ($items as [$title, $command, $category, $osTarget, $description, $tags]) {
    $categoryId = $commands->ensureCategory($category);
    $osTargetId = $commands->ensureOsTarget($osTarget);
    $tagIds = array_map(fn(string $tag): int => $commands->ensureTag($tag), $tags);

    if (isset($byTitle[$title])) {
        $commands->update($byTitle[$title], $title, $command, $categoryId, $osTargetId, $description, $tagIds);
        $updated++;
    } else {
        $commands->create($title, $command, $categoryId, $osTargetId, $description, $tagIds);
        $created++;
    }
}

echo "Comandos operativos creados: {$created}\n";
echo "Comandos operativos actualizados: {$updated}\n";
