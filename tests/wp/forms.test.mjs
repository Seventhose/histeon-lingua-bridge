// The language of what a visitor sends (1.10.0, 1.14.0), with Fluent Forms copied into dev/.third-party
// (dev/test.sh): the texts of a form are strings of the site; on the English page the labels and the error messages of
// the browser are English; a form sent from the English page answers in English (errors, confirmation, redirect) and
// keeps its language; the email to the visitor leaves in English even when Fluent Forms sends it later, the one to the
// owner of the site stays in the original's language; someone who registers from an English page gets English in their
// profile.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
let made;

// A form with two fields (a name, an email with its own required message and the site's message for a wrong address),
// a button, a confirmation with markup and two notifications: one to the visitor (the address of the email field), one
// to the owner of the site (a fixed address). An Italian page with it and its English version; the strings registered
// by drawing the form, then translated and published as a person would.
const setup = `
global $wpdb;
$text = array( 'index' => 0, 'element' => 'input_text', 'attributes' => array( 'type' => 'text', 'name' => 'nome', 'value' => '', 'placeholder' => 'LB Il tuo nome' ), 'settings' => array( 'label' => 'LB Nome', 'help_message' => '', 'validation_rules' => array( 'required' => array( 'value' => false, 'message' => 'LB Campo obbligatorio' ) ), 'conditional_logics' => array() ), 'editor_options' => array( 'title' => 'Simple Text' ), 'uniqElKey' => 'el_lb1' );
$email = array( 'index' => 1, 'element' => 'input_email', 'attributes' => array( 'type' => 'email', 'name' => 'email', 'value' => '', 'placeholder' => '' ), 'settings' => array( 'label' => 'LB La tua email', 'help_message' => '', 'validation_rules' => array( 'required' => array( 'value' => true, 'message' => 'LB Serve la tua email' ), 'email' => array( 'value' => true, 'message' => 'This field must contain a valid email', 'global' => true ) ), 'conditional_logics' => array() ), 'editor_options' => array( 'title' => 'Email' ), 'uniqElKey' => 'el_lb3' );
$fields = array(
  'fields' => array( $text, $email ),
  'submitButton' => array( 'uniqElKey' => 'el_lb2', 'element' => 'button', 'attributes' => array( 'type' => 'submit', 'class' => '' ), 'settings' => array( 'align' => 'left', 'button_style' => 'default', 'button_size' => 'md', 'button_ui' => array( 'type' => 'default', 'text' => 'LB Invia', 'img_url' => '' ) ), 'editor_options' => array( 'title' => 'Submit Button' ) ),
);
$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'LB Contatti', 'status' => 'published', 'form_fields' => wp_json_encode( $fields ), 'has_payment' => 0, 'type' => 'form', 'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$form = (int) $wpdb->insert_id;
$settings = \\FluentForm\\App\\Models\\Form::getFormsDefaultSettings();
$settings['confirmation']['redirectTo'] = 'samePage';
$settings['confirmation']['messageToShow'] = '<p>LB Grazie, a presto</p><p><strong>LB A risentirci</strong></p>';
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => $form, 'meta_key' => 'formSettings', 'value' => wp_json_encode( $settings ) ) );
$notification = static function ( $name, $send_to, $subject, $message ) {
  return wp_json_encode( array( 'name' => $name, 'sendTo' => $send_to, 'fromName' => '', 'fromEmail' => '', 'replyTo' => '', 'bcc' => '', 'subject' => $subject, 'message' => $message, 'conditionals' => array( 'status' => false, 'type' => 'all', 'conditions' => array( array( 'field' => null, 'value' => null, 'operator' => null ) ) ), 'enabled' => true, 'email_template' => '' ) );
};
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => $form, 'meta_key' => 'notifications', 'value' => $notification( 'LB al visitatore', array( 'type' => 'field', 'email' => '', 'field' => 'email', 'routing' => array() ), 'LB Abbiamo ricevuto il tuo messaggio', '<p>LB Ciao {inputs.nome}, grazie del messaggio.</p>' ) ) );
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => $form, 'meta_key' => 'notifications', 'value' => $notification( 'LB al gestore', array( 'type' => 'email', 'email' => 'gestore@example.test', 'field' => '', 'routing' => array() ), 'LB Nuovo messaggio dal sito', '<p>LB Messaggio da {inputs.nome}</p>' ) ) );
$global = get_option( '_fluentform_global_form_settings' );
$changed = is_array( $global ) ? $global : array();
$changed['default_messages']['email'] = 'LB Indirizzo non valido';
update_option( '_fluentform_global_form_settings', $changed );
$it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB Modulo', 'post_name' => 'lb-modulo', 'post_content' => '[fluentform id="' . $form . '"]' ) ) );
$en = LinguaBridge\\Translations::create_draft( $it, 'en' );
wp_update_post( wp_slash( array( 'ID' => $en, 'post_status' => 'publish', 'post_title' => 'LB Form', 'post_name' => 'lb-form' ) ) );
do_shortcode( '[fluentform id="' . $form . '"]' );
$english = array( 'LB Nome' => 'LB Name', 'LB Il tuo nome' => 'LB Your name', 'LB Invia' => 'LB Send', 'LB Grazie, a presto' => 'LB Thank you, see you soon', 'LB A risentirci' => 'LB Talk soon', 'LB La tua email' => 'LB Your email', 'LB Serve la tua email' => 'LB We need your email', 'LB Indirizzo non valido' => 'LB Not a valid address', 'LB Abbiamo ricevuto il tuo messaggio' => 'LB We received your message', 'LB Ciao {inputs.nome}, grazie del messaggio.' => 'LB Hi {inputs.nome}, thank you for your message.', 'LB Nuovo messaggio dal sito' => 'LB New message from the site', 'LB Messaggio da {inputs.nome}' => 'LB Message from {inputs.nome}' );
$found = array();
foreach ( $english as $source => $target ) {
  $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LinguaBridge\\Strings::table() . " WHERE context = 'html' AND language = 'en' AND source = %s", $source ) );
  if ( $id ) {
    LinguaBridge\\Strings::save( $id, $target, 'published' );
    $found[] = $source;
  }
}
echo wp_json_encode( array( 'form' => $form, 'it' => $it, 'en' => $en, 'it_path' => wp_parse_url( get_permalink( $it ), PHP_URL_PATH ), 'en_path' => wp_parse_url( get_permalink( $en ), PHP_URL_PATH ), 'found' => $found, 'global' => $global ) );
`;

