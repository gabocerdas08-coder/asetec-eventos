<?php
/**
 * Plugin Name: ASETEC Eventos
 * Description: Control de entradas con PDF+QR, check-in, modo offline y sorteos para ASETEC.
 * Version: 0.0.6
 * Author: ASETEC TI
 */

if (!defined('ABSPATH')) exit;

/**
 * CARGA DE CLASES DEL PLUGIN
 * - Core (activador y modelo de eventos)
 * - Admin (menú y pantallas)
 */
require_once __DIR__ . '/includes/core/class-activator.php';
require_once __DIR__ . '/includes/core/class-events.php';
require_once __DIR__ . '/admin/class-admin-menu.php';

/**
 * HOOKS DE ACTIVACIÓN
 * - Crea/actualiza tablas (dbDelta)
 */
register_activation_hook(__FILE__, ['Asetec_Activator', 'activate']);

/**
 * PUNTO DE ENTRADA
 * - Aquí podríamos inicializar otros módulos (API REST, servicios, etc.)
 */
add_action('plugins_loaded', function () {
  // Lugar para inicializar futuros módulos (REST API, servicios, etc.)
});
