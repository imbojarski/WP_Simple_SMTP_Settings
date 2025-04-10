<?php

/**
 * Plugin Name: WP SMTP Settings
 * Description: Wtyczka do zarządzania ustawieniami serwera poczty w WordPress.
 * Version: 1.0.0
 * Author: Marcin Bojarski
 * Author URI: https://whooooops.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-smtp-settings
 */

use PHPMailer\PHPMailer\PHPMailer;

if (!defined('ABSPATH')) {
    exit;
}

class WP_Simple_SMTP_Settings {
    private static $instance;
    private string $option_name = 'wp_simple_smtp_settings';
    private array $default_settings = [
        'smtp_host'     => '',
        'smtp_port'     => '587',
        'smtp_auth'     => '1',
        'smtp_user'     => '',
        'smtp_pass'     => '',
        'smtp_secure'   => 'tls',
        'from_email'    => '',
        'from_name'     => '',
        'enable_smtp'   => '0',
        'admin_email'   => '',
        'test_email'    => ''
    ] ;

    /**
     * @param array<string|int> $options
     * @return array<string>
     */
    private function check_configuration_issues(array $options): array
    {
        $issues = [];
        
        if (empty($options['enable_smtp']) || $options['enable_smtp'] !== '1') {
            return [];
        }
        
        if (empty($options['smtp_host'])) {
            $issues[] = 'Nie skonfigurowano serwera SMTP.';
        }
        
        if (empty($options['smtp_port']) || !is_numeric($options['smtp_port'])) {
            $issues[] = 'Port SMTP jest nieprawidłowy.';
        }
        
        if (empty($options['from_email'])) {
            $issues[] = 'Adres e-mail nadawcy nie jest ustawiony.';
        } elseif (!is_email($options['from_email'])) {
            $issues[] = 'Adres e-mail nadawcy jest nieprawidłowy: ' . esc_html($options['from_email']);
        }
        
        if ($options['smtp_auth'] === '1') {
            if (empty($options['smtp_user'])) {
                $issues[] = 'Włączono uwierzytelnianie, ale nie podano nazwy użytkownika.';
            }
            if (empty($options['smtp_pass'])) {
                $issues[] = 'Włączono uwierzytelnianie, ale nie podano hasła.';
            }
        }
        
        if (!empty($options['admin_email']) && !is_email($options['admin_email'])) {
            $issues[] = 'Adres e-mail do testów jest nieprawidłowy: ' . esc_html($options['admin_email']);
        }
        
        return $issues;
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('phpmailer_init', [$this, 'configure_phpmailer'], 999);
        add_filter('wp_mail_from', [$this, 'override_wordpress_from_email'], 999);
        add_filter('wp_mail_from_name', [$this, 'override_wordpress_from_name'], 999);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }
    
    public function override_wordpress_from_email($from_email) {
        $options = get_option($this->option_name);
        
        if (empty($options['enable_smtp']) || $options['enable_smtp'] !== '1') {
            return $from_email;
        }
        
        if (!empty($options['from_email']) && is_email($options['from_email'])) {
            return $options['from_email'];
        }
        
        return $from_email;
    }
    
    public function override_wordpress_from_name($from_name) {
        $options = get_option($this->option_name);
        
        if (empty($options['enable_smtp']) || $options['enable_smtp'] !== '1') {
            return $from_name;
        }
        
        if (!empty($options['from_name'])) {
            return $options['from_name'];
        }
        
        return $from_name;
    }

    public static function get_instance(): WP_Simple_SMTP_Settings
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add_admin_menu(): void
    {
        add_options_page(
            'Ustawienia SMTP',
            'Ustawienia SMTP',
            'manage_options',
            'wp-simple-smtp-settings',
            [$this, 'display_settings_page']
        );
        
        add_action('admin_head', function() {
            echo '<style>
                .smtp-notice {
                    padding: 10px;
                    margin: 10px 0;
                    border-radius: 4px;
                }
                .smtp-notice-success {
                    background-color: #dff0d8;
                    border: 1px solid #d6e9c6;
                    color: #3c763d;
                }
                .smtp-notice-error {
                    background-color: #f2dede;
                    border: 1px solid #ebccd1;
                    color: #a94442;
                }
                .smtp-notice-warning {
                    background-color: #fcf8e3;
                    border: 1px solid #faebcc;
                    color: #8a6d3b;
                }
                .smtp-notice-info {
                    background-color: #d9edf7;
                    border: 1px solid #bce8f1;
                    color: #31708f;
                }
                #test-email-result {
                    margin-left: 10px;
                    font-weight: bold;
                }
                .test-email-field {
                    margin-top: 15px;
                    padding: 10px;
                    background-color: #f9f9f9;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
            </style>';
        });
    }

