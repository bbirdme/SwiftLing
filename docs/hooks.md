# SwiftLing hooks

The hooks SwiftLing offers other plugins and themes. SwiftLing Pro is built on them alone: it changes
nothing of SwiftLing itself.

`SWIFTLING_HOOKS` is the version of this list. It grows when a hook is added or changes, so a plugin
can check that SwiftLing has what it needs:

```php
if ( ! defined( 'SWIFTLING_HOOKS' ) || SWIFTLING_HOOKS < 1 ) {
	return; // Too old a SwiftLing.
}
```

`tests/HooksTest.php` fails when a hook is added to SwiftLing without being described here, or
described here without being in SwiftLing.

## Admin screens

### `swiftling_admin_screens`

Filter, since hooks 1. SwiftLing's admin screens, keyed by the slug of their address
(`admin.php?page=<slug>`), in menu order. A plugin adds a screen to the menu, or to a group, see
`swiftling_admin_groups`.

| Key | |
|---|---|
| `menu` | Name: the screen's title, its menu item, and its name in the list of its group. Required. |
| `view` | A name for the screen, unique among the screens. Required. |
| `file` | Path of the PHP file that prints the screen. Without it, `admin/views/<view>.php` of SwiftLing. |
| `group` | A group of `swiftling_admin_groups`: the screen is out of the menu, unless it is the group's first, and shows the group's header and list. |
| `part` | The part of the group's list the screen is listed in. Without it, the screen is in the group but not listed. |
| `icon`, `tint` | The screen's picture in the list: the path of a 16 by 16 line drawing, on a tile of `orange`, `blue`, `green`, `teal`, `amber`, `violet` or `ink`. |
| `hidden` | Reachable only by link, never in the menu. |
| `count` | Since hooks 25: a number to show beside the screen's menu item, as WordPress shows pending comments, such as replies waiting to be read. A screen of a group adds it to the group's item; SwiftLing's own item shows the sum of all, so it is seen with the menu closed. 0 shows nothing. |
| `cap` | Since hooks 12: the capability the screen takes; without it, `manage_options`. The Translations screens and the translation editor take `swiftling_translate`, see `swiftling_can_translate`. The menu and the list of a group show a user only the screens they may open. |
| `before_setup` | Since hooks 20: the screen opens before the first setup is answered, as an import that sets the languages up from another plugin's (`swiftling_import_languages()`). Until then every other screen leads to the first setup. |

The file prints the screen inside SwiftLing's page and styles. It starts with
`SwiftLing\Admin\Ui::screenHeader()` as SwiftLing's views do. A screen without a name or a view,
or whose file cannot be read, is left out; a screen of a group SwiftLing does not have is a screen
of its own.

```php
add_filter(
	'swiftling_admin_screens',
	static function ( array $screens ): array {
		$screens['swiftling-glossary'] = array(
			'menu'  => __( 'Glossary', 'my-plugin' ),
			'view'  => 'glossary',
			'file'  => __DIR__ . '/views/glossary.php',
			'group' => 'settings',
			'part'  => 'swiftling',
			'icon'  => 'M2 4h12M2 8h12M2 12h8',
			'tint'  => 'violet',
		);
		return $screens;
	}
);
```

### `swiftling_admin_groups`

Filter, since hooks 1. The groups of screens, keyed by ID: `translations` and `settings`. A group
is one menu item under its name, which opens its first screen; each of its screens shows the
group's header (`name`, `description`) and a list of its screens in parts (`parts`, names keyed by
part). A plugin adds a group, or parts to a group.

```php
add_filter(
	'swiftling_admin_groups',
	static function ( array $groups ): array {
		$groups['settings']['parts']['automatic'] = __( 'Automatic', 'my-plugin' );
		return $groups;
	}
);
```

### `swiftling_setup_offer`

Action, since hooks 24. Fires above the first setup's question, the language the site is written
in, for whoever manages the site: another plugin offers there the way to set SwiftLing up that fits
the site better, as SwiftLing Pro's import of the translations of a multilingual plugin used before,
on a screen that opens before the setup (`before_setup` above). When it prints something, that
comes first and is the page's main title (`<h1>`); the question follows under "or set SwiftLing up
by hand", its title one level down and its button secondary. When it prints nothing, the setup is as
without it.

