<?php
/**
 * Plugin Name: ASETEC Eventos
 * Description: Control de entradas con PDF+QR, check-in y sorteos (esqueleto).
 * Version: 0.0.1
 * Author: ASETEC TI
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function(){
  add_menu_page(
    'ASETEC Eventos', 'ASETEC Eventos',
    'manage_options', 'asetec-eventos',
    function(){
      echo '<div class="wrap"><h1>ASETEC Eventos</h1><p>Plugin activo. Aquí iremos agregando módulos.</p></div>';
    },
    'dashicons-tickets', 26
  );
});

