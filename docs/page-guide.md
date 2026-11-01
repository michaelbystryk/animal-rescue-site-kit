# Your rescue's pages

The demo is a practice set. Its animals, adoption updates, families and quotes are
fictional. The illustrations are generated shapes, not photographs of animals.
Keep the demo labels until you have replaced the examples with checked facts.

## Load the practice set

In Settings > Homeward Setup, tick **Import demo content** on the Finish step.
Setup makes seven pages: Home, Adopt, Foster, Donate, Contact, About and
Volunteer. The import fills Adopt, Foster, Donate, Contact, About and Volunteer
with practice text. It finds each page by its address, such as `adopt`, so it
does not make a second copy. Existing page titles stay. Home is not changed.

The menu has seven links, in this order: Animals, Adopt, Foster, Volunteer,
Donate, About and Contact.

An administrator who uses the command line can run:

```sh
wp homeward import-demo
```

Running the import again keeps the same records and preserves edits made since
the first import. The sample JSON lives in demo-content/sample.json. Its pages
contain editable copies of the theme's patterns and the plugin's blocks. The
plugin ZIP includes this sample set and its illustrations.

With no shelter number, the import switches your animal connection to a separate
offline set of practice animals, and says so. With a shelter number, your
connection stays as it is and the made-up animals are added beside your real
ones. No animal service or image download is needed for the practice import.
The practice animals include dogs and a cat. Only the species you have selected
in Homeward settings appear in animal listings.

## What each page does

- **Adopt** shows example adoption steps, the Animal Grid and adoption stories.
  Replace the steps with your application process, fees and meeting arrangements.
  The grid reads animal records automatically.
- **Foster** explains temporary care. Its Apply Button and Contact Form use the
  foster topic. Describe the space, time and support your team expects.
- **Donate** shows the Donate Panel and an example email transfer card. Replace
  the example address in both the visible text and copy button. Set up your real
  payment links in Homeward settings. In the wizard's Donations step, paste your
  PayPal donation link (it starts with https://paypal.me or https://paypal.com).
  The import does not add payment details.
- **Contact** has the Contact Form and a fictional email address. Add the public
  details and reply times your team can maintain. Form messages go into Messages;
  delivery by email depends on your site's settings.
- **About** explains your work and shows Story Cards for stories and testimonials.
  Replace the sample mission with the area you serve and the animals you help.
- **Volunteer** has example roles, a volunteer Apply Button and Contact Form.
  Explain the available tasks, time needed and how to get started.

Edit pages under Pages. To change the home page, open **Pages > Home**. The
wizard creates it and sets it as your front page, unless your site already had
one. Select a block to change its text or settings. Animal
Grid, Story Cards and Donate Panel get their information from the plugin, so
edit the records or settings behind them. Application buttons need your real
application destinations in Homeward settings.

## Print kennel cards and adoption posters

Staff with permission to edit posts can choose **Print kennel card** on an animal
profile or its row in Animals. The print address is the profile URL with
`?homeward_print=1`. Select half page cards (two per sheet) or a full page poster,
then Letter or A4. Match the paper size in your print dialog and turn off browser
headers and footers. Printing waits for the local QR code, photos and fonts.
A single animal fills both half page cards; a poster prints once.

In Animals, select rows and use **Print kennel cards** in Bulk actions.
**Print all available** prints available animals on the current filtered admin
page. Repeat for further pages. Cards include the rescue identity, photo, public
facts, fee in your site currency, short description and a QR code linking to the
animal's page. Photo crops favour the upper third. An optional
`homeward_focal_point` post or featured-image meta value with normalized `x` and
`y` coordinates overrides that crop.

Profiles and printouts show confirmed spay/neuter and vaccination status beside
compatibility. Unknown status has no icon. Edit these two yes/no/unknown fields
on manual animals; Petstablished animals use the public detail toggles. Control
visibility under the public fields in Homeward settings. Microchip IDs, rabies
tag numbers and other source identifiers are discarded by the public allow-list.

## Connect an animal, a story and an adopter's words

An animal profile describes the animal. An adoption story is a separate update
under Stories. A testimonial is another Stories record whose Kind is Testimonial.
The demo pairs Nimbus Sprout, Juniper Pixel and Mochi Comet with one story and
one fictional testimonial each. Their example updates do not change the practice
animals' availability. Set real availability in your animal source.

1. Open Stories > Add new story. Choose **Adoption story** as the Kind, select
   the **Linked animal**, and fill in the title and Story or quote field. Add a
   photo you have permission to use. Publish when the account is ready.
2. Add a testimonial. Choose **Testimonial** as the Kind. Select both the
   **Linked animal** and **Linked adoption story**. Use the adopter's words with
   permission and a family name they have agreed to share. Publish it.
3. Open the animal's public profile. Its Adoption stories section links to the
   published story. Its Words from their families section shows the linked
   testimonials. The story page also shows testimonials linked to that story.

Links use the chosen records, not matching names. Renaming an animal does not
break them. Draft, private and password-protected stories or testimonials do not
appear in these public sections. If something is missing, check its publication
status, both selectors and whether the animal's profile is public. Story Cards
on About give visitors another way to find the accounts.

## Replace the examples

Edit each page in place, keeping its address where possible. Remove the demo
notice only after checking the text, forms, payment details and application
links. Replace fictional accounts with real accounts shared with permission.
You can edit the imported records in place or create your own records and then
remove the demo set. Choose images in the Media Library and add helpful alt text.

Before removing the demo animals, configure your live shelter ID and source in
Homeward settings and run a sync. Check your species selection and real profiles.
If the connection stays on the practice animals, a later scheduled sync can
bring them back. Never replace an animal's source ID with an unrelated real animal's ID.

To remove the imported practice set, go to **Settings > Homeward Rescue** and
click **Remove practice content**. The button shows while practice content
exists. An administrator can also run:

```sh
wp homeward import-demo --remove
```

Removal permanently deletes the stories, animals and images that this importer
created, including edits to those records. Copy any work you want to keep into
new records first. Pages you created are kept. A page the import filled gets its
earlier content back only if the practice text is still unchanged; later page
edits stay. It keeps the animal source setting. Running removal twice is safe.
Images reused elsewhere still belong to the practice set, so replace those uses
before removal. Then review your site in a private browser window.
