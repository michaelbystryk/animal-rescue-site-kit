<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Define the settings volunteers can edit, grouped by task.
 */
class Settings_Fields {
	/**
	 * Return the ordered settings sections and their setup links.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'rescue'     => array( __( 'Your rescue', 'homeward-rescue' ), 1, self::rescue() ),
			'animals'    => array( __( 'Animals and sync', 'homeward-rescue' ), 2, self::animals() ),
			'donations'  => array( __( 'Donations', 'homeward-rescue' ), 3, self::donations() ),
			'contact'    => array( __( 'Contact form', 'homeward-rescue' ), 0, self::contact() ),
			'statistics' => array( __( 'Statistics', 'homeward-rescue' ), 6, self::statistics() ),
			'features'   => array( __( 'Profile features', 'homeward-rescue' ), 0, self::features() ),
			'search'     => array( __( 'Search and sharing', 'homeward-rescue' ), 0, self::search() ),
			'advanced'   => array( __( 'Advanced', 'homeward-rescue' ), 0, self::advanced() ),
		);
	}

	/**
	 * Return field definitions indexed by their existing option keys.
	 *
	 * @return array
	 */
	public static function all() {
		$fields = array();
		foreach ( self::sections() as $section ) {
			$fields = array_merge( $fields, $section[2] );
		}
		return $fields;
	}

	private static function rescue() {
		return array_merge( self::identity_fields(), self::appearance_fields() );
	}

	private static function identity_fields() {
		return array(
			'org_name' => array(
				__( 'Rescue name', 'homeward-rescue' ),
				__( 'Use the name visitors know your rescue by.', 'homeward-rescue' ),
				'text',
			),
			'contact_email' => array(
				__( 'Contact email', 'homeward-rescue' ),
				__( 'The default inbox for messages from your contact page.', 'homeward-rescue' ),
				'email',
			),
			'phone' => array(
				__( 'Phone number', 'homeward-rescue' ),
				__( 'Include the area code and any extension.', 'homeward-rescue' ),
				'tel',
			),
			'country' => array(
				__( 'Country code', 'homeward-rescue' ),
				__( 'Enter a two-letter country code, such as CA or US; CA enables e-Transfer donations.', 'homeward-rescue' ),
				'text',
			),
			'currency' => array(
				__( 'Donation currency', 'homeward-rescue' ),
				__( 'Leave blank to choose the currency from your country, or enter a three-letter code such as CAD.', 'homeward-rescue' ),
				'text',
			),
			'help_contact_name' => array(
				__( 'Website helper', 'homeward-rescue' ),
				__( 'The person volunteers should ask for help with the website.', 'homeward-rescue' ),
				'text',
			),
			'help_contact_email' => array(
				__( 'Website helper email', 'homeward-rescue' ),
				__( 'Shown to volunteers in the dashboard guide.', 'homeward-rescue' ),
				'email',
			),
			'social' => array(
				__( 'Social profiles', 'homeward-rescue' ),
				__( 'Add a service name and its secure profile link in each entry.', 'homeward-rescue' ),
				'rows',
			),
			'logo_id' => array(
				__( 'Logo image number', 'homeward-rescue' ),
				__( 'Choose a logo in setup step 1, or enter its Media Library attachment number; 0 removes it.', 'homeward-rescue' ),
				'number',
			),
		);
	}

	private static function appearance_fields() {
		return array(
			'color_primary' => array(
				__( 'Links and buttons', 'homeward-rescue' ),
				__( 'Enter a hex colour or leave blank for the theme default; preview it in setup step 4.', 'homeward-rescue' ),
				'color',
			),
			'color_accent' => array(
				__( 'Highlights', 'homeward-rescue' ),
				__( 'Enter a hex colour or leave blank for the theme default; check contrast in setup step 4.', 'homeward-rescue' ),
				'color',
			),
			'color_surface' => array(
				__( 'Card background', 'homeward-rescue' ),
				__( 'Enter a hex colour for card backgrounds, or leave blank for the theme default.', 'homeward-rescue' ),
				'color',
			),
			'font_heading' => array(
				__( 'Heading font', 'homeward-rescue' ),
				__( 'Used for page titles and headings; setup step 5 previews each pairing.', 'homeward-rescue' ),
				'font',
			),
			'font_body' => array(
				__( 'Reading font', 'homeward-rescue' ),
				__( 'Used for paragraphs; choose a font that is comfortable to read.', 'homeward-rescue' ),
				'font',
			),
		);
	}

