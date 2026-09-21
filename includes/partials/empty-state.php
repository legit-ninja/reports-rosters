<?php
/**
 * Shared Empty State Partial
 *
 * Reusable empty-state component for reports and rosters.
 * Uses --intersoccer-* design tokens for consistent styling.
 *
 * @package InterSoccer_Reports_Rosters
 * @since 1.4.0
 *
 * @param array $args {
 *     Configuration arguments for the empty state.
 *
 *     @type string $title        Required. Heading text for the empty state.
 *     @type string $message      Required. Descriptive message explaining the empty state.
 *     @type string $icon         Optional. Emoji or dashicon class to display. Default '📋'.
 *     @type string $variant      Optional. Visual variant: 'empty' | 'no-results' | 'error'. Default 'empty'.
 *     @type string $action_label Optional. Text for primary action button.
 *     @type string $action_url   Optional. URL for primary action button.
 *     @type array  $actions      Optional. Array of additional action links: [ ['label' => '', 'url' => ''], ... ]
 * }
 */

if (!defined('ABSPATH')) {
    exit;
}

$title        = isset($args['title']) ? $args['title'] : __('No data found', 'intersoccer-reports-rosters');
$message      = isset($args['message']) ? $args['message'] : '';
$icon         = isset($args['icon']) ? $args['icon'] : '📋';
$variant      = isset($args['variant']) ? $args['variant'] : 'empty';
$action_label = isset($args['action_label']) ? $args['action_label'] : '';
$action_url   = isset($args['action_url']) ? $args['action_url'] : '';
$actions      = isset($args['actions']) && is_array($args['actions']) ? $args['actions'] : [];

$variant_class = '';
switch ($variant) {
    case 'no-results':
        $variant_class = 'intersoccer-empty-state--no-results';
        break;
    case 'error':
        $variant_class = 'intersoccer-empty-state--error';
        break;
    default:
        $variant_class = 'intersoccer-empty-state--empty';
        break;
}

$is_dashicon = strpos($icon, 'dashicons') === 0;
?>
<div class="intersoccer-empty-state <?php echo esc_attr($variant_class); ?>">
    <?php if ($is_dashicon): ?>
        <div class="intersoccer-empty-state-icon intersoccer-empty-state-icon--dashicon" aria-hidden="true">
            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
        </div>
    <?php else: ?>
        <div class="intersoccer-empty-state-icon" aria-hidden="true"><?php echo esc_html($icon); ?></div>
    <?php endif; ?>
    
    <h3 class="intersoccer-empty-state-title"><?php echo esc_html($title); ?></h3>
    
    <?php if ($message): ?>
        <p class="intersoccer-empty-state-message"><?php echo esc_html($message); ?></p>
    <?php endif; ?>
    
    <?php if ($action_label && $action_url): ?>
        <div class="intersoccer-empty-state-actions">
            <a href="<?php echo esc_url($action_url); ?>" class="button button-primary">
                <?php echo esc_html($action_label); ?>
            </a>
            <?php foreach ($actions as $action): ?>
                <?php if (!empty($action['label']) && !empty($action['url'])): ?>
                    <a href="<?php echo esc_url($action['url']); ?>" class="button">
                        <?php echo esc_html($action['label']); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php elseif (!empty($actions)): ?>
        <div class="intersoccer-empty-state-actions">
            <?php foreach ($actions as $action): ?>
                <?php if (!empty($action['label']) && !empty($action['url'])): ?>
                    <a href="<?php echo esc_url($action['url']); ?>" class="button">
                        <?php echo esc_html($action['label']); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