before(() => {
  wp('plugin', 'activate', 'fluentform', '--quiet');
  made = JSON.parse(wp('eval', '--user=admin', setup).split('\n').pop());
});

after(() => {
  const global = made.global === false || made.global === null ? 'delete_option( \'_fluentform_global_form_settings\' );' : `update_option( '_fluentform_global_form_settings', json_decode( base64_decode( '${Buffer.from(JSON.stringify(made.global)).toString('base64')}' ), true ) );`;
  wp('eval', '--user=admin', `global $wpdb; foreach ( array( ${made.en}, ${made.it} ) as $id ) { LinguaBridge\\Storage::delete( $id ); wp_delete_post( $id, true ); } $wpdb->delete( $wpdb->prefix . 'fluentform_forms', array( 'id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_submission_meta', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'ff_scheduled_actions', array( 'form_id' => ${made.form} ) ); $wpdb->query( 'DELETE FROM ' . LinguaBridge\\Strings::table() . " WHERE source LIKE 'LB %'" ); delete_option( 'lingua_bridge_form_${made.form}' ); delete_option( 'lb_dev_mail_log' ); ${global} if ( $u = get_user_by( 'login', 'lb_registrata' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u->ID ); }`);
  wp('option', 'update', 'users_can_register', '0');
  wp('plugin', 'deactivate', 'fluentform', '--quiet');
});

