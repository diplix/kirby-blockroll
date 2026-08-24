<?php

/**
 * Directory OPML listing all pages that contain a blogroll block.
 *
 * @var \Kirby\Cms\Pages $pages
 * @var \Kirby\Cms\Site $site
 */

use Blockroll\Opml;

$title = 'Blogrolls on ' . $site->title()->value();

$modified = 0;
foreach ($pages as $blogrollPage) {
    $modified = max($modified, (int) $blogrollPage->modified());
}

echo Opml::prolog();
?>
<opml version="2.0">
  <head>
    <title><?= htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></title>
<?php if ($modified > 0): ?>
    <dateModified><?= htmlspecialchars(date('r', $modified), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></dateModified>
<?php endif ?>
    <ownerName><?= htmlspecialchars($site->title()->value(), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></ownerName>
    <ownerId><?= htmlspecialchars($site->url(), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></ownerId>
  </head>
  <body>
<?php foreach ($pages as $blogrollPage): ?>
    <outline
      text="<?= htmlspecialchars(Opml::title($blogrollPage), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?>"
      type="include"
      url="<?= htmlspecialchars(Opml::opmlUrl($blogrollPage), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?>"
    />
<?php endforeach ?>
  </body>
</opml>
