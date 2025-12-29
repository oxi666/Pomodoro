<?php
// Ajusta SOLO la contraseña ($db_pass) a la que te sale en el panel.
$db_host = "sql107.infinityfree.com";
$db_user = "if0_40606511";
$db_pass = "Mofw8UFaJQO";
$db_name = "if0_40606511_horas";

// PRIMERO: Configurar zona horaria de PHP ANTES de conectar
date_default_timezone_set('Atlantic/Canary');

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die("Error de conexión MySQL: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// SEGUNDO: Configurar zona horaria de MySQL (+0:00 para Canarias en invierno)
$mysqli->query("SET time_zone = '+00:00'");
?>