```php
add_action(
	'swiftling_setup_offer',
	static function (): void {
		if ( my_plugin_has_old_translations() ) {
			printf( '<section class="swiftling-card"><h1>%s</h1><p><a class="swiftling-button is-primary" href="%s">%s</a></p></section>', esc_html__( 'Bring your translations along', 'my-plugin' ), esc_url( admin_url( 'admin.php?page=my-import' ) ), esc_html__( 'Import', 'my-plugin' ) );
		}
	}
);
```

### `swiftling_setup_aside`

Action, since hooks 20. Fires under the first setup, which asks for the language the site is
written in, for whoever manages the site: another plugin can offer to set the languages up another
way, on a screen that opens before the setup (`before_setup` above). For an offer that should come
first, see `swiftling_setup_offer`.

```php
add_action(
	'swiftling_setup_aside',
	static function (): void {
		printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=my-import' ) ), esc_html__( 'Import from my old plugin', 'my-plugin' ) );
	}
);
```

### `swiftling_admin_screen_assets`

Action, since hooks 1. Fires where a SwiftLing screen loads its scripts and styles, after
SwiftLing's own. SwiftLing's stylesheet is loaded on every one of its screens, also those of other
plugins.

- `string $slug`: slug of the screen's address, e.g. `swiftling-languages`.
- `array $screen`: the screen, as `swiftling_admin_screens` gives it.

```php
add_action(
	'swiftling_admin_screen_assets',
	static function ( string $slug ): void {
		if ( 'swiftling-glossary' === $slug ) {
			wp_enqueue_script( 'my-glossary', plugins_url( 'glossary.js', __FILE__ ), array(), '1.0.0', true );
		}
	}
);
```

## Translation

### `swiftling_can_translate`

Filter, since hooks 12. Whether a user who does not manage the site may translate. Translating is
the capability `swiftling_translate`: whoever has `manage_options` has it, and whoever this filter
names; a role that has it saved, as a role editor would give it, does not count. SwiftLing Pro gives
it to its Translator role and the roles chosen for it.

A translator who does not manage the site opens the Translations screens and the translation
editor and translates every text, in every language, also of what they may not edit, such as a
template, a form, or a theme's texts (`swiftling_is_translator()`). The settings stay the site
manager's: languages, addresses, switcher, service, general, logs, Translate everything, and the
choices on the Translations screens that are settings, such as which fields of terms are text,
which languages menu items show in, and finding the texts of themes and plugins.

- `bool $can`: whether the user may translate, false unless another plugin said so.
- `WP_User $user`: the user.

### `swiftling_translator_machine`

Filter, since hooks 13. Whether a translator who does not manage the site may translate with the
site's translation service, which costs its manager (`swiftling_can_machine_translate()`). Refused,
the machine route answers 403 and the translation editor offers no machine translation, as without
a service. Whoever manages the site always may. SwiftLing Pro asks it on Settings › Translators.

- `bool $can`: true unless a plugin says no.
- `WP_User $user`: the translator.

### `swiftling_translation_services`

Filter. The machine translation services, keyed by ID. A plugin adds a service of its own, a
subclass of `SwiftLing\Translation\Service`; the Translation service screen lists it with the
others.

- `array<string, Service> $services`

A service extends `SwiftLing\Translation\Service`, as `SwiftLing\Translation\Google` does:

