<?php
if (!defined('ABSPATH')) exit;

/**
 * Endpoint REST para recibir envíos de Zoho Forms (o cualquier form)
 * Ruta: POST /wp-json/asetec/v1/zoho-hook
 * Seguridad: token por query (?token=XYZ) o header "X-Asetec-Token: XYZ"
 *
 * Payload esperado (JSON):
 * {
 *   "event_code": "PA2025",
 *   ...campos variables según mapeo...
 * }
 *
 * Mapeo por evento (wp_options, key: "asetec_formmap_{event_code}"):
 * [
 *   "cedula_field"   => "Cedula",
 *   "nombre_field"   => "Nombre",
 *   "apellido1_field"=> "Primer Apellido",
 *   "apellido2_field"=> "Segundo Apellido",
 *   "email_field"    => "Email",
 *   "capacity_mode"  => "fixed" | "from_field",
 *   "capacity_field" => "CantidadEntradas",
 *   "extras_fields"  => ["AceptaTerminos","OpcionQuincenas", ...]
 * ]
 *
 * Si no hay mapeo configurado: usa defaults:
 *  cedula, nombre, apellido1, apellido2, email, total_personas
 */
class Asetec_Zoho_Hook {

  public function __construct() {
    add_action('rest_api_init', array($this, 'register_routes'));
  }

  public function register_routes() {
    register_rest_route('asetec/v1', '/zoho-hook', array(
      'methods'  => 'POST',
      'callback' => array($this, 'handle'),
      'permission_callback' => '__return_true',
    ));
  }

  private function get_secret_token() {
    // 1) Desde wp-config.php (constante), o
    if (defined('ASETEC_ZOHO_SECRET')) return ASETEC_ZOHO_SECRET;
    // 2) Desde opciones
    $opt = get_option('asetec_zoho_secret');
    return is_string($opt) && $opt !== '' ? $opt : null;
  }

  private function check_auth($req) {
    $provided = $req->get_param('token');
    if (!$provided) {
      $provided = isset($_SERVER['HTTP_X_ASETEC_TOKEN']) ? sanitize_text_field($_SERVER['HTTP_X_ASETEC_TOKEN']) : '';
    }
    $secret = $this->get_secret_token();
    return ($secret && hash_equals($secret, (string)$provided));
  }

