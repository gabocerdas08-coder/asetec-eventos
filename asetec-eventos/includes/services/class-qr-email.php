<?php
if (!defined('ABSPATH')) exit;

class Asetec_QR_Email {

  /** Genera un QR PNG (local si existe phpqrcode, si no con fallback a servicio público).
   *  Retorna: ['path' => ..., 'url' => ...]
   */
  public static function generate_qr_png($token, $event_code) {
    $upload = wp_upload_dir();
    $base   = trailingslashit($upload['basedir']) . 'asetec/qr/' . sanitize_title($event_code) . '/';
    $baseUrl= trailingslashit($upload['baseurl']) . 'asetec/qr/' . sanitize_title($event_code) . '/';

    if (!wp_mkdir_p($base)) {
      return new WP_Error('qr_dir', 'No se pudo crear la carpeta de QR en uploads');
    }

    $filename = sanitize_file_name($token) . '.png';
    $filepath = $base . $filename;
    $fileurl  = $baseUrl . $filename;

    // URL de validación/visualización (puedes cambiarla cuando tengas la vista de check-in)
    $data = add_query_arg(['tid' => $token], home_url('/asetec/ticket'));

    // 1) Intento local con phpqrcode
    $lib = plugin_dir_path(__FILE__) . '../lib/phpqrcode/qrlib.php';
    if (file_exists($lib)) {
      require_once $lib; // \QRcode::png
      // calidad ~ QR_ECLEVEL_M, tamaño 6
      \QRcode::png($data, $filepath, QR_ECLEVEL_M, 6, 1);
      return ['path' => $filepath, 'url' => $fileurl];
    }

    // 2) Fallback: servicio público (temporal)
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . rawurlencode($data);
    $png   = wp_remote_get($qrUrl, ['timeout' => 15]);
    if (is_wp_error($png)) return $png;

    $body = wp_remote_retrieve_body($png);
    if (empty($body)) return new WP_Error('qr_empty', 'No se pudo generar el QR');

    file_put_contents($filepath, $body);
    return ['path' => $filepath, 'url' => $fileurl];
  }

  /** Envía el correo de ticket con QR adjunto como PNG */
  public static function send_ticket_email($ticket, $event, $qr) {
    $to   = $ticket['email'];
    if (!is_email($to)) return new WP_Error('email', 'Correo inválido');

    $cap  = intval($ticket['capacity']);
    $capT = $cap === 1 ? '1 persona' : $cap . ' personas';

    $subject = sprintf(
      '[%s] Tu código QR de acceso — Entrada #%d',
      $event['code'],
      intval($ticket['entry_number'])
    );

    // Cuerpo HTML
    $html  = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.45">';
    $html .= sprintf('<h2 style="margin:0 0 8px">Acceso a <strong>%s</strong></h2>', esc_html($event['name'] ?? $event['code']));
    $html .= '<p style="margin:8px 0 0">A continuación tu QR de acceso:</p>';
    $html .= '<ul style="margin:8px 0 0;padding-left:18px">';
    $html .= sprintf('<li><strong>Entrada:</strong> #%d</li>', intval($ticket['entry_number']));
    $html .= sprintf('<li><strong>Asociado:</strong> %s — cédula %s</li>', esc_html($ticket['nombre']), esc_html($ticket['cedula']));
    $html .= sprintf('<li><strong>Cantidad de invitados:</strong> %d</li>', max(0, $cap - 1));
    $html .= '</ul>';
    $html .= '<p style="margin:12px 0">Presenta este QR en el ingreso. Si traes acompañantes, deben ingresar junto contigo.</p>';
    $html .= '<hr style="margin:16px 0;border:none;border-top:1px solid #ddd">';
    $html .= '<p style="color:#666;margin:0">Este correo fue generado automáticamente por ASETEC.</p>';
    $html .= '</div>';

    // Alternativo texto plano
    $text  = "Acceso a {$event['name']} ({$event['code']})\n";
    $text .= "Entrada #" . intval($ticket['entry_number']) . "\n";
    $text .= "Asociado: {$ticket['nombre']} — cédula {$ticket['cedula']}\n";
    $text .= "Válido para: {$capT}\n";
    $text .= "Ticket: " . add_query_arg(['tid'=>$ticket['token']], home_url('/asetec/ticket')) . "\n";

    // Adjuntos
    $attachments = [];
    if (!is_wp_error($qr) && !empty($qr['path'])) {
      $attachments[] = $qr['path'];
    }

    // Headers
    $headers = [
      'Content-Type: text/html; charset=UTF-8',
    ];

    // Enviar
    $ok = wp_mail($to, $subject, $html, $headers, $attachments);

    return $ok ? true : new WP_Error('mail', 'No se pudo enviar el correo');
  }
}
