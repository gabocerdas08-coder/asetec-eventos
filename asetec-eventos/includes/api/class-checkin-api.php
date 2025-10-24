<?php
if (!defined('ABSPATH')) exit;

class Asetec_Checkin_API {

  public function __construct() { add_action('rest_api_init', [$this,'routes']); }

  public function routes() {
    register_rest_route('asetec/v1', '/checkin/lookup', [
      'methods'  => 'GET',
      'callback' => [$this,'lookup'],
      'permission_callback' => '__return_true',
      'args' => [
        'event_code' => ['required' => true],
        'token'      => ['required' => true],
      ],
    ]);

    register_rest_route('asetec/v1', '/checkin/lookup-by-cedula', [
      'methods'  => 'GET',
      'callback' => [$this,'lookup_by_cedula'],
      'permission_callback' => '__return_true',
      'args' => [
        'event_code' => ['required' => true],
        'cedula'     => ['required' => true],
      ],
    ]);

    register_rest_route('asetec/v1', '/checkin', [
      'methods'  => 'POST',
      'callback' => [$this,'confirm'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('asetec/v1', '/tickets/resend', array(
  'methods'  => 'POST',
  'callback' => array($this, 'resend_ticket_email'),
  'permission_callback' => function() {
    return is_user_logged_in() && current_user_can('manage_options');
  },
));

  }

  public function resend_ticket_email( WP_REST_Request $req ) {
  global $wpdb;

  $event_code = sanitize_text_field( $req->get_param('event_code') );
  $cedula     = sanitize_text_field( $req->get_param('cedula') );

  if ($event_code === '' || $cedula === '') {
    return new WP_Error('bad_request', 'Faltan parámetros', array('status' => 400));
  }

  // 1) Buscar evento
  $ev = $wpdb->get_row(
    $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code),
    ARRAY_A
  );
  if (!$ev) return new WP_Error('not_found', 'Evento no encontrado', array('status'=>404));

  // 2) Buscar ticket por cédula (JOIN members + tickets)
  $m  = $wpdb->prefix . 'asetec_members';
  $tk = $wpdb->prefix . 'asetec_tickets';

  $row = $wpdb->get_row(
    $wpdb->prepare("
      SELECT tk.id, tk.entry_number, tk.token, tk.capacity, tk.consumed, tk.email,
             m.nombre, m.cedula
      FROM $tk tk
      INNER JOIN $m m ON m.id = tk.member_id
      WHERE tk.event_id=%d AND m.cedula=%s
      ORDER BY tk.id DESC
      LIMIT 1
    ", $ev['id'], $cedula),
    ARRAY_A
  );

  if (!$row) {
    return new WP_Error('not_found', 'No existe ticket para esa cédula en este evento', array('status'=>404));
  }
  if (!is_email($row['email'])) {
    return new WP_Error('no_email', 'El asociado no tiene un correo válido', array('status'=>400));
  }

  // 3) Generar (o reutilizar) el PNG del QR para el token existente
  if (!class_exists('Asetec_QR_Email')) {
    return new WP_Error('deps', 'Servicio de email no disponible', array('status'=>500));
  }

  $qr = Asetec_QR_Email::generate_qr_png($row['token'], $ev['code']);
  if (is_wp_error($qr)) return $qr;

  // 4) Reenviar correo usando los datos del ticket existente
  $ticket = array(
    'entry_number' => intval($row['entry_number']),
    'token'        => $row['token'],
    'capacity'     => intval($row['capacity']),
    'consumed'     => intval($row['consumed']),
    'email'        => $row['email'],
    'nombre'       => $row['nombre'],
    'cedula'       => $row['cedula'],
  );
  $event  = array('code'=>$ev['code'],'name'=>$ev['name']);

  $ok = Asetec_QR_Email::send_ticket_email($ticket, $event, $qr);
  if (is_wp_error($ok) || !$ok) {
    return new WP_Error('send_fail', 'No se pudo reenviar el correo', array('status'=>500));
  }

  return array(
    'success' => true,
    'message' => 'QR reenviado a ' . $row['email'],
    'ticket'  => array('entry_number'=>$ticket['entry_number'], 'cedula'=>$ticket['cedula']),
  );
}


  private function get_event($code){
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $code),
      ARRAY_A
    );
  }

  /** GET /checkin/lookup?event_code=XXX&token=YYY */
  public function lookup(\WP_REST_Request $req) {
    global $wpdb;
    $event_code = sanitize_text_field($req->get_param('event_code'));
    $token      = sanitize_text_field($req->get_param('token'));

    $ev = $this->get_event($event_code);
    if (!$ev) return new \WP_Error('not_found','Evento no encontrado', ['status'=>404]);

    $t = $wpdb->prefix.'asetec_tickets';
    $tk = $wpdb->get_row($wpdb->prepare(
      "SELECT id, entry_number, cedula, nombre, email, capacity, consumed, status
       FROM $t WHERE event_id=%d AND token=%s",
      (int)$ev['id'], $token
    ), ARRAY_A);
    if (!$tk) return new \WP_Error('not_found','Ticket no encontrado', ['status'=>404]);

    return [
      'event'=>['id'=>(int)$ev['id'],'code'=>$ev['code'],'name'=>$ev['name']],
      'ticket'=>[
        'id'=>(int)$tk['id'],
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],'nombre'=>$tk['nombre'],'email'=>$tk['email'],
        'capacity'=>(int)$tk['capacity'],'consumed'=>(int)$tk['consumed'],'status'=>$tk['status'],
      ],
      'remaining'=>max(0,(int)$tk['capacity']-(int)$tk['consumed']),
    ];
  }

