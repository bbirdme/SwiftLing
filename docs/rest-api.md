# SwiftLing and the REST API

For a site or app that shows the content of a WordPress site elsewhere, as a headless front end or
a mobile app.

## Content in a language

WordPress's own routes answer in a language when they are asked in it:

- at the address of the language: `https://example.com/de/wp-json/wp/v2/posts` with language
  folders, or the language's own host with hosts or domains per language;
- or with `?lang=de`, in every format of addresses: `https://example.com/wp-json/wp/v2/posts?lang=de`.

Titles, content, excerpts, fields, terms and links come translated, as on a page in that
language. Only requests that read are answered in a language: `GET` or `HEAD`, and not
`context=edit`. A request that writes, or asks for what an editor edits, is answered in the
original, so no client saves a translation over the original. SwiftLing's own routes
and those of its add-ons (`swiftling/v1/…`, `swiftling-pro/v1/…`) are never answered in a language.

WooCommerce's REST API (`wc/v3`) answers in a language as WordPress's routes do; a product's name,
description and short description come translated. Its Store API, which the cart and checkout
blocks ask from a page, is answered in the language of that page, whatever its address: the cart
names its products in the visitor's language. An order keeps the names as stored.

## The languages

`GET /wp-json/swiftling/v1/languages` lists the languages visitors see, in the order of the
Languages screen; a language hidden from visitors is not among them. Anyone may read it.

```json
[
  { "code": "hr", "locale": "hr", "slug": "hr", "name": "Hrvatski", "original": true,
    "current": true, "direction": "ltr", "flag": "https://…/hr.svg", "url": "https://example.com/" },
  { "code": "de-DE", "locale": "de_DE", "slug": "de", "name": "Deutsch", "original": false,
    "current": false, "direction": "ltr", "flag": "https://…/de.svg", "url": "https://example.com/de/" }
]
```

`code` is the language's tag, as in `hreflang`; `current` is the language the request is answered
in; `url` is the front page in the language.

## A post or term in each language

Posts of the types SwiftLing translates, and terms of the lists it translates, carry a field
`swiftling`: the language of the answer, and for each language visitors see the address there and,
for a post, the state of its translation (`original`, `reviewed`, `machine`, `partial`, `outdated`,
`missing`, or `duplicate` where the language keeps the original, showing the page as it is). A language the post is hidden in is left out.

```json
"swiftling": {
  "language": "hr",
  "translations": [
    { "code": "hr", "locale": "hr", "url": "https://example.com/kontakt/", "state": "original" },
    { "code": "de-DE", "locale": "de_DE", "url": "https://example.com/de/kontakt/", "state": "reviewed" }
  ]
}
```
