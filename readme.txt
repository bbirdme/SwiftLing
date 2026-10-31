=== SwiftLing ===
Contributors: mckilem
Tags: multilingual, translation, machine translation, language switcher, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual WordPress: any number of languages, an address for each, Elementor, Bricks, WooCommerce, forms and SEO. Translate with your own API key.

== Description ==

**SwiftLing is a multilingual plugin for WordPress.** Translate your pages, posts, products, menus, forms and theme texts into any number of languages, give each language an address of its own, and add a language switcher, all from one editor beside the original. The free plugin has no limit on languages.

SwiftLing finds the texts of a page as visitors see them, wherever they come from: the block editor, a page builder, custom fields, forms, your theme and your plugins.

= Why SwiftLing =

* **No duplicated pages.** Translations are kept in SwiftLing's own tables, so there is one page, not one copy per language. A text without a translation simply shows in the original.
* **Works where your content lives.** The block editor and page builders such as Elementor, Bricks, Beaver Builder, Breakdance, Brizy and Avada, with no shortcodes to wrap around your texts.
* **Your own translation key.** Machine translation with Google Cloud Translation and your own API key. SwiftLing does not charge per word or per language.
* **Ready for search engines.** An address for each language, hreflang links, translated slugs, canonical addresses and sitemaps.

= What it translates =

* **Content:** pages, posts and custom post types (titles, content, excerpts and fields), the block editor with its templates, patterns and template parts, and categories, tags and other taxonomies with their addresses.
* **Menus:** a label of their own for each language, and items shown only in some languages.
* **Page builders:** Elementor and Bricks with their templates and components, and Beaver Builder, Breakdance, Brizy and Avada.
* **Fields and images:** Advanced Custom Fields, options pages included, and another image for a language with its alt text.
* **Theme and plugins:** the texts of your theme and plugins, and the settings of theirs that visitors read.
* **Forms:** Contact Form 7, Forminator, Gravity Forms, Ninja Forms, Fluent Forms, WPForms and Formidable Forms: labels, choices, messages, errors and the email to the visitor, while the notification to you stays in your language.
* **Shops and courses:** WooCommerce products (texts, images and the names of their attributes), and Tutor LMS and LearnPress courses with their quizzes. A student who types an answer in their language gets it graded as right.
* **Events:** The Events Calendar, Events Manager, Event Organiser and My Calendar, with venues, organizers, categories and tickets, and the calendar's own words and addresses in each language.
* **Cookie banners:** Complianz, CookieYes, Cookie Notice, GDPR Cookie Compliance, Real Cookie Banner, Cookiebot and iubenda.
* **SEO titles and descriptions:** Yoast SEO, Rank Math, SEOPress and All in One SEO.

= Multilingual SEO and addresses =

* An address for each language: a folder (`/de/`), a parameter (`?lang=de`), a subdomain (`de.example.com`) or a domain of its own (`example.de`).
* Translated slugs, also of categories, tags and the bases of their addresses.
* hreflang links, canonical addresses in each language, and sitemaps of every language, also those of Yoast SEO, Rank Math, SEOPress and All in One SEO.
* Search finds the translated texts in each language.

= Language switcher =

* As a block, the shortcode `[swiftling_switcher]`, a widget, a menu item or a switcher at the edge of every page.
* With flags, the names of the languages or both, each language named in its own language or in the language of the page.
* Visitors can be offered their browser's language, or taken to it, if you want.

= Translating =

* An editor beside the original, page by page, that shows which texts are translated and which changed since.
* Machine translation with Google Cloud Translation and your own API key: the empty texts of a page at a click, or the whole site at once with an estimate of the characters first.
* Words that are never translated, such as the name of your business, and content types that are never translated.
* A separate copy of a page for a language, when it needs another layout there.
* Languages you hide from visitors until they are ready, and languages of your own beside those WordPress knows.

= Works with =

* **Caching plugins:** when a translation changes, SwiftLing empties the pages concerned in WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Breeze, Nginx Helper, Proxy Cache Purge and Cloudflare.
* **Networks of sites:** each site has its languages and translations of its own.

= SwiftLing Pro =

