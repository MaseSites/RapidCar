<?php
/**
 * Öffentliche Landingpage (§4 bis §7).
 *
 * Alle Grafiken sind handgezeichnetes Inline-SVG: keine Emojis, keine
 * Fremdbibliothek, keine externe Anfrage. Die Bewegungen laufen rein über CSS
 * und schalten sich bei "prefers-reduced-motion" selbst ab.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Integration\ChannelRegistry;

$pageTitle = t('app.tagline');
require BASE_PATH . '/includes/layout/public-header.php';
?>

<section class="hero">
    <div class="hero-text">
        <h1 class="hero-h">
            <span class="hero-prefix"><?= t('home.hero.prefix') ?></span>
            <span class="hero-time">
                <span class="time-old"><?= t('home.hero.old_time') ?></span>
                <span class="time-new"><?= t('home.hero.new_time') ?></span>
            </span>
        </h1>
        <p class="hero-lead"><?= t('home.hero.lead') ?></p>
        <div class="hero-actions">
            <a class="btn btn-accent btn-lg" href="<?= base_url('register.php') ?>"><?= t('home.hero.start') ?></a>
            <a class="btn btn-secondary btn-lg" href="<?= base_url('demo.php') ?>"><?= icon('eye', 16) ?> <?= t('nav.demo') ?></a>
        </div>
        <div class="hero-note"><?= icon('check', 14) ?> <?= t('home.hero.note') ?></div>
    </div>

    <!-- Zwei Telefone mit dem Ablauf: Fotos rein, fertiges Inserat raus -->
    <div class="hero-phones" aria-hidden="true">
        <div class="phone phone-hero-back">
            <div class="phone-notch"></div>
            <div class="phone-screen">
                <div class="mini-app">
                    <div class="mini-topbar"><i style="width:46%"></i></div>
                    <div class="mini-grid">
                        <?php for ($i = 0; $i < 6; $i++): ?><span class="mini-thumb"></span><?php endfor; ?>
                    </div>
                    <div class="mini-progress"><i></i></div>
                    <div class="mini-line" style="width:64%"></div>
                    <div class="mini-line" style="width:42%"></div>
                </div>
            </div>
        </div>
        <div class="phone phone-hero-front">
            <div class="phone-notch"></div>
            <div class="phone-screen">
                <div class="mini-app">
                    <div class="post-photo">
                        <svg viewBox="0 0 240 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect width="240" height="96" fill="#e3eaf2"/>
                            <rect y="66" width="240" height="30" fill="#ccd7e2"/>
                            <g>
                                <path d="M28 62c0-8 9-13 21-15l18-14c5-4 12-7 21-7h38c11 0 21 5 28 12l9 9c12 2 21 7 21 15v5h-12a12 12 0 0 0-24 0H66a12 12 0 0 0-24 0H28z" fill="#22262e"/>
                                <path d="M70 34c4-3 10-6 18-6h14v14H60z" fill="#93a7b8"/>
                                <path d="M108 28h26c8 0 15 3 20 8l6 6h-52z" fill="#93a7b8"/>
                                <circle cx="54" cy="67" r="10" fill="#14161b"/>
                                <circle cx="54" cy="67" r="4.5" fill="#5a6470"/>
                                <circle cx="166" cy="67" r="10" fill="#14161b"/>
                                <circle cx="166" cy="67" r="4.5" fill="#5a6470"/>
                            </g>
                        </svg>
                    </div>
                    <div class="mini-title"></div>
                    <div class="mini-line" style="width:82%"></div>
                    <div class="mini-line" style="width:58%"></div>
                    <div class="mini-score"><i></i><span><?= t('home.hero.score_label') ?></span></div>
                    <div class="mini-cta"><?= t('home.hero.publish_label') ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================================================== Zeitvergleich -->
<section class="section" id="vergleich">
    <h2 class="section-title"><?= t('home.compare.title') ?></h2>
    <p class="section-sub"><?= t('home.compare.sub') ?></p>

    <div class="compare">
        <div class="compare-col">
            <div class="compare-head">
                <span class="compare-label"><?= t('home.compare.before') ?></span>
                <span class="compare-time"><?= t('home.compare.before_time') ?></span>
            </div>
            <?php foreach ([
                'home.compare.step_photos'  => 8,
                'home.compare.step_data'    => 9,
                'home.compare.step_text'    => 7,
                'home.compare.step_publish' => 6,
            ] as $key => $minutes): ?>
                <div class="compare-row">
                    <span class="compare-step"><?= t($key) ?></span>
                    <span class="compare-bar"><i style="--w:<?= (int) round($minutes / 9 * 100) ?>%"></i></span>
                    <span class="compare-min"><?= $minutes ?> min</span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="compare-col is-after">
            <div class="compare-head">
                <span class="compare-label"><?= t('home.compare.after') ?></span>
                <span class="compare-time"><?= t('home.compare.after_time') ?></span>
            </div>
            <?php foreach ([
                'home.compare.step_photos'  => 'home.compare.clicks',
                'home.compare.step_data'    => 'home.compare.auto',
                'home.compare.step_text'    => 'home.compare.auto',
                'home.compare.step_publish' => 'home.compare.clicks',
            ] as $key => $badge): ?>
                <div class="compare-row">
                    <span class="compare-step"><?= t($key) ?></span>
                    <span class="compare-bar is-short"><i style="--w:9%"></i></span>
                    <span class="compare-min is-auto"><?= t($badge) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================================================== Ablauf -->
<section class="section" id="how-it-works">
    <h2 class="section-title"><?= t('home.how.title') ?></h2>
    <p class="section-sub"><?= t('home.how.sub') ?></p>
    <div class="steps-grid">
        <?php
        $steps = [
            ['1', 'home.how.step1.title', 'home.how.step1.text', 'camera'],
            ['2', 'home.how.step2.title', 'home.how.step2.text', 'file-text'],
            ['3', 'home.how.step3.title', 'home.how.step3.text', 'share'],
        ];
        foreach ($steps as [$number, $title, $text, $iconName]): ?>
            <div class="step-card">
                <div class="step-mark">
                    <span class="step-icon"><?= icon($iconName, 19) ?></span>
                    <span class="num"><?= $number ?></span>
                </div>
                <h3><?= t($title) ?></h3>
                <p><?= t($text) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ================================================== Plattformen -->
<section class="section" id="plattformen">
    <h2 class="section-title"><?= t('home.platforms.title') ?></h2>
    <p class="section-sub"><?= t('home.platforms.sub') ?></p>

    <div class="platform-wall">
        <?php foreach (ChannelRegistry::all() as $key => $channel): ?>
            <div class="platform-chip">
                <span class="platform-icon"><?= icon($channel['icon'], 16) ?></span>
                <span class="platform-name"><?= e($channel['name']) ?></span>
                <span class="platform-region"><?= e($channel['region']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="platform-note"><?= icon('shield', 15) ?> <?= t('home.platforms.note') ?></p>
</section>

<!-- ================================================== Werbung -->
<section class="section">
    <div class="ads-band">
        <div class="ads-text">
            <h2><?= t('home.ads.title') ?></h2>
            <p><?= t('home.ads.text') ?></p>
            <a class="btn btn-secondary" href="<?= base_url('features.php') ?>"><?= t('nav.features') ?></a>
        </div>
        <div class="ads-art" aria-hidden="true">
            <!-- Telefon mit fertigem Beitrag: Fahrzeug, Herz, Bildunterschrift -->
            <div class="phone">
                <div class="phone-notch"></div>
                <div class="phone-screen">
                    <div class="post-head">
                        <span class="post-avatar"></span>
                        <span class="post-lines"><i></i><i></i></span>
                    </div>
                    <div class="post-photo">
                        <svg viewBox="0 0 240 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect width="240" height="96" fill="#dfe7ee"/>
                            <rect y="66" width="240" height="30" fill="#c6d2dc"/>
                            <g class="post-car">
                                <path d="M28 62c0-8 9-13 21-15l18-14c5-4 12-7 21-7h38c11 0 21 5 28 12l9 9c12 2 21 7 21 15v5h-12a12 12 0 0 0-24 0H66a12 12 0 0 0-24 0H28z" fill="#22262e"/>
                                <path d="M70 34c4-3 10-6 18-6h14v14H60z" fill="#8fa3b4"/>
                                <path d="M108 28h26c8 0 15 3 20 8l6 6h-52z" fill="#8fa3b4"/>
                                <circle cx="54" cy="67" r="10" fill="#14161b"/>
                                <circle cx="54" cy="67" r="4.5" fill="#5a6470"/>
                                <circle cx="166" cy="67" r="10" fill="#14161b"/>
                                <circle cx="166" cy="67" r="4.5" fill="#5a6470"/>
                                <rect x="180" y="52" width="10" height="4" rx="2" fill="#98a2b3"/>
                            </g>
                        </svg>
                        <span class="post-like"><?= icon('star', 13) ?></span>
                    </div>
                    <div class="post-actions">
                        <span class="dot-heart"></span><span class="dot"></span><span class="dot"></span>
                    </div>
                    <div class="post-caption"><i></i><i></i></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================================================== Funktionen -->
<section class="section" id="features">
    <h2 class="section-title"><?= t('home.features.title') ?></h2>
    <p class="section-sub"><?= t('home.features.sub') ?></p>
    <div class="features-grid">
        <?php
        $features = [
            ['search', 'feature.detection.title', 'feature.detection.text'],
            ['image', 'feature.images.title', 'feature.images.text'],
            ['file-text', 'feature.generator.title', 'feature.generator.text'],
            ['chart', 'feature.score.title', 'feature.score.text'],
            ['tag', 'feature.price.title', 'feature.price.text'],
            ['instagram', 'feature.social.title', 'feature.social.text'],
            ['message', 'feature.leads.title', 'feature.leads.text'],
            ['activity', 'feature.analytics.title', 'feature.analytics.text'],
        ];
        foreach ($features as [$iconName, $title, $text]): ?>
            <div class="feature-card">
                <div class="feature-icon"><?= icon($iconName, 18) ?></div>
                <h3><?= t($title) ?></h3>
                <p><?= t($text) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="cta-wrap">
    <div class="cta">
        <h2><?= t('home.cta.title') ?></h2>
        <a class="btn btn-accent btn-lg" href="<?= base_url('register.php') ?>"><?= t('home.cta.button') ?></a>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