| Method | |
|---|---|
| `id()`, `name()`, `fullName()` | `deepl`; `DeepL`, as in "Translate with DeepL"; `DeepL API`. The ID names its settings and the constant that can hold its key, `SWIFTLING_DEEPL_API_KEY`. |
| `check( $key )` | Checks a key with the service: `array( 'languages' => codes )`, or a `WP_Error` whose message says what to do. Since hooks 5, a service with models to choose from adds `'models' => array( ID => name )`, the models the key may use. Since hooks 6, an error whose data has `'reason' => 'billing'` is a key that works on an account that cannot pay (no credits, billing off, a spend limit reached): the screen says so as a warning, and the service does not translate until a check passes. |
| `languageCode( $language )` | The service's code for a language of `swiftling_languages()`, or null when it does not translate it. |
| `translate( $items, $source, $target )` | Translates texts: see the method's description. Since hooks 5, a language model adds `tokens` to its answer, the tokens it read and wrote, counted by month beside the characters (`Services::tokens()`). |
| `freeCharacters()`, `pricingUrl()` | Optional: characters translated free of charge each month, and the address of its prices. |
| `quota()` | Optional, since hooks 4: the usage of the billing period as the service counts it, `array( 'used' => …, 'limit' => … )` (0 for a plan without a limit). The screen shows it instead of `freeCharacters()`. |
| `guide()` | Optional, since hooks 2: how to get a key, shown on the Translation service screen while the service is not connected. |
| `defaultModel()` | Optional, since hooks 5: the model the service translates with until another is chosen. The Translation service screen offers the models of the last check in a list; `model()` gives the one chosen, the default, or the first. |
| `sourceLanguage()`, `targetLanguage()` | Since hooks 9: the languages of the translation under way, as `swiftling_languages()` gives them, told by `setLanguages()` before every `translate()`. A service heeds more than its codes with them: the formal variant of a locale (`de_DE_formal` is `DE` to DeepL), or what is set for the language. |
| `kind()` | Optional, since hooks 3: `machine` (the default), a translation engine billed per character, or `ai`, a language model billed per token. The Translation service screen lists services in a card per kind, each with SwiftLing's icon of its kind, never a logo of the service. |

Keys typed on the Translation service screen keep letters, digits, `-`, `_`, `:` and `.`. When several
services are connected, the one chosen on that screen translates (`Services::current()`, since hooks 2).

`SwiftLing\Translation\Markup` (since hooks 2) does for every service what Google needs with HTML:
`protect()` and `restore()` keep the attributes of tags on the site, `keep()` and `unkeep()` keep
placeholders, the variables of SEO plugins (`swiftling_seo_variables()`) and never-translated words
in `<span translate="no">`, `plainToHtml()` and `htmlToPlain()` carry plain text as HTML.

### `swiftling_translation_service`

Filter. The service that translates a page, template or list of terms into a language, so a plugin
can use different services for different languages or pages.

- `Service|null $service`: the connected service, or null when none is.
- `string $subject`: key of what is translated, e.g. `post:12`.
- `array $language`: the language, as `swiftling_languages()` gives it.

### Checking a page on the server

Since hooks 14, `swiftling_server_check( $key, $user_id = null )` checks the page of a subject
(`post:12`, `template:…`, `terms:category`, see `swiftling_subject()`) on the server, as the
translation editor checks it in the browser, and stores what it found: for translating without a
browser, in the background, on publishing, through the REST API or WP-CLI. The site asks itself
for both pages of the check through the loopback, as Site Health does, with a signed token that
is good for a few minutes, for that subject and page, rendered for the user given (the current
user by default), who must be able to translate the subject. It returns what was stored, or a
`WP_Error`. Scripts do not run: a text a script builds after the page loads is found only by the
check in the browser.

### Translating without the editor

Since hooks 15, `includes/api.php` takes the steps of the translation editor and the Translate
everything run on the server, as the current user, with the same rights and checks; the editor's
own routes call the same functions. Each returns a `WP_Error` (with a `status` for a REST answer)
when the subject, the language or the user's rights do not fit.

