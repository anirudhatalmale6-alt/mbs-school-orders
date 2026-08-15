=== MBS Order Forms ===
Private online order pages for WooCommerce — schools, clubs, studios and events.
Built for Mark Nicholas Photography / Manhattan Beach Studios.

== What it does ==
Gives a school, club, studio or event its own private online order page (like a
paper order form, but online). The customer picks a package (or none), adds extras
with quantities, enters their details, and pays by card at checkout. Every order
lands in WooCommerce > Orders with the full breakdown.

== Requirements ==
- WooCommerce active
- Any WooCommerce card payment gateway enabled (WooPayments, Square, Stripe, etc.)
  The plugin is gateway-neutral — it uses whatever you have turned on. Tip: enable
  just ONE card option at checkout so parents aren't shown two side-by-side.

== Install ==
1. WordPress admin > Plugins > Add New > Upload Plugin.
2. Choose the plugin zip, Install, then Activate.
   (On activation it quietly creates one hidden product, "Sports Photo Order",
    that every order is billed through. You never need to touch it.)

== Turn on sales tax (one time) ==
1. WooCommerce > Settings > General > tick "Enable taxes" > Save.
2. WooCommerce > Settings > Tax > Standard rates > Insert row:
   Rate % = 9.5000  (Redondo). Name it "Sales Tax". Save.

== Add or edit an order form (no code) ==
Everything about an order form is edited in WooCommerce > Order Forms.

To set up a new one the quick way:
1. WooCommerce > Order Forms.
2. Find an existing form close to the new one and click Duplicate.
3. Change the name, the shortcode key, the logo, the groups and any prices that
   differ. Untick "Show" on anything this one doesn't sell. If it isn't a school,
   set the Wording section (see below).
4. Tick "Create the order page for me when I save", then Save.
5. You get a published page with the shortcode already on it. That link is what
   you send out — it isn't in any menu, so nobody finds it by browsing.

Notes:
- Untick "Show" instead of deleting when a product is only paused. The item stays
  on file and past orders keep making sense.
- Deleting a form leaves its WordPress page alone. Remove that from Pages
  yourself if you want it gone.
- Sample photos in the "See sample" popups are set up for you — send new ones over
  and they'll be dropped in.

== Where the order forms are stored ==
In the database, edited through the screen above. includes/programs.php is only
the starting data, copied in once when v1.1.0+ is first activated. Editing that
file after that has no effect.

== Logos ==
On the form's edit screen, click "Choose from Media Library" and pick the logo.
A white or single-colour transparent PNG works best — it sits on the dark navy
header. The included redondo-white.png is the Sea Hawks mark.

== Sample product photos ==
Each item's "See sample" popup can show a real photo. The photos already set up
stay attached to their items through any edit — you'll see a thumbnail next to each
row on the edit screen. An item with no photo shows a neutral placeholder.
To swap a photo yourself, replace the file in /assets/samples keeping the same
filename. To add photos to new items, send them over and they'll be wired in.

== Note on the "/day" pricing and "Request a Quote" button ==
This site still carries "quote mode" code in the Bridge child theme from its
equipment-rental days (it adds "/day" to prices and relabels the cart's checkout
button to "Request a Quote"). This plugin automatically neutralises that ONLY for
photo-order carts, so they show clean prices and a real "Proceed to Checkout"
button. Rental carts are untouched. No theme edits required.

== Dedicated checkout page ==
This site's normal WooCommerce Checkout page was turned into a gear-rental quote
form. Photo orders are paid by card, so on activation the plugin creates its own
page "Sports Photo Checkout" (holding the [woocommerce_checkout] shortcode) and
automatically sends photo-order carts there for real card payment. Rental carts
still go to the quote form, untouched. You don't need to configure anything.

== Details carry into checkout ==
The parent name, phone and email entered on the order form are (a) saved onto the
order line in WooCommerce > Orders, and (b) pre-filled into the checkout billing
fields so parents never type them twice.

== Phone check, Notes, and a Back link ==
- Phone must be a valid 10-digit number (checked in the browser and on the server).
- Optional "Notes / special requests" field on the order form; it shows in the cart
  and is saved on the order in WooCommerce > Orders.
