<?php

declare(strict_types=1);

// =============================================================================
// helpers/mail_templates.php — Plantillas HTML de correo transaccional
// "Premium" (MODULO_01_LOGIN_Y_ACCESO.md §9.4): diseño tabular (los clientes
// de correo reales — Outlook de escritorio en particular — no soportan
// flexbox/grid de forma confiable, <table> sigue siendo el único layout
// universalmente compatible), fondo oscuro de marca, URLs SIEMPRE absolutas
// vía APP_URL (nunca rutas relativas — un cliente de correo no conoce el
// dominio del sitio que lo envía).
//
// helpers/mailer.php sigue siendo el TRANSPORTE (mail() nativo + cabeceras
// anti-SPAM/List-Unsubscribe, aplicadas a cualquier correo del proyecto).
// Este archivo solo construye el CUERPO HTML — separación de responsabilidad
// real: transporte vs. maquetación, no una duplicación (Mandamiento #10).
// =============================================================================

/**
 * Plantilla del correo de recuperación de contraseña — botón CTA con URL
 * absoluta, TTL informado en texto plano (nunca asumir que el usuario revisa
 * el correo antes de que expire), footer de cumplimiento.
 */
function build_password_reset_email_html(string $resetUrl, string $userName, int $ttlMinutes): string
{
    $env    = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $appUrl = (string) ($env['APP_URL'] ?? 'http://localhost/CaboVision.tv');
    $logoUrl = $appUrl . '/assets/img/logocabovis_glow.png';

    $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

    // Diseño tabular real (table/tr/td, no <div> con flex/grid) — ancho fijo
    // de 480px es el estándar de la industria para correo (no de la web:
    // aquí SÍ es correcto usar px, a diferencia de main.css, porque los
    // clientes de correo no tienen viewport real que redimensionar).
    return <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#10141E;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#1a1f2e;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">
                <tr>
                    <td style="padding:28px 32px 0 32px;" align="center">
                        <img src="{$logoUrl}" alt="CaboVision.tv" width="160" style="display:block;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 8px 32px;color:#f5f5f5;font-size:18px;font-weight:bold;">
                        Recupera tu acceso
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 20px 32px;color:#c7cbd4;font-size:14px;line-height:1.6;">
                        Hola {$safeName}, recibimos una solicitud para restablecer tu contraseña en el panel de CaboVision.tv. Si no fuiste tú, ignora este correo — tu cuenta sigue segura.
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 24px 32px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="background-color:#8a1f2b;border-radius:6px;">
                                    <a href="{$safeUrl}" style="display:inline-block;padding:12px 28px;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">
                                        Restablecer contraseña
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 24px 32px;color:#8a90a0;font-size:12px;line-height:1.5;">
                        Este enlace es válido por {$ttlMinutes} minutos y solo puede usarse una vez. Si el botón no funciona, copia y pega esta dirección en tu navegador:<br>
                        <a href="{$safeUrl}" style="color:#c7cbd4;word-break:break-all;">{$safeUrl}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
}

/**
 * Plantilla de aviso de nota pendiente de revisión — reemplaza el flujo
 * legacy (Mail::send('admin.articles.email-template', ...) en
 * Admin\ArticleController::store()), que solo avisaba a un correo hardcodeado.
 * Aquí se envía a cada usuario con rol Admin/Editor (ver articles_create.php).
 */
function build_pending_article_email_html(string $articleTitle, string $authorName, string $reviewUrl): string
{
    $env    = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW) ?: [];
    $appUrl = (string) ($env['APP_URL'] ?? 'http://localhost/CaboVision.tv');
    $logoUrl = $appUrl . '/assets/img/logocabovis_glow.png';

    $safeTitle  = htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8');
    $safeAuthor = htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');
    $safeUrl    = htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#10141E;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#1a1f2e;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">
                <tr>
                    <td style="padding:28px 32px 0 32px;" align="center">
                        <img src="{$logoUrl}" alt="CaboVision.tv" width="160" style="display:block;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 8px 32px;color:#f5f5f5;font-size:18px;font-weight:bold;">
                        Nota pendiente de revisión
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 20px 32px;color:#c7cbd4;font-size:14px;line-height:1.6;">
                        {$safeAuthor} envió "<strong>{$safeTitle}</strong>" y quedó como borrador, lista para tu revisión.
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 24px 32px;" align="center">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="background-color:#8a1f2b;border-radius:6px;">
                                    <a href="{$safeUrl}" style="display:inline-block;padding:12px 28px;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">
                                        Ir al panel de edición
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
}