	private static function animals() {
		return array_merge( self::connection_fields(), self::application_and_badge_fields(), self::profile_fields() );
	}

	private static function connection_fields() {
		return array(
			'source' => array(
				__( 'Animal connection', 'homeward-rescue' ),
				__( 'Choose your live connection, sample animals, or the fixture used for local checks.', 'homeward-rescue' ),
				'select',
			),
			'shelter_id' => array(
				__( 'Petstablished shelter number', 'homeward-rescue' ),
				__( 'Find this number in your shelter link; use setup step 2 to check the connection.', 'homeward-rescue' ),
				'number',
			),
			'species' => array(
				__( 'Animals you help', 'homeward-rescue' ),
				__( 'Choose which species appear in your public animal list.', 'homeward-rescue' ),
				'checks',
			),
			'noun_singular' => array(
				__( 'Name for one animal', 'homeward-rescue' ),
				__( 'Used when referring to one animal, such as animal or companion.', 'homeward-rescue' ),
				'text',
			),
			'noun_plural' => array(
				__( 'Name for several animals', 'homeward-rescue' ),
				__( 'Used in headings and links, such as animals or companions.', 'homeward-rescue' ),
				'text',
			),
			'courtesy_mode' => array(
				__( 'Courtesy listings', 'homeward-rescue' ),
				__( 'Hide courtesy listings, give them their own section, or include them with other animals.', 'homeward-rescue' ),
				'select',
			),
			'name_cleaning' => array(
				__( 'Clean imported names', 'homeward-rescue' ),
				__( 'Remove status markers from imported animal names.', 'homeward-rescue' ),
				'checkbox',
			),
			'hidden_statuses' => array(
				__( 'Statuses to hide', 'homeward-rescue' ),
				__( 'Animals with these statuses stay out of the public list.', 'homeward-rescue' ),
				'checks',
			),
			'public_fields' => array(
				__( 'Details visitors can see', 'homeward-rescue' ),
				__( 'Choose which optional animal details appear on public profiles.', 'homeward-rescue' ),
				'checks',
			),
			'apply_mode' => array(
				__( 'Adoption applications', 'homeward-rescue' ),
				__( 'Use a separate application for each animal or a general application.', 'homeward-rescue' ),
				'select',
			),
		);
	}

	private static function application_and_badge_fields() {
		return array(
			'adopt_general_pet_ids' => array(
				__( 'General adoption forms', 'homeward-rescue' ),
				__( 'Add a visitor-facing label and its Petstablished animal number in each entry.', 'homeward-rescue' ),
				'rows',
			),
			'foster_pet_id' => array(
				__( 'Foster form animal number', 'homeward-rescue' ),
				__( 'Enter the Petstablished animal number for your foster application; 0 leaves it unset.', 'homeward-rescue' ),
				'number',
			),
			'adopt_url' => array(
				__( 'Adoption application link', 'homeward-rescue' ),
				__( 'Optional secure link for your adoption application.', 'homeward-rescue' ),
				'url',
			),
			'foster_url' => array(
				__( 'Foster application link', 'homeward-rescue' ),
				__( 'Optional secure link for your foster application.', 'homeward-rescue' ),
				'url',
			),
			'volunteer_url' => array(
				__( 'Volunteer application link', 'homeward-rescue' ),
				__( 'Optional secure link for your volunteer application.', 'homeward-rescue' ),
				'url',
			),
			'badge_new_days' => array(
				__( 'Days to show the New badge', 'homeward-rescue' ),
				__( 'Use 0 to turn off the badge; the maximum is 365 days.', 'homeward-rescue' ),
				'number',
			),
			'badge_long_wait_days' => array(
				__( 'Days before the Longest waiting badge', 'homeward-rescue' ),
				__( 'Use 0 to turn off the badge.', 'homeward-rescue' ),
				'number',
			),
			'badge_special_needs' => array(
				__( 'Show the Special needs badge', 'homeward-rescue' ),
				__( 'Highlight animals whose profiles identify special needs.', 'homeward-rescue' ),
				'checkbox',
			),
			'badge_bonded' => array(
				__( 'Show the Bonded pair badge', 'homeward-rescue' ),
				__( 'Highlight animals that should be adopted together.', 'homeward-rescue' ),
				'checkbox',
			),
			'badge_pending' => array(
				__( 'Show the Adoption pending badge', 'homeward-rescue' ),
				__( 'Highlight animals with a pending adoption.', 'homeward-rescue' ),
				'checkbox',
			),
		);
	}

