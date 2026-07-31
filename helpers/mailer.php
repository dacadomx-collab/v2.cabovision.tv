<?php

declare(strict_types=1);

// =============================================================================
// helpers/mailer.php — Correo transaccional mínimo, sin dependencias externas
// (mismo criterio que api/jwt.php: HS256 sin librerías) — usa mail() nativo de
// PHP en vez de PHPMailer/SwiftMailer porque este proyecto no usa Composer en
// ningún otro punto (verificado: no existe composer.json/vendor/ en el repo).
//
// MODULO_01_LOGIN_Y_ACCESO.md §2.4 (Ley de Oro de Entregabilidad Anti-SPAM):
// dominio de origen limpio, URLs absolutas vía APP_URL, cabecera
// List-Unsubscribe, footer de cumplimiento visible — todo aplicado aquí una
// sola vez, para que cualquier endpoint que envíe correo lo cumpla gratis.
//
// Estado real (2026-07-23): SMTP_HOST/PORT/USER en .env son placeholders
// (SMTP_PASS vacío, mismo estado que ya señalaba views/partials/footer.php:
// "Módulo de correo pendiente de configuración") — mail() nativo de PHP en
// XAMPP/Windows no tiene un MTA local configurado, así que el envío real
// FALLARÁ en este entorno. Se implementa igual, a propósito ("prepáralo a
// nivel código, aunque estemos en localhost"): el fallo se captura, se
// registra en error_log con el motivo, y NUNCA bloquea el flujo que lo llamó
// (crear un usuario no debe fallar porque el correo de bienvenida no salió).
// =============================================================================

/**
 * Envía un correo transaccional HTML. Best-effort: devuelve true/false, nunca
 * lanza — el llamador decide si el fallo es crítico (nunca lo es para un
 * correo de bienvenida, sí podría serlo para un reset de password si el
 * proyecto decide bloquear esa opción sin correo entregable).
 */
function send_transactional_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $env = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];

    $fromName  = (string) ($env['MAIL_FROM_NAME'] ?? 'CaboVision.tv');
    $fromEmail = (string) ($env['SMTP_USER'] ?? 'hola@cabovision.tv');
    $appUrl    = (string) ($env['APP_URL'] ?? 'http://localhost/CaboVision.tv');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [mailer] Destinatario inválido, correo no enviado: ' . $toEmail);
        return false;
    }

    // Footer de cumplimiento visible (CAN-SPAM) — siempre se agrega, sin
    // importar la plantilla. Si $htmlBody ya viene armado como tabla premium
    // propia (helpers/mail_templates.php — logo y layout ya incluidos), no
    // se envuelve de nuevo en el <div>+logo genérico para no duplicarlo;
    // solo se le anexa el footer de cumplimiento con el mismo criterio.
    $logoUrl = $appUrl . '/assets/img/logocabovis_glow.png';
    $isPrebuiltTable = str_starts_with(ltrim($htmlBody), '<table');

    $complianceFooter = <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center" style="padding:16px;font-family:Arial,sans-serif;font-size:11px;color:#888;">
            CaboVision.tv — Los Cabos, Baja California Sur, México.<br>
            Recibiste este correo porque un administrador creó o gestionó una cuenta para ti en nuestro sistema.
            <a href="mailto:{$fromEmail}?subject=Baja">Darme de baja</a>.
        </td>
    </tr>
</table>
HTML;

    if ($isPrebuiltTable) {
        $body = $htmlBody . $complianceFooter;
    } else {
        $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;color:#1a1a1a;">
    <img src="{$logoUrl}" alt="CaboVision.tv" width="160" height="30" style="margin-bottom:16px;">
    {$htmlBody}
    <hr style="border:none;border-top:1px solid #ddd;margin:24px 0;">
</div>
{$complianceFooter}
HTML;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        // RFC 2369/8058 — requisito universal de MODULO_01 §2.4, no solo de
        // plantillas premium: se agrega aquí, a nivel de infraestructura de
        // envío, para que TODO correo saliente del proyecto lo herede gratis.
        'List-Unsubscribe: <mailto:' . $fromEmail . '?subject=unsubscribe>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    ];

    $ok = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

    if (!$ok) {
        // Nunca se expone el detalle de por qué falló al llamador HTTP — solo
        // al log del servidor (Mandamiento: cero fugas de configuración de
        // entorno al cliente).
        error_log(
            '[' . date('Y-m-d H:i:s') . '] [mailer] mail() nativo falló (esperado en XAMPP/Windows sin MTA local '
            . 'configurado) — destinatario=' . $toEmail . ' asunto="' . $subject . '"'
        );
    }

    return $ok;
}
