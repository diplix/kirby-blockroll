<?php

/** @var \Kirby\Cms\Block $block */
/** @var string|null $render */

use Blockroll\Links;
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
?>
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
          <a class="u-feed blockroll-feed" rel="alternate noopener" type="application/rss+xml" href="<?= esc($link['feedUrl'], 'attr') ?>" target="_blank">feed</a>
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