test('C38: the texts of a form are strings of the site, translated on the English page', async () => {
  assert.deepEqual([...made.found].sort(), ['LB A risentirci', 'LB Abbiamo ricevuto il tuo messaggio', 'LB Ciao {inputs.nome}, grazie del messaggio.', 'LB Grazie, a presto', 'LB Il tuo nome', 'LB Indirizzo non valido', 'LB Invia', 'LB La tua email', 'LB Nome', 'LB Serve la tua email'], 'labels, placeholder, button, error messages, confirmation and the email to the visitor registered from the form; the email to the owner not');
  const en = await (await fetchRetry(base + made.en_path)).text();
  assert.match(en, /LB Name/);
  assert.match(en, /placeholder="LB Your name"/);
  assert.match(en, /LB Send/);
  assert.match(en, /LB Your email/);
  assert.doesNotMatch(en, /LB Il tuo nome/);
  const it = await (await fetchRetry(base + made.it_path)).text();
  assert.match(it, /placeholder="LB Il tuo nome"/);
});

test('product 10: the error messages the browser shows are translated on the English page, inside the script too', async () => {
  const en = await (await fetchRetry(base + made.en_path)).text();
  assert.match(en, /LB We need your email/);
  assert.match(en, /LB Not a valid address/);
  assert.doesNotMatch(en, /LB Serve la tua email/);
  assert.doesNotMatch(en, /LB Indirizzo non valido/);
  const it = await (await fetchRetry(base + made.it_path)).text();
  assert.match(it, /LB Serve la tua email/);
  assert.match(it, /LB Indirizzo non valido/);
});

// A read first: a keep-alive connection closed by Apache meanwhile is retried on a read, never on a write.
const submit = async (from, data = { nome: 'Prova', email: 'visitatore@example.test' }) => {
  await fetchRetry(base + from);
  const body = new URLSearchParams({ action: 'fluentform_submit', form_id: String(made.form), data: new URLSearchParams(data).toString() });
  const r = await fetchRetry(base + '/wp-admin/admin-ajax.php', { method: 'POST', body, headers: { Referer: base + from, 'X-Requested-With': 'XMLHttpRequest' } });
  return r.json();
};
const languageOfLast = () => wp('eval', `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}fluentform_submission_meta WHERE form_id = %d AND meta_key = 'lingua_bridge_language' ORDER BY id DESC LIMIT 1", ${made.form} ) );`);

test('C38/C22: a form sent from the English page answers in English and keeps its language', async () => {
  const en = await submit(made.en_path);
  assert.match(JSON.stringify(en), /LB Thank you, see you soon/);
  assert.match(JSON.stringify(en), /<strong>LB Talk soon<\/strong>/, 'the markup of the confirmation stays');
  assert.equal(languageOfLast(), 'en');
  const it = await submit(made.it_path);
  assert.match(JSON.stringify(it), /LB Grazie, a presto/);
  assert.match(JSON.stringify(it), /<strong>LB A risentirci<\/strong>/);
  assert.equal(languageOfLast(), 'it');
});

// The site's own message for a wrong address (a «global» rule) is only in the browser: Fluent Forms 6.2 checks it on the
// server with the field's message.
test('product 10: the errors of a form sent from the English page are English', async () => {
  const en = await submit(made.en_path, { nome: 'Prova', email: '' });
  assert.match(JSON.stringify(en), /LB We need your email/);
  const it = await submit(made.it_path, { nome: 'Prova', email: '' });
  assert.match(JSON.stringify(it), /LB Serve la tua email/);
});

// The answer of Fluent Forms: { data: { result } } or { result }, by version.
const redirectPath = (answer) => new URL((answer.data?.result ?? answer.result ?? {}).redirectUrl ?? 'http://x/none').pathname;