	private static function profile_fields() {
		return array(
			'badge_labels' => array(
				__( 'Badge wording', 'homeward-rescue' ),
				__( 'Select Use custom wording to override a badge; clear that selection to restore the standard wording.', 'homeward-rescue' ),
				'badges',
			),
			'image_mode' => array(
				__( 'Animal photos', 'homeward-rescue' ),
				__( 'Load photos from the source or copy cover images into the Media Library.', 'homeward-rescue' ),
				'select',
			),
		);
	}

	private static function donations() {
		return array(
			'donate_paypal_url' => array(
				__( 'PayPal donation link', 'homeward-rescue' ),
				__( 'Paste your secure paypal.me or paypal.com donation link.', 'homeward-rescue' ),
				'url',
			),
			'donate_card_url' => array(
				__( 'Card donation link', 'homeward-rescue' ),
				__( 'Paste the secure address of your card donation page.', 'homeward-rescue' ),
				'url',
			),
			'donate_card_label' => array(
				__( 'Card button wording', 'homeward-rescue' ),
				__( 'The text visitors see on the card donation button.', 'homeward-rescue' ),
				'text',
			),
			'donate_etransfer_email' => array(
				__( 'e-Transfer email', 'homeward-rescue' ),
				__( 'Shown when your country is Canada.', 'homeward-rescue' ),
				'email',
			),
			'donate_etransfer_note' => array(
				__( 'e-Transfer instructions', 'homeward-rescue' ),
				__( 'Add a short note to help donors send their gift.', 'homeward-rescue' ),
				'text',
			),
			'donate_order' => array(
				__( 'Donation method order', 'homeward-rescue' ),
				__( 'One method per line, in display order: card, paypal, etransfer; omit a method to hide it.', 'homeward-rescue' ),
				'lines',
			),
		);
	}

	private static function contact() {
		return array(
			'message_recipients' => array(
				__( 'Topic inboxes', 'homeward-rescue' ),
				__( 'Each topic can go to a different inbox; blank addresses use Contact email.', 'homeward-rescue' ),
				'contact',
			),
			'turnstile_site_key' => array(
				__( 'Turnstile site key', 'homeward-rescue' ),
				__( 'Optional: add both Cloudflare Turnstile keys to enable the spam check; clear either key to turn it off.', 'homeward-rescue' ),
				'contact',
			),
			'turnstile_secret' => array(
				__( 'Turnstile secret key', 'homeward-rescue' ),
				__( 'The saved secret stays hidden; leave this blank to keep it, or enter a replacement.', 'homeward-rescue' ),
				'contact',
			),
			'ask_hint' => array(
				__( 'Hint in the message box', 'homeward-rescue' ),
				__( 'Grey text shown in an empty message box when someone asks about one animal; {name} becomes the animal name. Leave blank for no hint.', 'homeward-rescue' ),
				'text',
			),
			'email_guard' => array(
				__( 'Protect public email addresses', 'homeward-rescue' ),
				__( 'Make email addresses harder for spam bots to collect while keeping them usable for visitors.', 'homeward-rescue' ),
				'checkbox',
			),
			'trust_proxy' => array(
				__( 'Use a trusted proxy for visitor addresses', 'homeward-rescue' ),
				__( 'Enable only behind a trusted proxy that replaces forwarding headers; developers can use the homeward_client_ip filter.', 'homeward-rescue' ),
				'checkbox',
			),
		);
	}

