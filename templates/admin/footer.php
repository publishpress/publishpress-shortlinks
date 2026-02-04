<?php 
$rating_star = '';
$rating_star .= '<span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span>';
$rating_star .= '<span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span>';
$rating_star .= '<span class="dashicons dashicons-star-filled"></span>';
?>
<br style="clear: both;">
<footer>
    <div class="tinypress-rating">
        <a
            href="//wordpress.org/support/plugin/<?php echo esc_attr($context['plugin_slug']); ?>/reviews/#new-post"
            target="_blank"
            rel="noopener noreferrer">
            <?php printf(esc_html__('If you like %1$s please leave us a %2$s rating. Thank you!', 'tinypress'), '<strong>'. $context['plugin_name'] .'</strong>', $rating_star); ?>
        </a>
    </div>
    <hr>
    <nav>
        <ul>
            <li>
                <a href="//publishpress.com/shortlinks/" target="_blank" rel="noopener noreferrer" title="About <?php echo esc_attr($context['plugin_name']); ?>"><?php esc_html_e('About', 'tinypress'); ?></a>
            </li>
            <li>
                <a href="//publishpress.com/knowledge-base/introduction-shortlinks/" target="_blank"
                   rel="noopener noreferrer"
                   title="Documentation"><?php esc_html_e('Documentation', 'tinypress'); ?></a>
            </li>
            <li>
                <a href="//publishpress.com/contact/" target="_blank" rel="noopener noreferrer"
                   title="Contact the PublishPress team"><?php esc_html_e('Contact', 'tinypress'); ?></a>
            </li>
        </ul>
    </nav>
    <div class="tinypress-pressshack-logo">
        <a href="//publishpress.com" target="_blank" rel="noopener noreferrer">
            <img src="<?php echo esc_attr($context['plugin_url']); ?>assets/admin/img/publishpress-logo.png" alt="PublishPress">
        </a>
    </div>
</footer>
</div>