| Function | |
|---|---|
| `swiftling_subject_texts( $key, $lang )` | The texts of a subject (`post:12`, `template:…`, `view:…`, `terms:category`, `menu:3`, a builder's subject) the user may translate, with their translations into a language: `key`, `object`, `path`, `section`, `kind`, `type` (`text`, `html`, `code`, `url`, or `format` for a text from code), `original`, `translation`, `state` (`reviewed`, `machine`, `official`, `outdated`, `missing`), `previous` and `follows`. The originals are never stored, so the page is checked on the server first (`swiftling_server_check()`). |
| `swiftling_save_texts( $key, $lang, $items, $state )` | Saves translations by key, as the editor saves what is typed: `$items` is a list of `key`, `text` and, for the check of placeholders, `original`; `$state` is `reviewed` or `machine`. An empty text removes a translation. Returns the `states` of what was saved, the keys `refused` for their placeholders and those `ignored`, the subject's state in every language (`languages`), and a post's `address` in the language. |
| `swiftling_save_earlier_texts( $key, $lang, $items )` | Since hooks 22, for an import: saves translations made for an earlier version of their originals, as another plugin kept them (`$items`: `key` of the text's place now, `text`, and the `earlier` original it translated). Each text is outdated then, as when its original changed after it was translated here: visitors read the original, the editor shows the earlier translation, Translate everything leaves it to the editor, and the page counts as translated once its other texts are. A text translated now, one whose earlier version is the text now, and one another language waits with in another earlier version (a text has one previous version for all languages) are `ignored`; returns the keys `saved` too. The subject's page must have been checked. |
| `swiftling_save_address( $owner, $lang, $slug )` | Saves the slug of a post (`post:12`) or of a term with a page of its own (`term:5`) in a language, as the editor saves a typed one: empty for the original slug, a number added to one taken there. Returns the address it gives: `slug`, `state`, `original`, `prefix`, `url`. |
| `swiftling_machine_translate( $key, $lang, $items )` | Translates texts (`key`, `text`, `html`) with the subject's translation service, through `swiftling_machine_items` and `swiftling_machine_translations`, at most 100 texts and 20,000 characters at once; it does not save them. Returns `translations`, `failed`, `kept` and `error`. |
| `swiftling_machine_translate_subject( $key, $lang, $again = array() )` | Fills the empty texts of a subject with the service and saves them as machine translations, in batches of 50 texts and 4,000 characters, as the Translate everything run fills a page; with states in `$again` (`machine`, `reviewed`), translates the texts in those states again instead. Returns how many `texts` there were, how many were `translated`, their `characters`, the keys `failed` and `refused`, the service's `error` if it stopped, and `own_page` when the language shows a separate copy of the post or keeps its original (the stored mode `duplicate`), where nothing is filled in. |

Since hooks 19, `swiftling_import_languages( $source, $targets, $address )` sets the site's
languages from another plugin's, for an import: the original (`locale`, `slug`) as the first setup
sets it, or the same one SwiftLing has (otherwise `swiftling_import_source`); targets (`locale`,
`slug`, `visible`, and `name`, `rtl` for a locale WordPress does not list, which becomes a custom
language) added by locale, those the site has kept; and `$address`: `format` (`parameter`,
`directory`; `domain` and `subdomain` keep their `domains` but wait for the Addresses screen) and
`source_folder`. It returns the locales `added` and `kept`, the `slugs`, whether the format waits for
`hosts`, and WordPress's translations installed and missing (`packs`). Since hooks 20 it runs from
the REST API, WP-CLI and WP-Cron too, not only on admin screens. Since hooks 21 a `name` given for a
locale WordPress names otherwise, as "English" for en_GB ("English (UK)"), becomes the name the
switcher shows for it, its label on the Languages screen.

Comments and the texts of a theme or plugin are lists their own screens show by pages: their texts
are saved and translated by key, but not listed or filled in here. Since hooks 23,
`swiftling_subject_found( $subject, array( 'page' => 1 ) )` gives one page of a list of comments
(`comments:<post type>`) as the comments editor lists it, all comments, the newest first, with
`list` (its `page` and `pages`; `lang` names the language its counts are for), so their translations
can be saved by key with `swiftling_save_texts()`.

### `swiftling_machine_items`

Filter, since hooks 9. The texts of the editor sent to the translation service, so a plugin can add
to them: parts to keep (`keep`), such as the words a glossary sets for a service that knows no
glossary, or keys a service of its own reads. SwiftLing keeps the texts, whether they are HTML and
its own parts to keep as they were, and sends only the texts of the page.

- `array<string, array> $items`: key => `text`, `html`, `keep`, see `Service::translate()`.
- `Service $service`: the service that translates them.
- `array $language`: the language they are translated into, as `swiftling_languages()` gives it.

### `swiftling_machine_translations`

Filter, since hooks 9. The translations the service sent back, before SwiftLing checks their
placeholders and the variables of SEO plugins and the editor fills them in, so a plugin can change
them, such as putting in the words a glossary sets where they were kept. Only the texts that were
translated, each still a text.

- `array<string, string> $translations`: key => translation.
- `array<string, array> $items`: what was sent, see `swiftling_machine_items`.
- `Service $service`
- `array $language`

### `swiftling_translated_changed`

Action. Fires once the languages some posts are translated into were written again.

- `int[] $post_ids`

### `swiftling_translate_all_modes`

Filter, since hooks 7. The ways the Translate everything screen runs, keyed by mode, shown as cards:
`fill`, SwiftLing's own, fills the empty texts. A plugin adds a way with `label` (the card's name),
`description` (the line under the screen's title), `hint` (what it does, on the card), `icon` and
`tint` (its picture, as a screen's in `swiftling_admin_screens`) and `file` (the PHP file that prints
its screen below the cards, at `admin.php?page=swiftling-translate-all&mode=<mode>`). SwiftLing alone
has one way and shows no cards. Its scripts load through `swiftling_admin_screen_assets` on
`swiftling-translate-all`; the page check (`window.swiftlingPageCheck`) and the shared machine
translation (`window.swiftlingMachine`) are there.

A run that translates texts again asks the probe route, with `fill`, for `again`: `machine`,
`reviewed` or both. It then answers with the texts translated in those states, each with its
`state`, instead of the empty ones; never one from WordPress, a theme or a plugin, and never one
whose original changed. `swiftling_translate_all_scan()` counts them per page and language in
`translated` (`machine`, `reviewed`; null for a page never checked).

### `swiftling_translate_all_aside`

Action, since hooks 17. Fires at the top of the side column of Translate everything, above How it
works, once a translation service is connected: where a plugin prints a card of its own about
translating the whole site (`<section class="swiftling-card swiftling-side">`), as SwiftLing Pro
that pages can be translated as soon as they are published. Hooks 16 had
`swiftling_translate_all_notices` under the screen's title instead; it is gone.

### `swiftling_translate_all_start_actions`

Action, since hooks 18. Fires in the Start step of Translate everything, beside the button that
starts the run in the tab: where a plugin adds a way to start it, as SwiftLing Pro on the server.

- `string $lang`: the language the steps show first, or `""` when none can be done.

### A run the server drives

Since hooks 18, `swiftling_translate_all_record( $lang, $args )` records what a run of Translate
everything did with a page, or that a language is finished, as the progress route does for the run
in the browser, and returns the state of the run (`swiftling_translate_all_state()`). `$args`:
`page` (`post:12`), `outcome` (`done` or `skipped`), `reason`, `texts`, `characters`, `total`,
`finish`, `stopped`, and `driver` (`browser` or `server`). A plugin that drives a run on the server
plans it with `swiftling_translate_all_plan( $lang )`, translates each page with
`swiftling_machine_translate_subject()` and records it with `driver` `server`.

One drives a language at a time: whoever records drives it until it stops (`stopped`), finishes, or
has not moved for three minutes (browser) or five (server). The other is refused meanwhile with
`swiftling_translate_all_busy` (409). The state's `driver` says who drives it now, and the screen's
tile of a language the server drives says so and cannot be chosen.

### `swiftling_editor_actions`

Action, since hooks 7. Fires in the head of the translation editor, after the tools that fill the
empty fields, so a plugin prints its own.

- `array $subject`: what is translated, see `swiftling_subject()`.
- `array $language`: the language shown, as `swiftling_languages()` gives it.
- `Service|null $service`: the service that translates it, or null.

Its script, loaded through `swiftling_admin_screen_assets` on `swiftling-translation` after
`swiftling-editor`, works with the fields through `window.swiftlingEditor`, once `ready` is true or
the event `swiftling:editor-ready` fired on `document`:

| | |
|---|---|
| `texts( states )` | The text fields translated in those states (`machine`, `reviewed`): `key`, `state`, `characters` of the original. Never one from WordPress, a theme or a plugin, one whose original changed, or one with changes not saved. |
| `translateAgain( states )` | Translates those fields again with the service in use and saves them as machine translations, as "Translate empty fields" does: a promise of `translated`, `failed` and `stopped` (why the service stopped, or empty). |
| `service` | The name of the service in use, or empty. |
| `dirty()` | How many fields have changes not saved. |

## Languages

Since hooks 10, `swiftling_switch_translation( $slug )` translates into a language for a while,
outside the reader's page, such as an email to a customer written in the admin or by a scheduled
task: stored values, texts from code and links are read in the language, and WordPress runs in its
locale, until `swiftling_resume_translation()`. `swiftling_pause_translation()` does the same for the
original. They nest; each needs its resume. A slug that is not one of the site's languages is the
original. SwiftLing Pro writes WooCommerce's emails to a customer in the language of the order so.

Since hooks 26, an order's products are named in the language being read wherever WooCommerce shows
them to the customer (`woocommerce_order_item_name` and the attribute lines under it): the page an
order is confirmed on, My account, and an email written in a language with
`swiftling_switch_translation()`. The order keeps the original names, for the shop owner. A plugin
that writes an email in the order's language needs no filter of its own for them.

### `swiftling_language_forgotten`

Action. Fires after what a language no longer on the site kept was deleted on the Languages
screen, so a plugin can delete what it keeps for the language too.

- `string $locale`: locale of the language.
- `array $done`: what was deleted, `texts` and `copies`.

### `swiftling_starting_over`

Action, since hooks 11. Fires as SwiftLing starts over from Settings › General, before it removes
anything, so a plugin built on it removes what it keeps while it can still use what SwiftLing
removes next, such as a translation service's key. With `everything`, SwiftLing then removes every
option and transient of its name, `swiftling_` included, also a plugin's that carries it, as
SwiftLing Pro's. Deleting SwiftLing removes nothing: Start over is the one way to remove its data.

- `string $scope`: `everything`, or `keep` when the translations are kept.

### `swiftling_started_over`

Action. Fires after SwiftLing started over from Settings › General.

- `string $scope`: `everything`, or `keep` when the translations were kept.

### `swiftling_start_over_removed`

Filter, since hooks 11. More lines for the list of what Start over removes on Settings › General,
so the user reads there what a plugin built on SwiftLing removes too.

- `string[] $removed`: lines, plain text.

### `swiftling_language_switcher`

Action, for themes. Prints the language switcher; prints nothing when SwiftLing is not active.

- `array $args`: optional, as `swiftling_language_switcher()` takes them: `style` (`dropdown`,
  `horizontal`, `vertical`), `flag`, `name`, `code`, `current`, `class`.

```php
<?php do_action( 'swiftling_language_switcher', array( 'style' => 'horizontal' ) ); ?>
```

### `swiftling_switcher_html`

Filter, since hooks 8. The HTML of a language switcher, in every place one is printed (menu,
footer, block, shortcode, widget, `swiftling_language_switcher`), so a plugin can add to it.
SwiftLing Pro adds its currency switcher beside it. Not called where there is nothing to switch to.

- `string $html`: the switcher, a `div.swiftling-switcher`.
- `array $args`: its options: `style` (`dropdown`, `horizontal`, `vertical`), `flag`, `name`,
  `code`, `current`, `class`, `flag_size`.

## Pages

### `swiftling_hreflangs`

Filter. The alternate addresses of the page being shown, printed as hreflang links.

- `array<string, string> $links`: language tag or `x-default` => address.
- `array $page`: the address of the page in the original language without a language (`url`),
  the post whose translations decide its languages (`post`, 0 for the front page of posts and
  archives), and what builds its address (`build`).

### `swiftling_search_limit`

Filter. How many posts one search takes from the index of translations at most.

- `int $limit`
- `WP_Query $query`

## Caches

### `swiftling_purge_all`

Action. Fires once at the end of a request after which every page may be stale, for page caches to
empty everything.

### `swiftling_purge_urls`

Action. Fires once at the end of a request after which some pages are stale, for page caches to
empty them.

- `string[] $urls`: addresses, in every language they are stale in.
- `int[] $post_ids`: posts the addresses belong to, for caches that empty by post.

## Logs and support

### `swiftling_log_areas`

Filter. The areas of the Logs screen, names keyed by area, so a plugin can name its own and write
into the same log with `swiftling_log( $level, $area, $message, $args )`.

- `array<string, string> $areas`

### `swiftling_request_url`

Filter. Where requests for support of a plugin, theme or form are sent, see `includes/requests.php`.

- `string $url`
