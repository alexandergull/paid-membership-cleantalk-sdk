<?php
/**
 * The template for displaying restricted pages.
 *
 * @package ProfilePress
 */

if ( ! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/** @global array $args */

$content = $args['noaccess_action_message_custom'] ?? '';

if (empty($content)) {

    $content = ppress_settings_by_key(
        'global_restricted_access_message',
        esc_html__('You are unauthorized to view this page.', 'wp-user-avatar'),
        true
    );
}

get_header();

?>
<main id="site-content" class="ppress-main-container">
    <div class="ppress-container-div">
        <?php echo do_shortcode(wp_kses_post($content)); ?>
    </div>
</main>

<?php get_footer(); ?>