SwiftLing itself stays free, with any number of languages. [SwiftLing Pro](https://swiftling.pro/#pricing) adds:

* DeepL, Claude, OpenAI and Gemini with your own key, and a glossary of terms.
* The whole site translated on the server with the tab closed, or from the command line. [See a 54-page site translated into five languages in about 12.5 minutes](https://swiftling.pro/whole-site-in-five-languages/).
* Automatic translation when you publish.
* WooCommerce prices in a currency for each language.
* Import from WPML, Polylang and TranslatePress.

[See what Pro adds](https://swiftling.pro/#pricing).

= Support and source =

Having a problem or a question? Please write to the [support forum](https://wordpress.org/support/plugin/swiftling/) first. I read it and will try to help you before you leave a review. The source code and issues are on [GitHub](https://github.com/bbirdme/SwiftLing); more at [swiftling.pro](https://swiftling.pro).

== Installation ==

1. Install SwiftLing from the Plugins screen of your site, or upload the plugin's folder to /wp-content/plugins/, and activate it.
2. The first setup opens: choose the language your site is written in and the languages to translate it into.
3. On SwiftLing → URLs, choose how the address of each language looks.
4. Translate on SwiftLing → Translations, or from a page itself through Edit translation in the toolbar.
5. Put the language switcher where visitors find it, on SwiftLing → Switcher.
6. To translate by machine, add a Google Cloud Translation API key on SwiftLing → Translation service.

== Frequently Asked Questions ==

= Do I need a translation service? =

No. You can translate every text by hand. A Google Cloud Translation API key is needed only for machine translation, and Google bills its use to your own Google Cloud account.

= What do visitors see of a text that is not translated? =

The original. Nothing is hidden or left empty.

= Does SwiftLing copy my pages for every language? =

No. Translations are kept in SwiftLing's own tables, and every language shows the same page with its texts translated. You can still make a separate copy of a page for a language when it needs another layout.

= Does it work with my page builder? =

SwiftLing finds texts where WordPress reads them, so it works with the block editor and with builders that keep their content in WordPress itself, such as Elementor, Bricks, Beaver Builder, Breakdance, Brizy and Avada. With Bricks, its elements, templates, components, popups and forms are translated, and its builder always edits the original; its WooCommerce templates were not checked. With Brizy, the texts of its free blocks and elements are translated; its Pro elements were not checked. A builder that prints its pages past WordPress shows its texts in the original.

= What language are the emails of my forms in? =

The email a form sends to the visitor is in the language of the page the visitor sent it from. The notification to you stays in the language of your site.

= Does it work on a network of sites? =

Yes. Each site of the network has its own languages and translations. The addresses of a network use a folder or a parameter for each language; subdomains and domains per language are for a single site.

= Can I move to SwiftLing from WPML, Polylang or TranslatePress? =

Yes. [SwiftLing Pro](https://swiftling.pro/#pricing) imports your translations from WPML, Polylang and TranslatePress, so you do not translate again what is already translated.

= What happens to my data when I delete SwiftLing? =

Nothing. Deleting the plugin keeps its translations and settings, so they are there again if you install it later. To remove them, use Settings › General › Start over › Remove everything before you delete the plugin; it cannot be undone. Your pages and posts stay as they are either way, as SwiftLing keeps its translations apart from them; a separate copy you made of a page for a language stays as a page of its own.

== Screenshots ==

1. The translation editor: the original and its translation side by side.
2. The languages of the site.
3. The address of each language.
4. The language switcher and its settings.
5. Translating the whole site at once.
6. A page in German, with the language switcher in the menu.

== External services ==

SwiftLing can translate your texts with Google Cloud Translation, a machine translation service by Google, using your own Google Cloud API key. Nothing is sent until you save a key on the Translation service screen.

* What is sent: when you save or check a key, a request for the list of languages Google translates and the word "Hi" to translate, which confirms the key works. When you press the translate button in the translation editor, the texts of the page that have no translation yet in the language shown, and the codes of the original language and of that language. When you start a run on the Translate everything screen, the same texts of every page and post of the site, one page at a time. Link addresses and other attributes of HTML tags are not sent.
* When: only when a logged-in administrator saves or checks the key, or starts a translation. Visits to your site never send anything.
* Where: [https://translation.googleapis.com](https://translation.googleapis.com), with your API key.

Google bills your Google Cloud account for the characters sent. [Terms of service](https://cloud.google.com/terms/service-terms). [Privacy policy](https://policies.google.com/privacy).

SwiftLing runs WordPress in each language with WordPress's own translations. When you add a language whose WordPress translation is not installed yet, SwiftLing asks WordPress to install it from WordPress.org, as the Languages setting of WordPress does: the code of the language is sent to [https://api.wordpress.org](https://api.wordpress.org) and the translation is downloaded from [https://downloads.wordpress.org](https://downloads.wordpress.org). This happens only when an administrator who may install languages saves the languages. [Privacy policy](https://wordpress.org/about/privacy/).

When you save a subdomain or a domain of its own for a language on the URLs screen, SwiftLing opens that address, with a random code, to check that it leads to your site. The request goes only to the address you entered.

SwiftLing can send a request to test and support a plugin, theme or form it does not translate yet to SwiftLing's support desk at [swiftling.pro](https://swiftling.pro), which the makers of SwiftLing run.

* What is sent: what the request is about (whether it is a plugin, theme, form or page builder, its name and version, and its folder or address on WordPress.org), what stays untranslated, an address of your site where it shows if you write one, the email address to answer to, the version of SwiftLing and the language of the person sending it. When "Add the support report" is ticked, also the report of the Logs screen: the versions of WordPress, PHP, the database server, SwiftLing and SwiftLing Pro, the theme and the active plugins with their versions, the site's languages and how their addresses look, the name of the translation service in use (never its key), the caches in use, and the latest errors and warnings of SwiftLing. No content of your site, keys or passwords.
* When: only when an administrator presses Send on the Request support screen, which shows what is sent first. Nothing is sent otherwise.
* Where: [https://swiftling.pro/wp-json/swiftling/v1/requests](https://swiftling.pro/wp-json/swiftling/v1/requests). If it cannot be reached, nothing is sent, and the screen offers the request to email yourself to support@swiftling.pro.

[Privacy policy](https://swiftling.pro/privacy/).

== Copyright ==

SwiftLing bundles these fonts for its admin screens. They are licensed under the SIL Open Font License 1.1, included in assets/fonts/OFL.txt.

Bricolage Grotesque
Copyright 2022 The Bricolage Grotesque Project Authors
License: [SIL Open Font License, 1.1](https://opensource.org/licenses/OFL-1.1)
Source: [https://github.com/ateliertriay/bricolage](https://github.com/ateliertriay/bricolage)

Instrument Sans
Copyright 2022 The Instrument Sans Project Authors
License: [SIL Open Font License, 1.1](https://opensource.org/licenses/OFL-1.1)
Source: [https://github.com/Instrument/instrument-sans](https://github.com/Instrument/instrument-sans)

JetBrains Mono
Copyright 2020 The JetBrains Mono Project Authors
License: [SIL Open Font License, 1.1](https://opensource.org/licenses/OFL-1.1)
Source: [https://github.com/JetBrains/JetBrainsMono](https://github.com/JetBrains/JetBrainsMono)

SwiftLing bundles these flags for its language switcher, in assets/flags. They are licensed under the MIT License, included in assets/flags/LICENSE.txt.

country-flag-icons
Copyright (c) 2020 @catamphetamine
License: [MIT](https://opensource.org/licenses/MIT)
Source: [https://gitlab.com/catamphetamine/country-flag-icons](https://gitlab.com/catamphetamine/country-flag-icons)

== Changelog ==

= 1.0.8 =
* Complianz: the texts of the cookie banner are translated, and what the legal documents name of your organisation, such as its name and address. What a consent area shows once a visitor consents is offered with the page's fields and loads in the language of the page. The banner's link to the cookie policy shows its translated title as soon as you save it.
* CookieYes: the banner shows in the language of the page when CookieYes has it in that language, and in its default language otherwise. On a site that still runs its earlier version, the texts of the banner and the cookie categories are translated.
* Cookie Notice: the texts of the banner, the buttons and the privacy policy link are translated.
* GDPR Cookie Compliance: the texts you changed in its settings are translated on the banner and on its settings screen.
* Real Cookie Banner: the texts of the banner, the content blockers and the cookie policy, and the names and descriptions of the services and their groups, are translated. A consent counts in every language. A translation that leaves out a placeholder, such as {{privacyPolicy}}, shows the original, and machine translation keeps them.
* Cookiebot and iubenda: the banner of the service shows in the language of the page.
* The texts of these plugins' settings have their own place on the Templates screen.

= 1.0.7 =
* The Events Calendar: venues and organizers are translated with each event, as are the words before and after the calendar, the formats of its dates, the excerpts in its lists, its calendar files and the link to Google Calendar. The calendar's links and pages work in every language, with the words of each in their addresses, as /de/events/monat/, and the language switcher leads to the same view. Moving to another month or page keeps the language, and the month view is no longer served from a cache of another language.
* Event Tickets: the names and descriptions of tickets are translated on the event, in the cart, at the checkout and on the confirmation, and an order sent from a page in a language leads to the confirmation in that language.
* Events Manager: events, locations and tickets are translated where the plugin prints them, also the tickets of recurring events, and the texts of its settings, such as the search form and the messages after a booking, have their own place on the Templates screen. Machine translation keeps its placeholders, and a translation that leaves one out shows the original. Bookings and searches answer in the language of the page.
* Event Organiser: the address and description of a venue are translated, and its calendar loads its events in the language of the page.
* My Calendar: events, locations and categories are translated in the calendar, in lists and on their own pages, without changing what the plugin keeps in its caches.
* An event of any of these plugins gets no separate copy, which would lose its dates.

= 1.0.6 =
* LearnPress: courses are translated where students take them: the sections, what students learn, the requirements, the audience, the FAQs and the words around the price, which LearnPress reads from its own copy of the course, and the quizzes with their answers, hints, explanations and blanks. A blank typed in the language of the page counts as right, and the address of a lesson has its slug in the language. A course, its lessons, quizzes and questions get no separate copy.
* Translated addresses: the address of a lesson inside its course keeps the lesson's translated slug instead of being sent back to the original one.
* A plugin that reads every meta key of a post as one value, as LearnPress does on its quiz page, no longer gets a PHP warning on a translated page.

= 1.0.5 =
* Bricks: its header, footer, popups, section templates and components are translated where they show, also popups and query loops it loads later, and its forms answer in the language of the page. Short texts it declares, such as a suffix, and the messages and emails of a form are offered too. Its builder always edits the original, also when you open it from a translated page. Machine translation keeps its dynamic data tags.

= 1.0.4 =
* Tutor LMS: courses are translated where students take them: the titles in the course's sidebar and header, the topics and announcements, the quizzes with their questions and answers, the review of an attempt, the cart and checkout of its shop, and the address of a lesson. An answer typed in the language of the page counts as right. A course, its lessons and quizzes get no separate copy, which would lose its curriculum and students.
* Recording texts: a page in a language is recorded as its original, and a page with several parameters in its address is checked, so the texts of plugins are found there too. Start recording leaves you on the screen, and Stop leaves you on the page you stopped it from.

= 1.0.3 =
* Brizy: the titles of tabs on small screens and the texts Brizy keeps in attributes, such as the message of a countdown, are translated too.
* The Translations panel of the block editor shows the state of a language in full in a narrow sidebar.

= 1.0.2 =
* Brizy: the texts of pages built with Brizy are found and translated: its blocks and free elements.

= 1.0.1 =
* Links to SwiftLing Pro lead to the prices on swiftling.pro.

= 1.0.0 =
* First version.
* One editor beside the original for pages, posts and custom post types, the block editor and its templates, menus, taxonomies, theme and plugin texts, images and Advanced Custom Fields.
* Page builders that keep their content in WordPress: Elementor, Beaver Builder, Breakdance and Avada.
* An address for each language: folder, parameter, subdomain or domain of its own, with translated slugs, hreflang links, canonical addresses and sitemaps.
* Machine translation with Google Cloud Translation and your own key, for one page or the whole site, with an estimate first.
* Forms of Contact Form 7, Forminator, Gravity Forms, Ninja Forms, Fluent Forms, WPForms and Formidable Forms, and WooCommerce products.
* SEO titles and descriptions of Yoast SEO, Rank Math, SEOPress and All in One SEO.
* A language switcher as a block, shortcode, widget, menu item or at the edge of the page, and an optional offer of the visitor's browser language.
* Caches emptied in the main caching plugins when a translation changes, and networks of sites with languages of their own for each site.