    public function register_settings(): void
    {
        register_setting(
            'wp_simple_smtp_settings_group',
            $this->option_name,
            [$this, 'sanitize_settings']
        );
    }

    public function sanitize_settings($input): array
    {
        $sanitized_input = [];

        $sanitized_input['smtp_host'] = sanitize_text_field($input['smtp_host']);
        $sanitized_input['smtp_port'] = absint($input['smtp_port']);
        $sanitized_input['smtp_auth'] = isset($input['smtp_auth']) ? '1' : '0';
        $sanitized_input['smtp_user'] = sanitize_text_field($input['smtp_user']);
        
        $options = get_option($this->option_name);
        if (empty($input['smtp_pass'])) {
            $sanitized_input['smtp_pass'] = $options['smtp_pass'] ?? '';
        } else {
            $sanitized_input['smtp_pass'] = $input['smtp_pass'];
        }
        
        $sanitized_input['smtp_secure'] = in_array($input['smtp_secure'], ['', 'tls', 'ssl']) ? $input['smtp_secure'] : 'tls';
        $sanitized_input['from_email'] = sanitize_email($input['from_email']);
        $sanitized_input['from_name'] = sanitize_text_field($input['from_name']);
        $sanitized_input['enable_smtp'] = isset($input['enable_smtp']) ? '1' : '0';
        $sanitized_input['admin_email'] = sanitize_email($input['admin_email']);
        $sanitized_input['test_email'] = sanitize_email($input['test_email']);

        return $sanitized_input;
    }

