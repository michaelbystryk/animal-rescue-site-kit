=== Homeward Rescue ===
Requires at least: 6.7
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Animal listings, stories, events, donations and a contact form for animal rescues.

== Description ==

Homeward Rescue helps a small rescue run its own website. It can:

* Show your adoptable animals with filters, profiles, share buttons and a QR code.
* Publish adoption stories and adopter quotes linked to each animal.
* List events such as adoption days and fundraisers.
* Show PayPal, card and e-Transfer donation options.
* Take messages through a contact form with topics such as adopt, foster and volunteer.
* Offer a plain-language volunteer guide in the dashboard.

Animals can come from your public Petstablished listings or be added by hand.
The companion Homeward theme is optional. Any block theme works.

This plugin is not made by, affiliated with or endorsed by Petstablished or
Wagtopia. It reads Petstablished's public listing data, which is not an
official, documented API and can change without notice. If it does, animal sync
may stop until the plugin is updated. Animals already on your site stay.

== Installation ==

1. Upload homeward-rescue.zip in Plugins > Add New > Upload Plugin and activate it.
2. The setup wizard opens. It takes six short steps and saves as you go: your
   rescue, your animals, donations, colours, fonts, and finish.
3. In step 2, enter your Petstablished shelter number. Enter 0 to try sample
   animals first.
4. On the Finish step you can load fictional practice content. Remove it later
   in Settings > Homeward Rescue with Remove practice content.

You can reopen the wizard in Settings > Homeward Setup, and change anything
later in Settings > Homeward Rescue.

== Privacy ==

Visitor statistics (optional): off until you enter a PostHog project key. When
on, the plugin sends page views, clicks, page speed and error reports to
PostHog. Logged-in users and visitors who send Do Not Track are excluded.
Session recording, heatmaps and surveys are off, and text on the page is masked.
By default PostHog keeps visitor identifiers in memory only. If you tick Allow
PostHog cookies in settings, it stores cookies and browser local storage on
visitors' devices to recognise return visits. Mention PostHog in your privacy
policy and cookie notice where the law requires, and get any consent needed.

Contact messages: messages sent through the contact form are stored on your
site as private posts, visible to administrators and editors, and deleted after
24 months.

Cloudflare Turnstile (optional): if you add Turnstile keys, each visitor's IP
address is sent to Cloudflare to check that they are not a bot.

Images: by default, animal photos load from Petstablished's servers (hotlinked),
so visitors' browsers contact those servers. YouTube videos load only after a
visitor clicks them.

The plugin has no update client and sends no other data.

== Settings notes ==

Only public animal fields are stored. Source notes, foster locations and other
unknown fields are discarded. Optional API keys are stored separately and are
never returned in forms or the REST API.

Changing the shelter or source needs a confirmation checkbox. Animals from the
old source are then moved to drafts. A sync that would remove more than half of
the available animals is rejected, and your existing animals are kept.

Messages: insert the Contact Form block, or use [homeward_contact_form
topic="general"]. Topics are general, adopt, foster, volunteer, transport,
animal and website. Set a contact email, and optionally a recipient for each
topic, in Settings > Homeward Rescue. Forms include a honeypot and a
per-visitor rate limit.

Developers: the homeward_photo_hosts filter sets the allowed image hosts,
homeward_keep_imported_attachment keeps imported covers, and
homeward_description_acronyms and homeward_animal_count_label adjust text.

== Deactivation and uninstall ==

Deactivating stops scheduled jobs. Deleting the plugin keeps your animals,
stories, events, images, messages and settings, so nothing is lost by accident.
Stored API keys, the Turnstile secret and the PostHog key are always deleted.
To remove everything, tick "Delete all Homeward data when the plugin is
deleted" in Settings > Homeward Rescue before you delete the plugin.

== Changelog ==

= 0.2.0 =
Printable kennel cards and full page adoption posters with a QR code to each animal, from the profile or in bulk from the Animals list.
Health icons: spayed or neutered, and vaccinations up to date, from Petstablished data or set by hand. Microchip and rabies numbers are never stored.

= 0.1.0 =
First public release.
