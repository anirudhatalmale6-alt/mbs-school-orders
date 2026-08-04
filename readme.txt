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

Version 1.0.4
