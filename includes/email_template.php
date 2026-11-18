<?php
/*
  The HTML layout every email Wallos sends is rendered through.

  Mail clients are not browsers: no external stylesheets, no flexbox, and
  Outlook on Windows lays out with Word. So the markup is nested tables with
  inline styles, and anything decorative (rounded corners, the badge colours)
  degrades to a plain but readable layout where it is not supported.

  Images are embedded as inline attachments (cid:) instead of linked from the
  server: they then display without the "load remote images" prompt and work
  for installations that never set a public server URL.

  Callers render the content with the functions below and hand the result to
  wallos_email_apply(), which also keeps the plain-text body as the
  alternative part for text-only clients.
*/

const WALLOS_EMAIL_LOGO_CID = 'wallos-logo';

const WALLOS_EMAIL_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif";

/**
 * Escapes text for the HTML body. Values stored through validate() are already
 * entity-encoded, so they are decoded first to avoid showing "&amp;amp;".
 *
 * @param mixed $value
 * @return string
 */
function wallos_email_text($value)
{
    return htmlspecialchars(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

/**
 * Maps a Wallos language code (zh_cn, pt_br, sr_lat) to an HTML lang attribute.
 * Clients use it to pick the right CJK glyphs, among other things.
 *
 * @param string $language
 * @return string
 */
function wallos_email_lang($language)
{
    $parts = explode('_', strtolower((string) $language));
    $tag = preg_replace('/[^a-z]/', '', $parts[0]) ?: 'en';
    if (isset($parts[1]) && preg_match('/^[a-z]{2}$/', $parts[1])) {
        $tag .= '-' . strtoupper($parts[1]);
    }
    return $tag;
}

/**
 * Returns the URL unchanged, or an empty string when it uses a scheme that
 * runs or embeds something instead of opening a page. Anything else is let
 * through on purpose: server_url is saved without validation, and a value
 * such as "wallos.lan:8282" has always produced the links in these emails.
 *
 * @param string $url
 * @return string
 */
function wallos_email_safe_url($url)
{
    $url = trim((string) $url);
    return preg_match('/^(javascript|vbscript|data|file):/i', $url) ? '' : $url;
}

/**
 * A small rounded label, e.g. "Tomorrow" next to a price.
 *
 * @param string $text
 * @param string $tone  danger | warning | info | neutral
 * @return string
 */
function wallos_email_badge($text, $tone = 'info')
{
    $tones = [
        'danger' => ['#FEE2E2', '#B91C1C'],
        'warning' => ['#FEF3C7', '#B45309'],
        'info' => ['#DBEAFE', '#1D4ED8'],
        'neutral' => ['#F3F4F6', '#4B5563'],
    ];
    [$background, $color] = $tones[$tone] ?? $tones['info'];

    return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:' . $background . ';color:' . $color
        . ';font-size:12px;line-height:18px;font-weight:600;white-space:nowrap;">' . wallos_email_text($text) . '</span>';
}

/**
 * Resolves a subscription's logo to an embeddable file and the size it should
 * be shown at, or null when there is nothing a mail client can display.
 *
 * @param string $logo  file name as stored on the subscription
 * @return array|null   ['path' => string, 'width' => int, 'height' => int]
 */
function wallos_email_logo_file($logo)
{
    $logo = basename((string) $logo);
    if ($logo === '' || !preg_match('/\.(png|jpe?g|gif)$/i', $logo)) {
        // SVG and WebP are not displayed by enough mail clients.
        return null;
    }

    $path = __DIR__ . '/../images/uploads/logos/' . $logo;
    $size = is_file($path) ? @getimagesize($path) : false;
    if (!$size || $size[0] <= 0 || $size[1] <= 0) {
        return null;
    }

    // Fit inside 48x32 and keep the aspect ratio: logos are mostly wordmarks.
    $scale = min(48 / $size[0], 32 / $size[1], 1);

    return [
        'path' => $path,
        'width' => max(1, (int) round($size[0] * $scale)),
        'height' => max(1, (int) round($size[1] * $scale)),
    ];
}

/**
 * Renders the list of subscriptions a notification is about.
 *
 * Each item: name, price, badge (['text', 'tone']) optional, details (list of
 * strings shown under the name) optional, logo (stored file name) optional.
 *
 * @param array $items
 * @param array $images  collects cid => path for the logos that were used
 * @return string
 */
function wallos_email_subscription_list(array $items, array &$images)
{
    $rows = '';
    $index = 0;

    foreach ($items as $item) {
        $border = $index > 0 ? 'border-top:1px solid #E5E7EB;' : '';
        $index++;

        $logo = wallos_email_logo_file($item['logo'] ?? '');
        if ($logo !== null) {
            $cid = 'logo-' . substr(md5($logo['path']), 0, 12);
            $images[$cid] = $logo['path'];
            $visual = '<img src="cid:' . $cid . '" width="' . $logo['width'] . '" height="' . $logo['height'] . '" alt="" style="display:block;margin:0 auto;border:0;outline:none;width:' . $logo['width'] . 'px;height:' . $logo['height'] . 'px;">';
        } else {
            $name = html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($name, 0, 1));
            $visual = '<div style="width:40px;height:40px;margin:0 auto;border-radius:10px;background-color:#DBEAFE;color:#1D4ED8;font-size:17px;line-height:40px;font-weight:700;text-align:center;">'
                . wallos_email_text($initial === '' ? '?' : $initial) . '</div>';
        }

        $details = array_values(array_filter(array_map('strval', $item['details'] ?? []), fn($detail) => trim($detail) !== ''));
        $detailsHtml = '';
        if (!empty($details)) {
            $detailsHtml = '<div style="margin-top:4px;font-size:13px;line-height:19px;color:#6B7280;">'
                . implode('<span style="color:#D1D5DB;"> &nbsp;·&nbsp; </span>', array_map(fn($detail) => '<span style="white-space:nowrap;">' . wallos_email_text($detail) . '</span>', $details))
                . '</div>';
        }

        $badge = '';
        if (!empty($item['badge']['text'])) {
            $badge = '<div style="margin-top:6px;">' . wallos_email_badge($item['badge']['text'], $item['badge']['tone'] ?? 'info') . '</div>';
        }

        $rows .= '<tr>'
            . '<td width="48" align="center" valign="middle" class="wl-item-visual" style="' . $border . 'padding:16px 0 16px 16px;width:48px;">' . $visual . '</td>'
            . '<td valign="middle" style="' . $border . 'padding:16px 12px;">'
            . '<div style="font-size:15px;line-height:21px;font-weight:600;color:#111827;word-break:break-word;">' . wallos_email_text($item['name'] ?? '') . '</div>'
            . $detailsHtml
            . '</td>'
            . '<td valign="middle" align="right" style="' . $border . 'padding:16px 16px 16px 0;white-space:nowrap;">'
            . '<div style="font-size:15px;line-height:21px;font-weight:700;color:#111827;">' . wallos_email_text($item['price'] ?? '') . '</div>'
            . $badge
            . '</td>'
            . '</tr>';
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;border:1px solid #E5E7EB;border-radius:12px;">'
        . $rows . '</table>';
}

/**
 * Renders label/value pairs in a highlighted box, e.g. the pay period summary.
 *
 * @param array $rows  list of [label, value]
 * @return string
 */
function wallos_email_summary(array $rows)
{
    $html = '';
    foreach ($rows as $index => [$label, $value]) {
        $padding = $index > 0 ? 'padding-top:6px;' : '';
        $html .= '<tr>'
            . '<td style="' . $padding . 'font-size:14px;line-height:20px;color:#1E3A8A;">' . wallos_email_text($label) . '</td>'
            . '<td align="right" style="' . $padding . 'font-size:14px;line-height:20px;font-weight:700;color:#1E3A8A;white-space:nowrap;">' . wallos_email_text($value) . '</td>'
            . '</tr>';
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;background-color:#EFF6FF;border-radius:12px;">'
        . '<tr><td style="padding:14px 16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $html . '</table></td></tr></table>';
}

/**
 * Renders a complete email document.
 *
 * Options (all text is plain text and escaped here):
 *   lang        Wallos language code
 *   title       document title, usually the subject
 *   preheader   the preview line inbox lists show next to the subject
 *   heading     the large title inside the card
 *   intro       paragraph under the heading
 *   content     already-rendered HTML placed under the intro
 *   button      ['label' => ..., 'url' => ...] call to action
 *   link_hint   text shown above the raw link, below the button
 *   note        small print at the end of the card
 *   server_url  linked in the footer when set
 *
 * @param array $options
 * @return string
 */
function wallos_email_layout(array $options)
{
    $font = WALLOS_EMAIL_FONT;
    $lang = wallos_email_lang($options['lang'] ?? 'en');
    $title = wallos_email_text($options['title'] ?? 'Wallos');

    $preheader = '';
    if (!empty($options['preheader'])) {
        // Padding keeps the client from filling the preview with body text.
        $preheader = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F3F4F6;opacity:0;">'
            . wallos_email_text($options['preheader']) . str_repeat('&#8199;&#847;', 40) . '</div>';
    }

    $body = '';
    if (!empty($options['heading'])) {
        $body .= '<h1 style="margin:0 0 8px 0;font-family:' . $font . ';font-size:22px;line-height:30px;font-weight:700;color:#111827;">'
            . wallos_email_text($options['heading']) . '</h1>';
    }
    if (!empty($options['intro'])) {
        $body .= '<p style="margin:0 0 24px 0;font-size:15px;line-height:24px;color:#4B5563;">' . nl2br(wallos_email_text($options['intro'])) . '</p>';
    }
    if (!empty($options['content'])) {
        $body .= $options['content'];
    }

    $url = wallos_email_safe_url($options['button']['url'] ?? '');
    if ($url !== '' && !empty($options['button']['label'])) {
        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px 0;"><tr>'
            . '<td align="center" bgcolor="#2563EB" style="border-radius:10px;background-color:#2563EB;">'
            . '<a href="' . $href . '" target="_blank" style="display:inline-block;padding:13px 28px;font-family:' . $font . ';font-size:15px;line-height:20px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:10px;">'
            . wallos_email_text($options['button']['label']) . '</a></td></tr></table>';

        if (!empty($options['link_hint'])) {
            $body .= '<p style="margin:0 0 6px 0;font-size:13px;line-height:20px;color:#6B7280;">' . wallos_email_text($options['link_hint']) . '</p>'
                . '<p style="margin:0 0 8px 0;font-size:13px;line-height:20px;word-break:break-all;"><a href="' . $href . '" target="_blank" style="color:#2563EB;text-decoration:underline;">' . $href . '</a></p>';
        }
    }

    if (!empty($options['note'])) {
        $body .= '<p style="margin:24px 0 0 0;padding-top:20px;border-top:1px solid #E5E7EB;font-size:13px;line-height:20px;color:#6B7280;">'
            . wallos_email_text($options['note']) . '</p>';
    }

    $footer = 'Wallos';
    $serverUrl = wallos_email_safe_url($options['server_url'] ?? '');
    if ($serverUrl !== '') {
        $host = parse_url($serverUrl, PHP_URL_HOST) ?: $serverUrl;
        $footer .= '&nbsp;&nbsp;·&nbsp;&nbsp;<a href="' . htmlspecialchars($serverUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="color:#6B7280;text-decoration:underline;">'
            . wallos_email_text($host) . '</a>';
    }

    return '<!DOCTYPE html>'
        . '<html lang="' . $lang . '" xmlns="http://www.w3.org/1999/xhtml">'
        . '<head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="x-apple-disable-message-reformatting">'
        . '<meta name="color-scheme" content="light">'
        . '<meta name="supported-color-schemes" content="light">'
        . '<title>' . $title . '</title>'
        // Progressive enhancement: clients without media query support keep the desktop paddings.
        . '<style>@media only screen and (max-width:480px){'
        . '.wl-outer{padding:16px 8px !important}'
        . '.wl-pad{padding-left:20px !important;padding-right:20px !important}'
        . '.wl-item-visual{padding-left:12px !important}'
        . '}</style>'
        . '</head>'
        . '<body style="margin:0;padding:0;background-color:#F3F4F6;-webkit-text-size-adjust:100%;">'
        . $preheader
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F3F4F6" style="background-color:#F3F4F6;">'
        . '<tr><td align="center" class="wl-outer" style="padding:32px 12px;">'
        // Outlook on Windows ignores max-width, so it gets a fixed-width table instead.
        . '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">'
        // Card
        . '<tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;border:1px solid #E5E7EB;border-radius:16px;border-top:4px solid #2563EB;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr><td class="wl-pad" style="padding:28px 32px 4px 32px;">'
        . '<img src="cid:' . WALLOS_EMAIL_LOGO_CID . '" width="108" height="40" alt="Wallos" style="display:block;border:0;outline:none;width:108px;height:40px;">'
        . '</td></tr>'
        . '<tr><td class="wl-pad" style="padding:20px 32px 32px 32px;font-family:' . $font . ';">' . $body . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        // Footer
        . '<tr><td align="center" style="padding:20px 24px 0 24px;font-family:' . $font . ';font-size:12px;line-height:18px;color:#9CA3AF;">' . $footer . '</td></tr>'
        . '</table>'
        . '<!--[if mso]></td></tr></table><![endif]-->'
        . '</td></tr></table>'
        . '</body></html>';
}

/**
 * Puts a rendered email on a PHPMailer instance: HTML body, the plain-text
 * alternative, and every image the HTML references. Attachments already on
 * the instance are replaced.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mail
 * @param string $html
 * @param string $plainText
 * @param array  $images  cid => path, beyond the Wallos logo which is always added
 */
function wallos_email_apply($mail, $html, $plainText, array $images = [])
{
    // The verification and reset jobs reuse one instance for every recipient.
    $mail->clearAttachments();
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = $plainText;

    $images = [WALLOS_EMAIL_LOGO_CID => __DIR__ . '/../images/siteicons/wallos.png'] + $images;
    foreach ($images as $cid => $path) {
        $mail->addEmbeddedImage($path, $cid, basename($path));
    }
}
