# wordpress.org images

The images of SwiftLing's page on wordpress.org live in `.wordpress-org`, the folder wordpress.org's
assets are uploaded from: one screenshot per caption under `== Screenshots ==` in `readme.txt`
(paired by number), the banner and the icon. `tests/ReadmeTest.php` fails when a caption has no image,
an image has no caption, or a banner or icon has the wrong size. Neither folder is in the package
(`.distignore`).

The scripts here make the images again, after the screens change.

## The screenshot site

A fresh WordPress with Twenty Twenty-Five and SwiftLing, nothing else. Its content is a plant
nursery, with the public domain (CC0) photos the theme ships, and hand-written translations into
German, French and Spanish, and some Italian.

```bash
mkdir ~/sites/swiftling-shots && cd ~/sites/swiftling-shots
ddev config --project-type=wordpress --project-name=swiftling-shots
ddev start
ddev wp core download
ddev wp core install --url=https://swiftling-shots.ddev.site --title=Fernhill \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev wp option update permalink_structure '/%postname%/'
```

Mount this repository as the plugin, in `.ddev/docker-compose.swiftling.yaml`, and `ddev restart`.
Download WordPress first: when the mount exists before `wp-content`, Docker creates `wp-content` as
root and the download cannot write into it.

```yaml
services:
  web:
    volumes:
      - /path/to/swiftling:/var/www/html/wp-content/plugins/swiftling
```

Then, from the site's folder:

```bash
ddev wp plugin activate swiftling
ddev wp eval-file wp-content/plugins/swiftling/tools/wordpress-org/content.php --user=admin
ddev wp eval-file wp-content/plugins/swiftling/tools/wordpress-org/site.php --user=admin
```

`site.php` saves a placeholder Google key marked as checked, so Translate everything shows its
estimate. Nothing is sent to Google until a run starts: do not start one on this site.

## The images

From this repository, with Playwright (`npx playwright install chromium`):

```bash
ABOUT=$(cd ~/sites/swiftling-shots && ddev wp post list --post_type=page --name=about-us --field=ID)
node tools/wordpress-org/shots.js https://swiftling-shots.ddev.site "$ABOUT"
node tools/wordpress-org/render.js
```

`shots.js` takes the screens at 1280×960, twice as sharp, and the German home page as a visitor
sees it. `render.js` takes the banner from `banner.html` and the icons from `.wordpress-org/icon.svg`.
Look at every image before committing them.
