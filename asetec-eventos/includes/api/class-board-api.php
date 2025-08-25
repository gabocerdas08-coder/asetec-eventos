<?php
if (!defined('ABSPATH')) exit;

class Asetec_Board_API {
  public function __construct() {
    add_action('rest_api_init', [$this, 'register_routes']);
  }

  public function register_routes() {
    register_rest_route('asetec/v1', '/board', [
      'methods'  => 'GET',
      'callback' => [$this, 'get_board'],
      'args' => [
        'event_code' => ['required' => true],
        'q'          => [],
        'status'     => [], // all|no_qr|qr|checked_in|qr_no_checkin
        'page'       => [],
        'per_page'   => [],
      ],
      'permission_callback' => '__return_true', // público (solo lectura)
    ]);

    register_rest_route('asetec/v1', '/board/export', [
      'methods'  => 'GET',
      'callback' => [$this, 'export_csv'],
      'permission_callback' => '__return_true',
    ]);
  }

  private function build_where($wpdb, $event_id, $q, $status) {
    $t = $wpdb->prefix.'asetec_tickets';
    $m = $wpdb->prefix.'asetec_members';

    $where   = ["m.activo = 1"]; // base: solo activos
    $params  = [];

    // Búsqueda por cédula o nombre
    if ($q !== '') {
      $like = '%' . $wpdb->esc_like($q) . '%';
      $where[] = "(m.cedula LIKE %s OR m.nombre LIKE %s)";
      $params[] = $like;
      $params[] = $like;
    }

    // Filtros de estado
    switch ($status) {
      case 'no_qr':
        $where[] = "tk.id IS NULL";
        break;
      case 'qr':
        $where[] = "tk.id IS NOT NULL";
        break;
      case 'checked_in':
        $where[] = "tk.status = 'checked_in'";
        break;
      case 'qr_no_checkin':
        $where[] = "tk.id IS NOT NULL AND tk.status <> 'checked_in'";
        break;
      default:
        // all (sin condición)
        break;
    }

    // JOIN fijo: tickets del evento
    $join = $wpdb->prepare("LEFT JOIN $t tk ON tk.cedula = m.cedula AND tk.event_id = %d", $event_id);

    return [$join, $where, $params];
  }

  public function get_board(\WP_REST_Request $req) {
    global $wpdb;
    $event_code = sanitize_text_field($req->get_param('event_code'));
    $q          = sanitize_text_field($req->get_param('q') ?? '');
    $status     = sanitize_text_field($req->get_param('status') ?? 'all');
    $page       = max(1, intval($req->get_param('page') ?? 1));
    $per_page   = min(500, max(1, intval($req->get_param('per_page') ?? 50)));

    $ev = $wpdb->get_row($wpdb->prepare(
      "SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code
    ), ARRAY_A);
    if (!$ev) return new \WP_Error('not_found', 'Evento no encontrado', ['status'=>404]);

    [$join, $where, $params] = $this->build_where($wpdb, $ev['id'], $q, $status);
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $m = $wpdb->prefix.'asetec_members';

    // Total
    $sql_total = "SELECT COUNT(*) 
                  FROM $m m
                  $join
                  $where_sql";
    $total = (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));

    // Data
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT m.cedula, m.nombre, COALESCE(tk.status,'') AS tk_status
            FROM $m m
            $join
            $where_sql
            ORDER BY m.nombre ASC
            LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params, $per_page, $offset), ARRAY_A);

    // Armar payload simple
    $items = array_map(function($r){
      $no_qr     = ($r['tk_status'] === '' || $r['tk_status'] === null);
      $solicito  = !$no_qr;
      $checkin   = ($r['tk_status'] === 'checked_in');
      return [
        'cedula'       => $r['cedula'],
        'nombre'       => $r['nombre'],
        'no_qr'        => $no_qr,
        'solicito_qr'  => $solicito,
        'check_in'     => $checkin,
        'estado_raw'   => $r['tk_status'],
      ];
    }, $rows ?: []);

    return [
      'event'    => ['id'=>$ev['id'], 'code'=>$ev['code'], 'name'=>$ev['name']],
      'total'    => $total,
      'page'     => $page,
      'per_page' => $per_page,
      'items'    => $items,
    ];
  }

  public function export_csv(\WP_REST_Request $req) {
    // Reusar la lógica del listado
    $data = $this->get_board($req);
    if (is_wp_error($data)) return $data;

    // CSV en memoria
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Cedula','Nombre','No solicito QR','Solicito QR','Check-in','Estado (raw)']);

    foreach ($data['items'] as $it) {
      fputcsv($fh, [
        $it['cedula'],
        $it['nombre'],
        $it['no_qr'] ? '1' : '0',
        $it['solicito_qr'] ? '1' : '0',
        $it['check_in'] ? '1' : '0',
        $it['estado_raw'],
      ]);
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);

    $resp = new \WP_REST_Response($csv, 200);
    $resp->header('Content-Type', 'text/csv; charset=UTF-8');
    $filename = 'reporte_' . sanitize_file_name($data['event']['code']) . '_' . date('Ymd_His') . '.csv';
    $resp->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    return $resp;
  }
}
