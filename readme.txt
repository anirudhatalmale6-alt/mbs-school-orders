=== MBS School Orders ===
Private per-program sports photo order pages for WooCommerce.
Built for Mark Nicholas Photography / Manhattan Beach Studios.

== What it does ==
Gives each school/team its own private online order page (like a paper order form,
but online). Parents pick a package (A/B/C or none), add extras with quantities,
enter athlete + parent details, and pay by card through your existing Square account.
Every order lands in WooCommerce > Orders with the full breakdown.

== Requirements ==
- WooCommerce active
- WooCommerce Square plugin connected (for card payments)

== Install ==
1. WordPress admin > Plugins > Add New > Upload Plugin.
2. Choose mbs-school-orders.zip, Install, then Activate.
   (On activation it quietly creates one hidden product, "Sports Photo Order",
    that every order is billed through. You never need to touch it.)

== Turn on sales tax (one time) ==
1. WooCommerce > Settings > General > tick "Enable taxes" > Save.
2. WooCommerce > Settings > Tax > Standard rates > Insert row:
   Rate % = 9.5000  (Redondo). Name it "Sales Tax". Save.

== Create a school's private page ==
1. Pages > Add New. Title it e.g. "Redondo Union Sports 2026".
2. In the content, add this shortcode:
      [mbs_order_form program="redondo"]
3. Publish. Use a full-width / no-sidebar page template if your theme offers one.
4. Keep it private simply by NOT adding it to any menu — share the page's URL
   with the school. It never appears in your shop or search.

== Add another school/team later ==
Open includes/programs.php, copy the "redondo" block, give it a new key
(e.g. "sharks"), change the name/logo/sports/divisions/prices, save.
Then make a new page with [mbs_order_form program="sharks"].
Each program can have its own prices, sports, and division list.

== Logos ==
Drop a transparent PNG into /assets and set 'logo' => 'yourfile.png' in the program.
The included redondo-white.png is the Sea Hawks mark.

== Sample product photos ==
Each item's "See sample" popup can show a real photo. Drop a JPG/PNG into
/assets/samples and set 'img' => 'samples/yourphoto.jpg' on that package or add-on
in includes/programs.php. Leave 'img' off and the item shows a neutral placeholder.
To swap a photo later, just replace the file in /assets/samples with the same name.

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
  form. Set 'productsUrl' on the program in includes/programs.php (already set to
  www.marknicholasphotography.com/products for Redondo); leave '' to hide it.
- The cart's "back" link now reads "CLICK HERE to add another athlete".
- The cart's checkout button now reads "Continue to Payment" so it's clear the
  final card entry is the next step (standard two-step: cart -> payment).

Version 1.0.13