	private static function statistics() {
		return array(
			'posthog_key' => array(
				__( 'PostHog project key', 'homeward-rescue' ),
				__( 'Paste your project API key beginning with phc_. Leave blank to keep statistics off.', 'homeward-rescue' ),
				'text',
			),
			'posthog_region' => array(
				__( 'PostHog region', 'homeward-rescue' ),
				__( 'Choose the region of your project; only public visits are counted and recording stays off.', 'homeward-rescue' ),
				'select',
			),
			'posthog_host' => array(
				__( 'Advanced: custom host', 'homeward-rescue' ),
				__( 'For self-hosted PostHog, enter a complete https:// host; leave blank to use the selected region.', 'homeward-rescue' ),
				'url',
			),
			'posthog_cookies' => array(
				__( 'Recognize returning visitors', 'homeward-rescue' ),
				__( 'Off uses memory only. On allows local storage and cookies; obtain any consent your site requires.', 'homeward-rescue' ),
				'checkbox',
			),
		);
	}

	/**
	 * Field definitions for one feature: its switch, then its wording.
	 *
	 * @param string $key   Feature key.
	 * @param string $label Switch label.
	 * @param string $help  What the feature shows.
	 * @param array  $extra Wording fields.
	 * @return array
	 */
	private static function feature( $key, $label, $help, $extra ) {
		$option = Features::option( $key );
		return array_merge(
			array(
				$option => array( $label, $help, 'checkbox' ),
			),
			$extra
		);
	}

	private static function features() {
		$name = __( '{name} becomes the animal name.', 'homeward-rescue' );
		return array_merge(
			self::feature(
				'donate-card',
				__( 'Donate card on available profiles', 'homeward-rescue' ),
				__( 'A short card after the story asking visitors who cannot adopt to donate.', 'homeward-rescue' ),
				array(
					'feature_donate_card_heading' => array( __( 'Donate card heading', 'homeward-rescue' ), $name, 'text' ),
					'feature_donate_card_text'    => array( __( 'Donate card text', 'homeward-rescue' ), $name, 'text' ),
					'feature_donate_card_button'  => array( __( 'Donate card button', 'homeward-rescue' ), $name, 'text' ),
				)
			),
			self::feature(
				'adopted-donate',
				__( 'Donate prompt on adopted profiles', 'homeward-rescue' ),
				__( 'When an animal has found a home, invite visitors to help the next one or meet the animals still waiting.', 'homeward-rescue' ),
				array(
					'feature_adopted_donate_heading'   => array( __( 'Adopted prompt heading', 'homeward-rescue' ), $name, 'text' ),
					'feature_adopted_donate_text'      => array( __( 'Adopted prompt text', 'homeward-rescue' ), __( '{name} becomes the animal name and {animal} your name for one animal.', 'homeward-rescue' ), 'text' ),
					'feature_adopted_donate_button'    => array( __( 'Adopted prompt donate button', 'homeward-rescue' ), $name, 'text' ),
					'feature_adopted_donate_secondary' => array( __( 'Adopted prompt second button', 'homeward-rescue' ), __( 'Goes to your animal list; {animals} becomes your name for several animals.', 'homeward-rescue' ), 'text' ),
					'feature_donate_url'               => array( __( 'Donate link for both prompts', 'homeward-rescue' ), __( 'A secure https:// link or a path on this site such as /donate/; leave blank for /donate/.', 'homeward-rescue' ), 'text' ),
				)
			),
		);
	}

