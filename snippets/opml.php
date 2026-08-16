<?php

/**
 * OPML for a single page blogroll.
 *
 * @var \Kirby\Cms\Page $page
 * @var array<int, array<string, mixed>> $links
 * @var string $title
 */

use Blockroll\Opml;

echo Opml::prolog();
?>
<opml version="2.0">
  <head>
    <title><?= htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></title>
    <dateModified><?= htmlspecialchars($page->modified('r'), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></dateModified>
    <ownerName><?= htmlspecialchars(site()->title()->value(), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></ownerName>
  </head>
  <body>
<?php foreach ($links as $link):
    $text = $link['name'] !== '' ? $link['name'] : $link['url'];
    $desc = Opml::plainDescription((string) ($link['description'] ?? ''));
    $attrs = 'text="' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
    $attrs .= ' type="rss"';
    if ($desc !== '') {
        $attrs .= ' description="' . htmlspecialchars($desc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
    }
    if (($link['feedUrl'] ?? '') !== '') {
        $attrs .= ' xmlUrl="' . htmlspecialchars($link['feedUrl'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
    }
    $attrs .= ' htmlUrl="' . htmlspecialchars($link['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
    ?>
    <outline <?= $attrs ?> />
<?php endforeach ?>
  </body>
</opml>
