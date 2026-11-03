<?php
/**
 * Optional visitor analytics. Recording, heatmaps and surveys stay disabled.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Provide optional visitor statistics with recording disabled.
 */
class Analytics {
	/**
	 * Register analytics output and privacy hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'snippet' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'events' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'privacy_content' ) );
	}

	/**
	 * Validate a public PostHog project key.
	 *
	 * @param mixed $key Candidate project key.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		return is_string( $key ) && preg_match( '/^phc_[A-Za-z0-9_-]{20,}$/D', $key ) ? $key : '';
	}

	/**
	 * Return supported PostHog cloud regions.
	 *
	 * @return array
	 */
	public static function regions() {
		return array(
			'us' => __( 'United States', 'homeward-rescue' ),
			'eu' => __( 'European Union', 'homeward-rescue' ),
		);
	}

	/**
	 * Resolve the host for a cloud region.
	 *
	 * @param string $region Region code.
	 * @return string
	 */
	public static function region_host( $region ) {
		return 'eu' === $region ? 'https://eu.i.posthog.com' : 'https://us.i.posthog.com';
	}

	/**
	 * Explain the required project key format.
	 *
	 * @return string
	 */
	public static function key_error() {
		return __( 'Enter a PostHog project API key starting with phc_ followed by at least 20 letters, numbers, underscores or hyphens, or leave it blank to keep statistics off.', 'homeward-rescue' );
	}

	/**
	 * Validate an HTTPS host without credentials or query data.
	 *
	 * @param mixed $host Candidate host URL.
	 * @return string
	 */
	public static function sanitize_host( $host ) {
		$host = Settings::https( $host );
		$parts = wp_parse_url( $host );
		$valid = filter_var( $host, FILTER_VALIDATE_URL ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
		return $valid ? rtrim( $host, '/' ) : '';
	}

	/**
	 * Check whether the saved key and host are valid.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::sanitize_key( Settings::get( 'posthog_key' ) )
			&& '' !== self::sanitize_host( Settings::get( 'posthog_host' ) );
	}

	/**
	 * Check whether this request may send visitor statistics.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::configured() && ! is_admin() && ! is_user_logged_in() && ! is_preview() && ! is_feed()
			&& ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_is_json_request();
	}

	/**
	 * Build the public analytics configuration.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'api_host'                    => self::sanitize_host( Settings::get( 'posthog_host' ) ),
			'person_profiles'             => 'identified_only',
			'capture_pageview'            => true,
			'capture_pageleave'           => true,
			'autocapture'                 => true,
			'rageclick'                   => true,
			'capture_dead_clicks'         => false,
			'capture_performance'         => array( 'web_vitals' => true ),
			'capture_exceptions'          => true,
			'disable_session_recording'   => true,
			'enable_heatmaps'             => false,
			'disable_surveys'             => true,
			'mask_all_text'               => true,
			'mask_all_element_attributes' => true,
			'persistence'                 => Settings::get( 'posthog_cookies' ) ? 'localStorage+cookie' : 'memory',
			'respect_dnt'                 => true,
		);
	}

	/**
	 * Add the analytics explanation to the privacy policy guide.
	 *
	 * @return void
	 */
	public static function privacy_content() {
		if ( self::configured() && function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Homeward Rescue',
				__( 'This site uses PostHog for visitor page views, clicks, web vitals and error reports. Logged-in users and visitors using Do Not Track or Global Privacy Control are excluded. Text and element attributes are masked. Visitor identifiers stay in memory unless cookies and local storage are enabled by the site administrator. Session recording, heatmaps and surveys are disabled.', 'homeward-rescue' )
			);
		}
	}

