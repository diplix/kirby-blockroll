<?php

/** @var \Kirby\Cms\Block $block */
/** @var string|null $render */

use Blockroll\Links;
use Blockroll\Opml;
use Blockroll\Photo;
use Blockroll\Xfn;

$isRss = isset($render) && $render === 'rss';
$rawLinks = $block->links()->toStructure();
$links = [];

foreach ($rawLinks as $item) {
    $links[] = Links::normalize([
        'url'         => $item->url()->value(),
        'name'        => $item->name()->value(),
        'description' => $item->description()->value(),
        'feedUrl'     => $item->feedUrl()->value(),
        'photo'       => $item->photo()->value(),
        'xfn'         => $item->xfn()->split(','),
        'added'       => $item->added()->value(),
        'active'      => $item->active()->toBool(true),
    ]);
}

$links = Links::onlyActive($links);
$sortBy = $block->sortBy()->or('name')->value();
$links = Links::sort($links, $sortBy);

if ($links === []) {
    return;
}

$showAvatars = $block->showAvatars()->toBool(true) && !$isRss;
$showXfn = $block->showXfn()->toBool(false) && !$isRss;
$opmlPage = (!$isRss && $block->parent() instanceof \Kirby\Cms\Page)
    ? $block->parent()
    : null;
$feedName = Opml::blockTitle($block);
?>
<section class="h-feed blockroll">
  <h2 class="p-name blockroll-feed-name"><?= esc($feedName) ?></h2>
  <ul class="blockroll-blogroll">
<?php foreach ($links as $link):
    $name = $link['name'] !== '' ? $link['name'] : $link['url'];
    $xfnRel = Xfn::relString($link['xfn']);
    $rel = trim($xfnRel . ' noopener');
    $showMeta = (!$isRss && $link['feedUrl'] !== '') || ($showXfn && $link['xfn'] !== []);
    ?>
    <li class="h-card blockroll-entry">
      <?php if ($showAvatars): ?>
        <?php if ($link['photo'] !== ''): ?>
          <img class="u-photo" src="<?= esc(Photo::src($link['photo']), 'attr') ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="blockroll-no-photo" aria-hidden="true"></span>
        <?php endif ?>
      <?php endif ?>
      <a class="u-url p-name" href="<?= esc($link['url'], 'attr') ?>" rel="<?= esc($rel, 'attr') ?>" target="_blank"><?= esc($name) ?></a>
      <?php if ($link['description'] !== ''): ?>
        <p class="p-note"><?= kti($link['description']) ?></p>
      <?php endif ?>
      <?php if ($showMeta): ?>
        <div class="blockroll-meta">
          <?php if ($link['feedUrl'] !== '' && !$isRss): ?>
            <span class="blockroll-feed">
              <a href="<?= esc('feed:' . $link['feedUrl'], 'attr') ?>" aria-label="Subscribe" title="Subscribe"><svg class="blockroll-feed-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M5 10.2h-.8v1.5H5c1.9 0 3.8.8 5.1 2.1 1.4 1.4 2.1 3.2 2.1 5.1v.8h1.5V19c0-2.3-.9-4.5-2.6-6.2-1.6-1.6-3.8-2.6-6.1-2.6zm10.4-1.6C12.6 5.8 8.9 4.2 5 4.2h-.8v1.5H5c3.5 0 6.9 1.4 9.4 3.9s3.9 5.8 3.9 9.4v.8h1.5V19c0-3.9-1.6-7.6-4.4-10.4zM4 20h3v-3H4v3z"/></svg></a>
              <a class="u-feed" rel="noopener" type="application/rss+xml" href="<?= esc($link['feedUrl'], 'attr') ?>" target="_blank" title="Load the feed">feed</a>
            </span>
          <?php endif ?>
          <?php if ($link['feedUrl'] !== '' && !$isRss && $showXfn && $link['xfn'] !== []): ?>
            <span class="blockroll-divider" aria-hidden="true">·</span>
          <?php endif ?>
          <?php if ($showXfn && $link['xfn'] !== []): ?>
            <ul class="blockroll-xfn">
              <?php foreach ($link['xfn'] as $token): ?>
                <li><?= esc($token) ?></li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>
      <?php endif ?>
    </li>
<?php endforeach ?>
  </ul>
<?php if ($opmlPage !== null):
  $opmlUrl = Opml::opmlUrl($opmlPage);
  $opmlDownload = $opmlPage->slug() . '.opml';
  ?>
  <p class="blockroll-opml">
    <a href="<?= esc($opmlUrl, 'attr') ?>" download="<?= esc($opmlDownload, 'attr') ?>">Herunterladen</a> oder <a href="<?= esc($opmlUrl, 'attr') ?>">öffnen</a> dieser Blogroll als OPML-Datei.
  </p>
<?php endif ?>
</section>
