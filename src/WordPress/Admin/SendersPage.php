<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\EmailAddress;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use DomainException;
use InvalidArgumentException;

final class SendersPage
{
    private const PAGE_SLUG = 'adct-parish-intake-senders';
    private const ACTION = 'adct_pi_sender_action';
    private const PAGE_SIZE = 20;

    public function __construct(
        private ParishContactRepository $contacts,
        private ContactService $contactService,
        private ParishRepository $parishes
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Senders',
            'Senders',
            Capabilities::MANAGE_DIRECTORY,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireDirectoryCapability();
        $filters = [
            'search' => sanitize_text_field($this->getText('search')),
            'trust' => sanitize_key($this->getText('trust')),
        ];

        if ($filters['trust'] !== '' && ! SenderTrust::isValid($filters['trust'])) {
            $filters['trust'] = '';
        }

        $page = max(1, absint($this->getText('paged')));
        $total = $this->contacts->countSenders($filters);
        $addresses = $this->contacts->findSenderAddresses(
            $filters,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );
        $emails = array_values(array_map(
            static fn (array $sender): string => (string) ($sender['email'] ?? ''),
            $addresses
        ));
        $linksByEmail = [];

        foreach ($this->contacts->findSenderLinksByEmails($emails) as $link) {
            $email = (string) ($link['email'] ?? '');
            $linksByEmail[$email][] = $link;
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Senders</h1>
            <hr class="wp-header-end" />

            <?php if (isset($_GET['sender_updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Sender details updated.</p></div>
            <?php endif; ?>

            <p>A sender address can be linked to several parishes. Trust changes apply to every parish link for that address.</p>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="sender-search">Search senders</label>
                    <input type="search" id="sender-search" name="search" value="<?php echo esc_attr($filters['search']); ?>" />
                    <input type="submit" class="button" value="Search senders" />
                </p>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label class="screen-reader-text" for="sender-trust-filter">Filter by trust</label>
                        <select id="sender-trust-filter" name="trust">
                            <option value="">All trust states</option>
                            <?php foreach (SenderTrust::all() as $trust) : ?>
                                <option value="<?php echo esc_attr($trust); ?>" <?php selected($filters['trust'], $trust); ?>>
                                    <?php echo esc_html($this->label($trust)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button">Filter</button>
                    </div>
                    <br class="clear" />
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Email</th>
                        <th scope="col">Trust</th>
                        <th scope="col">Linked parishes</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($addresses === []) : ?>
                        <tr><td colspan="4">No senders match these filters.</td></tr>
                    <?php else : ?>
                        <?php foreach ($addresses as $sender) : ?>
                            <?php
                            $email = (string) ($sender['email'] ?? '');
                            $trust = (string) ($sender['trust'] ?? '');

                            if (
                                ! SenderTrust::isValid($trust)
                                || (int) ($sender['trust_count'] ?? 0) !== 1
                            ) {
                                wp_die(esc_html__('Sender trust data is inconsistent across parish links. Please contact the site administrator.', 'adct-parish-intake'), '', [
                                    'response' => 500,
                                ]);
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($email); ?></strong></td>
                                <td><?php echo esc_html($this->label($trust)); ?></td>
                                <td>
                                    <?php $links = $linksByEmail[$email] ?? []; ?>
                                    <?php if ($links === []) : ?>
                                        <span class="description">No parish links</span>
                                    <?php else : ?>
                                        <ul>
                                            <?php foreach ($links as $link) : ?>
                                                <?php
                                                $details = array_filter([
                                                    trim((string) ($link['display_name'] ?? '')),
                                                    trim((string) ($link['role_label'] ?? '')),
                                                ], static fn (string $detail): bool => $detail !== '');
                                                ?>
                                                <li>
                                                    <strong><?php echo esc_html((string) ($link['parish_name'] ?? 'Unknown parish')); ?></strong>
                                                    <?php if ($details !== []) : ?>
                                                        — <?php echo esc_html(implode(' / ', $details)); ?>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($trust === SenderTrust::BLOCKED) : ?>
                                        <?php $this->renderStateActionForm('unblock', $email, 'Unblock address'); ?>
                                    <?php else : ?>
                                        <?php if ($trust !== SenderTrust::VERIFIED) : ?>
                                            <?php $this->renderStateActionForm('verify', $email, 'Verify address'); ?>
                                        <?php endif; ?>
                                        <?php $this->renderStateActionForm('block', $email, 'Block address', 'secondary'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->renderPagination($filters, $page, $total); ?>

            <hr />
            <h2>Link a sender to a parish</h2>
            <p>Use this when one address serves more than one parish. Existing trust is retained and shared by every link.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                <input type="hidden" name="sender_action" value="link" />
                <?php wp_nonce_field('adct_pi_sender_link', 'sender_nonce'); ?>
                <p>
                    <label for="sender-link-email">Email address</label><br />
                    <input id="sender-link-email" class="regular-text" name="email" type="email" maxlength="191" required />
                </p>
                <p>
                    <label for="sender-link-parish">Parish</label><br />
                    <select id="sender-link-parish" name="parish_id" required>
                        <option value="">Choose a parish</option>
                        <?php foreach ($this->parishes->findAllForImport() as $parish) : ?>
                            <option value="<?php echo esc_attr((string) $parish['id']); ?>">
                                <?php echo esc_html((string) $parish['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="sender-link-name">Display name</label><br />
                    <input id="sender-link-name" class="regular-text" name="display_name" type="text" maxlength="191" />
                </p>
                <p>
                    <label for="sender-link-role">Role</label><br />
                    <input id="sender-link-role" class="regular-text" name="role_label" type="text" maxlength="191" />
                </p>
                <p><label><input name="receives_reminders" type="checkbox" value="1" checked /> Receives reminders</label></p>
                <?php submit_button('Link sender', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public function handleAction(): void
    {
        $this->requireDirectoryCapability();
        $action = sanitize_key($this->postText('sender_action'));

        if (! in_array($action, ['link', 'verify', 'block', 'unblock'], true)) {
            wp_die(esc_html__('Choose a valid sender action.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        try {
            if ($action === 'link') {
                check_admin_referer('adct_pi_sender_link', 'sender_nonce');
                $parishId = absint($this->postText('parish_id'));

                if ($parishId < 1 || $this->parishes->findWithRelations($parishId) === null) {
                    wp_die(esc_html__('Choose a parish from the list.', 'adct-parish-intake'), '', [
                        'response' => 400,
                    ]);
                }

                $this->contactService->link(
                    $parishId,
                    $this->postText('email'),
                    sanitize_text_field($this->postText('display_name')),
                    sanitize_text_field($this->postText('role_label')),
                    $this->postText('receives_reminders') === '1'
                );
            } else {
                $email = EmailAddress::normalize($this->postText('email'));
                check_admin_referer($this->nonceAction($action, $email), 'sender_nonce');
                $lookup = $this->contactService->lookup($email);

                if ($lookup->parishIds === []) {
                    wp_die(esc_html__('The sender address has no parish links.', 'adct-parish-intake'), '', [
                        'response' => 404,
                    ]);
                }

                if ($action === 'verify') {
                    $this->contactService->verify($email);
                } elseif ($action === 'block') {
                    $this->contactService->block($email);
                } else {
                    $this->contactService->unblock($email);
                }
            }
        } catch (DomainException | InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()), esc_html__('Sender update failed', 'adct-parish-intake'), [
                'response' => 400,
            ]);
        }

        wp_safe_redirect($this->pageUrl(['sender_updated' => 1]));
        exit;
    }

    private function renderStateActionForm(
        string $action,
        string $email,
        string $buttonLabel,
        string $buttonClass = 'secondary'
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
            <input type="hidden" name="sender_action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>" />
            <?php wp_nonce_field($this->nonceAction($action, $email), 'sender_nonce'); ?>
            <button class="button button-<?php echo esc_attr($buttonClass); ?>" type="submit"><?php echo esc_html($buttonLabel); ?></button>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function renderPagination(array $filters, int $page, int $total): void
    {
        $pages = (int) ceil($total / self::PAGE_SIZE);

        if ($pages <= 1) {
            return;
        }

        $arguments = ['page' => self::PAGE_SLUG, 'paged' => '%#%'];

        foreach ($filters as $key => $value) {
            if ($value !== '' && $value !== 0) {
                $arguments[$key] = $value;
            }
        }

        $links = paginate_links([
            'base' => add_query_arg($arguments, admin_url('admin.php')),
            'format' => '',
            'current' => $page,
            'total' => $pages,
            'type' => 'plain',
        ]);

        if (is_string($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
        }
    }

    private function nonceAction(string $action, string $email): string
    {
        return 'adct_pi_sender_' . $action . '_' . $email;
    }

    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function requireDirectoryCapability(): void
    {
        if (! current_user_can(Capabilities::MANAGE_DIRECTORY)) {
            wp_die(esc_html__('You do not have permission to manage parish contacts.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
    }

    private function label(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function getText(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }

    private function postText(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }
}
