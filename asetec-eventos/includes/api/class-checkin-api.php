<?php
if (!defined('ABSPATH')) exit;

class Asetec_Checkin_API {

  public function __construct() {
    add_action('rest_api_init', array($this, 'routes'));
  }

  public function routes() {
    register_rest_route('asetec/v1', '/checkin/lookup', array(
      'methods'  => 'GET',
      'callback' => array($this, 'lookup'),
      'permission_callback' => array($this, 'perm'),
      'args' => array(
        'event_code' => array('required' => true),
        'token'      => array('required' => true),
      ),
    ));

    register_rest_route('asetec/v1', '/checkin', array(
      'methods'  => 'POST',
      'callback' => array($this, 'confirm'),
      'permission_callback' => array($this, 'perm'),
    ));
  }

  /** Sólo staff (ajusta la capability si quieres algo más granular) */
  public function perm() {
    return is_user_logged_in() && current_user_can('manage_options');
  }

  /** GET /checkin/lookup?event_code=XXX&token=YYYY */
  public function lookup(\WP_REST_Request $req) {
    global $wpdb;
    $event_code = sanitize_text_field($req->get_param('event_code'));
    $token      = sanitize_text_field($req->get_param('token'));

    $ev = $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code),
      ARRAY_A
    );
    if (!$ev) return new \WP_Error('not_found', 'Evento no encontrado', array('status'=>404));

    $t = $wpdb->prefix . 'asetec_tickets';
    $tk = $wpdb->get_row(
      $wpdb->prepare("SELECT id, entry_number, cedula, nombre, email, capacity, consumed, status
                      FROM $t WHERE event_id=%d AND token=%s",
                      (int)$ev['id'], $token),
      ARRAY_A
    );
    if (!$tk) return new \WP_Error('not_found', 'Ticket no encontrado', array('status'=>404));

    return array(
      'event'  => array('id'=>(int)$ev['id'],'code'=>$ev['code'],'name'=>$ev['name']),
      'ticket' => array(
        'id'=>(int)$tk['id'],
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],
        'nombre'=>$tk['nombre'],
        'email'=>$tk['email'],
        'capacity'=>(int)$tk['capacity'],
        'consumed'=>(int)$tk['consumed'],
        'status'=>$tk['status'],
      ),
      'remaining' => max(0, (int)$tk['capacity'] - (int)$tk['consumed']),
    );
  }

  /** POST /checkin  Body: {event_code, token, qty} */
  public function confirm(\WP_REST_Request $req) {
    global $wpdb;

    $p = $req->get_json_params();
    if (!is_array($p)) $p = $req->get_params();

    $event_code = sanitize_text_field($p['event_code'] ?? '');
    $token      = sanitize_text_field($p['token'] ?? '');
    $qty        = max(1, intval($p['qty'] ?? 1));

    if ($event_code==='' || $token==='') {
      return new \WP_Error('bad_request','Faltan parámetros',array('status'=>400));
    }

    $ev = $wpdb->get_row(
      $wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code),
      ARRAY_A
    );
    if (!$ev) return new \WP_Error('not_found','Evento no encontrado',array('status'=>404));

    $t = $wpdb->prefix . 'asetec_tickets';

    // Traer ticket
    $tk = $wpdb->get_row(
      $wpdb->prepare("SELECT id, entry_number, cedula, nombre, capacity, consumed, status
                      FROM $t WHERE event_id=%d AND token=%s FOR UPDATE",
                      (int)$ev['id'], $token),
      ARRAY_A
    );

    if (!$tk) return new \WP_Error('not_found','Ticket no encontrado',array('status'=>404));

    $remaining = (int)$tk['capacity'] - (int)$tk['consumed'];
    if ($remaining <= 0) {
      return new \WP_Error('forbidden','Cupo agotado para este QR',array('status'=>403));
    }
    if ($qty > $remaining) {
      return new \WP_Error('forbidden',"Sólo quedan $remaining disponibles",array('status'=>403));
    }

    // Intentar actualización atómica por condición de carrera
    $new_consumed = (int)$tk['consumed'] + $qty;
    $new_status   = ($new_consumed >= (int)$tk['capacity']) ? 'checked_in' : $tk['status'];

    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE $t
       SET consumed=%d, status=%s, checked_in_at=%s, updated_at=%s
       WHERE id=%d AND consumed=%d",
      $new_consumed, $new_status, current_time('mysql'), current_time('mysql'),
      (int)$tk['id'], (int)$tk['consumed']
    ));

    if ($updated === false) {
      return new \WP_Error('db','Error al actualizar',array('status'=>500));
    }
    if ($updated === 0) {
      // Otro dispositivo consumió en simultáneo
      return new \WP_Error('conflict','El ticket cambió, vuelve a leer/confirmar',array('status'=>409));
    }

    return array(
      'ok'=>true,
      'event'=>array('code'=>$ev['code'],'name'=>$ev['name']),
      'ticket'=>array(
        'entry_number'=>(int)$tk['entry_number'],
        'cedula'=>$tk['cedula'],
        'nombre'=>$tk['nombre'],
        'capacity'=>(int)$tk['capacity'],
        'consumed'=>$new_consumed,
        'status'=>$new_status,
      ),
      'remaining'=>max(0, (int)$tk['capacity'] - $new_consumed),
      'message'=>'Ingreso registrado',
    );
  }
}
