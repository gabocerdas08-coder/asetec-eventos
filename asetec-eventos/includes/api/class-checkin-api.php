<?php
if (!defined('ABSPATH')) exit;

class Asetec_Checkin_API {

  public function __construct() { add_action('rest_api_init', [$this,'routes']); }

  public function routes() {
    register_rest_route('asetec/v1', '/checkin/lookup', [
      'methods'=>'GET','callback'=>[$this,'lookup'],'permission_callback'=>[$this,'perm'],
      'args'=>['event_code'=>['required'=>true], 'token'=>['required'=>true]]
    ]);

    // NUEVO: lookup por cédula
    register_rest_route('asetec/v1', '/checkin/lookup-by-cedula', [
      'methods'=>'GET','callback'=>[$this,'lookup_by_cedula'],'permission_callback'=>[$this,'perm'],
      'args'=>['event_code'=>['required'=>true], 'cedula'=>['required'=>true]]
    ]);

    // Confirmar: ahora acepta token O ticket_id
    register_rest_route('asetec/v1', '/checkin', [
      'methods'=>'POST','callback'=>[$this,'confirm'],'permission_callback'=>[$this,'perm'],
    ]);
  }

  public function perm() {
    return is_user_logged_in() && current_user_can('manage_options');
  }

  private function get_event($code){
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $code),
      ARRAY_A
    );
  }

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

  // NUEVO: buscar por cédula (1 ticket por evento)
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
        'id'=>(int)$tk['id'], 'token'=>$tk['token'],
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],'nombre'=>$tk['nombre'],'email'=>$tk['email'],
        'capacity'=>(int)$tk['capacity'],'consumed'=>(int)$tk['consumed'],'status'=>$tk['status'],
      ],
      'remaining'=>max(0,(int)$tk['capacity']-(int)$tk['consumed']),
    ];
  }

  // POST /checkin  Body: {event_code, qty, token? , ticket_id?}
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

    // update atómico
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
