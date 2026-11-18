<?php
/*
  includes/email_template.php renders every email Wallos sends. Subscription,
  category and household names are user input stored entity-encoded by
  validate(), so they must come out escaped exactly once.
*/

require_once WALLOS_ROOT . '/libs/PHPMailer/Exception.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/PHPMailer.php';
require_once WALLOS_ROOT . '/includes/email_template.php';

wallos_test('user text in an email is escaped once, stored entities are not shown twice', function () {
    $images = [];
    $html = wallos_email_subscription_list([
        ['name' => 'AT&amp;T &lt;b&gt;', 'price' => '$5.00', 'details' => ['<script>alert(1)</script>']],
        ['name' => 'Tom & Jerry', 'price' => '€3,00'],
    ], $images);

    assert_contains('AT&amp;T &lt;b&gt;', $html, 'a stored, already-encoded name is shown as typed');
    assert_not_contains('&amp;amp;', $html, 'stored entities are not encoded a second time');
    assert_contains('Tom &amp; Jerry', $html, 'a raw name is encoded');
    assert_not_contains('<script>', $html, 'markup in details is escaped');
    assert_same([], $images, 'no logo, no embedded image');
});

wallos_test('a subscription without a usable logo gets an initial instead', function () {
    $images = [];
    $html = wallos_email_subscription_list([
        ['name' => 'netflix', 'price' => '1', 'logo' => '../../db/wallos.db'],
        ['name' => 'Ünicode', 'price' => '1', 'logo' => 'logo.svg'],
        ['name' => 'Missing', 'price' => '1', 'logo' => 'does-not-exist.png'],
    ], $images);

    assert_same([], $images, 'paths outside the logo folder, SVGs and missing files are never embedded');
    assert_contains('>N</div>', $html, 'initial is upper-cased');
    assert_contains('>Ü</div>', $html, 'initial is taken by character, not by byte');
});

wallos_test('the layout links only to URLs that open a page', function () {
    $html = wallos_email_layout([
        'heading' => 'Reset',
        'button' => ['label' => 'Go', 'url' => 'javascript:alert(1)'],
        'server_url' => 'data:text/html,hi',
    ]);
    assert_not_contains('javascript:', $html, 'a script URL never becomes the button');
    assert_not_contains('data:text', $html, 'a data URL never becomes the footer link');

    $html = wallos_email_layout([
        'button' => ['label' => 'Verify', 'url' => 'https://wallos.example.com/verifyemail.php?email=a@b.c&token=x'],
        'link_hint' => 'Copy this link',
        'server_url' => 'https://wallos.example.com',
    ]);
    assert_contains('href="https://wallos.example.com/verifyemail.php?email=a@b.c&amp;token=x"', $html, 'the button links to the escaped URL');
    assert_contains('>wallos.example.com</a>', $html, 'the footer shows the host of the server URL');
    assert_contains('cid:' . WALLOS_EMAIL_LOGO_CID, $html, 'the logo is referenced as an inline image');
});

wallos_test('the html lang attribute follows the recipient language', function () {
    assert_same('zh-CN', wallos_email_lang('zh_cn'), 'region is upper-cased');
    assert_same('pt-BR', wallos_email_lang('pt_br'), 'pt_br');
    assert_same('sr', wallos_email_lang('sr_lat'), 'a script suffix is not a region');
    assert_same('en', wallos_email_lang(''), 'empty falls back to English');
});

wallos_test('applying an email sets both bodies and embeds the logo once per message', function () {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    wallos_email_apply($mail, '<p>first</p>', 'first');
    wallos_email_apply($mail, '<p>second</p>', 'second');

    assert_same('text/html', $mail->ContentType, 'the message is HTML');
    assert_same('UTF-8', $mail->CharSet, 'the message is UTF-8');
    assert_same('<p>second</p>', $mail->Body, 'HTML body');
    assert_same('second', $mail->AltBody, 'plain-text alternative');

    $inline = array_filter($mail->getAttachments(), fn($attachment) => $attachment[6] === 'inline');
    assert_same(1, count($inline), 'reusing an instance does not pile up copies of the logo');
    assert_same(WALLOS_EMAIL_LOGO_CID, array_values($inline)[0][7], 'the logo is attached under the cid the layout uses');
});