  /** GET /checkin/lookup-by-cedula?event_code=XXX&cedula=NNN */
  public function lookup_by_cedula(\WP_REST_Request $req) {
    global $wpdb;
    $event_code = sanitize_text_field($req->get_param('event_code'));
    $cedula     = sanitize_text_field($req->get_param('cedula'));

    $ev = $this->get_event($event_code);
    if (!$ev) return new \WP_Error('not_found','Evento no encontrado', ['status'=>404]);

    $t = $wpdb->prefix.'asetec_tickets';
    $tk = $wpdb->get_row($wpdb->prepare(
      "SELECT id, entry_number, token, cedula, nombre, email, capacity, consumed, status
       FROM $t WHERE event_id=%d AND cedula=%s",
      (int)$ev['id'], $cedula
    ), ARRAY_A);
    if (!$tk) return new \WP_Error('not_found','No existe ticket para esa cédula en este evento', ['status'=>404]);

    return [
      'event'=>['id'=>(int)$ev['id'],'code'=>$ev['code'],'name'=>$ev['name']],
      'ticket'=>[
        'id'=>(int)$tk['id'],'token'=>$tk['token'],
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],'nombre'=>$tk['nombre'],'email'=>$tk['email'],
        'capacity'=>(int)$tk['capacity'],'consumed'=>(int)$tk['consumed'],'status'=>$tk['status'],
      ],
      'remaining'=>max(0,(int)$tk['capacity']-(int)$tk['consumed']),
    ];
  }

  /** POST /checkin  Body: {event_code, qty, token? , ticket_id?} */
  public function confirm(\WP_REST_Request $req) {
    global $wpdb;
    $p = $req->get_json_params(); if (!is_array($p)) $p=$req->get_params();

    $event_code = sanitize_text_field($p['event_code'] ?? '');
    $qty        = max(1, intval($p['qty'] ?? 1));
    $token      = isset($p['token']) ? sanitize_text_field($p['token']) : '';
    $ticket_id  = isset($p['ticket_id']) ? intval($p['ticket_id']) : 0;

    if ($event_code==='') return new \WP_Error('bad_request','Falta event_code', ['status'=>400]);
    if (!$token && !$ticket_id) return new \WP_Error('bad_request','Falta token o ticket_id', ['status'=>400]);

    $ev = $this->get_event($event_code);
    if (!$ev) return new \WP_Error('not_found','Evento no encontrado', ['status'=>404]);

    $t = $wpdb->prefix.'asetec_tickets';

    if ($ticket_id) {
      $tk = $wpdb->get_row($wpdb->prepare(
        "SELECT id, entry_number, cedula, nombre, capacity, consumed, status
         FROM $t WHERE event_id=%d AND id=%d",
        (int)$ev['id'], (int)$ticket_id
      ), ARRAY_A);
    } else {
      $tk = $wpdb->get_row($wpdb->prepare(
        "SELECT id, entry_number, cedula, nombre, capacity, consumed, status
         FROM $t WHERE event_id=%d AND token=%s",
        (int)$ev['id'], $token
      ), ARRAY_A);
    }

    if (!$tk) return new \WP_Error('not_found','Ticket no encontrado', ['status'=>404]);

    $remaining = (int)$tk['capacity'] - (int)$tk['consumed'];
    if ($remaining <= 0) return new \WP_Error('forbidden','Cupo agotado para este QR', ['status'=>403]);
    if ($qty > $remaining) return new \WP_Error('forbidden',"Sólo quedan $remaining disponibles", ['status'=>403]);

    $new_consumed = (int)$tk['consumed'] + $qty;
    $new_status   = ($new_consumed >= (int)$tk['capacity']) ? 'checked_in' : $tk['status'];

    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE $t SET consumed=%d, status=%s, checked_in_at=%s, updated_at=%s
       WHERE id=%d AND consumed=%d",
      $new_consumed, $new_status, current_time('mysql'), current_time('mysql'),
      (int)$tk['id'], (int)$tk['consumed']
    ));
    if ($updated === false) return new \WP_Error('db','Error al actualizar', ['status'=>500]);
    if ($updated === 0) return new \WP_Error('conflict','El ticket cambió, vuelve a confirmar', ['status'=>409]);

    return [
      'ok'=>true,
      'event'=>['code'=>$ev['code'],'name'=>$ev['name']],
      'ticket'=>[
        'id'=>(int)$tk['id'],
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],'nombre'=>$tk['nombre'],
        'capacity'=>(int)$tk['capacity'],'consumed'=>$new_consumed,'status'=>$new_status,
      ],
      'remaining'=>max(0,(int)$tk['capacity'] - $new_consumed),
      'message'=>'Ingreso registrado',
    ];
  }


}
