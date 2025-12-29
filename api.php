<?php
// api.php - API para el sistema de registro de horas

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Fechas límite del proyecto
$fecha_inicio = '2025-12-04';
$fecha_fin    = '2026-01-16';

switch ($action) {
    case 'get_usuarios':
        getUsuarios();
        break;
    case 'iniciar_sesion':
        iniciarSesion();
        break;
    case 'renovar_sesion':
        renovarSesion();
        break;
    case 'detener_sesion':
        detenerSesion();
        break;
    case 'get_estado':
        getEstado();
        break;
    case 'get_estadisticas':
        getEstadisticas();
        break;
    case 'get_historial':
        getHistorial();
        break;
        case 'geteventos':
    getEventos();
    break;
case 'addevento':
    addEvento();
    break;
case 'editevento':
    editEvento();
    break;
case 'deleteevento':
    deleteEvento();
    break;

    default:
        echo json_encode(['error' => 'Acción no válida']);
}

function getUsuarios() {
    global $mysqli;
    $result = $mysqli->query("SELECT id, nombre FROM usuarios ORDER BY id");
    $usuarios = [];
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
    echo json_encode(['usuarios' => $usuarios]);
}

function verificarYActualizarRachaMadrugador($usuario_id, $hora_inicio, $fecha_hoy) {
    global $mysqli;
    
    // Extraer la hora de inicio (formato HH:MM:SS)
    $hora_parts = explode(' ', $hora_inicio);
    $solo_hora = $hora_parts[1] ?? '00:00:00';
    
    // Verificar si es antes de las 05:30:00
    $es_madrugador = ($solo_hora < '05:30:00');
    
    if (!$es_madrugador) {
        return; // No actualizar nada si no es madrugador
    }
    
    // Obtener datos actuales del usuario
    $stmt = $mysqli->prepare("SELECT racha_madrugador, mejor_racha_madrugador, ultima_fecha_madrugador FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    
    $racha_actual = $usuario['racha_madrugador'] ?? 0;
    $mejor_racha = $usuario['mejor_racha_madrugador'] ?? 0;
    $ultima_fecha = $usuario['ultima_fecha_madrugador'];
    
    // Calcular si es día consecutivo
    $fecha_hoy_obj = new DateTime($fecha_hoy);
    
    if ($ultima_fecha) {
        $ultima_fecha_obj = new DateTime($ultima_fecha);
        $diff = $fecha_hoy_obj->diff($ultima_fecha_obj)->days;
        
        if ($diff == 0) {
            // Mismo día, no hacer nada (ya contabilizado)
            return;
        } elseif ($diff == 1) {
            // Día consecutivo, incrementar racha
            $racha_actual++;
        } else {
            // Se rompió la racha, reiniciar
            $racha_actual = 1;
        }
    } else {
        // Primera vez madrugador
        $racha_actual = 1;
    }
    
    // Actualizar mejor racha si es necesario
    if ($racha_actual > $mejor_racha) {
        $mejor_racha = $racha_actual;
    }
    
    // Guardar en la base de datos
    $stmt = $mysqli->prepare("UPDATE usuarios SET racha_madrugador = ?, mejor_racha_madrugador = ?, ultima_fecha_madrugador = ? WHERE id = ?");
    $stmt->bind_param("iisi", $racha_actual, $mejor_racha, $fecha_hoy, $usuario_id);
  $stmt->execute();
}

function iniciarSesion() {
    global $mysqli, $fecha_inicio, $fecha_fin;

    $usuario_id = intval($_POST['usuario_id'] ?? 0);
    if ($usuario_id <= 0) {
        echo json_encode(['error' => 'Usuario no válido']);
        return;
    }

    $ahora      = date('Y-m-d H:i:s');
    $fecha_hoy  = date('Y-m-d');

    // Verificar fechas límite
    if ($fecha_hoy < $fecha_inicio || $fecha_hoy > $fecha_fin) {
        echo json_encode(['error' => 'Fuera del período de registro (6 dic - 16 ene)']);
        return;
    }

    // Cerrar sesiones activas anteriores
    cerrarSesionesActivas($usuario_id);

    // Crear nueva sesión
    $stmt = $mysqli->prepare("INSERT INTO sesiones (usuario_id, fecha, hora_inicio, activa) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("iss", $usuario_id, $fecha_hoy, $ahora);
    $stmt->execute();
    $sesion_id = $mysqli->insert_id;

verificarYActualizarRachaMadrugador($usuario_id, $ahora, $fecha_hoy);

    // Actualizar última activación en resumen
    actualizarUltimaActivacion($usuario_id, $fecha_hoy, $ahora);

    echo json_encode([
        'success'     => true,
        'sesion_id'   => $sesion_id,
        'hora_inicio' => $ahora,
        'mensaje'     => 'Sesión iniciada. Recuerda renovar cada 50 minutos.'
    ]);
}

function renovarSesion() {
    global $mysqli;

    $usuario_id = intval($_POST['usuario_id'] ?? 0);
    if ($usuario_id <= 0) {
        echo json_encode(['error' => 'Usuario no válido']);
        return;
    }

    $ahora      = date('Y-m-d H:i:s');
    $fecha_hoy  = date('Y-m-d');

    // Buscar sesión activa
    $stmt = $mysqli->prepare("SELECT id, hora_inicio FROM sesiones WHERE usuario_id = ? AND activa = 1 ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        echo json_encode(['error' => 'No hay sesión activa. Inicia una nueva.']);
        return;
    }

    $sesion  = $result->fetch_assoc();
    $inicio  = new DateTime($sesion['hora_inicio']);
    $fin     = new DateTime($ahora);
    $diff    = $inicio->diff($fin);
    $minutos = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

    // Cerrar sesión actual
    $stmt = $mysqli->prepare("UPDATE sesiones SET hora_fin = ?, minutos = ?, activa = 0 WHERE id = ?");
    $stmt->bind_param("sii", $ahora, $minutos, $sesion['id']);
    $stmt->execute();

    // Crear nueva sesión
    $stmt = $mysqli->prepare("INSERT INTO sesiones (usuario_id, fecha, hora_inicio, activa) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("iss", $usuario_id, $fecha_hoy, $ahora);
    $stmt->execute();
    $nueva_sesion_id = $mysqli->insert_id;

    // Actualizar resumen diario
    actualizarResumenDiario($usuario_id, $fecha_hoy);
    actualizarUltimaActivacion($usuario_id, $fecha_hoy, $ahora);

    echo json_encode([
        'success'            => true,
        'sesion_id'          => $nueva_sesion_id,
        'minutos_registrados'=> $minutos,
        'hora_renovacion'    => $ahora,
        'mensaje'            => 'Sesión renovada. +' . $minutos . ' minutos registrados.'
    ]);
}

function detenerSesion() {
    global $mysqli;

    $usuario_id = intval($_POST['usuario_id'] ?? 0);
    if ($usuario_id <= 0) {
        echo json_encode(['error' => 'Usuario no válido']);
        return;
    }

    $ahora      = date('Y-m-d H:i:s');
    $fecha_hoy  = date('Y-m-d');

    // Cerrar sesiones activas y registrar tiempo
    $minutos_cerrados = cerrarSesionesActivas($usuario_id);

    // Actualizar resumen
    actualizarResumenDiario($usuario_id, $fecha_hoy);

    echo json_encode([
        'success'            => true,
        'minutos_registrados'=> $minutos_cerrados,
        'mensaje'            => 'Sesión detenida. ' . $minutos_cerrados . ' minutos registrados.'
    ]);
}

function cerrarSesionesActivas($usuario_id) {
    global $mysqli;

    $ahora         = date('Y-m-d H:i:s');
    $total_minutos = 0;

    $stmt = $mysqli->prepare("SELECT id, hora_inicio FROM sesiones WHERE usuario_id = ? AND activa = 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($sesion = $result->fetch_assoc()) {
        $inicio  = new DateTime($sesion['hora_inicio']);
        $fin     = new DateTime($ahora);
        $diff    = $inicio->diff($fin);
        $minutos = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

        // Máximo 25 minutos por sesión (margen de seguridad)
        if ($minutos > 55) $minutos = 55;

        $stmt2 = $mysqli->prepare("UPDATE sesiones SET hora_fin = ?, minutos = ?, activa = 0 WHERE id = ?");
        $stmt2->bind_param("sii", $ahora, $minutos, $sesion['id']);
        $stmt2->execute();

        $total_minutos += $minutos;
    }

    return $total_minutos;
}

function actualizarResumenDiario($usuario_id, $fecha) {
    global $mysqli;

    // Total minutos del día
    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(minutos), 0) as total FROM sesiones WHERE usuario_id = ? AND fecha = ? AND activa = 0");
    $stmt->bind_param("is", $usuario_id, $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $minutos_dia = $row['total'];
    $horas_dia = round($minutos_dia / 60, 2);

    // Acumulado total
    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(minutos), 0) as total FROM sesiones WHERE usuario_id = ? AND activa = 0");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $minutos_acum = $row['total'];
    $horas_acumuladas = round($minutos_acum / 60, 2);

    // Insertar o actualizar resumen diario
    $stmt = $mysqli->prepare(
        "INSERT INTO resumen_diario (usuario_id, fecha, horas_dia, horas_acumuladas)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE horas_dia = ?, horas_acumuladas = ?"
    );
    $stmt->bind_param("isdddd", $usuario_id, $fecha, $horas_dia, $horas_acumuladas, $horas_dia, $horas_acumuladas);
    $stmt->execute();

    // ========== NUEVA LÓGICA: ACTUALIZAR RÉCORD Y DÍAS ACTIVOS ==========
    
    // 1. Actualizar récord de horas en un día
    $stmt = $mysqli->prepare("SELECT record_horas_dia FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $record_actual = $usuario['record_horas_dia'] ?? 0;

    if ($horas_dia > $record_actual) {
        $stmt = $mysqli->prepare("UPDATE usuarios SET record_horas_dia = ? WHERE id = ?");
        $stmt->bind_param("di", $horas_dia, $usuario_id);
        $stmt->execute();
    }

    // 2. Calcular días activos (días con sesiones registradas)
    $stmt = $mysqli->prepare("SELECT COUNT(DISTINCT fecha) as dias FROM sesiones WHERE usuario_id = ? AND activa = 0 AND minutos > 0");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $dias_activos = $row['dias'];

    $stmt = $mysqli->prepare("UPDATE usuarios SET dias_activos = ? WHERE id = ?");
    $stmt->bind_param("ii", $dias_activos, $usuario_id);
    $stmt->execute();
}


function actualizarUltimaActivacion($usuario_id, $fecha, $hora) {
    global $mysqli;

    $stmt = $mysqli->prepare(
        "INSERT INTO resumen_diario (usuario_id, fecha, ultima_activacion)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE ultima_activacion = ?"
    );
    $stmt->bind_param("isss", $usuario_id, $fecha, $hora, $hora);
    $stmt->execute();
}

function getEstado() {
    global $mysqli;

    $usuario_id = intval($_GET['usuario_id'] ?? 0);
    if ($usuario_id <= 0) {
        echo json_encode(['error' => 'Usuario no válido']);
        return;
    }

    // Buscar sesión activa
    $stmt = $mysqli->prepare("SELECT id, hora_inicio FROM sesiones WHERE usuario_id = ? AND activa = 1 ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $sesion_activa = null;

    if ($result->num_rows > 0) {
        $sesion = $result->fetch_assoc();
        $inicio = new DateTime($sesion['hora_inicio']);
        $ahora = new DateTime();
        $diff = $ahora->getTimestamp() - $inicio->getTimestamp();
        $seg_tr = $diff;
        $restante= max(0, (50 * 60) - $seg_tr);

        $sesion_activa = [
            'id' => $sesion['id'],
            'hora_inicio' => $sesion['hora_inicio'],
            'segundos_transcurridos' => $seg_tr,
            'tiempo_restante' => $restante
        ];
    }

    // Resumen de hoy
    $fecha_hoy = date('Y-m-d');
    $stmt = $mysqli->prepare("SELECT horas_dia, horas_acumuladas, ultima_activacion FROM resumen_diario WHERE usuario_id = ? AND fecha = ?");
    $stmt->bind_param("is", $usuario_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();

    $resumen = ['horas_dia' => 0, 'horas_acumuladas' => 0, 'ultima_activacion' => null];
    if ($result->num_rows > 0) {
        $resumen = $result->fetch_assoc();
    }

    // ========== NUEVA PARTE: RÉCORD Y DÍAS ACTIVOS ==========
    $stmt = $mysqli->prepare("SELECT record_horas_dia, dias_activos FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    $resumen['record_horas_dia'] = $stats['record_horas_dia'] ?? 0;
    $resumen['dias_activos'] = $stats['dias_activos'] ?? 0;
    // =======================================================

// ========== RÉCORD, DÍAS Y RACHA MADRUGADOR ==========
$stmt = $mysqli->prepare("SELECT record_horas_dia, dias_activos, racha_madrugador, mejor_racha_madrugador FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();

$resumen['record_horas_dia'] = $stats['record_horas_dia'] ?? 0;
$resumen['dias_activos'] = $stats['dias_activos'] ?? 0;
$resumen['racha_madrugador'] = $stats['racha_madrugador'] ?? 0;
$resumen['mejor_racha_madrugador'] = $stats['mejor_racha_madrugador'] ?? 0;
// ====================================================


    echo json_encode([
        'sesion_activa' => $sesion_activa,
        'resumen' => $resumen
    ]);
}


function getEstadisticas() {
    global $mysqli;

    $estadisticas = [];

    $query = "SELECT u.id, u.nombre,
              COALESCE(SUM(s.minutos), 0) as minutos_totales,
              ROUND(COALESCE(SUM(s.minutos), 0) / 60, 2) as horas_totales
              FROM usuarios u
              LEFT JOIN sesiones s ON u.id = s.usuario_id AND s.activa = 0
              GROUP BY u.id, u.nombre
              ORDER BY horas_totales DESC";

    $result = $mysqli->query($query);
    while ($row = $result->fetch_assoc()) {
        $estadisticas[] = $row;
    }

    echo json_encode(['estadisticas' => $estadisticas]);
}

function getHistorial() {
    global $mysqli;

    $usuario_id = intval($_GET['usuario_id'] ?? 0);
    $historial  = [];

    if ($usuario_id > 0) {
        $stmt = $mysqli->prepare("SELECT fecha, hora_inicio, hora_fin, minutos FROM sesiones WHERE usuario_id = ? AND activa = 0 ORDER BY hora_inicio DESC LIMIT 50");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $mysqli->query("SELECT s.*, u.nombre FROM sesiones s JOIN usuarios u ON s.usuario_id = u.id WHERE s.activa = 0 ORDER BY s.hora_inicio DESC LIMIT 100");
    }

    while ($row = $result->fetch_assoc()) {
        $historial[] = $row;
    }

    echo json_encode(['historial' => $historial]);
}

function getEventos() {
    global $mysqli;
    $eventos = [];

    $query = "SELECT id, titulo, descripcion, fecha, hora
              FROM eventos
              WHERE fecha BETWEEN '2025-12-01' AND '2026-06-30'
              ORDER BY fecha, hora";
    $result = $mysqli->query($query);

    while ($row = $result->fetch_assoc()) {
        $eventos[] = $row;
    }
    echo json_encode(['eventos' => $eventos]);
}

function addEvento() {
    global $mysqli;

    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha = $_POST['fecha'] ?? '';
    $hora  = $_POST['hora']  ?? null;

    if ($titulo === '' || $fecha === '') {
        echo json_encode(['error' => 'Título y fecha son obligatorios']);
        return;
    }

    $stmt = $mysqli->prepare(
        "INSERT INTO eventos (titulo, descripcion, fecha, hora)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("ssss", $titulo, $descripcion, $fecha, $hora);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'id'      => $stmt->insert_id,
        'mensaje' => 'Evento creado correctamente'
    ]);
}

function editEvento() {
    global $mysqli;

    $id    = intval($_POST['id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha = $_POST['fecha'] ?? '';
    $hora  = $_POST['hora']  ?? null;

    if ($id <= 0) {
        echo json_encode(['error' => 'ID de evento no válido']);
        return;
    }

    $stmt = $mysqli->prepare(
        "UPDATE eventos
         SET titulo = ?, descripcion = ?, fecha = ?, hora = ?
         WHERE id = ?"
    );
    $stmt->bind_param("ssssi", $titulo, $descripcion, $fecha, $hora, $id);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'mensaje' => 'Evento actualizado'
    ]);
}

function deleteEvento() {
    global $mysqli;

    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['error' => 'ID de evento no válido']);
        return;
    }

    $stmt = $mysqli->prepare("DELETE FROM eventos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'mensaje' => 'Evento eliminado'
    ]);
}


$mysqli->close();
?>
