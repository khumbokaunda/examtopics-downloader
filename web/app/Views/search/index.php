<?php
/**
 * Search every exam in the library: matching questions grouped by exam.
 *
 * @var string $q @var list<string> $words @var array<int,list<array>> $groups matches by exam
 * @var int $count @var bool $more more than the limit matched @var int $waiting exams not indexed yet
 */
use App\Services\Editions;
use App\Services\SearchIndex;

$limit = SearchIndex::LIMIT;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Search questions</h1>
        <p class="page-subtitle">Find questions across the library.</p>
    </div>
</header>

<form class="toolbar" method="get" action="<?= e(url('/search')) ?>" role="search">
    <div class="search-field search-field--wide"><?= icon('search') ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="For example: OSPF area border router" aria-label="Words to search for" autofocus></div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<?php if ($waiting > 0): ?>
    <div class="alert alert-info small"><?= icon('hourglass-split') ?><div class="alert-body"><?= $waiting === 1 ? '1 exam is' : number_format($waiting) . ' exams are' ?> still being added to the search. Search again in a minute to include <?= $waiting === 1 ? 'it' : 'them' ?>.</div></div>
<?php endif; ?>

<?php if ($q === ''): ?>
    <div class="card"><div class="card-body"><?= empty_state('search', 'Find a question anywhere', 'Type words from a question.', null, true) ?></div></div>
<?php elseif ($words === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('search', 'Type a longer word', 'Each word needs at least two letters.', null, true) ?></div></div>
<?php elseif ($groups === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('search', 'No questions match', 'Try fewer words.', null, true) ?></div></div>
<?php else: ?>
    <p class="text-muted small"><?= $more ? 'The first ' . $limit . ' questions found' : ($count === 1 ? '1 question' : number_format($count) . ' questions') ?>, in <?= count($groups) === 1 ? '1 exam' : count($groups) . ' exams' ?>.<?= $more ? ' Add a word to narrow it down.' : '' ?></p>
    <?php foreach ($groups as $rows): $x = $rows[0];
        $base = '/library/' . rawurlencode((string) $x['provider_slug']) . '/' . rawurlencode((string) $x['code']); ?>
        <section class="card mb-3 search-group">
            <div class="card-header">
                <span class="card-title"><a href="<?= e(url($base)) ?>"><?= e(Editions::title($x)) ?></a></span>
                <span class="small text-muted"><?= e(provider_name($x, 'provider_name', 'provider_slug')) ?> · <?= count($rows) ?> found</span>
            </div>
            <ul class="list-rows search-hits">
                <?php foreach ($rows as $r): $n = (int) $r['number']; ?>
                    <li>
                        <div class="row-main">
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url($base . '/view', ['jump' => (string) $n]) . '#question-' . $n) ?>">Question <?= $n ?></a>
                                <div class="search-snippet"><?= SearchIndex::snippet((string) $r['body'], $words) ?></div>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/view', ['mode' => 'practice', 'jump' => (string) $n]) . '#question-' . $n) ?>" title="Without the answer">Practise</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
