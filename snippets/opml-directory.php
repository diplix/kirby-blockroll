<?php

/**
 * Directory OPML listing all pages that contain a blogroll block.
 *
 * @var \Kirby\Cms\Pages $pages
 * @var \Kirby\Cms\Site $site
 */

use Blockroll\Opml;

$title = 'Blogrolls on ' . $site->title()->value();

echo Opml::prolog();
?>
<opml version="2.0">
  <head>
    <title><?= htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></title>
  </head>
  <body>
<?php foreach ($pages as $blogrollPage): ?>
    <outline
      text="<?= htmlspecialchars(Opml::title($blogrollPage), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?>"
      type="link"
      url="<?= htmlspecialchars(Opml::opmlUrl($blogrollPage), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?>"
    />
<?php endforeach ?>
  </body>
</opml>
