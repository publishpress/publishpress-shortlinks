<?php
/**
 * @package PublishPress Shortlinks
 * @author  PublishPress
 *
 * Copyright (C) 2024 PublishPress
 *
 * This file is part of PublishPress Shortlinks
 *
 * PublishPress Shortlinks is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * PublishPress Shortlinks is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PublishPress Shortlinks.  If not, see <http://www.gnu.org/licenses/>.
 */

use PublishPress\WordPressReviews\ReviewsController;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class SHORTLINKS_Reviews
 *
 * This class adds a review request system for the PublishPress Shortlinks plugin.
 */
class SHORTLINKS_Reviews
{
    /**
     * @var ReviewsController
     */
    private $reviewController;

    /**
     * @var SHORTLINKS_Reviews
     */
    private static $instance = null;

    /**
     * The constructor
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'initReviews'));
    }

    /**
     * Initialize the review system
     */
    public function initReviews()
    {
        if (!class_exists('PublishPress\\WordPressReviews\\ReviewsController')) {
            return;
        }

        $this->reviewController = new ReviewsController(
            'tinypress',
            'PublishPress Shortlinks',
            TINYPRESS_PLUGIN_URL . 'assets/admin/img/Shortlinks-logo.png'
        );

        $this->reviewController->init();
    }

    /**
     * Get instance
     *
     * @return SHORTLINKS_Reviews
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
