<?php
if (!defined('ABSPATH')) exit;

/**
 * Webhook para Zoho Forms (o cualquier form)
 * POST /wp-json/asetec/v1/zoho-hook?token=SECRETO
 *
 * Body (JSON) flexible con mapeo por evento:
 * {
 *   "event_code": "PA2025",
 *   "cedula": "10101010",
 *   "nombre": "Juan",
 *   "apellido1": "Pérez",        // opcional
 *   "apellido2": "Soto",         // opcional
 *   "email": "juan@itcr.ac.cr",
 *   "total_personas": 3          // si evento familiar
 * }
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

  /** Lee el secreto desde constante o desde options */
  private function get_secret_token() {
    if (defined('ASETEC_ZOHO_SECRET')) return ASETEC_ZOHO_SECRET;
    $opt = get_option('asetec_zoho_secret');
    return is_string($opt) && $opt !== '' ? $opt : null;
  }

  /** Auth simple por token en query o header */
  private function check_auth($req) {
    $provided = $req->get_param('token');
    if (!$provided && isset($_SERVER['HTTP_X_ASETEC_TOKEN'])) {
      $provided = sanitize_text_field($_SERVER['HTTP_X_ASETEC_TOKEN']);
    }
    $secret = $this->get_secret_token();
    return ($secret && hash_equals($secret, (string)$provided));
  }

  /** Busca evento por code (tal cual está en tu tabla) */
  private function get_event_by_code($code) {
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $code),
      ARRAY_A
    );
  }

  /** Mapeo por evento opcional (si no, defaults) */
  private function get_formmap($event_code) {
    $map = get_option('asetec_formmap_' . $event_code);
    if (is_array($map) && !empty($map)) return $map;

    return array(
      'cedula_field'    => 'cedula',
      'nombre_field'    => 'nombre',
      'apellido1_field' => 'apellido1',
      'apellido2_field' => 'apellido2',
      'email_field'     => 'email',
      'capacity_mode'   => 'from_field', // "fixed" o "from_field"
      'capacity_field'  => 'total_personas',
      'extras_fields'   => array(),       // si querés guardar página 2 en otro lugar más adelante
    );
  }

  /** Toma un valor del array, tolerante a llaves con espacios/acentos */
  private function pick($arr, $key, $default = '') {
    if (!is_array($arr)) return $default;
    if (array_key_exists($key, $arr)) return $arr[$key];
    $slug = sanitize_title($key);
    foreach ($arr as $k => $v) {
      if (sanitize_title($k) === $slug) return $v;
    }
    return $default;
  }

  /** Próximo número de entrada dentro del evento */
  private function next_entry_number($event_id) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'asetec_tickets';
    $max = (int)$wpdb->get_var($wpdb->prepare("SELECT MAX(entry_number) FROM $tbl WHERE event_id=%d", $event_id));
    return $max + 1;
  }

  /** Token único para el ticket (para QR/enlace) */
  private function random_token($len = 32) {
    return bin2hex(random_bytes($len)); // 64 hex chars por defecto
  }

  public function handle(\WP_REST_Request $req) {
    if (!$this->check_auth($req)) {
      return new \WP_Error('forbidden', 'Token inválido o ausente', array('status' => 403));
    }

    $body = json_decode($req->get_body(), true);
    if (!is_array($body)) $body = $req->get_params(); // por si Zoho manda form-encoded

    $event_code = sanitize_text_field(isset($body['event_code']) ? $body['event_code'] : '');
    if ($event_code === '') {
      return new \WP_Error('bad_request', 'Falta event_code', array('status' => 400));
    }

    $ev = $this->get_event_by_code($event_code);
    if (!$ev) {
      return new \WP_Error('not_found', 'Evento no encontrado', array('status' => 404));
    }

    $map = $this->get_formmap($event_code);

    // Datos del asociado
    $cedula = sanitize_text_field( $this->pick($body, $map['cedula_field'], '') );
    $nom    = sanitize_text_field( $this->pick($body, $map['nombre_field'], '') );
    $ap1    = sanitize_text_field( $this->pick($body, $map['apellido1_field'], '') );
    $ap2    = sanitize_text_field( $this->pick($body, $map['apellido2_field'], '') );
    $email  = sanitize_email( $this->pick($body, $map['email_field'], '') );

    if ($cedula === '' || $nom === '' || $email === '') {
      return new \WP_Error('bad_request', 'Faltan campos mínimos (cédula, nombre, email)', array('status' => 400));
    }

    $nombre_full = trim($nom . ' ' . $ap1 . ' ' . $ap2);

    // Capacity
    $capacity = 1;
    if ($map['capacity_mode'] === 'from_field') {
      $cap = (int) $this->pick($body, $map['capacity_field'], 1);
      $capacity = max(1, $cap);
    } else {
      $capacity = 1; // default si no usás formulario familiar
    }

    global $wpdb;
    $members = $wpdb->prefix . 'asetec_members';
    $tickets = $wpdb->prefix . 'asetec_tickets';

    // Upsert miembro
    $mem = $wpdb->get_row($wpdb->prepare("SELECT cedula FROM $members WHERE cedula=%s", $cedula), ARRAY_A);
    if ($mem) {
      $wpdb->update($members, array(
        'nombre'     => $nombre_full,
        'email'      => $email,
        'activo'     => 1,
        'updated_at' => current_time('mysql'),
      ), array('cedula' => $cedula), array('%s','%s','%d','%s'), array('%s'));
    } else {
      $wpdb->insert($members, array(
        'cedula'     => $cedula,
        'nombre'     => $nombre_full,
        'email'      => $email,
        'activo'     => 1,
        'updated_at' => current_time('mysql'),
      ), array('%s','%s','%s','%d','%s'));
    }

    // Upsert ticket por (event_id, cedula)
    $tk = $wpdb->get_row($wpdb->prepare(
      "SELECT id, entry_number, capacity, consumed, token, status FROM $tickets WHERE event_id=%d AND cedula=%s",
      (int)$ev['id'], $cedula
    ), ARRAY_A);

    if ($tk) {
      // No reducir capacity por debajo de lo ya consumido
      $new_cap = max((int)$tk['consumed'], (int)$capacity);
      $wpdb->update($tickets, array(
        'capacity'   => $new_cap,
        'nombre'     => $nombre_full,
        'email'      => $email,
        'updated_at' => current_time('mysql'),
      ), array('id' => (int)$tk['id']), array('%d','%s','%s','%s'), array('%d'));

      $ticket_id    = (int)$tk['id'];
      $entry_number = (int)$tk['entry_number'];
      $token        = $tk['token'];

    } else {
      $entry_number = $this->next_entry_number((int)$ev['id']);
      $token        = $this->random_token(16); // 32 hex

      $wpdb->insert($tickets, array(
        'event_id'     => (int)$ev['id'],
        'entry_number' => (int)$entry_number,
        'capacity'     => (int)$capacity,
        'consumed'     => 0,
        'token'        => $token,
        'cedula'       => $cedula,
        'nombre'       => $nombre_full,
        'email'        => $email,
        'status'       => 'issued',
        'created_at'   => current_time('mysql'),
        'updated_at'   => current_time('mysql'),
      ), array('%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s'));

      $ticket_id = (int)$wpdb->insert_id;
    }

    return array(
      'ok'           => true,
      'event'        => array('id' => (int)$ev['id'], 'code' => $ev['code'], 'name' => $ev['name']),
      'ticket_id'    => $ticket_id,
      'entry_number' => $entry_number,
      'cedula'       => $cedula,
      'nombre'       => $nombre_full,
      'email'        => $email,
      'capacity'     => (int)$capacity,
      'message'      => 'Ticket creado/actualizado (Módulo 2A). QR y correo siguen en 2B.',
    );
  }
}