  private function get_event_by_code($code) {
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name, default_ticket_capacity FROM {$wpdb->prefix}asetec_events WHERE code=%s", $code),
      ARRAY_A
    );
  }

  private function get_formmap($event_code) {
    $map = get_option('asetec_formmap_' . $event_code);
    if (is_array($map) && !empty($map)) return $map;
    // defaults
    return array(
      'cedula_field'    => 'cedula',
      'nombre_field'    => 'nombre',
      'apellido1_field' => 'apellido1',
      'apellido2_field' => 'apellido2',
      'email_field'     => 'email',
      'capacity_mode'   => 'from_field', // o "fixed"
      'capacity_field'  => 'total_personas',
      'extras_fields'   => array(),       // p.ej. ["quincenas","acepta_reglamento"]
    );
  }

  private function pick($arr, $key, $default = '') {
    if (!is_array($arr)) return $default;
    // Zoho puede mandar keys con espacios/acentos; intentamos exacto y variante "slug"
    if (array_key_exists($key, $arr)) return $arr[$key];
    $slug = sanitize_title($key);
    foreach ($arr as $k => $v) {
      if (sanitize_title($k) === $slug) return $v;
    }
    return $default;
  }

  private function next_entry_number($event_id) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'asetec_tickets';
    $max = (int)$wpdb->get_var($wpdb->prepare("SELECT MAX(entry_number) FROM $tbl WHERE event_id=%d", $event_id));
    return $max + 1;
  }

  public function handle(\WP_REST_Request $req) {
    if (!$this->check_auth($req)) {
      return new \WP_Error('forbidden', 'Token inválido o ausente', array('status' => 403));
    }

    $body = json_decode($req->get_body(), true);
    if (!is_array($body)) $body = $req->get_params(); // fallback form-encoded

    $event_code = sanitize_text_field(isset($body['event_code']) ? $body['event_code'] : '');
    if ($event_code === '') {
      return new \WP_Error('bad_request', 'Falta event_code', array('status' => 400));
    }

    $ev = $this->get_event_by_code($event_code);
    if (!$ev) {
      return new \WP_Error('not_found', 'Evento no encontrado', array('status' => 404));
    }

    $map = $this->get_formmap($event_code);

    $cedula   = sanitize_text_field( $this->pick($body, $map['cedula_field'], '') );
    $nombre   = sanitize_text_field( $this->pick($body, $map['nombre_field'], '') );
    $ap1      = sanitize_text_field( $this->pick($body, $map['apellido1_field'], '') );
    $ap2      = sanitize_text_field( $this->pick($body, $map['apellido2_field'], '') );
    $email    = sanitize_email( $this->pick($body, $map['email_field'], '') );

    if ($cedula === '' || $email === '' || $nombre === '') {
      return new \WP_Error('bad_request', 'Faltan campos mínimos (cédula, nombre, email)', array('status' => 400));
    }

    // Capacity
    $capacity = 1;
    if ($map['capacity_mode'] === 'from_field') {
      $cap = (int) $this->pick($body, $map['capacity_field'], 1);
      $capacity = max(1, $cap);
    } else {
      // fixed -> usar default del evento o 1
      $capacity = max(1, (int)($ev['default_ticket_capacity'] ?? 1));
    }

    // Extras (página 2)
    $extras = array();
    if (!empty($map['extras_fields']) && is_array($map['extras_fields'])) {
      foreach ($map['extras_fields'] as $ek) {
        $extras[$ek] = $this->pick($body, $ek, null);
      }
    }

    global $wpdb;
    $members = $wpdb->prefix . 'asetec_members';
    $tickets = $wpdb->prefix . 'asetec_tickets';

    // Asegurar que el asociado existe (si no, crea mínimo)
    $member = $wpdb->get_row($wpdb->prepare("SELECT cedula FROM $members WHERE cedula=%s", $cedula), ARRAY_A);
    if (!$member) {
      $wpdb->insert($members, array(
        'cedula' => $cedula,
        'nombre' => trim($nombre.' '.$ap1.' '.$ap2),
        'email'  => $email,
        'activo' => 1,
      ), array('%s','%s','%s','%d'));
    } else {
      // opcional: actualizar nombre/email si vienen distintos
      $wpdb->update($members, array(
        'nombre' => trim($nombre.' '.$ap1.' '.$ap2),
        'email'  => $email,
        'activo' => 1,
      ), array('cedula'=>$cedula), array('%s','%s','%d'), array('%s'));
    }

    // Upsert ticket por (event_id, cedula)
    $tk = $wpdb->get_row($wpdb->prepare(
      "SELECT id, entry_number, capacity, consumed, status FROM $tickets WHERE event_id=%d AND cedula=%s",
      $ev['id'], $cedula
    ), ARRAY_A);

    if ($tk) {
      // Actualizar capacidad si vino nueva (sin romper consumos)
      $new_cap = max($tk['consumed'], $capacity);
      $wpdb->update($tickets, array(
        'capacity' => $new_cap,
        'updated_at' => current_time('mysql'),
        'meta_json'  => wp_json_encode($extras),
      ), array('id' => $tk['id']), array('%d','%s','%s'), array('%d'));

      $ticket_id   = (int)$tk['id'];
      $entry_number= (int)$tk['entry_number'];

    } else {
      $entry_number = $this->next_entry_number((int)$ev['id']);
      $wpdb->insert($tickets, array(
        'event_id'     => (int)$ev['id'],
        'cedula'       => $cedula,
        'entry_number' => $entry_number,
        'status'       => 'issued',
        'capacity'     => $capacity,
        'consumed'     => 0,
        'meta_json'    => wp_json_encode($extras),
        'created_at'   => current_time('mysql'),
        'updated_at'   => current_time('mysql'),
      ), array('%d','%s','%d','%s','%d','%d','%s','%s','%s'));

      $ticket_id = (int) $wpdb->insert_id;
    }

    // Respuesta minimal (QR y e-mail los agregamos en la Parte B)
    return array(
      'ok'          => true,
      'event'       => array('id'=>$ev['id'], 'code'=>$ev['code'], 'name'=>$ev['name']),
      'ticket_id'   => $ticket_id,
      'entry_number'=> $entry_number,
      'cedula'      => $cedula,
      'capacity'    => $capacity,
      'extras'      => $extras,
      'message'     => 'Ticket creado/actualizado. Parte B enviará QR y correo.',
    );
  }
}
