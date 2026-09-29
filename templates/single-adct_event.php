<?php

declare(strict_types=1);

use ADCT\ParishIntake\WordPress\Plugin;

get_header();

$event = Plugin::publicEventPage()->viewCurrentPost();
$poster = $event['poster'];
$parish = $event['parish'];
$venue = $event['venue'];
?>
<main id="primary" class="site-main adct-event-page">
    <article class="adct-event adct-event--<?php echo esc_attr($event['status_class']); ?>">
        <?php if ($event['status_banner'] !== '') : ?>
            <p class="adct-event__banner adct-event__banner--<?php echo esc_attr($event['status_class']); ?>">
                <?php echo esc_html($event['status_banner']); ?>
            </p>
        <?php endif; ?>

        <header class="adct-event__header">
            <h1 class="adct-event__title"><?php echo esc_html($event['title']); ?></h1>
            <p class="adct-event__datetime">
                <time datetime="<?php echo esc_attr($event['date_iso']); ?>">
                    <?php echo esc_html($event['date_label']); ?>
                </time>
                <span class="adct-event__time"><?php echo esc_html($event['time_label']); ?></span>
            </p>
            <?php if ($event['recurrence_phrase'] !== 'One-off event') : ?>
                <p class="adct-event__recurrence"><?php echo esc_html($event['recurrence_phrase']); ?></p>
            <?php endif; ?>
        </header>

        <?php if ($poster !== null) : ?>
            <figure class="adct-event__poster">
                <img
                    src="<?php echo esc_url($poster['url']); ?>"
                    width="<?php echo esc_attr((string) $poster['width']); ?>"
                    height="<?php echo esc_attr((string) $poster['height']); ?>"
                    alt="<?php echo esc_attr($poster['alt'] !== '' ? $poster['alt'] : $event['title']); ?>"
                />
            </figure>
        <?php endif; ?>

        <div class="adct-event__description">
            <?php echo $event['description_html']; ?>
        </div>

        <section class="adct-event__actions" aria-label="Calendar options">
            <a class="button" href="<?php echo esc_url($event['calendar_url']); ?>">Download ICS</a>
            <?php if ($event['google_calendar_url'] !== null) : ?>
                <a class="button button-primary" href="<?php echo esc_url($event['google_calendar_url']); ?>" target="_blank" rel="noopener noreferrer">
                    Add to Google Calendar
                </a>
            <?php endif; ?>
        </section>

        <section class="adct-event__details" aria-label="Event details">
            <h2>Details</h2>
            <dl>
                <?php if ($parish['name'] !== '') : ?>
                    <dt>Parish</dt>
                    <dd>
                        <?php echo esc_html($parish['name']); ?>
                        <?php if ($parish['phone'] !== '') : ?>
                            <br /><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $parish['phone'])); ?>"><?php echo esc_html($parish['phone']); ?></a>
                        <?php endif; ?>
                        <?php if ($parish['website'] !== '') : ?>
                            <br /><a href="<?php echo esc_url($parish['website']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($parish['website']); ?></a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($venue['name'] !== '') : ?>
                    <dt>Venue</dt>
                    <dd><?php echo esc_html($venue['name']); ?></dd>
                <?php endif; ?>

                <?php if ($venue['address'] !== '' || $parish['address'] !== '') : ?>
                    <dt>Address</dt>
                    <dd>
                        <?php echo esc_html($venue['address'] !== '' ? $venue['address'] : $parish['address']); ?>
                        <?php if ($event['map_url'] !== null) : ?>
                            <br /><a href="<?php echo esc_url($event['map_url']); ?>" target="_blank" rel="noopener noreferrer">Open map</a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($event['contact']['name'] !== '' || $event['contact']['email'] !== '' || $event['contact']['phone'] !== '') : ?>
                    <dt>Contact</dt>
                    <dd>
                        <?php if ($event['contact']['name'] !== '') : ?>
                            <?php echo esc_html($event['contact']['name']); ?>
                            <br />
                        <?php endif; ?>
                        <?php if ($event['contact']['email'] !== '') : ?>
                            <a href="mailto:<?php echo esc_attr($event['contact']['email']); ?>"><?php echo esc_html($event['contact']['email']); ?></a>
                            <br />
                        <?php endif; ?>
                        <?php if ($event['contact']['phone'] !== '') : ?>
                            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $event['contact']['phone'])); ?>"><?php echo esc_html($event['contact']['phone']); ?></a>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($event['next_dates'] !== []) : ?>
                    <dt>Next dates</dt>
                    <dd>
                        <ul class="adct-event__next-dates">
                            <?php foreach ($event['next_dates'] as $nextDate) : ?>
                                <li>
                                    <time datetime="<?php echo esc_attr($nextDate['datetime']); ?>">
                                        <?php echo esc_html($nextDate['label']); ?>
                                    </time>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </dd>
                <?php endif; ?>
            </dl>
        </section>

        <script type="application/ld+json">
            <?php echo wp_json_encode($event['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>
        </script>
    </article>
</main>
<?php
get_footer();
