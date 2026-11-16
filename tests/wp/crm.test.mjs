// FluentCRM in the language of the page (1.14.0), with Fluent Forms and FluentCRM copied into dev/.third-party (dev/test.sh, dev/forms.sh):
// a form with a FluentCRM feed and double opt-in. Someone who subscribes from the English page is an English contact
// and gets the confirmation email in English; from the Italian page in Italian. The texts of FluentCRM's emails are
// strings of the site; the emails of automations and campaigns (the same filter) are translated for the contact's
// language before FluentCRM resolves its codes.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fetchRetry } from './fetch.mjs';

const base = process.env.LB_BASE;
const wp = (...args) => execFileSync('docker', ['compose', 'run', '--rm', '-T', 'cli', 'wp', ...args], { cwd: process.env.LB_DEV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
let made;

// A form (a name, an email) whose FluentCRM feed subscribes the visitor with double opt-in; the double opt-in email
// written in Italian; an Italian page with the form and its English version.
const setup = `
global $wpdb;
$text = array( 'index' => 0, 'element' => 'input_text', 'attributes' => array( 'type' => 'text', 'name' => 'nome', 'value' => '', 'placeholder' => '' ), 'settings' => array( 'label' => 'LB CRM Nome', 'help_message' => '', 'validation_rules' => array( 'required' => array( 'value' => false, 'message' => '' ) ), 'conditional_logics' => array() ), 'editor_options' => array( 'title' => 'Simple Text' ), 'uniqElKey' => 'el_lbc1' );
$email = array( 'index' => 1, 'element' => 'input_email', 'attributes' => array( 'type' => 'email', 'name' => 'email', 'value' => '', 'placeholder' => '' ), 'settings' => array( 'label' => 'LB CRM Email', 'help_message' => '', 'validation_rules' => array( 'required' => array( 'value' => true, 'message' => 'LB CRM Serve la email' ), 'email' => array( 'value' => true, 'message' => 'This field must contain a valid email' ) ), 'conditional_logics' => array() ), 'editor_options' => array( 'title' => 'Email' ), 'uniqElKey' => 'el_lbc2' );
$fields = array(
  'fields' => array( $text, $email ),
  'submitButton' => array( 'uniqElKey' => 'el_lbc3', 'element' => 'button', 'attributes' => array( 'type' => 'submit', 'class' => '' ), 'settings' => array( 'align' => 'left', 'button_style' => 'default', 'button_size' => 'md', 'button_ui' => array( 'type' => 'default', 'text' => 'LB CRM Iscriviti', 'img_url' => '' ) ), 'editor_options' => array( 'title' => 'Submit Button' ) ),
);
$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'LB CRM Iscrizione', 'status' => 'published', 'form_fields' => wp_json_encode( $fields ), 'has_payment' => 0, 'type' => 'form', 'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$form = (int) $wpdb->insert_id;
$settings = \\FluentForm\\App\\Models\\Form::getFormsDefaultSettings();
$settings['confirmation']['redirectTo'] = 'samePage';
$settings['confirmation']['messageToShow'] = '<p>LB CRM Controlla la posta</p>';
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => $form, 'meta_key' => 'formSettings', 'value' => wp_json_encode( $settings ) ) );
$feed = array( 'name' => 'LB CRM', 'first_name' => '', 'last_name' => '', 'full_name' => '{inputs.nome}', 'email' => '{inputs.email}', 'other_fields' => array(), 'company_fields' => array(), 'list_id' => '', 'tag_ids' => array(), 'tag_ids_selection_type' => 'simple', 'tag_routers' => array(), 'skip_if_exists' => false, 'double_opt_in' => true, 'force_subscribe' => false, 'skip_primary_data' => false, 'conditionals' => array( 'conditions' => array(), 'status' => false, 'type' => 'all' ), 'enabled' => true );
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => $form, 'meta_key' => 'fluentcrm_feeds', 'value' => wp_json_encode( $feed ) ) );
$old = fluentcrm_get_option( 'double_optin_settings', null );
fluentcrm_update_option( 'double_optin_settings', array(
  'email_subject' => 'LB CRM Conferma la tua iscrizione',
  'email_pre_header' => 'LB CRM Ancora un passo',
  'email_body' => '<p>LB CRM Ciao {{contact.first_name}}, conferma la tua iscrizione.</p><p><a href="#activate_link#">LB CRM Confermo</a></p>',
  'design_template' => 'simple',
  'after_confirmation_type' => 'message',
  'after_confirm_message' => '<p>LB CRM Iscrizione confermata</p>',
  'after_conf_redirect_url' => '',
) );
$it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB CRM Iscriviti', 'post_name' => 'lb-crm-iscriviti', 'post_content' => '[fluentform id="' . $form . '"]' ) ) );
$en = LinguaBridge\\Translations::create_draft( $it, 'en' );
wp_update_post( wp_slash( array( 'ID' => $en, 'post_status' => 'publish', 'post_title' => 'LB CRM Subscribe', 'post_name' => 'lb-crm-subscribe' ) ) );
echo wp_json_encode( array( 'form' => $form, 'it' => $it, 'en' => $en, 'it_path' => wp_parse_url( get_permalink( $it ), PHP_URL_PATH ), 'en_path' => wp_parse_url( get_permalink( $en ), PHP_URL_PATH ), 'old' => $old ) );
`;

before(() => {
  wp('plugin', 'activate', 'fluentform', '--quiet');
  wp('plugin', 'activate', 'fluent-crm', '--quiet');
  made = JSON.parse(wp('eval', '--user=admin', setup).split('\n').pop());
});

after(() => {
  const old = made.old === null || made.old === false || made.old === '' ? "fluentcrm_delete_option( 'double_optin_settings' );" : `fluentcrm_update_option( 'double_optin_settings', json_decode( base64_decode( '${Buffer.from(JSON.stringify(made.old)).toString('base64')}' ), true ) );`;
  wp('eval', '--user=admin', `global $wpdb; foreach ( array( ${made.en}, ${made.it} ) as $id ) { LinguaBridge\\Storage::delete( $id ); wp_delete_post( $id, true ); } $wpdb->delete( $wpdb->prefix . 'fluentform_forms', array( 'id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'fluentform_submission_meta', array( 'form_id' => ${made.form} ) ); $wpdb->delete( $wpdb->prefix . 'ff_scheduled_actions', array( 'form_id' => ${made.form} ) ); $ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE 'lb-crm-%'" ); foreach ( $ids as $id ) { $wpdb->delete( $wpdb->prefix . 'fc_subscriber_meta', array( 'subscriber_id' => $id ) ); $wpdb->delete( $wpdb->prefix . 'fc_subscribers', array( 'id' => $id ) ); } $wpdb->query( 'DELETE FROM ' . LinguaBridge\\Strings::table() . " WHERE source LIKE 'LB CRM %'" ); delete_option( 'lingua_bridge_form_${made.form}' ); delete_option( 'lb_dev_mail_log' ); ${old}`);
  wp('plugin', 'deactivate', 'fluent-crm', '--quiet');
  wp('plugin', 'deactivate', 'fluentform', '--quiet');
});

// A read first: a keep-alive connection closed by Apache meanwhile is retried on a read, never on a write.
const submit = async (from, data) => {
  await fetchRetry(base + from);
  const body = new URLSearchParams({ action: 'fluentform_submit', form_id: String(made.form), data: new URLSearchParams(data).toString() });
  const r = await fetchRetry(base + '/wp-admin/admin-ajax.php', { method: 'POST', body, headers: { Referer: base + from, 'X-Requested-With': 'XMLHttpRequest' } });
  return r.json();
};
// Whatever Fluent Forms left for later is sent too (FluentCRM's feed runs during the submission).
const sendQueued = () => wp('eval', `wpFluentForm( 'fluentFormAsyncRequest' )->processActions();`);
const mails = () => JSON.parse(wp('eval', `echo wp_json_encode( get_option( 'lb_dev_mail_log', array() ) );`));
const mailTo = (list, to) => list.find((m) => m.to.includes(to));
const contact = (email) => JSON.parse(wp('eval', `$s = \\FluentCrm\\App\\Models\\Subscriber::where( 'email', '${email}' )->first(); echo wp_json_encode( $s ? array( 'id' => (int) $s->id, 'status' => $s->status, 'language' => (string) fluentcrm_get_subscriber_meta( $s->id, '_lingua_bridge_language', '' ) ) : null );`));
const registered = () => JSON.parse(wp('eval', `global $wpdb; echo wp_json_encode( $wpdb->get_col( 'SELECT DISTINCT source FROM ' . LinguaBridge\\Strings::table() . " WHERE context = 'html' AND source LIKE 'LB CRM %' ORDER BY source" ) );`));

test('product 10: a contact who subscribes from the Italian page is Italian, its confirmation email is Italian and its texts are strings of the site', async () => {
  wp('option', 'update', 'lb_dev_mail_log', '[]', '--format=json');
  await submit(made.it_path, { nome: 'Bruno', email: 'lb-crm-bruno@example.test' });
  sendQueued();
  const saved = contact('lb-crm-bruno@example.test');
  assert.ok(saved, 'contact created by the feed');
  assert.equal(saved.status, 'pending');
  assert.equal(saved.language, 'it', 'the language of the original is kept too');
  const mail = mailTo(mails(), 'lb-crm-bruno@example.test');
  assert.ok(mail, 'double opt-in email sent: ' + JSON.stringify(mails()));
  assert.equal(mail.subject, 'LB CRM Conferma la tua iscrizione');
  assert.match(mail.message, /LB CRM Ciao Bruno, conferma la tua iscrizione\./);
  const strings = registered();
  for (const text of ['LB CRM Conferma la tua iscrizione', 'LB CRM Ancora un passo', 'LB CRM Ciao {{contact.first_name}}, conferma la tua iscrizione.', 'LB CRM Confermo']) {
    assert.ok(strings.includes(text), `${text} registered: ${JSON.stringify(strings)}`);
  }
});

// The translations of the texts registered above, published as a person would.
const publish = () => wp('eval', `global $wpdb; foreach ( array( 'LB CRM Conferma la tua iscrizione' => 'LB CRM Confirm your subscription', 'LB CRM Ancora un passo' => 'LB CRM One more step', 'LB CRM Ciao {{contact.first_name}}, conferma la tua iscrizione.' => 'LB CRM Hi {{contact.first_name}}, please confirm your subscription.', 'LB CRM Confermo' => 'LB CRM I confirm', 'LB CRM Grazie {{contact.first_name}} per la lettura' => 'LB CRM Thank you {{contact.first_name}} for reading' ) as $source => $target ) { $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LinguaBridge\\Strings::table() . " WHERE context = 'html' AND language = 'en' AND source = %s", $source ) ); if ( $id ) { LinguaBridge\\Strings::save( $id, $target, 'published' ); } }`);

test('product 10: a contact who subscribes from the English page is English and gets the confirmation email in English', async () => {
  publish();
  wp('option', 'update', 'lb_dev_mail_log', '[]', '--format=json');
  await submit(made.en_path, { nome: 'Anna', email: 'lb-crm-anna@example.test' });
  sendQueued();
  const saved = contact('lb-crm-anna@example.test');
  assert.ok(saved, 'contact created by the feed');
  assert.equal(saved.language, 'en');
  const mail = mailTo(mails(), 'lb-crm-anna@example.test');
  assert.ok(mail, 'double opt-in email sent: ' + JSON.stringify(mails()));
  assert.equal(mail.subject, 'LB CRM Confirm your subscription');
  assert.match(mail.message, /LB CRM Hi Anna, please confirm your subscription\./);
  assert.match(mail.message, /LB CRM I confirm/);
  assert.match(mail.message, /LB CRM One more step/);
  assert.doesNotMatch(mail.message, /LB CRM Ciao/);
});

// Automations and campaigns pass their texts through the same filter, later and without a page: the language is the
// contact's.
test("product 10: the texts of automations and campaigns are translated in the contact's language, with FluentCRM's codes resolved", () => {
  const parse = (email) => wp('eval', `$s = \\FluentCrm\\App\\Models\\Subscriber::where( 'email', '${email}' )->first(); echo apply_filters( 'fluent_crm/parse_campaign_email_text', '<p>LB CRM Grazie {{contact.first_name}} per la lettura</p>', $s );`);
  // The first pass registers the text; once translated, the English contact reads it in English.
  assert.equal(parse('lb-crm-bruno@example.test'), '<p>LB CRM Grazie Bruno per la lettura</p>');
  assert.ok(registered().includes('LB CRM Grazie {{contact.first_name}} per la lettura'));
  publish();
  assert.equal(parse('lb-crm-anna@example.test'), '<p>LB CRM Thank you Anna for reading</p>');
  assert.equal(parse('lb-crm-bruno@example.test'), '<p>LB CRM Grazie Bruno per la lettura</p>');
});