- A "Back to the order form" link appears on the cart and checkout so parents can
  return to add another athlete or fix a detail.

== The form remembers entries ==
The order form now remembers what a parent has typed (name, phone, email, notes,
team, package) in their browser. If they go to the cart and come back - or reload
the page - the form is still filled in, so nothing has to be re-entered. After an
athlete is added to the cart, the athlete-specific fields clear for the next one
while the contact details stay.

== Editing an athlete already in the cart ==
On the cart, each athlete line has an "Edit this athlete" link. It re-opens the
order form pre-filled with that athlete (name, team, package, extras, contact,
notes). Saving replaces that cart line instead of adding a duplicate, then returns
to the cart.

== Quantity locked + edit made cache-proof ==
Each athlete line is now fixed at quantity 1 (changing it used to double the whole
order). "Edit this athlete" now fetches the saved details live, so it works even
when the page is cached. A small version number shows at the bottom of the form.

== Products link + clearer labels (1.0.13) ==
- Optional "See photos & descriptions of every product" link at the top of the
  form. Set it in the "Products link" box on the form's edit screen (already set
  to www.marknicholasphotography.com/products for Redondo); leave it blank to hide it.
- The cart's "back" link now reads "CLICK HERE to add another athlete".
- The cart's checkout button now reads "Continue to Payment" so it's clear the
  final card entry is the next step (standard two-step: cart -> payment).

== Prominent name, clean order lines, real product image (1.0.14) ==
- The athlete's name is now bold and larger on the cart line, and bold on the
  "Athlete" row, so it stands out.
- Each ordered item now shows on its own labelled row (Package / Item). This fixes
  a "<br>" that was showing as literal text inside the Square/WooPay checkout
  order summary (that widget renders details as plain text).
- The hidden order product now carries a clean branded "Photo Order" thumbnail
  (assets/order-thumb.png) instead of WooCommerce's grey placeholder image, which
  looked broken in the cart and on the payment page. Imported into your media
  library automatically; no action needed.

== Redondo hawk thumbnail + versioned default (1.0.15) ==
- The default order thumbnail is now the Redondo Union hawk logo. If your product
  is still using the plugin's auto default, updating swaps it in automatically.
- If you set your OWN product image (Products > "Sports Photo Order" > Product
  image), the plugin never overrides it. To change the shared default later, bump
  MBS_THUMB_VER and replace assets/order-thumb.png.

== How to add products / change pricing ==
All of it is on the form's edit screen in WooCommerce > Order Forms.
- Change a package price: edit the Price box on that package row.
- Change an add-on price: edit the Price box on that item's row.
- Add a new item: "+ Add an item", then set its group, name and price.
- Stop selling something: untick "Show" on its row (keeps it on file), or Remove
  the row to delete it outright.
Save, then clear your cache (WP Rocket / SiteGround) so parents see the change.

== Gateway-neutral wording (1.0.16) ==
- The order form's trust badge now reads "Secure encrypted card checkout" instead
  of naming a specific processor, since the plugin works with whatever card gateway
  you have enabled. No functional change to payments.

Version 1.0.16

== School Order Forms manager (1.1.0) ==
Schools are no longer defined in code. A new screen at WooCommerce > School Order
Forms lists every school with its page link and Edit / Duplicate / Delete, and the
edit screen covers the name, header, logo, sports, teams/divisions, deadline,
products link, packages and every add-on.
- Duplicate copies a whole school, product list and all, so a new one takes a
  couple of minutes.
- Saving can create the order page for you, shortcode already in place.
- Your existing Redondo setup is copied into the database automatically the first
  time this version runs. Nothing to retype, and the live page keeps working.
- Untick "Show" to pause a package or item without losing it. Anything switched
  off can't be ordered, even through a hand-made request.
- Renaming a school's shortcode key rewrites the shortcode on its existing page,
  so the page doesn't break.
- Deleting a school leaves its WordPress page in place, on purpose.

