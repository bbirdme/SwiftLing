=== SwiftLing ===
Contributors: mckilem
Tags: multilingual, translation, language switcher, translate, localization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight but powerful and fast: make your site multilingual with your own translation API key, any number of languages, an address for each.

== Description ==

SwiftLing makes your WordPress site multilingual. It finds the texts of a page as visitors see them, wherever they come from: the block editor, a page builder, custom fields, forms, menus, your theme and your plugins. You translate them in one editor, beside the original, and each language gets an address of its own.

= Why SwiftLing =

* **Your pages are not duplicated.** Translations are kept in SwiftLing's own tables, so there is one page, not one copy per language, and a text without a translation simply shows in the original.
* **It works where your content lives.** The block editor and page builders that keep their content in WordPress itself, such as Elementor, Beaver Builder, Breakdance, Brizy and Avada, with no shortcodes to wrap around your texts.

= What it translates =

* Pages, posts and custom post types: titles, content, excerpts and fields.
* The block editor and its templates, patterns and template parts.
* Page builders: Elementor, and builders that keep their content in WordPress itself, such as Beaver Builder, Breakdance, Brizy and Avada.
* Advanced Custom Fields, options pages included.
* Categories, tags and other taxonomies, with their addresses.
* Menus: a label of their own for each language, and items shown only in some languages.
* The texts of your theme and plugins, and the settings of theirs that visitors read.
* Images: another image for a language, and the alt texts.
* Forms of Contact Form 7, Forminator, Gravity Forms, Ninja Forms, Fluent Forms, WPForms and Formidable Forms: labels, choices, the message after sending, the error messages and the email to the visitor, while the notification to you stays in your language.
* WooCommerce products: their texts, their images and the names of their attributes.
* Tutor LMS courses: lessons, topics, announcements, and quizzes with their questions and answers. A student who types an answer in their language gets it graded as right.
* The titles and descriptions of Yoast SEO, Rank Math, SEOPress and All in One SEO.

= Addresses and search engines =

* An address for each language: a folder (/de/), a parameter (?lang=de), a subdomain (de.example.com) or a domain of its own (example.de).
* Translated slugs, also of categories, tags and the bases of their addresses.
* hreflang links, canonical addresses in each language, and sitemaps of every language, also those of Yoast SEO, Rank Math, SEOPress and All in One SEO.
* Search finds the translated texts in each language.

= The language switcher =

* As a block, the shortcode [swiftling_switcher], a widget, an item of a menu or a switcher at the edge of every page.
* With flags, the names of the languages or both, each language named in its own language or in the language of the page.
* Visitors can be offered their browser's language, or taken to it, if you want.

= Translating =

* An editor beside the original, page by page, that shows which texts are translated and which changed since.
* Machine translation with Google Cloud Translation and your own API key: the empty texts of a page at a click, or the whole site at once with an estimate of the characters first.
* Words that are never translated, such as the name of your business, and content types that are never translated.
* A separate copy of a page for a language, when a page needs another layout there.
* Languages you hide from visitors until they are ready, and languages of your own beside those WordPress knows.

= Works with =

* Caching plugins: when a translation changes, SwiftLing empties the pages concerned in WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Breeze, Nginx Helper, Proxy Cache Purge and Cloudflare.
* Networks of sites: each site has its languages and translations of its own.

= SwiftLing Pro =

SwiftLing itself stays free, with any number of languages. [SwiftLing Pro](https://swiftling.pro/#pricing) adds better translation and a better shop: DeepL, Claude, OpenAI and Gemini with your own key, a glossary, the whole site translated on the server with the tab closed, automatic translation when you publish, prices in a currency for each language, and import from WPML, Polylang and TranslatePress. [See what Pro adds](https://swiftling.pro/#pricing).

= Support =

Having a problem or a question? Please write to the [support forum](https://wordpress.org/support/plugin/swiftling/) first. I read it and will try to help you before you leave a review.

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

SwiftLing finds texts where WordPress reads them, so it works with the block editor and with builders that keep their content in WordPress itself, such as Elementor, Beaver Builder, Breakdance, Brizy and Avada. With Brizy, the texts of its free blocks and elements are translated; its Pro elements were not checked. A builder that prints its pages past WordPress shows its texts in the original.

= What language are the emails of my forms in? =

The email a form sends to the visitor is in the language of the page the visitor sent it from. The notification to you stays in the language of your site.

= Does it work on a network of sites? =

Yes. Each site of the network has its own languages and translations. The addresses of a network use a folder or a parameter for each language; subdomains and domains per language are for a single site.

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
* Where: https://translation.googleapis.com, with your API key.

Google bills your Google Cloud account for the characters sent. Terms of service: https://cloud.google.com/terms/service-terms. Privacy policy: https://policies.google.com/privacy.

SwiftLing runs WordPress in each language with WordPress's own translations. When you add a language whose WordPress translation is not installed yet, SwiftLing asks WordPress to install it from WordPress.org, as the Languages setting of WordPress does: the code of the language is sent to https://api.wordpress.org and the translation is downloaded from https://downloads.wordpress.org. This happens only when an administrator who may install languages saves the languages. Privacy policy: https://wordpress.org/about/privacy/.

When you save a subdomain or a domain of its own for a language on the URLs screen, SwiftLing opens that address, with a random code, to check that it leads to your site. The request goes only to the address you entered.

SwiftLing can send a request to test and support a plugin, theme or form it does not translate yet to SwiftLing's support desk at swiftling.pro, which the makers of SwiftLing run.

* What is sent: what the request is about (whether it is a plugin, theme, form or page builder, its name and version, and its folder or address on WordPress.org), what stays untranslated, an address of your site where it shows if you write one, the email address to answer to, the version of SwiftLing and the language of the person sending it. When "Add the support report" is ticked, also the report of the Logs screen: the versions of WordPress, PHP, the database server, SwiftLing and SwiftLing Pro, the theme and the active plugins with their versions, the site's languages and how their addresses look, the name of the translation service in use (never its key), the caches in use, and the latest errors and warnings of SwiftLing. No content of your site, keys or passwords.
* When: only when an administrator presses Send on the Request support screen, which shows what is sent first. Nothing is sent otherwise.
* Where: https://swiftling.pro/wp-json/swiftling/v1/requests. If it cannot be reached, nothing is sent, and the screen offers the request to email yourself to support@swiftling.pro.

Privacy policy: https://swiftling.pro/privacy/.

== Copyright ==

SwiftLing bundles these fonts for its admin screens. They are licensed under the SIL Open Font License 1.1, included in assets/fonts/OFL.txt.

Bricolage Grotesque
Copyright 2022 The Bricolage Grotesque Project Authors
License: SIL Open Font License, 1.1, https://opensource.org/licenses/OFL-1.1
Source: https://github.com/ateliertriay/bricolage

Instrument Sans
Copyright 2022 The Instrument Sans Project Authors
License: SIL Open Font License, 1.1, https://opensource.org/licenses/OFL-1.1
Source: https://github.com/Instrument/instrument-sans

JetBrains Mono
Copyright 2020 The JetBrains Mono Project Authors
License: SIL Open Font License, 1.1, https://opensource.org/licenses/OFL-1.1
Source: https://github.com/JetBrains/JetBrainsMono

SwiftLing bundles these flags for its language switcher, in assets/flags. They are licensed under the MIT License, included in assets/flags/LICENSE.txt.

country-flag-icons
Copyright (c) 2020 @catamphetamine
License: MIT, https://opensource.org/licenses/MIT
Source: https://gitlab.com/catamphetamine/country-flag-icons

== Changelog ==

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
