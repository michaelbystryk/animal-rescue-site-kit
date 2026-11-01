<?php
/**
 * Dashboard-only guide. Organization and help details are supplied by settings.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

?>
<div class="wrap homeward-volunteer-guide">
	<h1><?php esc_html_e( 'Volunteer guide', 'homeward-rescue' ); ?></h1>
	<p>
		<?php
		/* translators: %s: rescue organization name. */
		echo esc_html( sprintf( __( 'A helping hand with the %s website.', 'homeward-rescue' ), $org ) );
		?>
	</p>
	<p><?php esc_html_e( 'Choose a task below. If a menu or button is missing, ask your website administrator for help. Your account may not have permission for that task.', 'homeward-rescue' ); ?></p>
	<nav aria-label="<?php esc_attr_e( 'Guide topics', 'homeward-rescue' ); ?>">
		<ul>
			<li><a href="#homeward-guide-stories"><?php esc_html_e( 'Stories', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-pages"><?php esc_html_e( 'Edit a page', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-events"><?php esc_html_e( 'Events', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-animals"><?php esc_html_e( 'Animal overrides', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-sync"><?php esc_html_e( 'Sync', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-donations"><?php esc_html_e( 'Donation settings', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-practice"><?php esc_html_e( 'Remove practice content', 'homeward-rescue' ); ?></a></li>
			<li><a href="#homeward-guide-messages"><?php esc_html_e( 'Messages and contact forms', 'homeward-rescue' ); ?></a></li>
		</ul>
	</nav>
	<h2><?php esc_html_e( 'Before editing', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'Keep a copy of longer text before changing it. Use photos you have permission to share. Ask the family before publishing names or photos of people. Keep foster addresses and other private information off public pages.', 'homeward-rescue' ); ?></p>
	<p><?php esc_html_e( 'Preview your work before publishing. If you notice a mistake while typing, use Undo. For an older change, ask an administrator to help recover the previous version if one is available.', 'homeward-rescue' ); ?></p>
	<h2 id="homeward-guide-stories"><?php esc_html_e( 'Add or edit a story', 'homeward-rescue' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Open Stories in the left menu. Choose Add new story, or open the name of an existing story.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Put the animal name in the title. In Story details, choose Adoption story for an update about an animal or Testimonial for a comment about the rescue.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Write in Story or quote. Leave a blank line between paragraphs. Choose a picture in the Photo box.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Add the family name only with permission. Fill in the month the update was received. Add a region only if it is known and offered in the list.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'A featured pull quote and time at home are optional. Use verified details, or leave them blank. Tick Featured on home if this story should lead the story section.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Choose Save Draft to finish later, Publish for a new story, or Update for an existing one. Open the public story to check the words and photo.', 'homeward-rescue' ); ?></li>
	</ol>
	<h2 id="homeward-guide-pages"><?php esc_html_e( 'Edit a page', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'The menu has Animals, Adopt, Foster, Volunteer, Donate, About and Contact. To change the words on the Home page or any other page, open Pages in the left menu and click its name, such as Home. Select a block to change its text, then click Update. The animal list, story cards and donation panel fill themselves in from your animals, stories and settings.', 'homeward-rescue' ); ?></p>
	<h2 id="homeward-guide-events"><?php esc_html_e( 'Add or edit an event', 'homeward-rescue' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Open Events. Choose Add new event, or open an existing event.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Enter an event name and fill in Starts with both the date and local time. Ask an administrator if you are unsure which time zone the website uses.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Add Ends if known, then the location name, address and a short description. Paste a ticket or sign-up link if there is one.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Choose a photo, then Publish or Update. Check the date, place and link on the public event. Finished events move out of upcoming lists automatically.', 'homeward-rescue' ); ?></li>
	</ol>
	<h2 id="homeward-guide-animals"><?php esc_html_e( 'Change animal overrides', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'Animal listings come from the rescue listing service selected by your administrator. Change the main description, photos, status and other source details in that service. A sync brings them to the website.', 'homeward-rescue' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Open the animal list in the left menu. Its name may be Animals, Dogs or the name chosen by your rescue. Open the animal you want.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Find Animal overrides. Display name and Short description change how the website introduces the animal. They do not change the listing service.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Use Visibility to hide an animal or return it to Automatic. Tick Featured to highlight it, or Needs foster when the team has confirmed a foster is needed. Ask an administrator about the other choices if you are unsure.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Click Update, then check the public list. These website choices stay in place after a sync. Clear an override or choose Automatic to use the usual setting again.', 'homeward-rescue' ); ?></li>
	</ol>
	<h2 id="homeward-guide-sync"><?php esc_html_e( 'Bring in the latest listings', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'The website checks for listing changes automatically. A new listing or photo may take time to arrive. An administrator can request an earlier check.', 'homeward-rescue' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Administrators: open Settings, then Homeward Rescue. Save any setting changes before starting a sync.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Click Sync now once. Read the status message near the top. Photos and full descriptions may finish in a later background step.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'If the status says sync is paused, wait until the time shown. If it keeps failing, send the status message to your website administrator. Leave Animal connection and Petstablished shelter number for the person who manages the connection.', 'homeward-rescue' ); ?></li>
	</ol>
	<h2 id="homeward-guide-donations"><?php esc_html_e( 'Update donation settings', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'Only administrators can change payment details. Volunteers can send the correct details to the administrator and help check the public Donate page afterward.', 'homeward-rescue' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Administrators: open Settings, then Homeward Rescue. Find the donation settings.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'For card donations, enter the secure payment page in Card donation link and choose its button label. For PayPal, paste the PayPal donation link from the rescue (a paypal.me or paypal.com link) into PayPal donation link. For e-transfer, enter the receiving email and any donor instructions.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Leave unused payment methods empty. Save Changes, then open the public Donate page. Check each address and button destination with the person responsible for donations.', 'homeward-rescue' ); ?></li>
	</ol>
	<h2 id="homeward-guide-practice"><?php esc_html_e( 'Remove practice content', 'homeward-rescue' ); ?></h2>
	<p><?php esc_html_e( 'If your site was set up with practice content, administrators can remove the made-up animals, stories and pictures. Open Settings, then Homeward Rescue, and click Remove practice content. Pages you created are kept. Check the pages afterwards.', 'homeward-rescue' ); ?></p>
	<h2 id="homeward-guide-messages"><?php esc_html_e( 'Read and answer website messages', 'homeward-rescue' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Open Messages and click a sender name. Opening the message marks it as read.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'Click Reply by email to answer in your email app, or reply to the notification email. The answer goes to the person who sent the form.', 'homeward-rescue' ); ?></li>
		<li><?php esc_html_e( 'If an email notification fails, the message stays in Messages. Messages are deleted automatically after two years.', 'homeward-rescue' ); ?></li>
	</ol>
	<p><?php esc_html_e( 'Administrators can choose a receiving inbox for each form topic in Settings, then Homeward Rescue. Blank topic addresses use Contact email. Include Website feedback when routing reports about the website.', 'homeward-rescue' ); ?></p>
	<p><?php esc_html_e( 'To add a form to a page, insert the Contact Form block. Select its topic in the block settings. For an animal question, choose the animal topic and enter the animal post ID supplied by an administrator. Save the page and check the form on the public site.', 'homeward-rescue' ); ?></p>
	<h2><?php esc_html_e( 'Ask for help', 'homeward-rescue' ); ?></h2>
	<?php Volunteer_Guide::help(); ?>
</div>