== Works for anything, not just schools (1.2.0) ==
The plugin never cared that a "program" was a school — only the wording did. Each
order form now sets its own wording on its edit screen, under Wording:
- Who the order is for: Athlete / Participant / Dancer / Guest / Player…
- Who is ordering: Parent / Customer / Contact…
- Jersey number: switch it off entirely for anything that isn't a team sport.
- Category dropdown label: Sport / Session / Class…
- Intro paragraph: the sentence under the big heading. Leave blank for the
  original sports wording. Wrap text in **stars** to make it bold.
- Leave the Teams / divisions box empty and that dropdown disappears too.
The order form re-labels itself from those — including the cart's "add another
…" link. Everything else (cart, checkout, payment, exports) is identical.

Forms that already existed keep the original wording exactly; the defaults are
the old hard-coded words.

== Existing pages are adopted, not duplicated (1.2.0) ==
If you built a page by hand before this screen existed, the plugin now finds it
by its shortcode and links it to the form, instead of offering to create a second
copy of a page you already have.

== Note on order records ==
Order line items still record Athlete / Parent / Team as the field names, whatever
the form calls them on screen. That is deliberate: the Manufacturing Export and
every order you have already taken look those names up, and renaming them would
break both. It only affects the admin-side labels, not what customers see.

== Ask for as little as you like (1.3.0) ==
Under "Which fields to ask for" on any form:
- The person's name block: on or off.
- The buyer's name block: on or off. (Keep at least one — an order needs a name.)
- Phone: Required / Optional / Don't ask.
- Email: Required / Optional / Don't ask.
- Notes box: on or off.
Selling prints at an art fair? Switch it all off except one name and you get a
single Name box and the products, nothing else. A form with no packages hides the
whole "Choose a Package" step and renumbers itself.

The checks are enforced on the server as well as in the browser, so a form that
doesn't ask for a phone number can't be made to demand one, and a form that does
still can't be bypassed.

The payment page still collects a name and email — that belongs to the card
processor and isn't something this plugin can remove.

== Colours (1.3.0) ==
Two pickers per form: Header background and Accent. The accent drives the year in
the heading, the buttons and the required stars. Leave both blank for the standard
navy and scarlet. Only real hex colours are accepted, and everything generated is
scoped to the order form, so nothing can leak into the rest of your site.

== Paper order form (1.3.0) ==
Attach a PDF from your media library and a "Download the paper order form" button
appears near the top, with wording you can change. Leave it blank and no button
shows.

== Product photos from the screen (1.3.0) ==
Every package and item row now has Choose / Remove for its sample photo, straight
from the media library. The photos already set up stay exactly as they are.

== Give the logo real room (1.3.1) ==
Two settings under the logo on any form:
- Logo placement: "Beside the heading (small)" — how it has always been — or
  "On the right, large", which gives the logo its own column at up to three times
  the size. Worth using when you want the school, team or event to feel like the
  page is theirs.
- "How ordering works": untick to drop the three-step box and hand the logo the
  whole right-hand side.
Both default to the existing behaviour, so no form changes unless you change it.

A logo can also no longer be clipped or squashed by a theme rule: the header,
the hero and the logo slot are all pinned to auto height with visible overflow,
and the logo is capped so a very large file can't blow the header out. Checked at
nine widths from 1440px down to 360px.

== Why the Sharks logo still looked wrong on 1.3.1 (1.3.2) ==
The form's stylesheet used to be loaded as a separate file. Your host's speed
plugin combines every stylesheet on the site into one bundle and serves that
bundle from its cache, so after a plugin update the page could show the NEW
layout and NEW behaviour while still painting with the OLD styles until that
cache happened to be cleared.

That is what the Sharks page was doing. The page was genuinely on 1.3.1 — it had
moved the logo into the right-hand column — but the bundle it was painting from
predated 1.3.1, so none of the rules that size a logo in that column existed and
it rendered at the old small size.

From 1.3.2 the stylesheet is delivered inside the page itself, alongside the
script, which has worked this way for a while for the same reason. Layout,
behaviour and styling now always ship together and cannot fall out of step, and
a stale cache can no longer half-apply an update.

Nothing to configure. It is still worth clearing the cache after an update so you
see changes immediately, but a missed cache clear can no longer break the layout.