    public function display_settings_page(): void
    {
        $options = wp_parse_args(get_option($this->option_name), $this->default_settings);
        $configuration_issues = $this->check_configuration_issues($options);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!empty($configuration_issues)): ?>
                <div class="smtp-notice smtp-notice-warning">
                    <strong>Wykryto problemy z konfiguracją SMTP:</strong>
                    <ul>
                        <?php foreach ($configuration_issues as $issue): ?>
                            <li><?php echo esc_html($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('wp_simple_smtp_settings_group');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[enable_smtp]">Włącz SMTP</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="wp_simple_smtp_settings[enable_smtp]"
                                       name="wp_simple_smtp_settings[enable_smtp]"
                                       value="1" <?php checked('1', $options['enable_smtp']); ?> />
                                Włącz własne ustawienia SMTP
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[from_email]">Adres e-mail nadawcy</label>
                        </th>
                        <td>
                            <input type="email" id="wp_simple_smtp_settings[from_email]"
                                   name="wp_simple_smtp_settings[from_email]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($options['from_email']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[from_name]">Nazwa nadawcy</label>
                        </th>
                        <td>
                            <input type="text" id="wp_simple_smtp_settings[from_name]"
                                   name="wp_simple_smtp_settings[from_name]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($options['from_name']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_host]">Serwer SMTP</label>
                        </th>
                        <td>
                            <input type="text" id="wp_simple_smtp_settings[smtp_host]"
                                   name="wp_simple_smtp_settings[smtp_host]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($options['smtp_host']); ?>"
                                   placeholder="np. smtp.gmail.com" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_port]">Port SMTP</label>
                        </th>
                        <td>
                            <input type="number" id="wp_simple_smtp_settings[smtp_port]"
                                   name="wp_simple_smtp_settings[smtp_port]"
                                   class="small-text"
                                   value="<?php echo esc_attr($options['smtp_port']); ?>"
                                   placeholder="587" />
                            <p class="description">Typowe porty: 25, 465 (SSL), 587 (TLS)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_secure]">Typ zabezpieczenia</label>
                        </th>
                        <td>
                            <select id="wp_simple_smtp_settings[smtp_secure]"
                                    name="wp_simple_smtp_settings[smtp_secure]">
                                <option value="" <?php selected('', $options['smtp_secure']); ?>>Brak</option>
                                <option value="tls" <?php selected('tls', $options['smtp_secure']); ?>>TLS</option>
                                <option value="ssl" <?php selected('ssl', $options['smtp_secure']); ?>>SSL</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_auth]">Uwierzytelnianie</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="wp_simple_smtp_settings[smtp_auth]"
                                       name="wp_simple_smtp_settings[smtp_auth]"
                                       value="1" <?php checked('1', $options['smtp_auth']); ?> />
                                Wymagane uwierzytelnianie
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_user]">Nazwa użytkownika SMTP</label>
                        </th>
                        <td>
                            <input type="text" id="wp_simple_smtp_settings[smtp_user]"
                                   name="wp_simple_smtp_settings[smtp_user]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($options['smtp_user']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[smtp_pass]">Hasło SMTP</label>
                        </th>
                        <td>
                            <input type="password" id="wp_simple_smtp_settings[smtp_pass]"
                                   name="wp_simple_smtp_settings[smtp_pass]"
                                   class="regular-text"
                                   value=""
                                   placeholder="<?php echo !empty($options['smtp_pass']) ? '••••••••' : ''; ?>" />
                            <p class="description">Pozostaw puste, aby zachować istniejące hasło.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp_simple_smtp_settings[admin_email]">Domyślny adres e-mail do testów</label>
                        </th>
                        <td>
                            <input type="email" id="wp_simple_smtp_settings[admin_email]"
                                   name="wp_simple_smtp_settings[admin_email]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($options['admin_email']); ?>"
                                   placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                            <p class="description">Adres e-mail używany do wysyłania wiadomości testowych. Jeśli puste, zostanie użyty domyślny adres administratora (<?php echo esc_html(get_option('admin_email')); ?>).</p>
                        </td>
                    </tr>
                </table>

                <div class="test-email-field">
                    <h3>Wyślij wiadomość testową</h3>
                    <p>
                        <button type="button" id="test-email-button" class="button button-secondary">
                            Wyślij testowy e-mail
                        </button>
                        <span id="test-email-result"></span>
                    </p>
                </div>

                <?php submit_button('Zapisz ustawienia'); ?>
            </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#test-email-button').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#test-email-result');
                    var testEmailAddress = $('#test_email_address').val();
                    
                    $('#wp_simple_smtp_settings\\[test_email\\]').val(testEmailAddress);
                    
                    button.prop('disabled', true);
                    resultSpan.text('Wysyłanie...');
                    resultSpan.css('color', '');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wp_simple_smtp_test_email',
                            nonce: '<?php echo wp_create_nonce('wp_simple_smtp_test_email_nonce'); ?>',
                            test_email: testEmailAddress
                        },
                        success: function(response) {
                            if (response.success) {
                                resultSpan.text('E-mail testowy został wysłany pomyślnie!');
                                resultSpan.css('color', 'green');
                            } else {
                                resultSpan.text('Błąd: ' + response.data);
                                resultSpan.css('color', 'red');
                            }
                        },
                        error: function() {
                            resultSpan.text('Wystąpił błąd podczas wysyłania e-maila testowego.');
                            resultSpan.css('color', 'red');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function configure_phpmailer(PHPMailer $phpmailer): void
    {
        $options = wp_parse_args(get_option($this->option_name), $this->default_settings);
        
        if ($options['enable_smtp'] !== '1') {
            return;
        }
        
        $phpmailer->isSMTP();
        $phpmailer->Host = $options['smtp_host'];
        $phpmailer->Port = $options['smtp_port'];
        
        if ($options['smtp_auth'] === '1') {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $options['smtp_user'];
            $phpmailer->Password = $options['smtp_pass'];
        } else {
            $phpmailer->SMTPAuth = false;
        }
        
        if (!empty($options['smtp_secure'])) {
            $phpmailer->SMTPSecure = $options['smtp_secure'];
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }
        
        if (!empty($options['from_email']) && is_email($options['from_email'])) {
            $phpmailer->From = $options['from_email'];
            $phpmailer->Sender = $options['from_email'];
        } else {
            $admin_email = get_option('admin_email');
            $phpmailer->From = $admin_email;
            $phpmailer->Sender = $admin_email;
        }
        
        if (!empty($options['from_name'])) {
            $phpmailer->FromName = $options['from_name'];
        } else {
            $phpmailer->FromName = get_bloginfo('name');
        }
        
        $phpmailer->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 2;
            $phpmailer->Debugoutput = 'error_log';
        }
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=wp-simple-smtp-settings">Ustawienia</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

function wp_simple_smtp_settings_init(): void
{
    WP_Simple_SMTP_Settings::get_instance();
}
add_action('plugins_loaded', 'wp_simple_smtp_settings_init');

function wp_simple_smtp_test_email_ajax(): void
{
    check_ajax_referer('wp_simple_smtp_test_email_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $option_name = 'wp_simple_smtp_settings';
    $options = get_option($option_name);
    
    if (empty($options['enable_smtp']) || $options['enable_smtp'] !== '1') {
        wp_send_json_error('SMTP nie jest włączone w ustawieniach. Włącz SMTP i zapisz ustawienia przed testem.');
        return;
    }
    
    if (empty($options['smtp_host'])) {
        wp_send_json_error('Serwer SMTP nie jest skonfigurowany. Wprowadź adres serwera SMTP i zapisz ustawienia.');
        return;
    }
    
    if (empty($options['from_email']) || !is_email($options['from_email'])) {
        wp_send_json_error('Adres e-mail nadawcy jest nieprawidłowy. Wprowadź poprawny adres e-mail nadawcy i zapisz ustawienia.');
        return;
    }
    
    $test_email = isset($_POST['test_email']) && !empty($_POST['test_email']) ? sanitize_email($_POST['test_email']) : null;
    
    if ($test_email && is_email($test_email)) {
        $to = $test_email;
    } else if (!empty($options['test_email']) && is_email($options['test_email'])) {
        $to = $options['test_email'];
    } else if (!empty($options['admin_email']) && is_email($options['admin_email'])) {
        $to = $options['admin_email'];
    } else {
        $to = get_option('admin_email');
    }
    
    if (!is_email($to)) {
        wp_send_json_error('Adres e-mail odbiorcy jest nieprawidłowy. Wprowadź poprawny adres e-mail odbiorcy.');
        return;
    }
    
    $subject = 'Test ustawień SMTP - ' . get_bloginfo('name') . ' - ' . current_time('Y-m-d H:i:s');
    $message = 'To jest testowa wiadomość e-mail wysłana za pomocą wtyczki WP Simple SMTP Settings. Jeśli otrzymałeś tę wiadomość, oznacza to, że Twoje ustawienia SMTP działają poprawnie.<br><br>';
    $message .= 'Użyte ustawienia:<br>';
    $message .= '- Serwer: ' . esc_html($options['smtp_host']) . '<br>';
    $message .= '- Port: ' . esc_html($options['smtp_port']) . '<br>';
    $message .= '- Zabezpieczenie: ' . (empty($options['smtp_secure']) ? 'Brak' : esc_html($options['smtp_secure'])) . '<br>';
    $message .= '- Uwierzytelnianie: ' . ($options['smtp_auth'] === '1' ? 'Tak' : 'Nie') . '<br>';
    $message .= '- Nadawca: ' . esc_html($options['from_email']) . '<br>';
    $message .= '- Odbiorca testu: ' . esc_html($to) . '<br>';
    $message .= '<br>Czas wysłania: ' . current_time('Y-m-d H:i:s') . '<br>';
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $options['from_name'] . ' <' . $options['from_email'] . '>'
    );
    
    global $phpmailer;
    if (isset($phpmailer)) {
        $phpmailer->ErrorInfo = '';
    }
    
    $result = wp_mail($to, $subject, $message, $headers);
    
    if ($result) {
        wp_send_json_success('E-mail testowy został wysłany pomyślnie na adres ' . $to);
    } else {
        if (isset($phpmailer) && !empty($phpmailer->ErrorInfo)) {
            $error = $phpmailer->ErrorInfo;
            wp_send_json_error('Nie udało się wysłać e-maila testowego. Błąd: ' . $error);
        } else {
            wp_send_json_error('Nie udało się wysłać e-maila testowego. Sprawdź ustawienia serwera SMTP i upewnij się, że adres e-mail nadawcy jest prawidłowy.');
        }
    }
}
add_action('wp_ajax_wp_simple_smtp_test_email', 'wp_simple_smtp_test_email_ajax');
