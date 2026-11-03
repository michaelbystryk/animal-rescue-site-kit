# Profile features and A/B tests

The kit has six optional features for animal profiles. Each one has an on/off
switch, and none of them needs a statistics account. If you also use PostHog,
you can test a feature fairly: half of your visitors see it, half do not, and
PostHog tells you which half adopted, asked or donated more.

This guide has three parts:

1. [What each feature does](#what-each-feature-does)
2. [Turn a feature on (no PostHog needed)](#turn-a-feature-on-no-posthog-needed)
3. [Run an A/B test with PostHog](#run-an-ab-test-with-posthog)

All settings are in **Settings > Homeward Rescue**, in the section
**Profile features and A/B tests**.

## What each feature does

| Feature | Where it shows | Starts |
| --- | --- | --- |
| **Donate card** | After the story on profiles of animals who are available. Asks visitors who cannot adopt to donate. | On |
| **Donate prompt on adopted profiles** | On the page of an animal who found a home: "{name} found a home. The next one is still waiting", with **Help the next one** and **Meet the animals waiting** buttons. | On |
| **Explain the adoption fee** | A small **What's included?** button under the fee. It opens a checklist of what the fee covers. | Off |
| **Share with the household card** | Under the profile buttons: "Deciding with someone?" with a button that opens the usual Share box. The normal Share button stays. | Off |
| **Adoption steps** | A numbered list of steps (Get to know, Apply, Review, Meet, Home) on profiles that take applications. | Off |
| **Waiting counter** | A small "Waiting for a home: N days" note under the name of available animals who have waited at least 14 days. | Off |

Every feature's wording can be changed in the same section. In the wording:

- `{name}` becomes the animal's name.
- `{animal}` and `{animals}` become your names for one animal and several
  animals (set under **Animals and sync**), so the wording fits dogs, cats or
  any other species.
- An empty box goes back to the standard wording.

The donate buttons go to your `/donate/` page unless you enter another link
in **Donate link for both prompts**.

There is one more small change that has no switch. When someone asks about one
animal on your contact form, the message box shows a grey hint: "What would you
like to know about {name}?". Change it under **Contact form > Hint in the
message box**, or clear the box to show no hint.

## Turn a feature on (no PostHog needed)

1. Go to **Settings > Homeward Rescue**.
2. Find **Profile features and A/B tests**.
3. Tick the feature's switch, for example **Explain the adoption fee**.
4. Change the wording if you like.
5. Click **Save Changes** at the bottom of the page.

The feature now shows on every matching profile, straight away. Untick the
switch and save to hide it again.

### Preview before you switch anything on

While logged in, add `?homeward_preview=all` to the end of any animal profile
address, for example:

```
https://your-site.org/animals/bella/?homeward_preview=all
```

This shows every feature on that page, only for you. To preview one or two,
list their keys instead, for example `?homeward_preview=fee-explainer,adoption-path`.
The keys are in the [table at the end](#flag-keys-and-events).

## Run an A/B test with PostHog

An A/B test answers a question such as "Does the fee checklist lead to more
adoption applications?". PostHog puts each visitor in one of two groups:

- **test** sees the feature.
- **control** sees the page without it.

After a few weeks you compare the groups.

Test one feature at a time while you are learning. Two tests can run together,
but each one then needs more visitors before the answer is clear.

### Step 1: Create a PostHog project

1. Sign up at [posthog.com](https://posthog.com). The free plan is enough for a
   rescue website.
2. Choose the **United States** or **European Union** region when asked.
   Remember which one you picked.
3. When PostHog asks how to install, you can skip its instructions. The kit
   installs it for you.

### Step 2: Put the key in Settings

1. In PostHog, open **Settings > Project** and copy the **Project API key**.
   It starts with `phc_`.
2. In WordPress, go to **Settings > Homeward Rescue > Statistics**.
3. Paste the key into **PostHog project key**.
4. Choose the same **PostHog region** as your project.

### Step 3: Turn on Recognize returning visitors

In the same **Statistics** section, tick **Recognize returning visitors**.

This matters. Without it, PostHog forgets each visitor between pages, so the
same person could see the feature on one page and not on the next, and the
results would not mean much. With it, PostHog stores a small identifier in the
visitor's browser so they keep the same group.

Because this uses cookies and local storage, mention PostHog in your privacy
policy and cookie notice where your local rules require it.

Click **Save Changes**.

### Step 4: Choose what visitors see if PostHog cannot answer

Some visitors never reach PostHog: people who are logged in (including you),
people whose browser sends Do Not Track or Global Privacy Control, and people
who block trackers. They see the page as the feature's on/off switch says.

- Leave the switch **off** if the feature is new and you want those visitors to
  see your normal page. This is the usual choice.
- Turn it **on** if those visitors should always get the feature.

### Step 5: Let PostHog decide

1. Under the feature, tick **Let PostHog decide (A/B test)**.
2. Click **Save Changes**.

Until the flag in the next step exists, everyone still sees the page as the
switch says, so nothing breaks while you set it up.

### Step 6: Create the feature flag in PostHog

The flag's name must match exactly. It is `homeward-` followed by the feature
key, for example `homeward-fee-explainer`. All names are in the
[table at the end](#flag-keys-and-events).

You can create the flag in either of two ways. The second is easier because
PostHog also measures the result for you.

**Option A: an Experiment (recommended).**

1. In PostHog, open **Experiments** and click **New experiment**.
2. Give it a name, such as "Fee checklist".
3. For the feature flag key, enter the flag name, such as
   `homeward-fee-explainer`.
4. Keep the two variants PostHog suggests, named exactly `control` and
   `test`, with 50% each.
5. For the goal (the primary metric), choose the event suggested in the table
   below, such as `application_click`.
6. Launch the experiment.

**Option B: a feature flag on its own.**

1. In PostHog, open **Feature flags** and click **New feature flag**.
2. Enter the flag name as the key, such as `homeward-fee-explainer`.
3. Choose **Multiple variants** and add two variants named exactly `control`
   and `test`, with 50% each.
4. Release it to 100% of users and save.
5. If you want PostHog to measure the result, create an Experiment later and
   pick this existing flag.

The site reads the flag on the next page load. A visitor in `test` sees the
feature; a visitor in `control` does not. If PostHog has not answered within
3 seconds, the visitor sees the page as the switch says. PostHog records which
group each visitor saw (its `$feature_flag_called` event), so the Experiment
can compare them.

### Step 7: Pick a goal

The goal is the action you hope the feature encourages. These events come from
the site:

| Event | Sent when a visitor |
| --- | --- |
| `application_click` | Clicks an adoption, foster or volunteer application link |
| `animal_ask_clicked` | Clicks Ask about an animal |
| `animal_share_opened` | Opens the Share box on a profile |
| `contact_form_submitted` | Sends the contact form |
| `donate_click` | Clicks a donate button (including the two donate features) |
| `fee_explainer_opened` | Opens What's included? |
| `household_share_clicked` | Clicks the household card's button |
| `adoption_path_viewed` | Scrolls the adoption steps into view |
| `waiting_counter_viewed` | Scrolls the waiting counter into view |

Feature events carry a `feature` property with the feature key, so you can
filter them in PostHog.

Good first goals:

| Feature | Main goal | Also worth watching |
| --- | --- | --- |
| Donate card | `donate_click` | `application_click`, to check the card does not distract adopters |
| Donate prompt on adopted profiles | `donate_click` | Visits to your animal list |
| Explain the adoption fee | `application_click` | `fee_explainer_opened` |
| Share with the household card | `animal_share_opened` | `application_click` |
| Adoption steps | `application_click` | `animal_ask_clicked` |
| Waiting counter | `application_click` | `animal_ask_clicked` |

The "viewed" and "opened" events show that people noticed the feature. They are
not good main goals, because only the `test` group can ever send them.

### Step 8: Let it run

- Run each test for **at least two full weeks**, so every day of the week is
  counted twice.
- Do not stop early because one side is ahead after a few days. Early leads
  often disappear.
- Small rescue sites may need four to eight weeks. PostHog's Experiment page
  shows how many visitors it still needs.
- Avoid changing the feature's wording while the test runs.

### Step 9: Read the results

Open the Experiment in PostHog. It shows, for each group, how many visitors
saw it and how many reached the goal.

- If PostHog says the **test** variant is significantly better, the feature
  helps.
- If it says **control** is better, the feature gets in the way.
- If it cannot tell after the planned time, the feature makes little
  difference. Either choice is fine; many rescues keep the simpler page.

### Step 10: End the test and keep the winner

1. In WordPress, set the feature's switch to the winner: **on** if `test`
   won, **off** if `control` won.
2. Untick **Let PostHog decide (A/B test)**.
3. Click **Save Changes**.
4. In PostHog, stop the Experiment (or turn the feature flag off).

Every visitor now gets the winning version straight from your site, with no
waiting for PostHog.

## Flag keys and events

| Feature | Key (for `?homeward_preview=`) | PostHog flag | Feature event | Starts |
| --- | --- | --- | --- | --- |
| Donate card | `donate-card` | `homeward-donate-card` | `donate_click` | On |
| Donate prompt on adopted profiles | `adopted-donate` | `homeward-adopted-donate` | `donate_click` | On |
| Explain the adoption fee | `fee-explainer` | `homeward-fee-explainer` | `fee_explainer_opened` | Off |
| Share with the household card | `household-share` | `homeward-household-share` | `household_share_clicked` | Off |
| Adoption steps | `adoption-path` | `homeward-adoption-path` | `adoption_path_viewed` | Off |
| Waiting counter | `waiting-counter` | `homeward-waiting-counter` | `waiting_counter_viewed` | Off |

Every flag uses the variants `control` and `test`.

## Questions

**I ticked Let PostHog decide, but I always see the same version.** You are
logged in, so PostHog does not run for you and you see the switch's setting.
Use a private browser window to see what visitors see, or use
`?homeward_preview=` while logged in.

**Does a test slow the page down?** Only the feature being tested waits, and
for at most 3 seconds on a visitor's first page. Later pages decide at once.
Features that are simply switched on never wait.

**Settings files.** The switches and wording can also go in a settings file
for the setup wizard. See `config.example.json` for the setting names, such as
`feature_fee_explainer` and `feature_fee_explainer_ab`.