	/**
	 * Print the consent-aware analytics bootstrap.
	 *
	 * @return void
	 */
	public static function snippet() {
		if ( ! self::enabled() ) {
			return;
		}
		?>
<script id="homeward-analytics">
(function () {
	if (navigator.globalPrivacyControl || navigator.doNotTrack === '1'
		|| navigator.doNotTrack === 'yes' || window.doNotTrack === '1') {
		return;
	}
<?php self::bootstrap(); ?>
	posthog.init(<?php echo wp_json_encode(
		self::sanitize_key( Settings::get( 'posthog_key' ) ),
		JSON_HEX_TAG | JSON_HEX_AMP
	); ?>, <?php echo wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP ); ?>);
	posthog.register(<?php echo wp_json_encode( array( 'site_environment' => wp_get_environment_type() ) ); ?>);
})();
</script>
		<?php
	}

	/**
	 * Track kit interactions without sending form values.
	 *
	 * @return void
	 */
	public static function events() {
		if ( ! self::enabled() ) {
			return;
		}
		$destinations = array(
			Applications::url( 'adopt' ),
			Applications::url( 'foster' ),
			Settings::get( 'volunteer_url' ),
		);
		?>
<script id="homeward-analytics-events">
(function () {
	'use strict';
	if (navigator.globalPrivacyControl || navigator.doNotTrack === '1'
		|| navigator.doNotTrack === 'yes' || window.doNotTrack === '1') {
		return;
	}
	var applications = <?php echo wp_json_encode(
			array_values( array_filter( $destinations ) ),
			JSON_HEX_TAG | JSON_HEX_AMP
		); ?>;
	var send = function (name, props) {
		if (window.posthog && window.posthog.capture) {
			window.posthog.capture(name, props || {});
		}
	};
	// Shared with assets/js/features.js so feature events use this same path and privacy checks.
	window.homewardAnalytics = { capture: send };
	document.addEventListener('click', function (event) {
		var el = event.target.closest ? event.target.closest('a, button') : null;
		if (!el) { return; }
		var href = el.getAttribute('href') || '';
		var props = { page: location.pathname };
		if (el.hasAttribute('data-homeward-copy')) { send('etransfer_email_copied', props); }
		else if (el.closest('.homeward-donate')) { send('donate_click', props); }
		else if (el.hasAttribute('data-homeward-share')) { send('animal_share_opened', props); }
		else if (applications.indexOf(href) !== -1 || /^https:\/\/(?:www\.)?petstablished\.com\//i.test(href)) {
			send('application_click', props);
		}
		else if (el.closest('.homeward-animal-page') && /^mailto:/i.test(href)) { send('animal_ask_clicked', props); }
	}, true);
	document.addEventListener('submit', function (event) {
		if (event.target.closest && event.target.closest('.homeward-contact')) {
			send('contact_form_submitted', { page: location.pathname });
		}
	}, true);
	document.addEventListener('change', function (event) {
		var field = event.target;
		if (field.closest && field.closest('[data-homeward-filters]')) {
			send('animal_filter_used', { filter: field.name || field.id || '' });
		}
	}, true);
})();
</script>
		<?php
	}
	/**
	 * Print the deferred analytics loader and its command queue.
	 *
	 * @return void
	 */
	private static function bootstrap() {
		echo <<<'JS'
(function (document, queue) {
	if (queue.__SV) { return; }
	window.posthog = queue;
	queue._i = [];
	queue.init = function (key, settings, alias) {
		function queueMethod(target, method) {
			var parts = method.split('.');
			if (parts.length === 2) {
				target = target[parts[0]];
				method = parts[1];
			}
			target[method] = function () {
				target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
			};
		}
		var script = document.createElement('script');
		script.type = 'text/javascript';
		script.crossOrigin = 'anonymous';
		script.async = true;
		script.src = settings.api_host.replace('.i.posthog.com', '-assets.i.posthog.com') + '/static/array.js';
		var first = document.getElementsByTagName('script')[0];
		first.parentNode.insertBefore(script, first);
		var instance = queue;
		if (alias !== undefined) {
			instance = queue[alias] = [];
		} else {
			alias = 'posthog';
		}
		instance.people = instance.people || [];
		instance.toString = function (nested) {
			var name = 'posthog';
			if (alias !== 'posthog') {
				name += '.' + alias;
			}
			if (!nested) {
				name += ' (stub)';
			}
			return name;
		};
		instance.people.toString = function () {
			return instance.toString(1) + '.people (stub)';
		};
		var methods = ('init capture register register_once unregister opt_out_capturing '
			+ 'has_opted_out_capturing opt_in_capturing reset get_distinct_id getFeatureFlag '
			+ 'isFeatureEnabled onFeatureFlags').split(' ');
		for (var i = 0; i < methods.length; i++) {
			queueMethod(instance, methods[i]);
		}
		queue._i.push([key, settings, alias]);
	};
	queue.__SV = 1;
})(document, window.posthog || []);
JS;
	}

}