	private static function search() {
		return array(
			'logo_url' => array(
				__( 'Logo link for search engines', 'homeward-rescue' ),
				__( 'Paste a secure link to your logo so search engines can recognize your rescue.', 'homeward-rescue' ),
				'url',
			),
			'llms_txt' => array(
				__( 'Publish a guide for AI search tools', 'homeward-rescue' ),
				__( 'Share your rescue name and selected public pages in llms.txt.', 'homeward-rescue' ),
				'checkbox',
			),
			'llms_pages' => array(
				__( 'Pages in the search guide', 'homeward-rescue' ),
				__( 'Private, draft and password-protected pages are left out.', 'homeward-rescue' ),
				'checks',
			),
			'share_networks' => array(
				__( 'Sharing options', 'homeward-rescue' ),
				__( 'Choose the services offered on animal and story pages.', 'homeward-rescue' ),
				'checks',
			),
			'archive_slug' => array(
				__( 'Animal list address', 'homeward-rescue' ),
				__( 'The path for your animal list; leave blank to follow the selected species.', 'homeward-rescue' ),
				'text',
			),
			'single_slug' => array(
				__( 'Animal profile address', 'homeward-rescue' ),
				__( 'The path before each animal name; leave blank to follow the animal list address.', 'homeward-rescue' ),
				'text',
			),
			'stories_slug' => array(
				__( 'Story address', 'homeward-rescue' ),
				__( 'The path for success stories; blank uses stories.', 'homeward-rescue' ),
				'text',
			),
			'events_slug' => array(
				__( 'Event address', 'homeward-rescue' ),
				__( 'The path for upcoming events; blank uses events.', 'homeward-rescue' ),
				'text',
			),
			'redirects' => array(
				__( 'Old page addresses', 'homeward-rescue' ),
				__( 'One pair per line: /old-page /new-page; add 302 at the end for a temporary move.', 'homeward-rescue' ),
				'textarea',
			),
		);
	}

	private static function advanced() {
		return array(
			'placeholder_pattern' => array(
				__( 'Application placeholder pattern', 'homeward-rescue' ),
				__( 'A regular expression that identifies application-only records to hide.', 'homeward-rescue' ),
				'text',
			),
			'courtesy_pattern' => array(
				__( 'Courtesy listing pattern', 'homeward-rescue' ),
				__( 'A regular expression that identifies courtesy listings.', 'homeward-rescue' ),
				'text',
			),
			'origin_enabled' => array(
				__( 'Show animal origins', 'homeward-rescue' ),
				__( 'Use the origin rules below to group animals by where they came from.', 'homeward-rescue' ),
				'checkbox',
			),
			'region_label' => array(
				__( 'Origin heading', 'homeward-rescue' ),
				__( 'The heading visitors see above an animal origin.', 'homeward-rescue' ),
				'text',
			),
			'origin_rules' => array(
				__( 'Origin matching rules', 'homeward-rescue' ),
				__( 'Add a region and its matching regular expressions, one expression per line.', 'homeward-rescue' ),
				'rows',
			),
			'gone_retention_days' => array(
				__( 'Days to retain departed animals', 'homeward-rescue' ),
				__( 'Older departed animals are moved to drafts; 0 disables this cleanup.', 'homeward-rescue' ),
				'number',
			),
			'import_stories_slug' => array(
				__( 'Story import page', 'homeward-rescue' ),
				__( 'The existing page slug to read when importing stories.', 'homeward-rescue' ),
				'text',
			),
			'import_testimonials_slug' => array(
				__( 'Testimonial import page', 'homeward-rescue' ),
				__( 'The existing page slug to read when importing testimonials.', 'homeward-rescue' ),
				'text',
			),
			'delete_on_uninstall' => array(
				__( 'Delete all Homeward data when the plugin is deleted', 'homeward-rescue' ),
				__( 'Off keeps settings and content; stored keys are always deleted. On also removes messages and temporary data.', 'homeward-rescue' ),
				'checkbox',
			),
			'delete_content_on_uninstall' => array(
				__( 'Also delete animals, stories and events', 'homeward-rescue' ),
				__( 'Applies only when deleting Homeward data; pages and media are kept.', 'homeward-rescue' ),
				'checkbox',
			),
		);
	}
}
