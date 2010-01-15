<?php

if (!class_exists('reCAPTCHA')) {
    class reCAPTCHA {
        // member variables
        private $options;
        private $wordpress_mu;

        private $saved_error;
        
        function __construct() {
            // initialize anything that might need initializing
            
            // determine what environment we're in
            $this->determine_environment();
            
            // get the site options
            $this->retrieve_options();
            
            // require the recaptcha library
            $this->require_library();
            
            // register the hooks
            $this->register_actions();
            $this->register_filters();
        }
        
        function register_actions() {
            // Actions
            
            // load the plugin's textdomain for localization
            add_action('init', 'load_textdomain');

            // styling
            add_action('wp_head', array(&$this, 'register_stylesheets')); // make unnecessary: instead, inform of classes for styling
            add_action('admin_head', array(&$this, 'register_stylesheets')); // make unnecessary: shouldn't require styling in the options page
            
            if ($this->options['show_in_registration'])
                add_action('login_head', array(&$this, 'registration_style')); // make unnecessary: instead use jQuery and add to the footer?

            // options
            register_activation_hook($this->path_to_plugin(), array(&$this, 'register_default_options')); // this way it only happens once, when the plugin is activated
            add_action('admin_init', array(&$this, 'register_settings_group'));

            // only register the hooks if the user wants recaptcha on the registration page
            if ($this->options['show_in_registration']) {
                // recaptcha form display
                if ($this->wordpress_mu)
                    add_action('signup_extra_fields', array(&$this, 'show_recaptcha_in_registration'));
                else
                    add_action('register_form', array(&$this, 'show_recaptcha_in_registration'));
            }

            // only register the hooks if the user wants recaptcha on the comments page
            if ($this->options['show_in_comments']) {
                add_action('comment_form', array(&$this, 'show_recaptcha_in_comments'));
                add_action('wp_footer', array(&$this, 'save_comment_script')); // preserve the comment that was entered

                // recaptcha comment processing (look into doing all of this with AJAX, optionally)
                add_action('wp_head', array(&$this, 'saved_comment'), 0);
                add_action('preprocess_comment', array(&$this, 'check_comment'), 0);
                add_action('comment_post_redirect', array(&$this, 'relative_redirect'), 0, 2);
            }

            // administration (menus, pages, notifications, etc.)
            $plugin = plugin_basename($this->path_to_plugin());
            add_filter("plugin_action_links_$plugin", array(&$this, 'show_settings_link'));

            add_action('admin_menu', array(&$this, 'add_settings_page'));
        }
        
        function register_filters() {
            // Filters

            // only register the hooks if the user wants recaptcha on the registration page
            if ($this->options['show_in_registration']) {
                // recaptcha validation
                if ($this->wordpress_mu)
                    add_filter('wpmu_validate_user_signup', array(&$this, 'validate_recaptcha_response_wpmu'));
                else
                    add_filter('registration_errors', array(&$this, 'validate_recaptcha_response'));
            }
        }
        
        function load_textdomain() {
            load_plugin_textdomain('recaptcha', false, 'locales');
        }
        
        // determine whether it's WordPress regular or WordPress MU sitewide
        function determine_environment() {
            // is it wordpress mu?
            if (is_dir(WP_CONTENT_DIR . '/mu-plugins')) {
                // is it site-wide?
                if (is_file(WP_CONTENT_DIR . '/mu-plugins/wp-recaptcha.php')) // forced activated
                   $this->wordpress_mu = true;
            }
            
            // otherwise it's just regular wordpress
            else {
                $this->wordpress_mu = false;
            }
        }
        
        // some utility methods for path-finding
        function plugins_directory() {
            if ($this->wordpress_mu)
                return WP_CONTENT_DIR . '/mu-plugins';
            else
                return WP_CONTENT_DIR . '/plugins';
        }
        
        function path_to_plugin_directory() {
            return $this->plugins_directory() . '/wp-recaptcha/';
        }
        
        function path_to_plugin() {
            if ($this->wordpress_mu)
                return $this->plugins_directory() . '/wp-recaptcha.php';
            else
                return $this->path_to_plugin_directory() . '/wp-recaptcha.php';
        }
        
        // set the default options
        function register_default_options() {
            // store the options in an array, to ensure that the options will be stored in a single database entry
            $option_defaults = array();

            // migrate the settings from the previous version of the plugin if they exist
            $old_options = get_option('recaptcha');
            if ($old_options) {
                // keys
                $option_defaults['public_key'] = $old_options['pubkey']; // the public key for reCAPTCHA
                $option_defaults['private_key'] = $old_options['privkey']; // the private key for reCAPTCHA

                // placement
                $option_defaults['show_in_comments'] = $old_options['re_comments']; // whether or not to show reCAPTCHA on the comment post
                $option_defaults['show_in_registration'] = $old_options['re_registration']; // whether or not to show reCAPTCHA on the registration page

                // bypass levels
                $option_defaults['bypass_for_registered_users'] = $old_options['re_bypass']; // whether to skip reCAPTCHAs for registered users
                $option_defaults['minimum_bypass_level'] = $old_options['re_bypasslevel']; // who doesn't have to do the reCAPTCHA (should be a valid WordPress capability slug)

                // styling
                $option_defaults['comments_theme'] = $old_options['re_theme']; // the default theme for reCAPTCHA on the comment post
                $option_defaults['registration_theme'] = $old_options['re_theme_reg']; // the default theme for reCAPTCHA on the registration form
                $option_defaults['recaptcha_language'] = $old_options['re_lang']; // the default language for reCAPTCHA
                $option_defaults['xhtml_compliance'] = $old_options['re_xhtml']; // whether or not to be XHTML 1.0 Strict compliant
                $option_defaults['comments_tab_index'] = $old_options['re_tabindex']; // the default tabindex for reCAPTCHA
                $option_defaults['registration_tab_index'] = 30; // the default tabindex for reCAPTCHA

                // error handling
                $option_defaults['no_response_error'] = $old_options['error_blank']; // message for no CAPTCHA response
                $option_defaults['incorrect_response_error'] = $old_options['error_incorrect']; // message for incorrect CAPTCHA response
                
                // now remove the option from the wp_options table because it's no longer needed
                // at least someone cares to keep the database nice and tidy, right?
                // todo: if this is done here, then mailhide won't be able to retrieve the options
                // possible solutions:
                //      - set priority level of mailhide to be after this one and then delete the option in that one
                //      - make mailhide a member object of this class and call mailhide's register_default_options in this section?
                //      - merge mailhide code into this class
                delete_option('recaptcha');
            }
            
            // define new settings
            else {
                // keys
                $option_defaults['public_key'] = ''; // the public key for reCAPTCHA
                $option_defaults['private_key'] = ''; // the private key for reCAPTCHA

                // placement
                $option_defaults['show_in_comments'] = 1; // whether or not to show reCAPTCHA on the comment post
                $option_defaults['show_in_registration'] = 1; // whether or not to show reCAPTCHA on the registration page

                // bypass levels
                $option_defaults['bypass_for_registered_users'] = 1; // whether to skip reCAPTCHAs for registered users
                $option_defaults['minimum_bypass_level'] = 'read'; // who doesn't have to do the reCAPTCHA (should be a valid WordPress capability slug)

                // styling
                $option_defaults['comments_theme'] = 'red'; // the default theme for reCAPTCHA on the comment post
                $option_defaults['registration_theme'] = 'red'; // the default theme for reCAPTCHA on the registration form
                $option_defaults['recaptcha_language'] = 'en'; // the default language for reCAPTCHA
                $option_defaults['xhtml_compliance'] = 0; // whether or not to be XHTML 1.0 Strict compliant
                $option_defaults['comments_tab_index'] = 5; // the default tabindex for reCAPTCHA
                $option_defaults['registration_tab_index'] = 30; // the default tabindex for reCAPTCHA

                // error handling
                $option_defaults['no_response_error'] = '<strong>ERROR</strong>: Please fill in the reCAPTCHA form.'; // message for no CAPTCHA response
                $option_defaults['incorrect_response_error'] = '<strong>ERROR</strong>: That reCAPTCHA response was incorrect.'; // message for incorrect CAPTCHA response
            }

            // add the option based on what environment we're in
            if ($this->wordpress_mu)
                add_site_option('recaptcha_options', $option_defaults);
            else
                add_option('recaptcha_options', $option_defaults);
        }
        
        // retrieve the options (call as needed for refresh)
        function retrieve_options() {
            if ($this->wordpress_mu)
                $this->options = get_site_option('recaptcha_options');

            else
                $this->options = get_option('recaptcha_options');
        }
        
        // require the recaptcha library
        function require_library() {
            require_once($this->path_to_plugin_directory() . '/recaptchalib.php');
        }
        
        // register the settings
        function register_settings_group() {
            register_setting('recaptcha_options_group', 'recaptcha_options', array(&$this, 'validate_options'));
        }
        
        // todo: make unnecessary
        function register_stylesheets() {
            $path = $this->path_to_plugin_directory() . '/recaptcha.css';
                
            echo '<link rel="stylesheet" type="text/css" href="' . $path . '" />';
        }
        
        // stylesheet information
        function registration_style() {
            $width = 0; // the width of the recaptcha form

            // every theme is 358 pixels wide except for the clean theme, so we have to programmatically handle that
            if ($this->options['registration_theme'] == 'clean')
                $width = 485;
            else
                $width = 360;

            echo <<<REGISTRATION
                <script type="text/javascript">
                window.onload = function() {
                    document.getElementById('login').style.width = '{$width}px';
                    document.getElementById('reg_passmail').style.marginTop = '10px';
                    document.getElementById('recaptcha_widget_div').style.marginBottom = '10px';
                };
                </script>
REGISTRATION;
        }
        
        // display recaptcha
        function show_recaptcha_in_registration($errors) {
            $format = <<<FORMAT
            <script type='text/javascript'>
            var RecaptchaOptions = { theme : '{$this->options['registration_theme']}', lang : '{$this->options['recaptcha_language']}' , tabindex : {$this->options['registration_tab_index']} };
            </script>
FORMAT;

            $comment_string = <<<COMMENT_FORM
            <script type='text/javascript'>   
            document.getElementById('recaptcha_table').style.direction = 'ltr';
            </script>
COMMENT_FORM;

            // todo: is this check necessary? look at the latest recaptchalib.php
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on")
                $use_ssl = true;
            else
                $use_ssl = false;

            // if it's for wordpress mu, show the errors
            if ($this->wordpress_mu) {
                $error = $errors->get_error_message('captcha');
                echo '<label for="verification">Verification:</label>';
                echo ($error ? '<p class="error">'.$error.'</p>' : '');
                echo $format . $this->get_recaptcha_html($_GET['rerror'], $use_ssl);
            }
            
            // for regular wordpress
            else {
                echo $format . $this->get_recaptcha_html($_GET['rerror'], $use_ssl);
            }
        }
        
        function validate_dropdown($array, $key, $value) {
            // make sure that the capability that was supplied is a valid capability from the drop-down list
            if (in_array($value, $array))
                return $value;
            else // if not, load the old value
                return $this->options[$key];
        }
        
        function validate_options($input) {
            // todo: make sure that 'incorrect_response_error' is not empty, prevent from being empty in the validation phase
            
            // trim the spaces out of the key, as they are usually present when copied and pasted
            // todo: keys seem to usually be 40 characters in length, verify and if confirmed, add to validation process
            $validated['public_key'] = trim($input['public_key']);
            $validated['private_key'] = trim($input['private_key']);
            
            $validated['show_in_comments'] = ($input['show_in_comments'] == 1 ? 1 : 0);
            $validated['bypass_for_registered_users'] = ($input['bypass_for_registered_users'] == 1 ? 1: 0);
            
            $capabilities = array ('read', 'edit_posts', 'publish_posts', 'moderate_comments', 'level_10');
            $themes = array ('red', 'white', 'blackglass', 'clean');
            
            $recaptcha_languages = array ('en', 'nl', 'fr', 'de', 'pt', 'ru', 'es', 'tr');
            
            $validated['minimum_bypass_level'] = $this->validate_dropdown($capabilities, 'minimum_bypass_level', $input['minimum_bypass_level']);
            $validated['comments_theme'] = $this->validate_dropdown($themes, 'comments_theme', $input['comments_theme']);
            
            $validated['comments_tab_index'] = $input['comments_tab_index']; // use the intval filter
            
            $validated['show_in_registration'] = ($input['show_in_registration'] == 1 ? 1 : 0);
            $validated['registration_theme'] = $this->validate_dropdown($themes, 'registration_theme', $input['registration_theme']);
            $validated['registration_tab_index'] = $input['registration_tab_index']; // use the intval filter
            
            $validated['recaptcha_language'] = $this->validate_dropdown($recaptcha_languages, 'recaptcha_language', $input['recaptcha_language']);
            $validated['xhtml_compliance'] = ($input['xhtml_compliance'] == 1 ? 1 : 0);
            
            $validated['no_response_error'] = $input['no_response_error'];
            $validated['incorrect_response_error'] = $input['incorrect_response_error'];
            
            return $validated;
        }
        
        function validate_recaptcha_response($errors) {
            // empty so throw the empty response error
            if (empty($_POST['recaptcha_response_field']) || $_POST['recaptcha_response_field'] == '') {
                $errors->add('blank_captcha', $this->options['no_response_error']);
                return $errors;
            }

            $response = recaptcha_check_answer($this->options['private_key'], $_SERVER['REMOTE_ADDR'], $_POST['recaptcha_challenge_field'], $_POST['recaptcha_response_field']);

            // response is bad, add incorrect response error
            if (!$response->is_valid)
                if ($response->error == 'incorrect-captcha-sol')
                    $errors->add('captcha_wrong', $this->options['incorrect_response_error']);

           return $errors;
        }
        
        function validate_recaptcha_response_wpmu($result) {
            // must make a check here, otherwise the wp-admin/user-new.php script will keep trying to call
            // this function despite not having called do_action('signup_extra_fields'), so the recaptcha
            // field was never shown. this way it won't validate if it's called in the admin interface
            
            if (!is_admin()) {
                // blogname in 2.6, blog_id prior to that
                // todo: why is this done?
                if (isset($_POST['blog_id']) || isset($_POST['blogname']))
                    return $result;
                    
                // no text entered
                if (empty($_POST['recaptcha_response_field']) || $_POST['recaptcha_response_field'] == '') {
                    $result['errors']->add('blank_captcha', $this->options['no_response_error']);
                    return $result;
                }
                
                $response = recaptcha_check_answer($this->options['private_key'], $_SERVER['REMOTEADDR'], $_POST['recaptcha_challenge_field'], $_POST['recaptcha_response_field']);
                
                // response is bad, add incorrect response error
                // todo: why echo the error here? wpmu specific?
                if (!$response->is_valid)
                    if ($response->error == 'incorrect-captcha-sol') {
                        $result['errors']->add('captcha_wrong', $this->options['incorrect_response_error']);
                        echo '<div class="error">' . $this->options['incorrect_response_error'] . '</div>';
                    }
                    
                return $result;
            }
        }
        
        // utility methods
        function hash_comment($id) {
            define ("RECAPTCHA_WP_HASH_SALT", "b7e0638d85f5d7f3694f68e944136d62");
            
            if (function_exists('wp_hash'))
                return wp_hash(RECAPTCHA_WP_HASH_COMMENT . $id);
            else
                return md5(RECAPTCHA_WP_HASH_COMMENT . $this->options['private_key'] . $id);
        }
        
        function get_recaptcha_html($recaptcha_error, $use_ssl=false) {
            return recaptcha_get_html($this->options['public_key'], $recaptcha_error, $use_ssl, $this->options['xhtml_compliance']);
        }
        
        function show_recaptcha_in_comments() {
            global $user_ID;

            // set the minimum capability needed to skip the captcha if there is one
            if ($this->options['bypass_for_registered_uysers'] && $this->options['minimum_bypass_level'])
                $needed_capability = $this->options['minimum_bypass_level'];

            // skip the reCAPTCHA display if the minimum capability is met
            if (($needed_capability && current_user_can($needed_capability)) || !$this->options['show_in_comments'])
                return;

            else {
                // Did the user fail to match the CAPTCHA? If so, let them know
                if (($_GET['rerror'] == 'incorrect-captcha-sol'))
                    echo '<p class="recaptcha-error">' . $this->options['incorrect_response_error'] . "</p>";

                //modify the comment form for the reCAPTCHA widget
                $recaptcha_js_opts = <<<OPTS
                <script type='text/javascript'>
                    var RecaptchaOptions = { theme : '{$this->options['comments_theme']}', lang : '{$this->options['recaptcha_language']}' , tabindex : {$this->options['comments_tab_index']} };
                </script>
OPTS;

                // todo: replace this with jquery: http://digwp.com/2009/06/including-jquery-in-wordpress-the-right-way/
                // todo: use math to increment+1 the submit button based on what the tab_index option is
                if ($this->options['xhtml_compliance']) {
                    $comment_string = <<<COMMENT_FORM
                        <div id="recaptcha-submit-btn-area">&nbsp;</div>
COMMENT_FORM;
                }

                else {
                    $comment_string = <<<COMMENT_FORM
                        <div id="recaptcha-submit-btn-area">&nbsp;</div>
                        <noscript>
                         <style type='text/css'>#submit {display:none;}</style>
                         <input name="submit" type="submit" id="submit-alt" tabindex="6" value="Submit Comment"/> 
                        </noscript>
COMMENT_FORM;
                }

                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on")
                    $use_ssl = true;
                else
                    $use_ssl = false;

                echo $recaptcha_js_opts . $this->get_recaptcha_html($_GET['rerror'], $use_ssl) . $comment_string;
           }
        }
        
        // this is what does the submit-button re-ordering
        function save_comment_script() {
            $javascript = <<<JS
                <script type="text/javascript">
                var sub = document.getElementById('submit');
                document.getElementById('recaptcha-submit-btn-area').appendChild (sub);
                document.getElementById('submit').tabIndex = 6;
                if ( typeof _recaptcha_wordpress_savedcomment != 'undefined') {
                        document.getElementById('comment').value = _recaptcha_wordpress_savedcomment;
                }
                document.getElementById('recaptcha_table').style.direction = 'ltr';
                </script>
JS;
            echo $javascript;
        }
        
        // todo: this doesn't seem necessary
        function show_captcha_for_comment() {
            global $user_ID;
            return true;
        }
        
        function check_comment($comment_data) {
            global $user_ID;
            
            if ($this->options['bypass_for_registered_users'] && $this->options['minimum_bypass_level'])
                $needed_capability = $this->options['minimum_bypass_level'];
            
            if (($needed_capability && current_user_can($needed_capability)) || !$this->options['show_in_comments'])
                return $comment_data;
            
            if ($this->show_captcha_for_comment()) {
                // do not check trackbacks/pingbacks
                if ($comment_data['comment_type'] == '') {
                    $challenge = $_POST['recaptcha_challenge_field'];
                    $response = $_POST['recaptcha_response_field'];
                    
                    $recaptcha_response = recaptcha_check_answer($this->options['private_key'], $_SERVER['REMOTE_ADDR'], $challenge, $response);
                    
                    if ($recaptcha_response->is_valid)
                        return $comment_data;
                        
                    else {
                        $this->saved_error = $recaptcha_response->error;
                        
                        add_filter('pre_comment_approved', create_function('$a', 'return \'spam\';'));
                        return $comment_data;
                    }
                }
            }
            
            return $comment_data;
        }
        
        function relative_redirect($location, $comment) {
            if ($this->saved_error != '') {
                // replace #comment- at the end of $location with #commentform
                
                $location = substr($location, 0, strpos($location, '#')) .
                    ((strpos($location, "?") === false) ? "?" : "&") .
                    'rcommentid=' . $comment->comment_ID .
                    '&rerror=' . $this->saved_error .
                    '&rchash=' . $this->hash_comment($comment->comment_ID) .
                    '#commentform';
            }
            
            return $location;
        }
        
        function saved_comment() {
            if (!is_single() && !is_page())
                return;
            
            if ($_GET['rcommentid'] && $_GET['rchash'] == $this->hash_comment($_GET['rcommentid'])) {
                $comment = get_comment($_GET['rcommentid']);

                // todo: removed double quote from list of 'dangerous characters'
                $com = preg_replace('/([\\/\(\)\+\;\'])/e','\'%\'.dechex(ord(\'$1\'))', $comment->comment_content);
                $com = preg_replace('/\\r\\n/m', '\\\n', $com);

                echo "
                <script type='text/javascript'>
                var _recaptcha_wordpress_savedcomment =  '" . $com  ."';
                _recaptcha_wordpress_savedcomment = unescape(_recaptcha_wordpress_savedcomment);
                </script>
                ";

                wp_delete_comment($comment->comment_ID);
            }
        }
        
        // todo: is this still needed?
        // this is used for the api keys url in the administration interface
        function blog_domain() {
            $uri = parse_url(get_settings('siteurl'));
            return $uri['host'];
        }
        
        // add a settings link to the plugin in the plugin list
        function show_settings_link($links) {
            $settings_title = __('Settings for this Plugin', 'recaptcha');
            $settings = __('Settings', 'recaptcha');
            $settings_link = '<a href="options-general.php?page=wp-recaptcha/recaptcha.php" title="' . $settings_title . '">' . $settings . '</a>';
            
            array_unshift($links, $settings_link);
            return $links;
        }
        
        // add the settings page
        function add_settings_page() {
            // add the options page
            if ($this->wordpress_mu && is_site_admin())
                add_submenu_page('wpmu-admin.php', 'WP-reCAPTCHA', 'WP-reCAPTCHA', 'manage_options', __FILE__, array(&$this, 'show_settings_page'));

            add_options_page('WP-reCAPTCHA', 'WP-reCAPTCHA', 'manage_options', __FILE__, array(&$this, 'show_settings_page'));
        }
        
        // store the xhtml in a separate file and use include on it
        function show_settings_page() {
            include("settings.html");
        }
        
        function build_dropdown($name, $keyvalue, $checked_value) {
            echo '<select name="' . $name . '" id="' . $name . '">' . "\n";
            
            foreach ($keyvalue as $key => $value) {
                if ($value == $checked_value)
                    $checked = ' selected="selected" ';
                
                echo '\t <option value="' . $value . '"' . $checked . ">$key</option> \n";
                $checked = NULL;
            }
            
            echo "</select> \n";
        }
        
        function capabilities_dropdown() {
            // define choices: Display text => permission slug
            $capabilities = array (
                __('all registered users', 'recaptcha') => 'read',
                __('edit posts', 'recaptcha') => 'edit_posts',
                __('publish posts', 'recaptcha') => 'publish_posts',
                __('moderate comments', 'recaptcha') => 'moderate_comments',
                __('administer site', 'recaptcha') => 'level_10'
            );
            
            $this->build_dropdown('recaptcha_options[minimum_bypass_level]', $capabilities, $this->options['minimum_bypass_level']);
        }
        
        function theme_dropdown($which) {
            $themes = array (
                __('Red', 'recaptcha') => 'red',
                __('White', 'recaptcha') => 'white',
                __('Black Glass', 'recaptcha') => 'blackglass',
                __('Clean', 'recaptcha') => 'clean'
            );
            
            if ($which == 'comments')
                $this->build_dropdown('recaptcha_options[comments_theme]', $themes, $this->options['comments_theme']);
            else if ($which == 'registration')
                $this->build_dropdown('recaptcha_options[registration_theme]', $themes, $this->options['registration_theme']);
        }
        
        function recaptcha_language_dropdown() {
            $languages = array (
                __('English', 'recaptcha') => 'en',
                __('Dutch', 'recaptcha') => 'nl',
                __('French', 'recaptcha') => 'fr',
                __('German', 'recaptcha') => 'de',
                __('Portuguese', 'recaptcha') => 'pt',
                __('Russian', 'recaptcha') => 'ru',
                __('Spanish', 'recaptcha') => 'es',
                __('Turkish', 'recaptcha') => 'tr'
            );
            
            $this->build_dropdown('recaptcha_options[recaptcha_language]', $languages, $this->options['recaptcha_language']);
        }
    } // end class declaration
} // end of class exists clause

?>