test('product 10: a confirmation that goes to a page or an address of the site goes to its English version', async () => {
  const set = (confirmation) => wp('eval', `$s = \\FluentForm\\App\\Models\\FormMeta::retrieve( 'formSettings', ${made.form} ); $s['confirmation'] = array_merge( $s['confirmation'], json_decode( '${JSON.stringify(confirmation)}', true ) ); \\FluentForm\\App\\Models\\FormMeta::persist( ${made.form}, 'formSettings', $s );`);
  try {
    set({ redirectTo: 'customPage', customPage: made.it, customUrl: '' });
    const en = await submit(made.en_path);
    assert.equal(redirectPath(en), made.en_path);
    const it = await submit(made.it_path);
    assert.equal(redirectPath(it), made.it_path);
    set({ redirectTo: 'customUrl', customPage: '', customUrl: base + made.it_path });
    const url = await submit(made.en_path);
    assert.equal(redirectPath(url), made.en_path);
  } finally {
    set({ redirectTo: 'samePage', customPage: '', customUrl: '' });
  }
});

// Fluent Forms sends its notifications in a request of its own after the submission (no page, no Referer): here that
// request is the command line, run after each submission.
const mails = () => JSON.parse(wp('eval', `echo wp_json_encode( get_option( 'lb_dev_mail_log', array() ) );`));
const sendQueued = () => wp('eval', `wpFluentForm( 'fluentFormAsyncRequest' )->processActions();`);
const mailTo = (list, to) => list.find((m) => m.to.includes(to));

test('product 10: the email to the visitor leaves in the language of the page, the one to the owner in the original', async () => {
  wp('option', 'update', 'lb_dev_mail_log', '[]', '--format=json');
  await submit(made.en_path, { nome: 'Anna', email: 'visitatore@example.test' });
  sendQueued();
  let list = mails();
  const visitor = mailTo(list, 'visitatore@example.test');
  assert.ok(visitor, 'email to the visitor sent: ' + JSON.stringify(list));
  assert.equal(visitor.subject, 'LB We received your message');
  assert.match(visitor.message, /LB Hi Anna, thank you for your message\./);
  assert.doesNotMatch(visitor.message, /LB Ciao/);
  const owner = mailTo(list, 'gestore@example.test');
  assert.ok(owner, 'email to the owner sent');
  assert.equal(owner.subject, 'LB Nuovo messaggio dal sito');
  assert.match(owner.message, /LB Messaggio da Anna/);

  wp('option', 'update', 'lb_dev_mail_log', '[]', '--format=json');
  await submit(made.it_path, { nome: 'Bruno', email: 'visitatore@example.test' });
  sendQueued();
  list = mails();
  const italian = mailTo(list, 'visitatore@example.test');
  assert.ok(italian, 'email to the visitor sent: ' + JSON.stringify(list));
  assert.equal(italian.subject, 'LB Abbiamo ricevuto il tuo messaggio');
  assert.match(italian.message, /LB Ciao Bruno, grazie del messaggio\./);
});

test('C22: someone who registers from an English page gets English in their profile', async () => {
  wp('option', 'update', 'users_can_register', '1');
  await fetchRetry(base + made.en_path);
  const body = new URLSearchParams({ user_login: 'lb_registrata', user_email: 'lb_registrata@example.test', 'wp-submit': 'Register', redirect_to: '' });
  await fetchRetry(base + '/wp-login.php?action=register', { method: 'POST', body, redirect: 'manual', headers: { Referer: base + made.en_path } });
  const locale = wp('eval', "$u = get_user_by( 'login', 'lb_registrata' ); echo $u ? get_user_meta( $u->ID, 'locale', true ) . '|' . LinguaBridge\\Locale::for_language( 'en' ) : 'nobody';");
  const [saved, expected] = locale.split('|');
  assert.ok(expected, 'registered: ' + locale);
  assert.equal(saved, expected);
});
